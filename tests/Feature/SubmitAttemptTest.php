<?php

namespace Jiannius\Myinvois\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Jiannius\Myinvois\Events\SubmissionAccepted;
use Jiannius\Myinvois\Events\SubmissionFailed;
use Jiannius\Myinvois\Exceptions\MyinvoisAuthenticationException;
use Jiannius\Myinvois\Exceptions\MyinvoisException;
use Jiannius\Myinvois\Exceptions\MyinvoisInFlightException;
use Jiannius\Myinvois\Exceptions\MyinvoisPendingAttemptException;
use Jiannius\Myinvois\Exceptions\MyinvoisPermissionException;
use Jiannius\Myinvois\Exceptions\MyinvoisUnavailableException;
use Jiannius\Myinvois\Jobs\PollSubmission;
use Jiannius\Myinvois\Jobs\SubmitDocuments;
use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Models\MyinvoisSubmitAttempt;
use Jiannius\Myinvois\Myinvois;
use Jiannius\Myinvois\Tests\Fixtures\CertFixture;
use Jiannius\Myinvois\Tests\Fixtures\DocumentFixture;
use Jiannius\Myinvois\Tests\Fixtures\Order;
use Jiannius\Myinvois\Tests\Fixtures\OrgOrder;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * jiannius/myinvois#13: a pending attempt is recorded before the POST, closed on a definite
 * answer, and looked up (locally, then on LHDN's documents/recent) before anything is resent.
 */
class SubmitAttemptTest extends TestCase
{
    /** @var mixed what the token endpoint answers */
    protected $token;

    /** @var mixed what the documentsubmissions POST answers (a response or a closure) */
    protected $post;

    /** @var mixed what documents/recent answers */
    protected $recent;

    /** @var mixed what the submission poll answers */
    protected $poll;

    /** Requests seen by the fake, counted before it answers (a thrown timeout is not recorded by Http). */
    protected int $postCount = 0;

    protected int $recentCount = 0;

    protected function setUp() : void
    {
        parent::setUp();

        config(['services.myinvois.preprod' => false]);
        Sleep::fake();

        $this->travelTo(Carbon::parse('2026-11-10 10:00:00'));

        $this->token = fn () => Http::response(['access_token' => 'tok', 'expires_in' => 3600]);
        $this->post = fn () => $this->accepted();
        $this->recent = fn () => $this->recentPage([]);
        $this->poll = fn () => Http::response(['documentSummary' => []]);

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/connect/token')) return value($this->token, $request);
            if (str_contains($url, 'documents/recent')) {
                $this->recentCount++;

                return value($this->recent, $request);
            }

            if ($request->method() === 'POST' && str_contains($url, 'documentsubmissions')) {
                $this->postCount++;

                return value($this->post, $request);
            }

            if (str_contains($url, 'documentsubmissions/')) return value($this->poll, $request);

            return Http::response([], 404);
        });
    }

    // ---- helpers -------------------------------------------------------

    protected function myinvois() : Myinvois
    {
        return (new Myinvois)
            ->setClientId('id')
            ->setClientSecret('secret')
            ->setPreprod(false)
            ->setPrivateKey(CertFixture::privateKey())
            ->setCertificate(CertFixture::certificate());
    }

    protected function accepted(string $uuid = 'UID1', string $number = 'INV-0001', string $uid = 'SUB1')
    {
        return Http::response([
            'submissionUid' => $uid,
            'acceptedDocuments' => [['uuid' => $uuid, 'invoiceCodeNumber' => $number]],
            'rejectedDocuments' => [],
        ], 202);
    }

    protected function entry(array $overrides = []) : array
    {
        return [
            'uuid' => 'UIDX',
            'submissionUID' => 'SUBX',
            'longId' => 'LONGX',
            'internalId' => 'INV-0001',
            'typeName' => 'Invoice',
            'dateTimeIssued' => '2026-01-15T09:30:00Z',
            'dateTimeReceived' => '2026-11-10T10:00:05Z',
            'dateTimeValidated' => '2026-11-10T10:00:09Z',
            'status' => 'Valid',
            ...$overrides,
        ];
    }

    protected function recentPage(array $entries, int $totalPages = 1)
    {
        return Http::response(['result' => $entries, 'metadata' => ['totalPages' => $totalPages, 'totalCount' => count($entries)]]);
    }

    protected function posts() : int
    {
        return $this->postCount;
    }

    protected function recentCalls() : int
    {
        return $this->recentCount;
    }

    /** The IssueDate + IssueTime of the last document POSTed. */
    protected function postedIssuedAt() : string
    {
        $request = Http::recorded(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'documentsubmissions'))->last()[0];
        $ubl = json_decode(base64_decode($request['documents'][0]['document']), true);

        return data_get($ubl, 'Invoice.0.IssueDate.0._').'T'.data_get($ubl, 'Invoice.0.IssueTime.0._');
    }

    /** A first submission that times out on the POST, leaving a pending attempt. */
    protected function timedOut(Order $order, array $document = []) : MyinvoisSubmitAttempt
    {
        $this->post = fn () => throw new ConnectionException('timed out');

        try {
            $this->myinvois()->forParents(['INV-0001' => $order])->submitDocuments([DocumentFixture::invoice($document)]);
            $this->fail('Expected the timeout to surface.');
        }
        catch (MyinvoisUnavailableException) {
        }

        $this->post = fn () => $this->fail('Nothing may be POSTed.');

        return MyinvoisSubmitAttempt::sole();
    }

    protected function resubmit(Order $order, array $document = []) : MyinvoisException
    {
        try {
            $this->myinvois()->forParents(['INV-0001' => $order])->submitDocuments([DocumentFixture::invoice($document)]);
        }
        catch (MyinvoisException $e) {
            return $e;
        }

        $this->fail('Expected the resubmission to be refused.');
    }

    // ---- recording -----------------------------------------------------

    #[Test]
    public function an_attempt_is_pending_while_the_post_is_sent_and_closed_once_accepted() : void
    {
        $order = Order::create();
        $during = null;

        $this->post = function () use (&$during) {
            $during = MyinvoisSubmitAttempt::query()->where('status', MyinvoisSubmitAttempt::PENDING)->get();

            return $this->accepted();
        };

        $this->myinvois()->forParents(['INV-0001' => $order])->withoutPolling()->submitDocuments([DocumentFixture::invoice()]);

        $this->assertCount(1, $during);
        $this->assertSame($order->id, $during[0]->parent_id);
        $this->assertSame(Order::class, $during[0]->parent_type);
        $this->assertSame('INV-0001', $during[0]->document_number);
        $this->assertSame('01', $during[0]->document_type);
        $this->assertFalse($during[0]->is_consolidate);
        $this->assertFalse($during[0]->is_preprod);
        $this->assertSame('2026-01-15T09:30:00Z', $during[0]->issued_at);

        $attempt = MyinvoisSubmitAttempt::sole();
        $this->assertSame(MyinvoisSubmitAttempt::CLOSED, $attempt->status);
        $this->assertSame(MyinvoisSubmitAttempt::ACCEPTED, $attempt->outcome);
        $this->assertSame('UID1', $attempt->document_uuid);
        $this->assertSame('SUB1', $attempt->submission_uid);
        $this->assertFalse(Myinvois::hasPendingAttempt($order));
    }

    #[Test]
    public function without_parents_nothing_is_recorded() : void
    {
        $this->myinvois()->withoutPolling()->submitDocuments([DocumentFixture::invoice()]);

        $this->assertSame(0, MyinvoisSubmitAttempt::count());
    }

    #[Test]
    public function a_token_failure_records_no_attempt() : void
    {
        $this->token = fn () => Http::response([], 503);
        $order = Order::create();

        try {
            $this->myinvois()->forParents(['INV-0001' => $order])->submitDocuments([DocumentFixture::invoice()]);
            $this->fail('Expected the token failure.');
        }
        catch (MyinvoisUnavailableException) {
        }

        $this->assertSame(0, MyinvoisSubmitAttempt::count());
    }

    #[Test]
    public function a_timeout_leaves_the_attempt_pending() : void
    {
        $order = Order::create();

        $attempt = $this->timedOut($order);

        $this->assertSame(MyinvoisSubmitAttempt::PENDING, $attempt->status);
        $this->assertTrue(Myinvois::hasPendingAttempt($order));
        $this->assertTrue($order->hasPendingMyinvoisAttempt());
        $this->assertFalse(Myinvois::hasPendingAttempt($order, preprod: true), 'environments are separate');
    }

    #[Test]
    public function a_5xx_leaves_the_attempt_pending() : void
    {
        $order = Order::create();
        $this->post = fn () => Http::response([], 502);

        try {
            $this->myinvois()->forParents(['INV-0001' => $order])->submitDocuments([DocumentFixture::invoice()]);
        }
        catch (MyinvoisUnavailableException) {
        }

        $this->assertTrue(Myinvois::hasPendingAttempt($order));
    }

    public static function definiteFailures() : array
    {
        return [
            'rejected documents' => [fn () => Http::response([
                'submissionUid' => 'SUB1',
                'acceptedDocuments' => [],
                'rejectedDocuments' => [['invoiceCodeNumber' => 'INV-0001', 'error' => ['message' => 'bad']]],
            ], 202), null],
            'whole-submission 4xx' => [fn () => Http::response(['error' => ['message' => 'bad request']], 400), null],
            '401 twice' => [fn () => Http::response([], 401), MyinvoisAuthenticationException::class],
            '403' => [fn () => Http::response([], 403), MyinvoisPermissionException::class],
            '429' => [fn () => Http::response([], 429, ['Retry-After' => '5']), MyinvoisUnavailableException::class],
        ];
    }

    #[Test]
    #[DataProvider('definiteFailures')]
    public function a_definite_failure_closes_the_attempt_and_no_lookup_runs_next_time(\Closure $response, ?string $exception) : void
    {
        $order = Order::create();
        $this->post = $response;

        try {
            $this->myinvois()->forParents(['INV-0001' => $order])->withoutPolling()->submitDocuments([DocumentFixture::invoice()]);

            if ($exception) $this->fail("Expected $exception.");
        }
        catch (MyinvoisException $e) {
            $this->assertInstanceOf($exception, $e);
        }

        $attempt = MyinvoisSubmitAttempt::sole();
        $this->assertSame(MyinvoisSubmitAttempt::CLOSED, $attempt->status);
        $this->assertFalse(Myinvois::hasPendingAttempt($order));

        $before = $this->posts();
        $this->post = fn () => $this->accepted();
        $this->myinvois()->forParents(['INV-0001' => $order])->withoutPolling()->submitDocuments([DocumentFixture::invoice()]);

        $this->assertSame(0, $this->recentCalls());
        $this->assertSame($before + 1, $this->posts());
    }

    // ---- lookup --------------------------------------------------------

    #[Test]
    public function a_matching_recent_document_is_adopted_without_a_post() : void
    {
        $order = Order::create();
        $attempt = $this->timedOut($order);
        $this->travel(6)->minutes();
        $this->recent = fn () => $this->recentPage([$this->entry()]);

        $e = $this->resubmit($order);

        $this->assertInstanceOf(MyinvoisPendingAttemptException::class, $e);
        $this->assertInstanceOf(MyinvoisInFlightException::class, $e, 'existing in-flight handling still catches it');
        $this->assertSame(MyinvoisPendingAttemptException::RECOVERED, $e->getReason());
        $this->assertSame(['SUBX'], $e->getSubmissionUids());
        $this->assertSame(1, $this->posts(), 'only the original POST');

        $row = MyinvoisDocument::sole();
        $this->assertSame($order->id, $row->parent_id);
        $this->assertSame('UIDX', $row->document_uuid);
        $this->assertSame('SUBX', $row->submission_uid);
        $this->assertSame('INV-0001', $row->document_number);
        $this->assertSame('valid', $row->status->value);
        $this->assertSame('2026-11-10 10:00:05', $row->created_at->utc()->toDateTimeString(), 'LHDN received time, not now');
        $this->assertSame('LONGX', data_get($row->response, 'longId'));

        $attempt->refresh();
        $this->assertSame(MyinvoisSubmitAttempt::CLOSED, $attempt->status);
        $this->assertSame(MyinvoisSubmitAttempt::ADOPTED, $attempt->outcome);

        $query = Http::recorded(fn ($request) => str_contains($request->url(), 'documents/recent'))->first()[0]->data();
        $this->assertSame('Sent', $query['InvoiceDirection']);
        $this->assertSame('01', $query['documentType']);
        $this->assertSame('2026-11-10T09:55:00Z', $query['submissionDateFrom']);
        $this->assertSame('2026-11-10T10:06:00Z', $query['submissionDateTo']);
    }

    #[Test]
    public function the_same_number_with_another_issue_time_is_not_a_match() : void
    {
        $order = Order::create();
        $this->timedOut($order);
        $this->travel(6)->minutes();
        $this->recent = fn () => $this->recentPage([$this->entry(['dateTimeIssued' => '2026-01-15T09:30:01Z'])]);

        $e = $this->resubmit($order);

        $this->assertSame(MyinvoisPendingAttemptException::STILL_CHECKING, $e->getReason());
        $this->assertSame(0, MyinvoisDocument::count());
    }

    #[Test]
    public function a_young_attempt_with_no_match_is_refused_as_still_checking() : void
    {
        $order = Order::create();
        $this->timedOut($order);
        $this->travel(6)->minutes();

        $e = $this->resubmit($order);

        $this->assertInstanceOf(MyinvoisPendingAttemptException::class, $e);
        $this->assertSame(MyinvoisPendingAttemptException::STILL_CHECKING, $e->getReason());
        $this->assertStringContainsString('INV-0001', $e->getMessage());
        $this->assertSame(1, $this->posts());
        $this->assertTrue(Myinvois::hasPendingAttempt($order));
    }

    #[Test]
    public function an_old_attempt_with_no_match_posts_once_reusing_the_issue_time() : void
    {
        $order = Order::create();
        $old = $this->timedOut($order);
        $this->travel(15)->minutes();
        $this->post = fn () => $this->accepted();

        // the app rebuilt the document with a new issue time
        $this->myinvois()->forParents(['INV-0001' => $order])->withoutPolling()
            ->submitDocuments([DocumentFixture::invoice(['issued_at' => '2026-11-10 10:15:00'])]);

        $this->assertSame(2, $this->posts());
        $this->assertSame('2026-01-15T09:30:00Z', $this->postedIssuedAt(), 'DV302 marks a copy invalid if the original was missed');

        $this->assertSame(MyinvoisSubmitAttempt::NOT_FOUND, $old->refresh()->outcome);
        $new = MyinvoisSubmitAttempt::query()->whereKeyNot($old->getKey())->sole();
        $this->assertSame(MyinvoisSubmitAttempt::ACCEPTED, $new->outcome);
        $this->assertSame('2026-01-15T09:30:00Z', $new->issued_at);
        $this->assertSame($order->id, MyinvoisDocument::sole()->parent_id);
    }

    #[Test]
    public function an_attempt_older_than_two_hours_posts_with_the_new_issue_time() : void
    {
        $order = Order::create();
        $this->timedOut($order);
        $this->travel(3)->hours();
        $this->post = fn () => $this->accepted();

        $this->myinvois()->forParents(['INV-0001' => $order])->withoutPolling()
            ->submitDocuments([DocumentFixture::invoice(['issued_at' => '2026-11-10 13:00:00'])]);

        $this->assertSame(2, $this->posts());
        $this->assertSame('2026-11-10T13:00:00Z', $this->postedIssuedAt());
    }

    public static function lookupFailures() : array
    {
        return [
            '5xx' => [fn () => Http::response([], 500)],
            '4xx' => [fn () => Http::response(['error' => 'bad'], 400)],
            'timeout' => [fn () => throw new ConnectionException('timed out')],
            'not json' => [fn () => Http::response('oops', 200)],
            'more pages than read' => [fn () => Http::response(['result' => [], 'metadata' => ['totalPages' => 99]])],
        ];
    }

    #[Test]
    #[DataProvider('lookupFailures')]
    public function a_failed_lookup_refuses_and_keeps_the_attempt_pending(\Closure $recent) : void
    {
        $order = Order::create();
        $this->timedOut($order);
        $this->travel(15)->minutes();
        $this->recent = $recent;

        $e = $this->resubmit($order);

        $this->assertInstanceOf(MyinvoisPendingAttemptException::class, $e);
        $this->assertSame(MyinvoisPendingAttemptException::LOOKUP_FAILED, $e->getReason());
        $this->assertSame(1, $this->posts());
        $this->assertTrue(Myinvois::hasPendingAttempt($order));
    }

    #[Test]
    public function the_lookup_reads_further_pages() : void
    {
        $order = Order::create();
        $this->timedOut($order);
        $this->travel(6)->minutes();
        $this->recent = fn (Request $request) => ($request->data()['pageNo'] ?? 1) == 1
            ? $this->recentPage([$this->entry(['internalId' => 'OTHER'])], 2)
            : $this->recentPage([$this->entry()], 2);

        $e = $this->resubmit($order);

        $this->assertSame(MyinvoisPendingAttemptException::RECOVERED, $e->getReason());
        $this->assertSame(2, $this->recentCalls());
    }

    #[Test]
    public function several_live_matches_are_refused_by_number() : void
    {
        $order = Order::create();
        $this->timedOut($order);
        $this->travel(15)->minutes();
        $this->recent = fn () => $this->recentPage([
            $this->entry(),
            $this->entry(['uuid' => 'UIDY', 'submissionUID' => 'SUBY', 'status' => 'Submitted']),
        ]);

        $e = $this->resubmit($order);

        $this->assertSame(MyinvoisPendingAttemptException::MULTIPLE_MATCHES, $e->getReason());
        $this->assertStringContainsString('INV-0001', $e->getMessage());
        $this->assertSame(0, MyinvoisDocument::count());
        $this->assertTrue(Myinvois::hasPendingAttempt($order));
        $this->assertSame(1, $this->posts());
    }

    #[Test]
    public function a_valid_original_beside_its_invalid_dv302_copy_is_adopted() : void
    {
        $order = Order::create();
        $this->timedOut($order);
        $this->travel(15)->minutes();
        $this->recent = fn () => $this->recentPage([
            $this->entry(['uuid' => 'COPY', 'submissionUID' => 'SUBC', 'status' => 'Invalid']),
            $this->entry(),
        ]);

        $e = $this->resubmit($order);

        $this->assertSame(MyinvoisPendingAttemptException::RECOVERED, $e->getReason());
        $this->assertSame('UIDX', MyinvoisDocument::sole()->document_uuid);
    }

    #[Test]
    public function one_invalid_match_is_recorded_then_posted() : void
    {
        $order = Order::create();
        $attempt = $this->timedOut($order);
        $this->travel(6)->minutes();
        $this->recent = fn () => $this->recentPage([$this->entry(['status' => 'Invalid'])]);
        $this->post = fn () => $this->accepted('UID2', 'INV-0001', 'SUB2');

        $this->myinvois()->forParents(['INV-0001' => $order])->withoutPolling()->submitDocuments([DocumentFixture::invoice()]);

        $this->assertSame(2, $this->posts());
        $this->assertSame(MyinvoisSubmitAttempt::FOUND_INVALID, $attempt->refresh()->outcome);

        $rows = MyinvoisDocument::query()->orderBy('created_at')->get();
        $this->assertSame(['invalid', 'submitted'], $rows->map(fn ($row) => $row->status->value)->all());
        $this->assertSame([$order->id, $order->id], $rows->pluck('parent_id')->all());
        $this->assertSame(['UIDX', 'UID2'], $rows->pluck('document_uuid')->all());
    }

    #[Test]
    public function a_parentless_local_row_is_adopted_without_asking_lhdn() : void
    {
        $order = Order::create();
        $attempt = $this->timedOut($order);
        $this->travel(1)->minutes();

        // v1.4-style: rows saved without a parent, then the request died
        MyinvoisDocument::create([
            'document_uuid' => 'UIDL', 'submission_uid' => 'SUBL', 'document_number' => 'INV-0001',
            'status' => 'valid', 'is_preprod' => false,
        ]);

        $this->travel(5)->minutes();
        $e = $this->resubmit($order);

        $this->assertSame(MyinvoisPendingAttemptException::RECOVERED, $e->getReason());
        $this->assertSame($order->id, MyinvoisDocument::sole()->parent_id);
        $this->assertSame(0, $this->recentCalls());
        $this->assertSame(MyinvoisSubmitAttempt::ADOPTED, $attempt->refresh()->outcome);
    }

    #[Test]
    public function a_parentless_row_from_before_the_attempt_or_another_environment_is_not_adopted() : void
    {
        $order = Order::create();

        MyinvoisDocument::create(['document_uuid' => 'OLD', 'document_number' => 'INV-0001', 'status' => 'valid', 'is_preprod' => false]);

        $this->travel(1)->minutes();
        $this->timedOut($order);

        MyinvoisDocument::create(['document_uuid' => 'PRE', 'document_number' => 'INV-0001', 'status' => 'valid', 'is_preprod' => true]);

        $this->travel(5)->minutes();
        $e = $this->resubmit($order);

        $this->assertSame(MyinvoisPendingAttemptException::STILL_CHECKING, $e->getReason());
        $this->assertSame(0, MyinvoisDocument::whereNotNull('parent_id')->count());
    }

    #[Test]
    public function a_consolidated_attempt_is_adopted_as_one_row_per_line() : void
    {
        $a = Order::create();
        $b = Order::create();
        $this->post = fn () => throw new ConnectionException('timed out');

        try {
            $this->myinvois()->forParents(['Widget' => $a, 'Receipt 1001-2000' => $b])->submitDocuments([DocumentFixture::consolidated()]);
        }
        catch (MyinvoisUnavailableException) {
        }

        $this->assertSame(2, MyinvoisSubmitAttempt::where('status', 'pending')->count(), 'one per document and parent');
        $this->assertTrue((bool) MyinvoisSubmitAttempt::first()->is_consolidate);

        $this->travel(6)->minutes();
        $this->recent = fn () => $this->recentPage([$this->entry()]);

        try {
            $this->myinvois()->forParents(['Widget' => $a, 'Receipt 1001-2000' => $b])->submitDocuments([DocumentFixture::consolidated()]);
            $this->fail('Expected recovery.');
        }
        catch (MyinvoisPendingAttemptException $e) {
            $this->assertSame(MyinvoisPendingAttemptException::RECOVERED, $e->getReason());
        }

        $this->assertSame($a->id, MyinvoisDocument::where('document_number', 'Widget')->value('parent_id'));
        $this->assertSame($b->id, MyinvoisDocument::where('document_number', 'Receipt 1001-2000')->value('parent_id'));
        $this->assertSame(['INV-0001', 'INV-0001'], MyinvoisDocument::pluck('consolidate_number')->all());
        $this->assertSame(1, $this->recentCalls(), 'one lookup per document, not per parent');
        $this->assertSame(0, MyinvoisSubmitAttempt::where('status', 'pending')->count());
    }

    // ---- crash after acceptance ---------------------------------------

    #[Test]
    public function a_poll_that_throws_after_the_202_leaves_linked_rows_and_the_next_submit_does_not_post() : void
    {
        $order = Order::create();
        $this->poll = fn () => throw new \RuntimeException('boom');

        $result = $this->myinvois()->forParents(['INV-0001' => $order])->submitDocuments([DocumentFixture::invoice()]);

        $this->assertCount(1, $result['myinvois_documents']);
        $this->assertSame($order->id, MyinvoisDocument::sole()->parent_id);
        $this->assertSame('submitted', MyinvoisDocument::sole()->status->value);
        $this->assertFalse(Myinvois::hasPendingAttempt($order));

        $this->expectException(MyinvoisInFlightException::class);

        try {
            $this->myinvois()->forParents(['INV-0001' => $order])->submitDocuments([DocumentFixture::invoice()]);
        }
        finally {
            $this->assertSame(1, $this->posts());
        }
    }

    #[Test]
    public function an_unknown_status_in_the_poll_is_ignored_instead_of_thrown() : void
    {
        $order = Order::create();
        $this->poll = fn () => Http::response(['documentSummary' => [['uuid' => 'UID1', 'submissionUid' => 'SUB1', 'status' => 'Mystery']]]);

        $result = $this->myinvois()->forParents(['INV-0001' => $order])->submitDocuments([DocumentFixture::invoice()]);

        $this->assertCount(1, $result['myinvois_documents']);
        $this->assertSame('submitted', MyinvoisDocument::sole()->status->value);
    }

    // ---- queued --------------------------------------------------------

    protected function queuedJob(OrgOrder $order) : SubmitDocuments
    {
        Queue::fake();
        Myinvois::queueSubmission([DocumentFixture::invoice()], ['INV-0001' => $order]);

        $job = Queue::pushed(SubmitDocuments::class)->first();
        $job->withFakeQueueInteractions();
        Queue::fake();

        return $job;
    }

    protected function pendingAttempt(OrgOrder $order) : MyinvoisSubmitAttempt
    {
        return MyinvoisSubmitAttempt::create([
            'parent_type' => $order->getMorphClass(),
            'parent_id' => $order->getKey(),
            'is_preprod' => false,
            'document_number' => 'INV-0001',
            'document_type' => '01',
            'is_consolidate' => false,
            'issued_at' => '2026-01-15T09:30:00Z',
            'status' => MyinvoisSubmitAttempt::PENDING,
        ]);
    }

    #[Test]
    public function the_job_waits_out_a_young_pending_attempt_instead_of_failing() : void
    {
        Event::fake([SubmissionAccepted::class, SubmissionFailed::class]);
        $order = OrgOrder::create();
        $this->pendingAttempt($order);
        $this->travel(2)->minutes();

        $job = $this->queuedJob($order);
        $job->handle();

        // released until the attempt is 10 minutes old (8 minutes left), plus a little
        $job->assertReleased(485);
        Event::assertNotDispatched(SubmissionFailed::class);
        Event::assertNotDispatched(SubmissionAccepted::class);
        $this->assertSame(0, $this->posts());
    }

    #[Test]
    public function the_job_retries_a_failed_lookup_three_times_then_reports_pending() : void
    {
        Event::fake([SubmissionAccepted::class, SubmissionFailed::class]);
        $order = OrgOrder::create();
        $this->pendingAttempt($order);
        $this->travel(15)->minutes();
        $this->recent = fn () => Http::response([], 500);

        $job = $this->queuedJob($order);

        foreach ([30, 60, 120] as $delay) {
            $job->handle();
            $job->assertReleased($delay);
            $job->withFakeQueueInteractions();
            Event::assertNotDispatched(SubmissionFailed::class);
        }

        $job->handle();

        $job->assertNotReleased();
        Event::assertDispatched(SubmissionFailed::class, fn ($e) => $e->reason === SubmissionFailed::PENDING
            && $e->parents === [['type' => OrgOrder::class, 'id' => $order->id]]);
        $this->assertSame(0, $this->posts());
    }

    // ---- batches (review of #14) ---------------------------------------

    /** Time out one POST of two invoices, INV-0001 for $a and INV-0002 for $b. */
    protected function batchTimedOut(Order $a, Order $b) : void
    {
        $this->post = fn () => throw new ConnectionException('timed out');

        try {
            $this->myinvois()->forParents(['INV-0001' => $a, 'INV-0002' => $b])
                ->submitDocuments([DocumentFixture::invoice(), DocumentFixture::invoice(['number' => 'INV-0002'])]);
            $this->fail('Expected the timeout.');
        }
        catch (MyinvoisUnavailableException) {
        }

        $this->post = fn () => $this->fail('Nothing may be POSTed.');
    }

    #[Test]
    public function a_refusal_in_a_batch_changes_nothing_for_the_other_documents() : void
    {
        $a = Order::create();
        $b = Order::create();
        $this->timedOut($a);

        $this->travel(5)->minutes();
        MyinvoisSubmitAttempt::create([
            'parent_type' => $b->getMorphClass(), 'parent_id' => $b->getKey(), 'is_preprod' => false,
            'document_number' => 'INV-0002', 'document_type' => '01', 'issued_at' => '2026-01-15T09:30:00Z',
            'status' => MyinvoisSubmitAttempt::PENDING,
        ]);

        // INV-0001 is adoptable, INV-0002 is still being checked
        $this->travel(7)->minutes();
        $this->recent = fn () => $this->recentPage([$this->entry()]);

        try {
            $this->myinvois()->forParents(['INV-0001' => $a, 'INV-0002' => $b])
                ->submitDocuments([DocumentFixture::invoice(), DocumentFixture::invoice(['number' => 'INV-0002'])]);
            $this->fail('Expected a refusal.');
        }
        catch (MyinvoisPendingAttemptException $e) {
            $this->assertSame(MyinvoisPendingAttemptException::STILL_CHECKING, $e->getReason());
            $this->assertSame(['INV-0002'], $e->getDocumentNumbers());
        }

        $this->assertSame(0, MyinvoisDocument::count(), 'nothing adopted while another document refuses');
        $this->assertTrue(Myinvois::hasPendingAttempt($a));
    }

    #[Test]
    public function a_recovered_document_is_dropped_and_the_rest_of_the_batch_is_sent() : void
    {
        $a = Order::create();
        $b = Order::create();
        $this->timedOut($a);
        $this->travel(6)->minutes();
        $this->recent = fn () => $this->recentPage([$this->entry()]);
        $this->post = fn () => $this->accepted('UID2', 'INV-0002', 'SUB2');

        $client = $this->myinvois();
        $result = $client->forParents(['INV-0001' => $a, 'INV-0002' => $b])->withoutPolling()
            ->submitDocuments([DocumentFixture::invoice(), DocumentFixture::invoice(['number' => 'INV-0002'])]);

        $posted = Http::recorded(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'documentsubmissions'))->last()[0];
        $this->assertSame(['INV-0002'], collect($posted['documents'])->pluck('codeNumber')->all());

        $this->assertSame(['SUBX' => ['INV-0001']], $client->recoveredSubmissions());
        $this->assertSame(['UID2'], $result['myinvois_documents']->pluck('document_uuid')->all());
        $this->assertSame($a->id, MyinvoisDocument::where('document_uuid', 'UIDX')->value('parent_id'));
        $this->assertSame($b->id, MyinvoisDocument::where('document_uuid', 'UID2')->value('parent_id'));
    }

    #[Test]
    public function one_lookup_covers_every_pending_document_of_a_type() : void
    {
        $a = Order::create();
        $b = Order::create();
        $this->batchTimedOut($a, $b);
        $this->travel(6)->minutes();
        $this->recent = fn () => $this->recentPage([
            $this->entry(),
            $this->entry(['uuid' => 'UIDY', 'internalId' => 'INV-0002']),
        ]);

        try {
            $this->myinvois()->forParents(['INV-0001' => $a, 'INV-0002' => $b])
                ->submitDocuments([DocumentFixture::invoice(), DocumentFixture::invoice(['number' => 'INV-0002'])]);
            $this->fail('Expected recovery.');
        }
        catch (MyinvoisPendingAttemptException $e) {
            $this->assertSame(MyinvoisPendingAttemptException::RECOVERED, $e->getReason());
            $this->assertSame(['INV-0001', 'INV-0002'], $e->getDocumentNumbers());
        }

        $this->assertSame(1, $this->recentCalls());
        $this->assertSame(2, MyinvoisDocument::count());
    }

    #[Test]
    public function the_job_announces_the_recovered_and_the_sent_documents_of_one_batch() : void
    {
        Event::fake([SubmissionAccepted::class, SubmissionFailed::class]);
        $a = OrgOrder::create();
        $b = OrgOrder::create();
        $this->pendingAttempt($a);
        $this->travel(2)->minutes();
        $this->recent = fn () => $this->recentPage([$this->entry(['status' => 'Submitted'])]);
        $this->post = fn () => $this->accepted('UID2', 'INV-0002', 'SUB2');

        Queue::fake();
        Myinvois::queueSubmission([DocumentFixture::invoice(), DocumentFixture::invoice(['number' => 'INV-0002'])], ['INV-0001' => $a, 'INV-0002' => $b]);
        $job = Queue::pushed(SubmitDocuments::class)->first();
        $job->withFakeQueueInteractions();
        Queue::fake();

        $job->handle();

        Event::assertDispatched(SubmissionAccepted::class, fn ($e) => $e->submissionUid === 'SUBX' && $e->documentNumbers === ['INV-0001']);
        Event::assertDispatched(SubmissionAccepted::class, fn ($e) => $e->submissionUid === 'SUB2' && $e->documentNumbers === ['INV-0002']);
        Event::assertNotDispatched(SubmissionFailed::class);
        Queue::assertPushed(PollSubmission::class, 2);
    }

    // ---- idempotence and windows (review of #14) ------------------------

    #[Test]
    public function adopting_a_document_whose_row_already_exists_links_it_instead_of_duplicating() : void
    {
        $order = Order::create();

        // saved (unlinked) just before the attempt was recorded: outside the local window
        MyinvoisDocument::create(['document_uuid' => 'UIDX', 'submission_uid' => 'SUBX', 'document_number' => 'INV-0001', 'status' => 'valid', 'is_preprod' => false]);
        $this->travel(1)->seconds();
        $this->timedOut($order);
        $this->travel(6)->minutes();
        $this->recent = fn () => $this->recentPage([$this->entry()]);

        $e = $this->resubmit($order);

        $this->assertSame(MyinvoisPendingAttemptException::RECOVERED, $e->getReason());
        $this->assertSame($order->id, MyinvoisDocument::sole()->parent_id);
    }

    #[Test]
    public function the_lookup_window_starts_at_the_first_attempt_that_used_the_issue_time() : void
    {
        $order = Order::create();
        $this->timedOut($order);

        // 10:15: nothing found, resent with the same issue time, and that times out too
        $this->travel(15)->minutes();
        $this->post = fn () => throw new ConnectionException('timed out');

        try {
            $this->myinvois()->forParents(['INV-0001' => $order])->submitDocuments([DocumentFixture::invoice(['issued_at' => '2026-11-10 10:15:00'])]);
        }
        catch (MyinvoisUnavailableException) {
        }

        $this->post = fn () => $this->fail('Nothing may be POSTed.');
        $this->travel(6)->minutes();

        $e = $this->resubmit($order);

        $this->assertSame(MyinvoisPendingAttemptException::STILL_CHECKING, $e->getReason());
        $query = Http::recorded(fn ($request) => str_contains($request->url(), 'documents/recent'))->last()[0]->data();
        $this->assertSame('2026-11-10T09:55:00Z', $query['submissionDateFrom'], 'the original may have landed late');
    }

    #[Test]
    public function a_408_on_the_post_is_ambiguous_keeps_the_lock_and_the_attempt() : void
    {
        $order = Order::create();
        $this->post = fn () => Http::response([], 408);

        try {
            $this->myinvois()->forParents(['INV-0001' => $order])->submitDocuments([DocumentFixture::invoice()]);
            $this->fail('Expected the 408 to surface.');
        }
        catch (MyinvoisUnavailableException $e) {
            $this->assertTrue($e->mayHaveReachedMyinvois());
        }

        $this->assertTrue(Myinvois::hasPendingAttempt($order));

        $this->expectException(MyinvoisInFlightException::class);
        $this->myinvois()->forParents(['INV-0001' => $order])->submitDocuments([DocumentFixture::invoice()]);
    }

    // ---- second review of #14 -----------------------------------------

    #[Test]
    public function a_batch_partly_restored_then_throttled_sends_the_rest_on_retry() : void
    {
        Event::fake([SubmissionAccepted::class, SubmissionFailed::class]);
        $a = OrgOrder::create();
        $b = OrgOrder::create();
        $this->pendingAttempt($a);
        $this->travel(2)->minutes();
        $this->recent = fn () => $this->recentPage([$this->entry(['status' => 'Submitted'])]);
        $this->post = fn () => Http::response([], 429, ['Retry-After' => '5']);

        Queue::fake();
        Myinvois::queueSubmission([DocumentFixture::invoice(), DocumentFixture::invoice(['number' => 'INV-0002'])], ['INV-0001' => $a, 'INV-0002' => $b]);
        $job = Queue::pushed(SubmitDocuments::class)->first();
        $job->withFakeQueueInteractions();
        Queue::fake();

        $job->handle();

        $job->assertReleased(5);
        Event::assertDispatched(SubmissionAccepted::class, fn ($e) => $e->submissionUid === 'SUBX');

        $this->post = fn () => $this->accepted('UID2', 'INV-0002', 'SUB2');
        $job->withFakeQueueInteractions();
        $job->handle();

        $posted = Http::recorded(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'documentsubmissions'))->last()[0];
        $this->assertSame(['INV-0002'], collect($posted['documents'])->pluck('codeNumber')->all());
        Event::assertDispatched(SubmissionAccepted::class, fn ($e) => $e->submissionUid === 'SUB2' && $e->documentNumbers === ['INV-0002']);
        Event::assertDispatchedTimes(SubmissionAccepted::class, 2);
        Event::assertNotDispatched(SubmissionFailed::class);
    }

    #[Test]
    public function an_adopted_row_keeps_lhdns_received_instant_in_a_non_utc_app() : void
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Kuala_Lumpur');

        try {
            $order = Order::create();
            $this->timedOut($order);
            $this->travel(6)->minutes();
            $this->recent = fn () => $this->recentPage([$this->entry()]);

            $this->resubmit($order);

            $this->assertSame('2026-11-10 10:00:05', MyinvoisDocument::sole()->created_at->utc()->toDateTimeString());
        }
        finally {
            date_default_timezone_set($timezone);
        }
    }

    #[Test]
    public function a_match_with_a_status_the_package_does_not_know_is_not_taken_as_live() : void
    {
        $order = Order::create();
        $attempt = $this->timedOut($order);
        $this->travel(15)->minutes();
        $this->recent = fn () => $this->recentPage([$this->entry(['status' => 'Rejected'])]);
        $this->post = fn () => $this->accepted();

        $this->myinvois()->forParents(['INV-0001' => $order])->withoutPolling()->submitDocuments([DocumentFixture::invoice(['issued_at' => '2026-11-10 10:15:00'])]);

        $this->assertSame(2, $this->posts());
        $this->assertSame(MyinvoisSubmitAttempt::NOT_FOUND, $attempt->refresh()->outcome);
        $this->assertSame('2026-01-15T09:30:00Z', $this->postedIssuedAt(), 'resent with the issue time reused, so DV302 catches a copy');
    }

    #[Test]
    public function an_attempt_without_an_issue_time_matches_on_the_number_alone() : void
    {
        $order = OrgOrder::create();
        $this->pendingAttempt($order)->update(['issued_at' => null]);
        $this->travel(6)->minutes();
        $this->recent = fn () => $this->recentPage([$this->entry(['dateTimeIssued' => '2026-02-01T00:00:00Z'])]);

        try {
            Myinvois::clientFor($order)->forParents(['INV-0001' => $order])->submitDocuments([DocumentFixture::invoice()]);
            $this->fail('Expected recovery.');
        }
        catch (MyinvoisPendingAttemptException $e) {
            $this->assertSame(MyinvoisPendingAttemptException::RECOVERED, $e->getReason());
        }

        $this->assertSame(0, $this->posts());
    }

    #[Test]
    public function attempts_can_use_their_own_connection() : void
    {
        $this->assertSame('testing', (new MyinvoisSubmitAttempt)->getConnectionName() ?? config('database.default'));

        config(['services.myinvois.attempts_connection' => 'attempts']);

        $this->assertSame('attempts', (new MyinvoisSubmitAttempt)->getConnectionName());
    }

    #[Test]
    public function the_job_announces_a_recovered_submission_as_accepted() : void
    {
        Event::fake([SubmissionAccepted::class, SubmissionFailed::class]);
        $order = OrgOrder::create();
        $this->pendingAttempt($order);
        $this->travel(2)->minutes();
        $this->recent = fn () => $this->recentPage([$this->entry(['status' => 'Submitted'])]);

        $this->queuedJob($order)->handle();

        Event::assertDispatched(SubmissionAccepted::class, fn ($e) => $e->submissionUid === 'SUBX'
            && $e->documentNumbers === ['INV-0001']
            && $e->parents === [['type' => OrgOrder::class, 'id' => $order->id]]);
        Event::assertNotDispatched(SubmissionFailed::class);
        Queue::assertPushed(PollSubmission::class, fn ($poll) => $poll->submissionUid === 'SUBX');
        $this->assertSame(0, $this->posts());
    }

    // ---- cancel window -------------------------------------------------

    #[Test]
    public function is_cancellable_prefers_lhdns_validated_time() : void
    {
        $row = new MyinvoisDocument(['status' => 'valid', 'response' => ['dateTimeValidated' => now()->subHours(80)->toIso8601ZuluString()]]);
        $row->created_at = now();
        $this->assertFalse($row->isCancellable());

        $row = new MyinvoisDocument(['status' => 'valid', 'response' => ['dateTimeValidated' => now()->subHours(10)->toIso8601ZuluString()]]);
        $row->created_at = now()->subHours(80);
        $this->assertTrue($row->isCancellable());

        $row = new MyinvoisDocument(['status' => 'valid', 'response' => []]);
        $row->created_at = now()->subHours(80);
        $this->assertFalse($row->isCancellable(), 'falls back to created_at');
    }
}
