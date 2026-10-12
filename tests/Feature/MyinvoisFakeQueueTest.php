<?php

namespace Jiannius\Myinvois\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Jiannius\Myinvois\Events\SubmissionAccepted;
use Jiannius\Myinvois\Events\SubmissionFailed;
use Jiannius\Myinvois\Events\SubmissionSucceeded;
use Jiannius\Myinvois\Exceptions\MyinvoisInFlightException;
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

/**
 * Apps test their queued MyInvois flow against Myinvois::fake(): no HTTP, no signing, no sleeping.
 */
class MyinvoisFakeQueueTest extends TestCase
{
    protected function setUp() : void
    {
        parent::setUp();

        Event::fake([SubmissionAccepted::class, SubmissionSucceeded::class, SubmissionFailed::class]);
    }

    /** Queue one invoice, run the submit job, and return the poll job it dispatched. */
    protected function runSubmitJob(OrgOrder $order) : PollSubmission
    {
        Queue::fake();

        Myinvois::queueSubmission([DocumentFixture::invoice()], ['INV-0001' => $order], ['batch' => 1]);

        Queue::pushed(SubmitDocuments::class)->first()->withFakeQueueInteractions()->handle();

        $poll = Queue::pushed(PollSubmission::class)->first();
        $poll->withFakeQueueInteractions();

        return $poll;
    }

    #[Test]
    public function the_fake_marks_the_post_as_started_before_it_runs_the_before_post_hook() : void
    {
        $fake = Myinvois::fake();
        $order = Order::create();
        $seen = null;

        $fake->forParents(['INV-0001' => $order])
            ->beforePost(function () use (&$seen, $fake) {
                $seen = $fake->postStarted();
            })
            ->submitDocuments([DocumentFixture::invoice()]);

        $this->assertTrue($seen, 'like the real client, postStarted is true by the time the hook runs');
        $this->assertTrue($fake->postStarted());
    }

    #[Test]
    public function for_parents_stamps_the_rows_and_never_sleeps_or_calls_lhdn() : void
    {
        Http::fake();
        $fake = Myinvois::fake();
        $order = Order::create();

        $started = microtime(true);
        $fake->forParents(['INV-0001' => $order])->submitDocuments([DocumentFixture::invoice()]);

        $this->assertLessThan(1, microtime(true) - $started);
        Http::assertNothingSent();
        $this->assertSame($order->id, MyinvoisDocument::first()->parent_id);
        $fake->assertDocumentSubmitted('INV-0001');
    }

    #[Test]
    public function for_parents_applies_the_lock_and_the_submitted_or_valid_refusal() : void
    {
        $order = Order::create();
        SubmissionLock::acquire([$order], true);

        try {
            Myinvois::fake()->forParents(['INV-0001' => $order])->submitDocuments([DocumentFixture::invoice()]);
            $this->fail('Expected MyinvoisInFlightException.');
        } catch (MyinvoisInFlightException) {
        }

        // submitted by the fake: a second go is refused, and the lock is free again
        $other = Order::create();
        $fake = Myinvois::fake();
        $fake->forParents(['INV-0001' => $other])->submitDocuments([DocumentFixture::invoice()]);

        $this->expectException(MyinvoisInFlightException::class);
        Myinvois::fake()->forParents(['INV-0001' => $other])->submitDocuments([DocumentFixture::invoice()]);
    }

    #[Test]
    public function the_default_fake_resolves_to_valid_so_the_first_poll_announces_success() : void
    {
        Myinvois::fake();
        $order = OrgOrder::create();

        $poll = $this->runSubmitJob($order);

        Event::assertDispatched(SubmissionAccepted::class);
        $this->assertSame('valid', MyinvoisDocument::first()->status->value);

        $poll->handle();

        $poll->assertNotReleased();
        Event::assertDispatched(SubmissionSucceeded::class, fn ($e) => $e->context === ['batch' => 1]);
    }

    #[Test]
    public function poll_resolves_to_leaves_the_rows_submitted_until_the_poll_runs() : void
    {
        Myinvois::fake()->pollResolvesTo('valid');
        $order = OrgOrder::create();

        $poll = $this->runSubmitJob($order);

        $this->assertSame('submitted', MyinvoisDocument::first()->status->value);
        $this->assertSame('submitted', $order->fresh()->myinvois_status->value);
        Event::assertNotDispatched(SubmissionSucceeded::class);

        $poll->handle();

        $this->assertSame('valid', MyinvoisDocument::first()->status->value);
        $this->assertSame('valid', $order->fresh()->myinvois_status->value);
        Event::assertDispatchedTimes(SubmissionSucceeded::class, 1);
        Event::assertNotDispatched(SubmissionFailed::class);
    }

    #[Test]
    public function poll_resolves_to_invalid_fires_failed_with_the_messages() : void
    {
        Myinvois::fake()->pollResolvesTo('invalid', ['Buyer TIN does not match']);

        $poll = $this->runSubmitJob(OrgOrder::create());
        $poll->handle();

        Event::assertDispatched(SubmissionFailed::class, fn ($e) => $e->reason === SubmissionFailed::INVALID
            && $e->errors === ['Buyer TIN does not match']
            && $e->documentNumbers === ['INV-0001']);
        Event::assertNotDispatched(SubmissionSucceeded::class);
    }

    #[Test]
    public function resolve_status_invalid_covers_the_failure_path_with_a_default_message() : void
    {
        Myinvois::fake()->resolveStatus('invalid');

        $poll = $this->runSubmitJob(OrgOrder::create());
        $poll->handle();

        Event::assertDispatched(SubmissionFailed::class, fn ($e) => $e->reason === SubmissionFailed::INVALID
            && $e->errors === ['Fake validation error']);
    }

    #[Test]
    public function a_canned_get_submission_body_still_wins() : void
    {
        $fake = Myinvois::fake()->pollResolvesTo('valid');
        $fake->respondToApi('documentsubmissions/*', ['documentSummary' => []]);
        $order = OrgOrder::create();

        $poll = $this->runSubmitJob($order);
        $poll->handle();

        // the stub returned nothing to resolve, so the row is still waiting
        $this->assertSame('submitted', MyinvoisDocument::first()->status->value);
        $poll->assertReleased(10);
    }
}
