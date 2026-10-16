<?php

namespace Jiannius\Myinvois\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Jiannius\Myinvois\Events\DocumentStatusChanged;
use Jiannius\Myinvois\Events\SubmissionAccepted;
use Jiannius\Myinvois\Events\SubmissionFailed;
use Jiannius\Myinvois\Jobs\PollSubmission;
use Jiannius\Myinvois\Jobs\SubmitDocuments;
use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Myinvois;
use Jiannius\Myinvois\Support\ParentReference;
use Jiannius\Myinvois\Support\SubmissionLock;
use Jiannius\Myinvois\Tests\Fixtures\CertFixture;
use Jiannius\Myinvois\Tests\Fixtures\DocumentFixture;
use Jiannius\Myinvois\Tests\Fixtures\Order;
use Jiannius\Myinvois\Tests\Fixtures\OrgOrder;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/** A parent from a different org: another client id and on-behalf-of. */
class OtherOrgOrder extends OrgOrder
{
    public function configureMyinvoisClient(Myinvois $client) : Myinvois
    {
        return $client
            ->setClientId('other-client')
            ->setClientSecret('other-secret')
            ->setOnBehalfOf('C-OTHER-TIN')
            ->setPreprod(false)
            ->setPrivateKey(CertFixture::privateKey())
            ->setCertificate(CertFixture::certificate());
    }
}

/** A parent whose client cannot be resolved (e.g. its org was deleted). */
class BrokenOrgOrder extends OrgOrder
{
    public function configureMyinvoisClient(Myinvois $client) : Myinvois
    {
        throw new \RuntimeException('org is gone');
    }
}

/**
 * Fixes from the PR #10 review: job retry hygiene, listener isolation, lock release on a
 * pre-POST failure, one client per submission, sweep order, redelivery, afterCommit.
 */
