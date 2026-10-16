<?php

namespace Jiannius\Myinvois\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Jiannius\Myinvois\Enums\Status;
use Jiannius\Myinvois\Events\DocumentStatusChanged;
use Jiannius\Myinvois\Events\SubmissionAccepted;
use Jiannius\Myinvois\Events\SubmissionFailed;
use Jiannius\Myinvois\Events\SubmissionSucceeded;
use Jiannius\Myinvois\Jobs\PollSubmission;
use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Myinvois;
use Jiannius\Myinvois\Tests\Fixtures\OrgOrder;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * LHDN's submission poll (documentSummary) says INVALID but not why; the reasons are only in
 * GET documents/{uuid}/details. The poll job and myinvois:sync fetch them for rows that just
 * turned INVALID, so SubmissionFailed(invalid)->errors and the row's getErrors() are filled.
 */
class InvalidReasonsTest extends TestCase
{
    protected const REASONS = [
        'DS304: The buyer TIN does not match the registered name.',
        'DS329: Invalid MSIC code.',
    ];

    protected function setUp() : void
    {
        parent::setUp();

        Event::fake([SubmissionFailed::class, SubmissionSucceeded::class, SubmissionAccepted::class, DocumentStatusChanged::class]);
    }

    // ---- helpers -------------------------------------------------------

    /** What LHDN's documents/{uuid}/details answers for an INVALID document. */
    protected function details(string $uuid = 'UID1', string $status = 'Invalid') : array
    {
        return [
            'uuid' => $uuid,
            'submissionUID' => 'SUB1',
            'longId' => 'LONG-'.$uuid,
            'status' => $status,
            'validationResults' => [
                'status' => 'Invalid',
                'validationSteps' => [[
                    'name' => 'Step03-Validate Issuer & Taxpayer TIN',
                    'status' => 'Invalid',
                    'error' => [
                        'propertyPath' => null,
                        'errorCode' => 'ValidationError',
                        'error' => 'Validation error',
                        'errorMS' => 'Ralat pengesahan',
                        'innerError' => [
                            ['propertyPath' => 'Invoice', 'errorCode' => 'DS304', 'error' => self::REASONS[0]],
                            ['propertyPath' => 'Invoice', 'errorCode' => 'DS329', 'error' => self::REASONS[1]],
                        ],
                    ],
                ]],
            ],
        ];
    }

    /**
     * LHDN: the poll answers with a documentSummary (no validationResults) for every uuid;
     * $detailsBy decides what documents/{uuid}/details answers.
     *
     * @param  array<int, string>  $uuids
     * @param  array<string, mixed>  $detailsBy  uuid => body array | response | callable
     */
    protected function lhdn(array $uuids, string $status, array $detailsBy = []) : void
    {
        $routes = [
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        ];

        foreach ($detailsBy as $uuid => $answer) {
            $routes["*documents/{$uuid}/details*"] = is_array($answer) ? Http::response($answer) : $answer;
        }

        $routes['*documentsubmissions*'] = Http::response([
            'submissionUid' => 'SUB1',
            'documentSummary' => collect($uuids)->map(fn ($uuid) => [
                'uuid' => $uuid,
                'submissionUid' => 'SUB1',
                'status' => $status,
                'longId' => 'LONG-'.$uuid,
            ])->all(),
        ]);

        Http::fake($routes);
    }

    protected function submittedRow(OrgOrder $order, string $uuid = 'UID1', string $number = 'INV-0001', array $attrs = []) : MyinvoisDocument
    {
        return MyinvoisDocument::create([
            'parent_type' => OrgOrder::class,
            'parent_id' => $order->id,
            'document_uuid' => $uuid,
            'submission_uid' => 'SUB1',
            'document_number' => $number,
            'status' => 'submitted',
            'is_preprod' => false,
            ...$attrs,
        ]);
    }

    protected function poll(OrgOrder $order) : PollSubmission
    {
        $poll = new PollSubmission('SUB1', [['type' => OrgOrder::class, 'id' => $order->id]], false, ['batch' => 7]);
        $poll->withFakeQueueInteractions();
        $poll->job->attempts = 1;

        return $poll;
    }

