<?php

namespace Jiannius\Myinvois\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Jiannius\Myinvois\Events\SubmissionAccepted;
use Jiannius\Myinvois\Events\SubmissionFailed;
use Jiannius\Myinvois\Jobs\PollSubmission;
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
        $this->setMarker($job, 'claimed:crashed-run');

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

    /**
     * Copy A is slow before the POST (token fetch); the queue serves a second copy B, which
     * proceeds. B's whole run happens inside A's token request.
     */
    protected function fakeWithTakeover(SubmitDocuments $a, SubmitDocuments $b, ?\Closure $afterTakeover = null) : void
    {
        $inside = false;

        Http::fake(function ($request) use (&$inside, $b, $afterTakeover) {
            if (str_contains($request->url(), 'connect/token')) {
                if (! $inside) {
                    $inside = true;
                    $b->handle();
                    if ($afterTakeover) $afterTakeover();
                }

                return Http::response(['access_token' => 'tok', 'expires_in' => 3600]);
            }

            return Http::response([
                'submissionUid' => 'SUB1',
                'acceptedDocuments' => [['uuid' => 'UID1', 'invoiceCodeNumber' => 'INV-0001']],
            ]);
        });
    }

    #[Test]
    public function a_copy_overtaken_before_its_post_never_posts_a_second_time() : void
    {
        Event::fake([SubmissionFailed::class, SubmissionAccepted::class]);
        $order = OrgOrder::create();
        $a = $this->queued($order);
        $a->withFakeQueueInteractions();
        $b = $this->redelivered($a);

        $this->fakeWithTakeover($a, $b);

        $a->handle();

        $this->assertSame(1, $this->posts(), 'only the copy that took over posts');
        $this->assertSame(1, MyinvoisDocument::count());
        Event::assertNotDispatched(SubmissionFailed::class);
        Event::assertDispatchedTimes(SubmissionAccepted::class, 1);
        $this->assertSame('done', $this->marker($a), 'the overtaken copy leaves the marker alone');
        $a->assertReleased(SubmitDocuments::CLAIMED_SECONDS);
    }

    #[Test]
    public function a_copy_overtaken_by_a_run_still_before_its_post_posts_nothing_either() : void
    {
        Event::fake([SubmissionFailed::class, SubmissionAccepted::class]);
        $order = OrgOrder::create();
        $a = $this->queued($order);
        $a->withFakeQueueInteractions();

        // B claims the run (overwriting A's marker) but has not posted yet: A must still stand down
        Http::fake([
            '*/connect/token' => function () use ($a) {
                $this->setMarker($a, 'claimed:someone-else');

                return Http::response(['access_token' => 'tok', 'expires_in' => 3600]);
            },
            '*documentsubmissions*' => Http::response(['submissionUid' => 'SUB1']),
        ]);

        $a->handle();

        $this->assertSame(0, $this->posts());
        $this->assertSame(0, MyinvoisDocument::count());
        Event::assertNotDispatched(SubmissionFailed::class);
        Event::assertNotDispatched(SubmissionAccepted::class);
        $this->assertSame('claimed:someone-else', $this->marker($a), 'not this copy\'s marker: not deleted, not marked done');
        $a->assertReleased(SubmitDocuments::CLAIMED_SECONDS);
    }

    #[Test]
    public function an_overtaken_copy_releases_itself_instead_of_acking_the_job() : void
    {
        Event::fake([SubmissionFailed::class, SubmissionAccepted::class]);
        $order = OrgOrder::create();
        $a = $this->queued($order);
        $a->withFakeQueueInteractions();

        // B claimed the run and has not posted yet
        Http::fake([
            '*/connect/token' => function () use ($a) {
                $this->setMarker($a, 'claimed:B');

                return Http::response(['access_token' => 'tok', 'expires_in' => 3600]);
            },
            '*documentsubmissions*' => Http::response(['submissionUid' => 'SUB1']),
        ]);

        $a->handle();

        // returning normally would delete the job row B shares: it stays queued for a re-check
        $a->assertReleased(SubmitDocuments::CLAIMED_SECONDS);
        $a->assertNotDeleted();
        $a->assertNotFailed();
        $this->assertSame(0, $this->posts());
        Event::assertNotDispatched(SubmissionFailed::class);
        Event::assertNotDispatched(SubmissionAccepted::class);
        $this->assertSame('claimed:B', $this->marker($a), 'B\'s marker is untouched');
        $this->assertFalse($this->lockIsFree($order), 'the lock is B\'s to free');
    }

    #[Test]
    public function the_re_check_after_the_other_copy_died_before_its_post_submits_exactly_once() : void
    {
        Event::fake([SubmissionFailed::class, SubmissionAccepted::class]);
        $order = OrgOrder::create();
        $a = $this->queued($order);
        $a->withFakeQueueInteractions();

        $overtaken = false;

        Http::fake([
            '*/connect/token' => function () use ($a, &$overtaken) {
                if (! $overtaken) $this->setMarker($a, 'claimed:B');
                $overtaken = true;

                return Http::response(['access_token' => 'tok', 'expires_in' => 3600]);
            },
            '*documentsubmissions*' => Http::response([
                'submissionUid' => 'SUB1',
                'acceptedDocuments' => [['uuid' => 'UID1', 'invoiceCodeNumber' => 'INV-0001']],
            ]),
        ]);

        $a->handle();
        $a->assertReleased(SubmitDocuments::CLAIMED_SECONDS);
        $this->assertSame(0, $this->posts());

        // B died before the POST; its claimed marker lapsed. The released job comes back.
        Cache::forget('myinvois:submit-run:'.$a->lockOwner);

        $this->redelivered($a)->handle();

        $this->assertSame(1, $this->posts());
        $this->assertSame(1, MyinvoisDocument::count());
        Event::assertNotDispatched(SubmissionFailed::class);
        Event::assertDispatchedTimes(SubmissionAccepted::class, 1);
        $this->assertSame('done', $this->marker($a));
    }

    #[Test]
    public function the_re_check_after_the_other_copy_finished_stays_silent_and_acks() : void
    {
        Event::fake([SubmissionFailed::class, SubmissionAccepted::class]);
        $order = OrgOrder::create();
        $a = $this->queued($order);
        $a->withFakeQueueInteractions();
        $b = $this->redelivered($a);

        $this->fakeWithTakeover($a, $b);

        $a->handle();
        $a->assertReleased(SubmitDocuments::CLAIMED_SECONDS);
        $this->assertSame(1, $this->posts());

        $again = $this->redelivered($a);
        $again->handle();

        $this->assertSame(1, $this->posts(), 'no second POST');
        $this->assertSame(1, MyinvoisDocument::count());
        Event::assertNotDispatched(SubmissionFailed::class);
        Event::assertDispatchedTimes(SubmissionAccepted::class, 1);
        $again->assertNotReleased();
        $this->assertSame('done', $this->marker($a));
    }

    #[Test]
    public function the_re_check_after_the_other_copy_died_mid_post_reports_ambiguous_once() : void
    {
        Event::fake([SubmissionFailed::class, SubmissionAccepted::class]);
        $a = $this->queued(OrgOrder::create());
        $a->withFakeQueueInteractions();

        Http::fake([
            '*/connect/token' => function () use ($a) {
                $this->setMarker($a, 'claimed:B');

                return Http::response(['access_token' => 'tok', 'expires_in' => 3600]);
            },
        ]);

        $a->handle();
        $a->assertReleased(SubmitDocuments::CLAIMED_SECONDS);

        // B reached `posting` and the worker died
        $this->setMarker($a, 'posting');

        $this->redelivered($a)->handle();

        $this->assertSame(0, $this->posts());
        Event::assertDispatchedTimes(SubmissionFailed::class, 1);
        Event::assertDispatched(SubmissionFailed::class, fn ($e) => $e->ambiguous);
        $this->assertSame('done', $this->marker($a));
    }

    #[Test]
    public function a_retry_or_escape_never_deletes_a_marker_another_run_owns() : void
    {
        Event::fake([SubmissionFailed::class]);
        $a = $this->queued(OrgOrder::create());
        $a->withFakeQueueInteractions();

        // the token request fails (a retry), and meanwhile another copy took the marker
        Http::fake(['*/connect/token' => function () use ($a) {
            $this->setMarker($a, 'claimed:someone-else');

            return Http::response('', 500);
        }]);

        $a->handle();

        $this->assertSame('claimed:someone-else', $this->marker($a));
        $this->assertSame(0, $this->posts());

        // and the same on the escape path
        Cache::forget('myinvois:submit-run:'.$a->lockOwner);
        $boom = new RetryBoomSubmitDocuments($a->documents, $a->parents, $a->preprod, $a->lockOwner, $a->context);
        $boom->withFakeQueueInteractions();
        RetryBoomSubmitDocuments::$boom = true;

        try {
            $boom->handle();
            $this->fail('the exception should escape handle()');
        }
        catch (\RuntimeException) {
        }

        $this->assertSame('claimed:someone-else', $this->marker($a));
    }

    #[Test]
    public function the_marker_says_accepted_with_the_submission_uid_before_the_app_is_told() : void
    {
        Event::fake([SubmissionFailed::class]);
        $this->accept();
        $job = $this->queued(OrgOrder::create());
        $job->withFakeQueueInteractions();
        $seen = null;

        Event::listen(SubmissionAccepted::class, function () use (&$seen, $job) {
            $seen = $this->marker($job);
        });

        $job->handle();

        $this->assertSame('accepted:SUB1', $seen);
        $this->assertSame('done', $this->marker($job));
    }

    #[Test]
    public function a_redelivery_after_a_crash_between_the_rows_and_the_announcement_finishes_the_job() : void
    {
        $this->accept();
        $order = OrgOrder::create();
        $job = $this->queued($order);
        $job->withFakeQueueInteractions();
        Event::fake([SubmissionFailed::class, SubmissionAccepted::class]);
        $job->handle();

        // as if the worker died right after the rows were written and the marker said accepted
        Queue::fake();
        Event::fake([SubmissionFailed::class, SubmissionAccepted::class]);
        $this->setMarker($job, 'accepted:SUB1');

        $this->redelivered($job)->handle();

        $this->assertSame(1, $this->posts(), 'no second POST');
        $this->assertSame(1, MyinvoisDocument::count());
        Queue::assertPushed(PollSubmission::class, fn ($poll) => $poll->submissionUid === 'SUB1');
        Event::assertNotDispatched(SubmissionFailed::class);
        Event::assertDispatchedTimes(SubmissionAccepted::class, 1);
        Event::assertDispatched(SubmissionAccepted::class, fn ($e) => $e->submissionUid === 'SUB1' && $e->documentNumbers === ['INV-0001']);
        $this->assertSame('done', $this->marker($job));

        // told once
        $this->redelivered($job)->handle();
        Event::assertDispatchedTimes(SubmissionAccepted::class, 1);
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
