<?php

namespace Jiannius\Myinvois\Tests\Feature;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\NullQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Jiannius\Myinvois\Events\SubmissionAccepted;
use Jiannius\Myinvois\Events\SubmissionFailed;
use Jiannius\Myinvois\Events\SubmissionSucceeded;
use Jiannius\Myinvois\Exceptions\MyinvoisInFlightException;
use Jiannius\Myinvois\Jobs\PollSubmission;
use Jiannius\Myinvois\Jobs\SubmitDocuments;
use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Myinvois;
use Jiannius\Myinvois\Support\SubmissionLock;
use Jiannius\Myinvois\Tests\Fixtures\CertFixture;
use Jiannius\Myinvois\Tests\Fixtures\DocumentFixture;
use Jiannius\Myinvois\Tests\Fixtures\Order;
use Jiannius\Myinvois\Tests\Fixtures\OrgOrder;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class QueuedSubmissionTest extends TestCase
{
    protected function setUp() : void
    {
        parent::setUp();

        Event::fake([SubmissionAccepted::class, SubmissionSucceeded::class, SubmissionFailed::class]);
    }

    // ---- helpers -------------------------------------------------------

    protected function accept(string $status = 'Submitted', array $extraSummary = []) : void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documentsubmissions*' => Http::response([
                'submissionUid' => 'SUB1',
                'acceptedDocuments' => [['uuid' => 'UID1', 'invoiceCodeNumber' => 'INV-0001']],
                'documentSummary' => [['uuid' => 'UID1', 'submissionUid' => 'SUB1', 'status' => $status, ...$extraSummary]],
            ]),
        ]);
    }

    /** Queue one invoice and hand back the dispatched job, ready to run. */
    protected function queued(?OrgOrder $order = null, array $context = ['batch' => 7]) : SubmitDocuments
    {
        Queue::fake();

        Myinvois::queueSubmission([DocumentFixture::invoice()], ['INV-0001' => $order ?? OrgOrder::create()], $context);

        $job = Queue::pushed(SubmitDocuments::class)->first();
        $job->withFakeQueueInteractions();

        return $job;
    }

    protected function lockIsFree(Order $order, bool $preprod = false) : bool
    {
        $lock = Cache::lock(SubmissionLock::key($order, $preprod), 5);

        if (! $lock->get()) return false;

        $lock->release();

        return true;
    }

    protected function posts() : int
    {
        return Http::recorded(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'documentsubmissions'))->count();
    }

    protected function failedEvent(?string $reason = null) : SubmissionFailed
    {
        $events = [];
        Event::assertDispatched(SubmissionFailed::class, function ($event) use (&$events) {
            $events[] = $event;

            return true;
        });

        $this->assertCount(1, $events, 'exactly one SubmissionFailed expected');

        if ($reason) $this->assertSame($reason, $events[0]->reason);

        return $events[0];
    }

    // ---- queueSubmission -----------------------------------------------

    #[Test]
    public function queue_submission_locks_dispatches_and_returns_without_calling_lhdn() : void
    {
        config()->set('services.myinvois.queue_connection', 'database');
        config()->set('services.myinvois.queue', 'myinvois');
        Http::fake();
        Queue::fake();
        $order = OrgOrder::create();

        Myinvois::queueSubmission([DocumentFixture::invoice()], ['INV-0001' => $order], ['batch' => 7]);

        Http::assertNothingSent();
        Queue::assertPushed(SubmitDocuments::class, function (SubmitDocuments $job) use ($order) {
            return $job->connection === 'database'
                && $job->queue === 'myinvois'
                && $job->parents === ['INV-0001' => ['type' => OrgOrder::class, 'id' => $order->id]]
                && $job->preprod === false
                && $job->context === ['batch' => 7]
                && $job->documents[0]['number'] === 'INV-0001'
                && $job->lockOwner !== '';
        });
        $this->assertFalse($this->lockIsFree($order), 'the lock is held from dispatch');
    }

    #[Test]
    public function queue_submission_refuses_a_held_lock_and_dispatches_nothing() : void
    {
        Queue::fake();
        $order = OrgOrder::create();
        SubmissionLock::acquire([$order], false);

        $this->expectException(MyinvoisInFlightException::class);

        try {
            Myinvois::queueSubmission([DocumentFixture::invoice()], ['INV-0001' => $order]);
        } finally {
            Queue::assertNothingPushed();
        }
    }

    #[Test]
    public function queue_submission_refuses_a_submitted_or_valid_parent_and_leaks_no_lock() : void
    {
        Queue::fake();

        foreach (['submitted', 'valid'] as $status) {
            $order = OrgOrder::create();
            MyinvoisDocument::create(['parent_type' => OrgOrder::class, 'parent_id' => $order->id, 'status' => $status, 'is_preprod' => false]);

            try {
                Myinvois::queueSubmission([DocumentFixture::invoice()], ['INV-0001' => $order]);
                $this->fail("Expected MyinvoisInFlightException for $status.");
            } catch (MyinvoisInFlightException) {
            }

            $this->assertTrue($this->lockIsFree($order));
        }

        Queue::assertNothingPushed();
    }

    #[Test]
    public function queue_submission_needs_a_parent_for_every_row() : void
    {
        Queue::fake();
        $order = OrgOrder::create();

        try {
            Myinvois::queueSubmission([DocumentFixture::consolidated()], ['Widget' => $order]);
            $this->fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Receipt 1001-2000', $e->getMessage());
        }

        Queue::assertNothingPushed();
        $this->assertTrue($this->lockIsFree($order));
    }

    #[Test]
    public function the_serialised_job_is_encrypted_and_holds_no_secret() : void
    {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $job = $this->queued();

        $this->assertInstanceOf(ShouldBeEncrypted::class, $job);

        $queue = new class extends NullQueue {
            public function payloadOf($job) : string
            {
                return $this->createPayload($job, 'default');
            }
        };
        $queue->setContainer($this->app);

        $payload = $queue->payloadOf($job);

        $this->assertStringNotContainsString('INV-0001', $payload, 'the documents must not sit in the payload in clear text');
        $this->assertStringNotContainsString('Supplier Sdn Bhd', $payload);

        // and even decrypted, nothing a client needs to authenticate is in there
        $plain = serialize($job);
        foreach (['org-secret', 'org-client', 'C-ORG-TIN', 'PRIVATE KEY', 'BEGIN CERTIFICATE', CertFixture::privateKey(), CertFixture::certificate()] as $secret) {
            $this->assertStringNotContainsString($secret, $plain);
            $this->assertStringNotContainsString($secret, $payload);
        }
    }

    // ---- SubmitDocuments -----------------------------------------------

    #[Test]
    public function the_job_writes_stamped_rows_announces_acceptance_and_dispatches_the_poll() : void
    {
        $this->accept();
        $order = OrgOrder::create();
        $job = $this->queued($order);
        Queue::fake();

        $job->handle();

        $row = MyinvoisDocument::first();
        $this->assertSame(OrgOrder::class, $row->parent_type);
        $this->assertSame($order->id, $row->parent_id);
        $this->assertSame('submitted', $row->status->value);
        $this->assertFalse((bool) $row->is_preprod);

        // token + POST only: no sleeping inline poll
        $this->assertSame(1, $this->posts());
        Http::assertSentCount(2);

        Event::assertDispatched(SubmissionAccepted::class, fn ($e) => $e->documentNumbers === ['INV-0001']
            && $e->submissionUid === 'SUB1'
            && $e->parents === [['type' => OrgOrder::class, 'id' => $order->id]]
            && $e->context === ['batch' => 7]);
        Event::assertNotDispatched(SubmissionFailed::class);

        Queue::assertPushed(PollSubmission::class, fn (PollSubmission $poll) => $poll->submissionUid === 'SUB1'
            && $poll->preprod === false
            && $poll->context === ['batch' => 7]
            && $poll->parents === [['type' => OrgOrder::class, 'id' => $order->id]]);

        $this->assertTrue($this->lockIsFree($order), 'released once the rows are written');
        $job->assertNotReleased();
    }

    #[Test]
    public function a_429_is_retried_after_retry_after() : void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documentsubmissions*' => Http::response('', 429, ['Retry-After' => '7']),
        ]);
        $job = $this->queued();

        $job->handle();

        $job->assertReleased(7);
        Event::assertNotDispatched(SubmissionFailed::class);
        $this->assertSame(0, MyinvoisDocument::count());
    }

    #[Test]
    public function retries_back_off_30_60_120_then_give_up_with_an_event() : void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documentsubmissions*' => Http::response('', 429),
        ]);
        $order = OrgOrder::create();
        $job = $this->queued($order);

        foreach ([30, 60, 120] as $delay) {
            $job->withFakeQueueInteractions();
            $job->handle();
            $job->assertReleased($delay);
        }

        Event::assertNotDispatched(SubmissionFailed::class);

        $job->withFakeQueueInteractions();
        $job->handle();

        $job->assertNotReleased();
        $this->failedEvent(SubmissionFailed::UNAVAILABLE);
        $this->assertTrue($this->lockIsFree($order));
    }

    #[Test]
    public function an_unreachable_token_endpoint_is_retried() : void
    {
        Http::fake(['*/connect/token' => fn () => throw new ConnectionException('cURL error 28')]);
        $job = $this->queued();

        $job->handle();

        $job->assertReleased(30);
        Event::assertNotDispatched(SubmissionFailed::class);
    }

    #[Test]
    public function rejected_credentials_are_not_retried() : void
    {
        Http::fake(['*/connect/token' => Http::response(['error' => 'invalid_client'], 400)]);
        $order = OrgOrder::create();
        $job = $this->queued($order);

        $job->handle();

        $job->assertNotReleased();
        $this->failedEvent(SubmissionFailed::ERROR);
        $this->assertTrue($this->lockIsFree($order));
    }

    #[Test]
    public function a_timeout_on_the_post_is_not_retried_and_keeps_the_lock() : void
    {
        $attempts = 0;
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documentsubmissions*' => function () use (&$attempts) {
                $attempts++;

                throw new ConnectionException('cURL error 28: Operation timed out');
            },
        ]);
        $order = OrgOrder::create();
        $job = $this->queued($order);

        $job->handle();

        $job->assertNotReleased();
        $event = $this->failedEvent(SubmissionFailed::AMBIGUOUS);
        $this->assertTrue($event->ambiguous);
        $this->assertSame(['INV-0001'], $event->documentNumbers);
        $this->assertSame(1, $attempts, 'never resent');
        $this->assertFalse($this->lockIsFree($order), 'kept until its TTL');
        $this->assertSame(0, MyinvoisDocument::count());
    }

    #[Test]
    public function a_5xx_on_the_post_is_not_retried_and_keeps_the_lock() : void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documentsubmissions*' => Http::response('', 502),
        ]);
        $order = OrgOrder::create();
        $job = $this->queued($order);

        $job->handle();

        $job->assertNotReleased();
        $this->assertTrue($this->failedEvent(SubmissionFailed::AMBIGUOUS)->ambiguous);
        $this->assertSame(1, $this->posts());
        $this->assertFalse($this->lockIsFree($order));
    }

    #[Test]
    public function a_partial_rejection_fires_failed_for_the_rejected_and_polls_the_accepted() : void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documentsubmissions*' => Http::response([
                'submissionUid' => 'SUB1',
                'acceptedDocuments' => [['uuid' => 'UID1', 'invoiceCodeNumber' => 'INV-0001']],
                'rejectedDocuments' => [[
                    'invoiceCodeNumber' => 'INV-0002',
                    'error' => ['code' => 'BadStructure', 'message' => 'Invalid structure', 'details' => [['message' => 'Missing TIN']]],
                ]],
            ]),
        ]);
        Queue::fake();
        $a = OrgOrder::create();
        $b = OrgOrder::create();

        Myinvois::queueSubmission(
            [DocumentFixture::invoice(), DocumentFixture::invoice(['number' => 'INV-0002'])],
            ['INV-0001' => $a, 'INV-0002' => $b],
        );
        $job = Queue::pushed(SubmitDocuments::class)->first()->withFakeQueueInteractions();

        $job->handle();

        $event = $this->failedEvent(SubmissionFailed::REJECTED);
        $this->assertSame(['INV-0002'], $event->documentNumbers);
        $this->assertSame([['type' => OrgOrder::class, 'id' => $b->id]], $event->parents);
        $this->assertSame(['Invalid structure', 'Missing TIN'], $event->errors);
        Event::assertDispatched(SubmissionAccepted::class, fn ($e) => $e->documentNumbers === ['INV-0001']);
        $this->assertSame(1, MyinvoisDocument::count());
        $this->assertTrue($this->lockIsFree($a));
        $this->assertTrue($this->lockIsFree($b));
    }

    #[Test]
    public function a_whole_batch_rejection_fires_failed_and_polls_nothing() : void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documentsubmissions*' => Http::response(['error' => ['code' => 'MaximumSizeExceeded', 'message' => 'Batch too large']], 400),
        ]);
        $order = OrgOrder::create();
        $job = $this->queued($order);
        Queue::fake();

        $job->handle();

        $this->assertSame(['Batch too large'], $this->failedEvent(SubmissionFailed::REJECTED)->errors);
        Queue::assertNothingPushed();
        $this->assertTrue($this->lockIsFree($order));
    }

    #[Test]
    public function a_client_resolving_to_another_environment_is_refused_before_anything_is_sent() : void
    {
        Http::fake();
        $order = OrgOrder::create(); // resolves to production
        $job = new SubmitDocuments(
            [DocumentFixture::invoice()],
            ['INV-0001' => ['type' => OrgOrder::class, 'id' => $order->id]],
            true, // but it was queued for preprod
            SubmissionLock::acquire([$order], true),
        );
        $job->withFakeQueueInteractions();

        $job->handle();

        Http::assertNothingSent();
        $this->failedEvent(SubmissionFailed::ENVIRONMENT);
        $this->assertTrue($this->lockIsFree($order, true));
    }

    #[Test]
    public function a_parent_that_became_submitted_or_valid_is_refused_on_the_worker() : void
    {
        foreach (['submitted', 'valid'] as $status) {
            Event::fake([SubmissionAccepted::class, SubmissionSucceeded::class, SubmissionFailed::class]);
            Http::fake();
            $order = OrgOrder::create();
            $job = $this->queued($order);

            // submitted by another route between dispatch and run
            MyinvoisDocument::create(['parent_type' => OrgOrder::class, 'parent_id' => $order->id, 'status' => $status, 'is_preprod' => false]);
            Http::fake();

            $job->handle();

            $this->failedEvent(SubmissionFailed::IN_FLIGHT);
            $this->assertSame(0, $this->posts());
            $this->assertTrue($this->lockIsFree($order));
        }
    }

    #[Test]
    public function a_deleted_parent_is_reported_and_its_lock_released() : void
    {
        Http::fake();
        $order = OrgOrder::create();
        $job = $this->queued($order);
        $order->delete();

        $job->handle();

        Http::assertNothingSent();
        $this->failedEvent(SubmissionFailed::ERROR);
        $this->assertTrue($this->lockIsFree($order));
    }

    #[Test]
    public function a_capped_rate_limiter_releases_the_job_instead_of_sleeping() : void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documentsubmissions*' => Http::response([]),
        ]);
        $order = OrgOrder::create();
        $job = $this->queued($order);
        $key = Myinvois::clientFor($order)->getThrottleKey('documentsubmissions');

        for ($i = 0; $i < 60; $i++) RateLimiter::hit($key);

        $started = microtime(true);
        $job->handle();

        $this->assertLessThan(2, microtime(true) - $started, 'must not sleep');
        $this->assertSame(0, $this->posts());
        $job->assertReleased(RateLimiter::availableIn($key));
        Event::assertNotDispatched(SubmissionFailed::class);
        $this->assertTrue($this->lockIsFree($order), 'freed so the retry can retake it');
    }

    #[Test]
    public function the_job_resolves_the_client_through_the_fake_when_one_is_installed() : void
    {
        $fake = Myinvois::fake();
        $order = OrgOrder::create();
        $job = $this->queued($order);

        $job->handle();

        $fake->assertDocumentSubmitted('INV-0001');
    }

    // ---- PollSubmission ------------------------------------------------

    protected function poll(OrgOrder $order, int $attempt = 1) : PollSubmission
    {
        $poll = new PollSubmission('SUB1', [['type' => OrgOrder::class, 'id' => $order->id]], false, ['batch' => 7]);
        $poll->withFakeQueueInteractions();
        $poll->job->attempts = $attempt;

        return $poll;
    }

    protected function submittedRow(OrgOrder $order) : MyinvoisDocument
    {
        return MyinvoisDocument::create([
            'parent_type' => OrgOrder::class,
            'parent_id' => $order->id,
            'document_uuid' => 'UID1',
            'submission_uid' => 'SUB1',
            'document_number' => 'INV-0001',
            'status' => 'submitted',
            'is_preprod' => false,
        ]);
    }

    #[Test]
    public function the_poll_backs_off_10_20_40_60_120_300_600_then_leaves_it_to_the_sweep() : void
    {
        $this->accept('Submitted');
        $order = OrgOrder::create();
        $this->submittedRow($order);

        foreach ([10, 20, 40, 60, 120, 300, 600] as $i => $delay) {
            $poll = $this->poll($order, $i + 1);
            $poll->handle();
            $poll->assertReleased($delay);
        }

        $last = $this->poll($order, 8);
        $last->handle();

        $last->assertNotReleased();
        Event::assertNotDispatched(SubmissionSucceeded::class);
        Event::assertNotDispatched(SubmissionFailed::class);
        $this->assertSame('submitted', MyinvoisDocument::first()->status->value);
    }

    #[Test]
    public function a_valid_verdict_fires_succeeded_once() : void
    {
        $this->accept('Valid');
        $order = OrgOrder::create();
        $this->submittedRow($order);

        $poll = $this->poll($order);
        $poll->handle();

        $poll->assertNotReleased();
        $this->assertSame('valid', MyinvoisDocument::first()->status->value);
        Event::assertDispatchedTimes(SubmissionSucceeded::class, 1);
        Event::assertDispatched(SubmissionSucceeded::class, fn ($e) => $e->documentNumbers === ['INV-0001']
            && $e->submissionUid === 'SUB1'
            && $e->context === ['batch' => 7]
            && $e->parents === [['type' => OrgOrder::class, 'id' => $order->id]]);

        // a duplicate delivery of the same job must not announce it again
        $this->poll($order)->handle();

        Event::assertDispatchedTimes(SubmissionSucceeded::class, 1);
    }

    #[Test]
    public function an_invalid_verdict_after_accept_fires_failed_with_lhdns_messages() : void
    {
        $this->accept('Invalid', ['validationResults' => ['status' => 'Invalid', 'validationSteps' => [
            ['status' => 'Invalid', 'error' => ['innerError' => [['error' => 'Buyer TIN does not match']]]],
        ]]]);
        $order = OrgOrder::create();
        $this->submittedRow($order);

        $poll = $this->poll($order);
        $poll->handle();

        $event = $this->failedEvent(SubmissionFailed::INVALID);
        $this->assertSame(['Buyer TIN does not match'], $event->errors);
        $this->assertSame(['INV-0001'], $event->documentNumbers);
        $this->assertFalse($event->ambiguous);
        Event::assertNotDispatched(SubmissionSucceeded::class);
    }

    #[Test]
    public function the_poll_waits_for_retry_after_on_a_429() : void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documentsubmissions*' => Http::response('', 429, ['Retry-After' => '45']),
        ]);
        $order = OrgOrder::create();
        $this->submittedRow($order);

        $poll = $this->poll($order);
        $poll->handle();

        $poll->assertReleased(45);
    }

    #[Test]
    public function the_poll_treats_an_lhdn_outage_as_not_yet() : void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documentsubmissions*' => Http::response('', 503),
        ]);
        $order = OrgOrder::create();
        $this->submittedRow($order);

        $poll = $this->poll($order, 2);
        $poll->handle();

        $poll->assertReleased(20);
        Event::assertNotDispatched(SubmissionFailed::class);
    }

    #[Test]
    public function the_poll_does_not_ask_the_wrong_environment() : void
    {
        Http::fake();
        $order = OrgOrder::create(); // resolves to production
        $this->submittedRow($order);

        $poll = new PollSubmission('SUB1', [['type' => OrgOrder::class, 'id' => $order->id]], true);
        $poll->withFakeQueueInteractions();
        $poll->handle();

        Http::assertNothingSent();
    }
}