    protected function detailsCalls(?string $uuid = null) : int
    {
        return Http::recorded(fn ($request) => $request->method() === 'GET'
            && str_ends_with(parse_url($request->url(), PHP_URL_PATH), $uuid === null ? '/details' : "/documents/{$uuid}/details"))->count();
    }

    protected function failedEvent() : SubmissionFailed
    {
        $events = [];
        Event::assertDispatched(SubmissionFailed::class, function ($event) use (&$events) {
            $events[] = $event;

            return true;
        });

        $this->assertCount(1, $events, 'announced exactly once');

        return $events[0];
    }

    // ---- the poll job --------------------------------------------------

    #[Test]
    public function an_invalid_verdict_carries_the_reasons_from_the_document_details() : void
    {
        $this->lhdn(['UID1'], 'Invalid', ['UID1' => $this->details()]);
        $order = OrgOrder::create();
        $row = $this->submittedRow($order);

        $this->poll($order)->handle();

        $event = $this->failedEvent();
        $this->assertSame(SubmissionFailed::INVALID, $event->reason);
        $this->assertSame(self::REASONS, $event->errors);
        $this->assertSame(['INV-0001'], $event->documentNumbers);

        $row->refresh();
        $this->assertSame('invalid', $row->status->value);
        $this->assertSame(self::REASONS, $row->getErrors());
        $this->assertSame(1, $this->detailsCalls('UID1'));
    }

    #[Test]
    public function storing_the_details_does_not_fire_a_second_status_change_or_touch_the_parent() : void
    {
        $this->lhdn(['UID1'], 'Invalid', ['UID1' => $this->details()]);
        $order = OrgOrder::create();
        $this->submittedRow($order);
        DB::table('orders')->where('id', $order->id)->update(['updated_at' => '2020-01-01 00:00:00']);

        $this->poll($order)->handle();

        // submitted -> invalid by the poll itself, and nothing more for the details write
        Event::assertDispatched(DocumentStatusChanged::class, fn ($e) => $e->to === Status::INVALID);
        Event::assertDispatchedTimes(DocumentStatusChanged::class, 2); // created (submitted) + invalid
        Event::assertNotDispatched(DocumentStatusChanged::class, fn ($e) => $e->from === Status::INVALID);
        $this->assertSame('2020-01-01 00:00:00', DB::table('orders')->where('id', $order->id)->value('updated_at'));
        $this->assertSame('invalid', DB::table('orders')->where('id', $order->id)->value('myinvois_status'));
    }

    #[Test]
    public function the_details_never_blank_the_status() : void
    {
        $body = $this->details();
        unset($body['status']);
        $this->lhdn(['UID1'], 'Invalid', ['UID1' => $body]);
        $order = OrgOrder::create();
        $row = $this->submittedRow($order);

        $this->poll($order)->handle();

        $this->assertSame('invalid', $row->refresh()->status->value);
        $this->assertSame(self::REASONS, $this->failedEvent()->errors);
    }

    #[Test]
    public function a_consolidated_submission_asks_once_per_document_uuid() : void
    {
        $this->lhdn(['UID1', 'UID2'], 'Invalid', ['UID1' => $this->details('UID1'), 'UID2' => $this->details('UID2')]);
        $order = OrgOrder::create();
        // one consolidated e-invoice (UID1) has three rows; a second document (UID2) has one
        $this->submittedRow($order, 'UID1', 'RCPT-1');
        $this->submittedRow($order, 'UID1', 'RCPT-2');
        $this->submittedRow($order, 'UID1', 'RCPT-3');
        $this->submittedRow($order, 'UID2', 'INV-0002');

        $this->poll($order)->handle();

        $this->assertSame(1, $this->detailsCalls('UID1'));
        $this->assertSame(1, $this->detailsCalls('UID2'));
        $this->assertSame(2, $this->detailsCalls());

        $event = $this->failedEvent();
        $this->assertSame(self::REASONS, $event->errors, 'the same reasons are de-duplicated');
        $this->assertEqualsCanonicalizing(['RCPT-1', 'RCPT-2', 'RCPT-3', 'INV-0002'], $event->documentNumbers);

        foreach (MyinvoisDocument::all() as $row) $this->assertSame(self::REASONS, $row->getErrors());
    }

