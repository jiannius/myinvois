<?php

namespace Jiannius\Myinvois\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Jiannius\Myinvois\Events\SubmissionAccepted;
use Jiannius\Myinvois\Events\SubmissionFailed;
use Jiannius\Myinvois\Jobs\SubmitDocuments;
use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Myinvois;
use Jiannius\Myinvois\Support\SubmissionLock;
use Jiannius\Myinvois\Tests\Fixtures\DocumentFixture;
use Jiannius\Myinvois\Tests\Fixtures\Order;
use Jiannius\Myinvois\Tests\Fixtures\OrgOrder;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/** A job whose retry bookkeeping blows up, to get an exception out of handle(). */
class RetryBoomSubmitDocuments extends SubmitDocuments
{
    public static bool $boom = true;

    protected function countRetry() : int
    {
        if (static::$boom) throw new \RuntimeException('cache is down');

        return parent::countRetry();
    }
}

/**
 * The run marker is a state (claimed / posting / done), not a flag: a redelivery after a
 * worker died must never be silent when LHDN may have the documents, and must still submit
 * when nothing was sent.
 */
class QueueRunMarkerTest extends TestCase
{
    protected function accept() : void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documentsubmissions*' => Http::response([
                'submissionUid' => 'SUB1',
                'acceptedDocuments' => [['uuid' => 'UID1', 'invoiceCodeNumber' => 'INV-0001']],
            ]),
        ]);
    }

    protected function queued(Order $order) : SubmitDocuments
    {
        Queue::fake();

        Myinvois::queueSubmission([DocumentFixture::invoice()], ['INV-0001' => $order]);

        return Queue::pushed(SubmitDocuments::class)->first();
    }

    /** What a queue re-serving the same payload hands to a second worker. */
    protected function redelivered(SubmitDocuments $job) : SubmitDocuments
    {
        $copy = unserialize(serialize($job));
        $copy->withFakeQueueInteractions();

        return $copy;
    }

    protected function marker(SubmitDocuments $job) : mixed
    {
        return Cache::get('myinvois:submit-run:'.$job->lockOwner);
    }

    protected function setMarker(SubmitDocuments $job, string $state) : void
    {
        Cache::put('myinvois:submit-run:'.$job->lockOwner, $state, 300);
    }

    protected function posts() : int
    {
        return Http::recorded(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'documentsubmissions'))->count();
    }

    protected function lockIsFree(Order $order) : bool
    {
        $lock = Cache::lock(SubmissionLock::key($order, false), 5);

        if (! $lock->get()) return false;

        $lock->release();

        return true;
    }

    #[Test]
    public function the_marker_flips_to_posting_just_before_the_post_goes_out() : void
    {
        $seen = [];
        $job = $this->queued(OrgOrder::create());
        $job->withFakeQueueInteractions();

        Http::fake(function ($request) use (&$seen, $job) {
            if (str_contains($request->url(), 'connect/token')) {
                return Http::response(['access_token' => 'tok', 'expires_in' => 3600]);
            }

            $seen[] = $this->marker($job);

            return Http::response(['submissionUid' => 'SUB1', 'acceptedDocuments' => [['uuid' => 'UID1', 'invoiceCodeNumber' => 'INV-0001']]]);
        });

        $job->handle();

        $this->assertSame(['posting'], $seen);
        $this->assertSame('done', $this->marker($job));
    }

    #[Test]
    public function a_redelivery_after_a_crash_before_the_post_still_submits() : void
    {
        Event::fake([SubmissionFailed::class, SubmissionAccepted::class]);
        $this->accept();
        $order = OrgOrder::create();
        $job = $this->queued($order);

        // worker 1 claimed the run and was killed before it posted
        $this->setMarker($job, 'claimed');

        $this->redelivered($job)->handle();

        $this->assertSame(1, $this->posts());
        $this->assertSame(1, MyinvoisDocument::count());
        Event::assertNotDispatched(SubmissionFailed::class);
        Event::assertDispatchedTimes(SubmissionAccepted::class, 1);
    }

    #[Test]
    public function a_redelivery_after_a_crash_while_posting_reports_ambiguous_and_does_not_post() : void
    {
        Event::fake([SubmissionFailed::class, SubmissionAccepted::class]);
        $this->accept();
        $order = OrgOrder::create();
        $job = $this->queued($order);

        // worker 1 got as far as sending the POST and was killed
        $this->setMarker($job, 'posting');

        $copy = $this->redelivered($job);
        $copy->handle();

        $this->assertSame(0, $this->posts());
        $this->assertSame(0, MyinvoisDocument::count());
        Event::assertDispatchedTimes(SubmissionFailed::class, 1);
        Event::assertDispatched(SubmissionFailed::class, fn ($e) => $e->reason === SubmissionFailed::AMBIGUOUS && $e->ambiguous === true);
        Event::assertNotDispatched(SubmissionAccepted::class);
        $this->assertFalse($this->lockIsFree($order), 'the lock is kept until its TTL');
        $copy->assertNotReleased();

        // told once: a further redelivery is quiet
        $this->redelivered($job)->handle();
        Event::assertDispatchedTimes(SubmissionFailed::class, 1);
    }

    #[Test]
    public function a_redelivery_after_a_completed_run_stays_silent() : void
    {
        Event::fake([SubmissionFailed::class, SubmissionAccepted::class]);
        $this->accept();
        $job = $this->queued(OrgOrder::create());
        $job->withFakeQueueInteractions();

        $job->handle();
        $this->redelivered($job)->handle();

        $this->assertSame(1, $this->posts());
        $this->assertSame(1, MyinvoisDocument::count());
        Event::assertNotDispatched(SubmissionFailed::class);
        Event::assertDispatchedTimes(SubmissionAccepted::class, 1);
    }

    #[Test]
    public function a_run_that_ended_in_a_definite_failure_is_done_too() : void
    {
        Event::fake([SubmissionFailed::class]);
        $this->accept();
        $order = OrgOrder::create();
        $job = $this->queued($order);
        $job->withFakeQueueInteractions();
        $order->delete();

        $job->handle();
        $this->redelivered($job)->handle();

        Event::assertDispatchedTimes(SubmissionFailed::class, 1);
        $this->assertSame(0, $this->posts());
    }

    #[Test]
    public function an_exception_escaping_handle_leaves_the_retry_free_to_run() : void
    {
        Event::fake([SubmissionFailed::class, SubmissionAccepted::class]);
        Http::fake([
            '*/connect/token' => Http::sequence()
                ->push('', 500)
                ->push(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documentsubmissions*' => Http::response([
                'submissionUid' => 'SUB1',
                'acceptedDocuments' => [['uuid' => 'UID1', 'invoiceCodeNumber' => 'INV-0001']],
            ]),
        ]);
        $job = $this->queued(OrgOrder::create());
        $boom = new RetryBoomSubmitDocuments($job->documents, $job->parents, $job->preprod, $job->lockOwner, $job->context);
        $boom->withFakeQueueInteractions();

        try {
            $boom->handle();
            $this->fail('the exception should escape handle()');
        }
        catch (\RuntimeException $e) {
            $this->assertSame('cache is down', $e->getMessage());
        }

        $this->assertNull($this->marker($job), 'nothing was sent, so the marker is dropped');

        // the queue's backoff retry
        RetryBoomSubmitDocuments::$boom = false;
        $retry = $this->redelivered($boom);
        $retry->handle();

        $this->assertSame(1, $this->posts());
        $this->assertSame(1, MyinvoisDocument::count());
        Event::assertNotDispatched(SubmissionFailed::class);

        RetryBoomSubmitDocuments::$boom = true;
    }

    #[Test]
    public function a_failed_transaction_releases_the_locks_queue_submission_took() : void
    {
        Queue::fake();
        $order = OrgOrder::create();

        try {
            DB::transaction(function () use ($order) {
                Myinvois::queueSubmission([DocumentFixture::invoice()], ['INV-0001' => $order]);

                $this->assertFalse($this->lockIsFree($order), 'held while the transaction is open');

                throw new \RuntimeException('rolled back');
            });
        }
        catch (\RuntimeException) {
        }

        $this->assertTrue($this->lockIsFree($order), 'the afterCommit job is discarded, so the lock must go');

        // and the caller can queue it again
        Myinvois::queueSubmission([DocumentFixture::invoice()], ['INV-0001' => $order]);
        Queue::assertPushed(SubmitDocuments::class);
    }

    #[Test]
    public function a_committed_transaction_keeps_the_lock_for_the_job() : void
    {
        Queue::fake();
        $order = OrgOrder::create();

        DB::transaction(fn () => Myinvois::queueSubmission([DocumentFixture::invoice()], ['INV-0001' => $order]));

        $this->assertFalse($this->lockIsFree($order));
    }
}