class QueueHardeningTest extends TestCase
{
    protected function accept(array $numbers = ['INV-0001']) : void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documentsubmissions*' => Http::response([
                'submissionUid' => 'SUB1',
                'acceptedDocuments' => collect($numbers)->map(fn ($n, $i) => ['uuid' => 'UID'.($i + 1), 'invoiceCodeNumber' => $n])->all(),
            ]),
        ]);
    }

    protected function queued(array $documents, array $parents) : SubmitDocuments
    {
        Queue::fake();

        Myinvois::queueSubmission($documents, $parents);

        $job = Queue::pushed(SubmitDocuments::class)->first();
        $job->withFakeQueueInteractions();

        return $job;
    }

    protected function lockIsFree(Order $order) : bool
    {
        $lock = Cache::lock(SubmissionLock::key($order, false), 5);

        if (! $lock->get()) return false;

        $lock->release();

        return true;
    }

    protected function posts() : int
    {
        return Http::recorded(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'documentsubmissions'))->count();
    }

    // ---- 1. no hot loop on an uncaught exception ------------------------

    #[Test]
    public function both_jobs_cap_exceptions_and_back_off_between_attempts() : void
    {
        foreach ([
            new SubmitDocuments([], [], false, 'owner'),
            new PollSubmission('SUB1', [], false),
        ] as $job) {
            $this->assertSame(3, $job->maxExceptions, $job::class);
            $this->assertSame([30, 60, 120], $job->backoff(), $job::class);
        }
    }

    #[Test]
    public function a_client_that_cannot_be_resolved_is_announced_and_frees_the_lock_not_thrown() : void
    {
        Event::fake([SubmissionFailed::class]);
        Http::fake();
        $order = BrokenOrgOrder::create();
        // queueSubmission() resolves the client too, so build the job by hand
        $lockOwner = SubmissionLock::acquire([$order], false);
        $job = new SubmitDocuments(
            [DocumentFixture::invoice()],
            ['INV-0001' => ParentReference::make($order)],
            false,
            $lockOwner,
        );
        $job->withFakeQueueInteractions();

        $job->handle();

        Event::assertDispatched(SubmissionFailed::class, fn ($e) => $e->reason === SubmissionFailed::ERROR && ! $e->ambiguous);
        $this->assertTrue($this->lockIsFree($order), 'nothing was sent, so the lock is freed');
        $this->assertSame(0, $this->posts());
    }

    #[Test]
    public function the_poll_job_does_not_throw_when_its_client_cannot_be_resolved() : void
    {
        Http::fake();
        $order = BrokenOrgOrder::create();
        $job = new PollSubmission('SUB1', [ParentReference::make($order)], false);
        $job->withFakeQueueInteractions();

        $job->handle();

        $job->assertNotReleased();
        Http::assertNothingSent();
    }

    // ---- 2. listener exceptions never break the pipeline ----------------

    #[Test]
    public function a_throwing_accepted_listener_still_dispatches_the_poll_and_a_rerun_is_silent() : void
    {
        $this->accept();
        $failures = [];
        Event::listen(SubmissionFailed::class, function ($event) use (&$failures) { $failures[] = $event->reason; });
        Event::listen(SubmissionAccepted::class, fn () => throw new \RuntimeException('listener boom'));
        $order = OrgOrder::create();
        $job = $this->queued([DocumentFixture::invoice()], ['INV-0001' => $order]);

        $job->handle();

        $this->assertSame(1, MyinvoisDocument::count());
        $this->assertSame(1, Queue::pushed(PollSubmission::class)->count(), 'the poll is dispatched despite the listener');

        // the queue delivers the same job again: it must not fail an accepted submission
        $job->handle();

        $this->assertSame([], $failures);
        $this->assertSame(1, $this->posts());
        $this->assertSame(1, Queue::pushed(PollSubmission::class)->count());
    }

    #[Test]
    public function a_throwing_status_listener_cannot_stop_accepted_documents_being_saved() : void
    {
        $this->accept(['INV-0001', 'INV-0002']);
        $seen = [];
        Event::listen(DocumentStatusChanged::class, function ($event) use (&$seen) {
            $seen[] = MyinvoisDocument::count();

            throw new \RuntimeException('listener boom');
        });
        $a = OrgOrder::create();
        $b = OrgOrder::create();

        $result = Myinvois::clientFor($a)
            ->forParents(['INV-0001' => $a, 'INV-0002' => $b])
            ->withoutPolling()
            ->submitDocuments([DocumentFixture::invoice(), DocumentFixture::invoice(['number' => 'INV-0002'])]);

        $this->assertSame(2, MyinvoisDocument::count(), 'every accepted document gets its row');
        $this->assertCount(2, $result['myinvois_documents']);
        $this->assertSame([2, 2], $seen, 'events fire after all rows are persisted');
        $this->assertTrue($this->lockIsFree($a));
        $this->assertTrue($this->lockIsFree($b));
    }

    // ---- 3. a pre-POST failure frees the lock ---------------------------

    #[Test]
    public function a_missing_key_or_certificate_on_the_queue_path_frees_the_lock() : void
    {
        Event::fake([SubmissionFailed::class]);
        Http::fake();
        config()->set('services.myinvois.client_id', 'x');
        config()->set('services.myinvois.client_secret', 'y');
        config()->set('services.myinvois.preprod', false);
        // a plain Order: config cascade, and no key / certificate configured
        $order = Order::create();
        $job = $this->queued([DocumentFixture::invoice()], ['INV-0001' => $order]);
        $this->assertFalse($this->lockIsFree($order), 'held from dispatch');

        $job->handle();

        Event::assertDispatched(SubmissionFailed::class, fn ($e) => $e->reason === SubmissionFailed::ERROR && ! $e->ambiguous);
        $this->assertTrue($this->lockIsFree($order), 'freed: nothing was sent');
    }

    // ---- 4. one client per submission -----------------------------------

    #[Test]
    public function queue_submission_refuses_parents_of_different_clients() : void
    {
        Queue::fake();
        $a = OrgOrder::create();
        $b = OtherOrgOrder::create();

        try {
            Myinvois::queueSubmission(
                [DocumentFixture::invoice(), DocumentFixture::invoice(['number' => 'INV-0002'])],
                ['INV-0001' => $a, 'INV-0002' => $b],
            );
            $this->fail('expected InvalidArgumentException');
        }
        catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('same client', $e->getMessage());
        }

        Queue::assertNothingPushed();
        $this->assertTrue($this->lockIsFree($a));
        $this->assertTrue($this->lockIsFree($b));
    }

    #[Test]
    public function parents_of_the_same_client_are_accepted() : void
    {
        Queue::fake();

        Myinvois::queueSubmission(
            [DocumentFixture::invoice(), DocumentFixture::invoice(['number' => 'INV-0002'])],
            ['INV-0001' => OrgOrder::create(), 'INV-0002' => OrgOrder::create()],
        );

        Queue::assertPushed(SubmitDocuments::class);
    }

    #[Test]
    public function for_parents_refuses_parents_of_different_clients() : void
    {
        $this->expectException(\InvalidArgumentException::class);

        $a = OrgOrder::create();

        Myinvois::clientFor($a)->forParents(['INV-0001' => $a, 'INV-0002' => OtherOrgOrder::create()]);
    }

    #[Test]
    public function the_worker_refuses_a_batch_whose_parents_now_resolve_to_different_clients() : void
    {
        Event::fake([SubmissionFailed::class]);
        $this->accept(['INV-0001', 'INV-0002']);
        $a = OrgOrder::create();
        $b = OtherOrgOrder::create();
        // as if an org was reassigned between dispatch and run
        $owner = SubmissionLock::acquire([$a, $b], false);
        $job = new SubmitDocuments(
            [DocumentFixture::invoice(), DocumentFixture::invoice(['number' => 'INV-0002'])],
            ['INV-0001' => ParentReference::make($a), 'INV-0002' => ParentReference::make($b)],
            false,
            $owner,
        );
        $job->withFakeQueueInteractions();

        $job->handle();

        Event::assertDispatched(SubmissionFailed::class, fn ($e) => $e->reason === SubmissionFailed::ERROR && ! $e->ambiguous);
        $this->assertSame(0, $this->posts());
        $this->assertSame(0, MyinvoisDocument::count());
        $this->assertTrue($this->lockIsFree($a));
        $this->assertTrue($this->lockIsFree($b));
    }

    // ---- 6. a redelivered copy of the job does not post twice -----------

    #[Test]
    public function a_redelivered_copy_of_the_job_returns_early_without_a_failure() : void
    {
        Event::fake([SubmissionFailed::class, SubmissionAccepted::class]);
        $this->accept();
        $job = $this->queued([DocumentFixture::invoice()], ['INV-0001' => OrgOrder::create()]);

        $job->handle();
        // same payload, same lock owner token: what a queue whose retry_after is shorter than
        // the job re-serves while the first copy is still running
        $copy = unserialize(serialize($job));
        $copy->withFakeQueueInteractions();
        $copy->handle();

        $this->assertSame(1, $this->posts());
        $this->assertSame(1, MyinvoisDocument::count());
        Event::assertNotDispatched(SubmissionFailed::class);
        Event::assertDispatchedTimes(SubmissionAccepted::class, 1);
    }

    #[Test]
    public function a_job_released_for_a_retry_may_run_again() : void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documentsubmissions*' => Http::sequence()
                ->push('', 429, ['Retry-After' => '7'])
                ->push(['submissionUid' => 'SUB1', 'acceptedDocuments' => [['uuid' => 'U1', 'invoiceCodeNumber' => 'INV-0001']]]),
        ]);
        $job = $this->queued([DocumentFixture::invoice()], ['INV-0001' => OrgOrder::create()]);

        $job->handle();
        $job->assertReleased(7);

        $job->withFakeQueueInteractions();
        $job->handle();

        $this->assertSame(1, MyinvoisDocument::count());
    }

    // ---- 7. after commit -------------------------------------------------

    #[Test]
    public function both_jobs_wait_for_the_open_transaction_to_commit() : void
    {
        $this->assertTrue((new SubmitDocuments([], [], false, 'owner'))->afterCommit);
        $this->assertTrue((new PollSubmission('SUB1', [], false))->afterCommit);
    }
}
