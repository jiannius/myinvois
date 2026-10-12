<?php

namespace Jiannius\Myinvois\Tests\Feature;

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Jiannius\Myinvois\Enums\Status;
use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Tests\Fixtures\Order;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * myinvois:sync -- the safety-net sweep, and its opt-in schedule.
 */
class SyncCommandTest extends TestCase
{
    protected function setUp() : void
    {
        parent::setUp();

        config()->set('services.myinvois.client_id', 'prod-id');
        config()->set('services.myinvois.client_secret', 'prod-secret');
        config()->set('services.myinvois.preprod_client_id', 'pre-id');
        config()->set('services.myinvois.preprod_client_secret', 'pre-secret');
        config()->set('services.myinvois.preprod', false);
    }

    // ---- helpers -------------------------------------------------------

    /** A submission answers with the given status for every uuid it is asked about. */
    protected function lhdn(array $answers) : void
    {
        $routes = ['*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600])];

        foreach ($answers as $submissionUid => $answer) {
            $routes["*documentsubmissions/{$submissionUid}*"] = is_callable($answer) ? $answer : Http::response([
                'submissionUid' => $submissionUid,
                'documentSummary' => collect((array) $answer)->map(fn ($status, $uuid) => [
                    'uuid' => $uuid, 'submissionUid' => $submissionUid, 'status' => $status,
                ])->values()->all(),
            ]);
        }

        Http::fake($routes);
    }

    protected function row(?Order $parent, array $attrs = []) : MyinvoisDocument
    {
        return MyinvoisDocument::create([
            'document_uuid' => $attrs['document_uuid'] ?? 'U-'.Str::random(6),
            'submission_uid' => 'SUB-'.Str::random(6),
            'document_number' => 'INV-'.Str::random(4),
            'status' => 'submitted',
            'is_preprod' => false,
            ...($parent ? ['parent_type' => Order::class, 'parent_id' => $parent->id] : []),
            ...$attrs,
        ]);
    }

    /** @return array<int, string> the submission uids LHDN was asked about */
    protected function asked() : array
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), 'documentsubmissions/'))
            ->map(fn ($pair) => Str::afterLast(parse_url($pair[0]->url(), PHP_URL_PATH), '/'))
            ->values()
            ->all();
    }

    protected function sync(array $options = []) : int
    {
        return Artisan::call('myinvois:sync', $options);
    }

    // ---- environment ---------------------------------------------------

    #[Test]
    public function a_preprod_run_only_touches_preprod_rows() : void
    {
        config()->set('services.myinvois.preprod', true);
        $this->lhdn(['SUB-PRE' => ['U-PRE' => 'Valid'], 'SUB-PROD' => ['U-PROD' => 'Valid'], 'SUB-NULL' => ['U-NULL' => 'Valid']]);
        $this->row(Order::create(), ['submission_uid' => 'SUB-PRE', 'document_uuid' => 'U-PRE', 'is_preprod' => true]);
        $this->row(Order::create(), ['submission_uid' => 'SUB-PROD', 'document_uuid' => 'U-PROD', 'is_preprod' => false]);
        $this->row(Order::create(), ['submission_uid' => 'SUB-NULL', 'document_uuid' => 'U-NULL', 'is_preprod' => null]);

        $this->assertSame(0, $this->sync());

        $this->assertSame(['SUB-PRE'], $this->asked());
        $this->assertSame('valid', MyinvoisDocument::where('document_uuid', 'U-PRE')->first()->status->value);
        $this->assertSame('submitted', MyinvoisDocument::where('document_uuid', 'U-PROD')->first()->status->value);
    }

    #[Test]
    public function a_production_run_touches_false_and_null_but_never_preprod_rows() : void
    {
        $this->lhdn(['SUB-PRE' => ['U-PRE' => 'Valid'], 'SUB-PROD' => ['U-PROD' => 'Valid'], 'SUB-NULL' => ['U-NULL' => 'Valid']]);
        $this->row(Order::create(), ['submission_uid' => 'SUB-PRE', 'document_uuid' => 'U-PRE', 'is_preprod' => true]);
        $this->row(Order::create(), ['submission_uid' => 'SUB-PROD', 'document_uuid' => 'U-PROD', 'is_preprod' => false]);
        $this->row(Order::create(), ['submission_uid' => 'SUB-NULL', 'document_uuid' => 'U-NULL', 'is_preprod' => null]);

        $this->sync();

        $this->assertEqualsCanonicalizing(['SUB-PROD', 'SUB-NULL'], $this->asked());
        $this->assertSame('submitted', MyinvoisDocument::where('document_uuid', 'U-PRE')->first()->status->value);
    }

    #[Test]
    public function inbound_rows_are_never_swept() : void
    {
        $this->lhdn(['SUB-IN' => ['U-IN' => 'Valid'], 'SUB-OUT' => ['U-OUT' => 'Valid']]);
        $this->row(Order::create(), ['submission_uid' => 'SUB-IN', 'document_uuid' => 'U-IN', 'is_inbound' => true]);
        $this->row(Order::create(), ['submission_uid' => 'SUB-OUT', 'document_uuid' => 'U-OUT', 'is_inbound' => null]);

        $this->sync();

        $this->assertSame(['SUB-OUT'], $this->asked());
    }

    // ---- what is swept -------------------------------------------------

    #[Test]
    public function a_consolidated_submission_costs_exactly_one_call_and_updates_every_row() : void
    {
        $this->lhdn(['SUB-C' => ['U-C' => 'Valid']]);
        $order = Order::create();
        $other = Order::create();
        $this->row($order, ['submission_uid' => 'SUB-C', 'document_uuid' => 'U-C', 'document_number' => 'Widget', 'consolidate_number' => 'INV-9']);
        $this->row($other, ['submission_uid' => 'SUB-C', 'document_uuid' => 'U-C', 'document_number' => 'Receipt 1', 'consolidate_number' => 'INV-9']);

        $this->sync();

        $this->assertSame(['SUB-C'], $this->asked());
        $this->assertSame(['valid', 'valid'], MyinvoisDocument::orderBy('id')->get()->map(fn ($r) => $r->status->value)->all());
        $this->assertSame(Status::VALID, $order->fresh()->myinvois_status);
        $this->assertSame(Status::VALID, $other->fresh()->myinvois_status);
    }

    #[Test]
    public function invalid_rows_are_final_and_never_swept_even_with_include_valid() : void
    {
        $this->lhdn(['SUB-BAD' => ['U-BAD' => 'Valid']]);
        $this->row(Order::create(), ['submission_uid' => 'SUB-BAD', 'document_uuid' => 'U-BAD', 'status' => 'invalid']);

        $this->sync();
        $this->sync(['--include-valid' => true]);

        $this->assertSame([], $this->asked());
        $this->assertSame('invalid', MyinvoisDocument::first()->status->value);
    }

    #[Test]
    public function cancelled_rows_are_not_swept() : void
    {
        $this->lhdn(['SUB-X' => ['U-X' => 'Valid']]);
        $this->row(Order::create(), ['submission_uid' => 'SUB-X', 'document_uuid' => 'U-X', 'status' => 'cancelled']);

        $this->sync(['--include-valid' => true]);

        $this->assertSame([], $this->asked());
    }

    #[Test]
    public function valid_rows_are_left_alone_unless_include_valid_is_given() : void
    {
        $this->lhdn(['SUB-V' => ['U-V' => 'Cancelled']]);
        $this->row(Order::create(), ['submission_uid' => 'SUB-V', 'document_uuid' => 'U-V', 'status' => 'valid']);

        $this->sync();
        $this->assertSame([], $this->asked());

        $this->sync(['--include-valid' => true]);
        $this->assertSame(['SUB-V'], $this->asked());
        $this->assertSame('cancelled', MyinvoisDocument::first()->status->value);
    }

    #[Test]
    public function include_valid_only_covers_the_72_hour_window_and_the_latest_submission() : void
    {
        $this->lhdn([
            'SUB-NEW' => ['U-NEW' => 'Valid'],
            'SUB-OLD' => ['U-OLD' => 'Valid'],
            'SUB-SUPERSEDED' => ['U-SUPERSEDED' => 'Valid'],
        ]);

        $this->row(Order::create(), ['submission_uid' => 'SUB-NEW', 'document_uuid' => 'U-NEW', 'status' => 'valid']);

        $old = $this->row(Order::create(), ['submission_uid' => 'SUB-OLD', 'document_uuid' => 'U-OLD', 'status' => 'valid']);
        DB::table('myinvois_documents')->where('id', $old->id)->update(['created_at' => now()->subHours(73)]);

        // a VALID row that a later submission of the same parent superseded
        $parent = Order::create();
        $this->row($parent, ['submission_uid' => 'SUB-SUPERSEDED', 'document_uuid' => 'U-SUPERSEDED', 'status' => 'valid']);
        $this->row($parent, ['submission_uid' => 'SUB-LATER', 'document_uuid' => 'U-LATER', 'status' => 'invalid']);

        $this->sync(['--include-valid' => true]);

        $this->assertSame(['SUB-NEW'], $this->asked());
    }

    #[Test]
    public function limit_counts_submissions_not_rows() : void
    {
        $this->lhdn(['SUB-1' => ['U-1' => 'Valid'], 'SUB-2' => ['U-2' => 'Valid'], 'SUB-3' => ['U-3' => 'Valid']]);
        $a = Order::create();
        $b = Order::create();
        // SUB-1 is consolidated: two rows, one submission
        $this->row($a, ['submission_uid' => 'SUB-1', 'document_uuid' => 'U-1', 'document_number' => 'a']);
        $this->row($b, ['submission_uid' => 'SUB-1', 'document_uuid' => 'U-1', 'document_number' => 'b']);
        $this->row(Order::create(), ['submission_uid' => 'SUB-2', 'document_uuid' => 'U-2']);
        $this->row(Order::create(), ['submission_uid' => 'SUB-3', 'document_uuid' => 'U-3']);

        $this->sync(['--limit' => 2]);

        $this->assertSame(['SUB-1', 'SUB-2'], $this->asked(), 'oldest first, two submissions');
        $this->assertSame('submitted', MyinvoisDocument::where('document_uuid', 'U-3')->first()->status->value);
    }

    #[Test]
    public function the_default_limit_comes_from_config() : void
    {
        config()->set('services.myinvois.sync_limit', 1);
        $this->lhdn(['SUB-1' => ['U-1' => 'Valid'], 'SUB-2' => ['U-2' => 'Valid']]);
        $this->row(Order::create(), ['submission_uid' => 'SUB-1', 'document_uuid' => 'U-1']);
        $this->row(Order::create(), ['submission_uid' => 'SUB-2', 'document_uuid' => 'U-2']);

        $this->sync();

        $this->assertSame(['SUB-1'], $this->asked());
    }

    #[Test]
    public function a_dry_run_lists_without_calling_lhdn() : void
    {
        $this->lhdn(['SUB-1' => ['U-1' => 'Valid']]);
        $this->row(Order::create(), ['submission_uid' => 'SUB-1', 'document_uuid' => 'U-1']);

        $this->sync(['--dry-run' => true]);

        Http::assertNothingSent();
        $this->assertStringContainsString('would check SUB-1', Artisan::output());
        $this->assertSame('submitted', MyinvoisDocument::first()->status->value);
    }

    #[Test]
    public function rows_without_a_parent_are_skipped_and_counted() : void
    {
        $this->lhdn(['SUB-NP' => ['U-NP' => 'Valid']]);
        $this->row(null, ['submission_uid' => 'SUB-NP', 'document_uuid' => 'U-NP']);

        $this->sync();

        $this->assertSame([], $this->asked());
        $this->assertStringContainsString('skipped, no parent: 1', Artisan::output());
    }

    #[Test]
    public function rows_whose_parent_was_deleted_are_skipped_and_counted() : void
    {
        $this->lhdn(['SUB-GONE' => ['U-GONE' => 'Valid']]);
        $order = Order::create();
        $this->row($order, ['submission_uid' => 'SUB-GONE', 'document_uuid' => 'U-GONE']);
        DB::table('orders')->where('id', $order->id)->delete();

        $this->sync();

        $this->assertSame([], $this->asked());
        $this->assertStringContainsString('skipped, no parent: 1', Artisan::output());
    }

    // ---- errors --------------------------------------------------------

    #[Test]
    public function an_auth_failure_skips_that_client_for_the_rest_of_the_run() : void
    {
        Http::fake(['*/connect/token' => Http::response(['error' => 'invalid_client'], 400)]);
        $this->row(Order::create(), ['submission_uid' => 'SUB-1', 'document_uuid' => 'U-1']);
        $this->row(Order::create(), ['submission_uid' => 'SUB-2', 'document_uuid' => 'U-2']);
        $this->row(Order::create(), ['submission_uid' => 'SUB-3', 'document_uuid' => 'U-3']);

        $this->assertSame(1, $this->sync());

        // one token attempt, not three
        $this->assertSame(1, Http::recorded(fn ($request) => str_contains($request->url(), 'connect/token'))->count());
        $output = Artisan::output();
        $this->assertStringContainsString('SUB-1', $output);
        $this->assertStringContainsString('credentials failed earlier in this run: 2', $output);
    }

    #[Test]
    public function another_error_is_reported_and_the_sweep_continues() : void
    {
        $this->lhdn([
            'SUB-1' => fn () => Http::response('', 503),
            'SUB-2' => ['U-2' => 'Valid'],
        ]);
        $this->row(Order::create(), ['submission_uid' => 'SUB-1', 'document_uuid' => 'U-1']);
        $this->row(Order::create(), ['submission_uid' => 'SUB-2', 'document_uuid' => 'U-2']);

        $this->assertSame(1, $this->sync());

        $this->assertSame(['SUB-1', 'SUB-2'], $this->asked());
        $this->assertSame('valid', MyinvoisDocument::where('document_uuid', 'U-2')->first()->status->value);
        $this->assertStringContainsString('SUB-1', Artisan::output());
    }

    // ---- the observer's rules still hold -------------------------------

    #[Test]
    public function the_sweep_does_not_touch_the_parents_updated_at() : void
    {
        $this->lhdn(['SUB-1' => ['U-1' => 'Valid']]);
        $order = Order::create();
        $past = now()->subDays(5)->startOfSecond();
        DB::table('orders')->where('id', $order->id)->update(['updated_at' => $past]);
        $this->row($order, ['submission_uid' => 'SUB-1', 'document_uuid' => 'U-1']);

        $this->sync();

        $fresh = $order->fresh();
        $this->assertSame(Status::VALID, $fresh->myinvois_status);
        $this->assertTrue($past->equalTo($fresh->updated_at));
    }

    #[Test]
    public function sweeping_a_superseded_row_does_not_flip_the_resubmitted_parent() : void
    {
        $this->lhdn(['SUB-OLD' => ['U-OLD' => 'Invalid']]);
        $order = Order::create();
        $this->row($order, ['submission_uid' => 'SUB-OLD', 'document_uuid' => 'U-OLD', 'status' => 'submitted']);
        $this->row($order, ['submission_uid' => 'SUB-NEW', 'document_uuid' => 'U-NEW', 'status' => 'valid']);

        $this->sync();

        $this->assertSame('invalid', MyinvoisDocument::where('document_uuid', 'U-OLD')->first()->status->value);
        $this->assertSame(Status::VALID, $order->fresh()->myinvois_status);
    }

    // ---- registration --------------------------------------------------

    #[Test]
    public function the_command_is_registered() : void
    {
        $this->assertArrayHasKey('myinvois:sync', Artisan::all());
    }

    protected function syncEvents() : array
    {
        $this->app->forgetInstance(Schedule::class);

        return collect($this->app->make(Schedule::class)->events())
            ->filter(fn (ScheduledEvent $event) => str_contains($event->command, 'myinvois:sync'))
            ->values()
            ->all();
    }

    #[Test]
    public function nothing_is_scheduled_unless_the_app_opts_in() : void
    {
        $this->assertSame([], $this->syncEvents());

        config()->set('services.myinvois.sync_schedule', '');
        $this->assertSame([], $this->syncEvents());
    }

    #[Test]
    public function hourly_schedules_the_sweep_without_overlapping_on_one_server() : void
    {
        config()->set('services.myinvois.sync_schedule', 'hourly');

        $events = $this->syncEvents();

        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertSame(30, $events[0]->expiresAt);
        $this->assertTrue($events[0]->onOneServer);
    }

    #[Test]
    public function a_cron_expression_is_used_as_given() : void
    {
        config()->set('services.myinvois.sync_schedule', '*/15 * * * *');

        $events = $this->syncEvents();

        $this->assertCount(1, $events);
        $this->assertSame('*/15 * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->onOneServer);
    }
}