    #[Test]
    public function a_valid_verdict_asks_for_no_details() : void
    {
        $this->lhdn(['UID1'], 'Valid');
        $order = OrgOrder::create();
        $this->submittedRow($order);

        $this->poll($order)->handle();

        $this->assertSame(0, $this->detailsCalls());
        Event::assertDispatchedTimes(SubmissionSucceeded::class, 1);
        Event::assertNotDispatched(SubmissionFailed::class);
    }

    #[Test]
    public function a_mixed_outcome_asks_only_for_the_invalid_document() : void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documents/BAD/details*' => Http::response($this->details('BAD')),
            '*documentsubmissions*' => Http::response(['submissionUid' => 'SUB1', 'documentSummary' => [
                ['uuid' => 'GOOD', 'submissionUid' => 'SUB1', 'status' => 'Valid'],
                ['uuid' => 'BAD', 'submissionUid' => 'SUB1', 'status' => 'Invalid'],
            ]]),
        ]);
        $order = OrgOrder::create();
        $this->submittedRow($order, 'GOOD', 'INV-1');
        $this->submittedRow($order, 'BAD', 'INV-2');

        $this->poll($order)->handle();

        $this->assertSame(1, $this->detailsCalls());
        $this->assertSame(1, $this->detailsCalls('BAD'));
        $this->assertSame(self::REASONS, $this->failedEvent()->errors);
        Event::assertDispatchedTimes(SubmissionSucceeded::class, 1);
    }

    #[Test]
    public function a_row_that_already_holds_its_reasons_is_not_fetched_again() : void
    {
        $this->lhdn(['UID1'], 'Invalid', ['UID1' => $this->details()]);
        $order = OrgOrder::create();
        $this->submittedRow($order, 'UID1', 'INV-0001', ['status' => 'invalid', 'response' => $this->details()]);

        $this->poll($order)->handle();

        $this->assertSame(0, $this->detailsCalls());
        $this->assertSame(self::REASONS, $this->failedEvent()->errors);
    }

    /** @return array<string, array{0: \Closure}> */
    public static function failingDetails() : array
    {
        return [
            'a 500' => [fn () => Http::response('', 500)],
            'a 503' => [fn () => Http::response('', 503)],
            'a 429' => [fn () => Http::response('', 429, ['Retry-After' => '30'])],
            'a 404' => [fn () => Http::response(['error' => ['code' => 'NotFound']], 404)],
            'an HTML body' => [fn () => Http::response('<html>bad gateway</html>', 200)],
            'a timeout' => [fn () => fn () => throw new ConnectionException('cURL error 28')],
        ];
    }

    #[Test]
    #[DataProvider('failingDetails')]
    public function a_details_failure_still_announces_with_no_reasons(\Closure $answer) : void
    {
        $this->lhdn(['UID1'], 'Invalid', ['UID1' => $answer()]);
        $order = OrgOrder::create();
        $row = $this->submittedRow($order);

        $poll = $this->poll($order);
        $poll->handle();

        $poll->assertNotReleased();
        $poll->assertNotFailed();
        $event = $this->failedEvent();
        $this->assertSame([], $event->errors);
        $this->assertSame(SubmissionFailed::INVALID, $event->reason);
        $this->assertSame('invalid', $row->refresh()->status->value);
        $this->assertSame([], $row->getErrors());

        // announced once: a repeat delivery says nothing more
        $this->poll($order)->handle();
        Event::assertDispatchedTimes(SubmissionFailed::class, 1);
    }

    #[Test]
    public function a_capped_rate_limiter_skips_the_details_without_sleeping_or_releasing() : void
    {
        $this->lhdn(['UID1'], 'Invalid', ['UID1' => $this->details()]);
        $order = OrgOrder::create();
        $row = $this->submittedRow($order);

        $key = Myinvois::clientFor($order)->getThrottleKey('documents/UID1/details');
        for ($i = 0; $i < 60; $i++) RateLimiter::hit($key);

        $poll = $this->poll($order);
        $poll->handle();

        $poll->assertNotReleased();
        $this->assertSame(0, $this->detailsCalls());
        $this->assertSame([], $this->failedEvent()->errors);
        $this->assertSame('invalid', $row->refresh()->status->value);
    }

    #[Test]
    public function a_capped_limiter_stops_after_the_first_throttled_uuid() : void
    {
        $this->lhdn(['UID1', 'UID2'], 'Invalid', ['UID1' => $this->details('UID1'), 'UID2' => $this->details('UID2')]);
        $order = OrgOrder::create();
        $this->submittedRow($order, 'UID1', 'INV-1');
        $this->submittedRow($order, 'UID2', 'INV-2');

        $key = Myinvois::clientFor($order)->getThrottleKey('documents/UID1/details');
        for ($i = 0; $i < 59; $i++) RateLimiter::hit($key);

        $this->poll($order)->handle();

        // one slot left: the first goes out, the second is throttled and skipped
        $this->assertSame(1, $this->detailsCalls());
        $this->assertSame(self::REASONS, $this->failedEvent()->errors);
    }

    // ---- the sweep -----------------------------------------------------

    protected function sync() : int
    {
        config()->set('services.myinvois.client_id', 'prod-id');
        config()->set('services.myinvois.client_secret', 'prod-secret');
        config()->set('services.myinvois.preprod', false);

        return Artisan::call('myinvois:sync');
    }

    #[Test]
    public function the_sweep_stores_the_reasons_of_rows_it_turns_invalid_and_fires_no_event() : void
    {
        $this->lhdn(['UID1'], 'Invalid', ['UID1' => $this->details()]);
        $order = OrgOrder::create();
        $row = $this->submittedRow($order);
        $this->submittedRow($order, 'UID1', 'INV-0002');
        DB::table('orders')->where('id', $order->id)->update(['updated_at' => '2020-01-01 00:00:00']);

        $this->assertSame(0, $this->sync());

        $row->refresh();
        $this->assertSame('invalid', $row->status->value);
        $this->assertSame(self::REASONS, $row->getErrors());
        $this->assertSame(1, $this->detailsCalls('UID1'), 'once per uuid');
        Event::assertNotDispatched(SubmissionFailed::class);
        $this->assertSame('2020-01-01 00:00:00', DB::table('orders')->where('id', $order->id)->value('updated_at'));
    }

    #[Test]
    public function the_sweep_asks_for_no_details_when_the_rows_turn_valid() : void
    {
        $this->lhdn(['UID1'], 'Valid');
        $this->submittedRow(OrgOrder::create());

        $this->assertSame(0, $this->sync());

        $this->assertSame(0, $this->detailsCalls());
        $this->assertSame('valid', MyinvoisDocument::first()->status->value);
    }

    #[Test]
    public function a_details_failure_does_not_stop_the_sweep() : void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documents/*/details*' => Http::response('', 500),
            '*documentsubmissions/SUB-A*' => Http::response(['documentSummary' => [['uuid' => 'UA', 'submissionUid' => 'SUB-A', 'status' => 'Invalid']]]),
            '*documentsubmissions/SUB-B*' => Http::response(['documentSummary' => [['uuid' => 'UB', 'submissionUid' => 'SUB-B', 'status' => 'Valid']]]),
        ]);
        $this->submittedRow(OrgOrder::create(), 'UA', 'INV-A', ['submission_uid' => 'SUB-A']);
        $this->submittedRow(OrgOrder::create(), 'UB', 'INV-B', ['submission_uid' => 'SUB-B']);

        $this->assertSame(0, $this->sync());

        $this->assertSame('invalid', MyinvoisDocument::where('document_uuid', 'UA')->first()->status->value);
        $this->assertSame('valid', MyinvoisDocument::where('document_uuid', 'UB')->first()->status->value);
        $this->assertSame([], MyinvoisDocument::where('document_uuid', 'UA')->first()->getErrors());
    }

    // ---- the fake ------------------------------------------------------

    #[Test]
    public function the_fake_delivers_the_poll_errors_through_its_details() : void
    {
        $fake = Myinvois::fake()->pollResolvesTo('invalid', ['Buyer TIN does not match']);
        $order = OrgOrder::create();
        $row = $this->submittedRow($order);

        $this->poll($order)->handle();

        $this->assertSame(['Buyer TIN does not match'], $this->failedEvent()->errors);
        $this->assertSame(['Buyer TIN does not match'], $row->refresh()->getErrors());
        $this->assertContains('documents/UID1/details', collect($fake->apiCalls)->pluck('uri')->all());
    }
}
