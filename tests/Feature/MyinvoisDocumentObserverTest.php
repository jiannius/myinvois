<?php

namespace Jiannius\Myinvois\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Jiannius\Myinvois\Enums\Status;
use Jiannius\Myinvois\Events\DocumentStatusChanged;
use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Tests\Fixtures\Order;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class MyinvoisDocumentObserverTest extends TestCase
{
    protected function child(Order $order, array $attrs = []) : MyinvoisDocument
    {
        return MyinvoisDocument::create([
            'parent_type' => Order::class,
            'parent_id' => $order->id,
            ...$attrs,
        ]);
    }

    #[Test]
    public function saving_a_prod_document_writes_status_to_the_parent() : void
    {
        $order = Order::create();

        $this->child($order, ['status' => 'valid', 'is_preprod' => false]);

        $this->assertSame(Status::VALID, $order->fresh()->myinvois_status);
        $this->assertNull($order->fresh()->myinvois_preprod_status);
    }

    #[Test]
    public function saving_a_preprod_document_writes_to_the_preprod_column() : void
    {
        $order = Order::create();

        $this->child($order, ['status' => 'submitted', 'is_preprod' => true]);

        $this->assertSame(Status::SUBMITTED, $order->fresh()->myinvois_preprod_status);
        $this->assertNull($order->fresh()->myinvois_status);
    }

    #[Test]
    public function deleting_a_document_clears_the_parent_status() : void
    {
        $order = Order::create();
        $doc = $this->child($order, ['status' => 'valid', 'is_preprod' => false]);

        $this->assertSame(Status::VALID, $order->fresh()->myinvois_status);

        $doc->delete();

        $this->assertNull($order->fresh()->myinvois_status);
    }

    #[Test]
    public function re_saving_an_old_submission_does_not_overwrite_the_latest_status() : void
    {
        $order = Order::create();

        $old = $this->child($order, ['status' => 'invalid', 'is_preprod' => false]);
        $this->child($order, ['status' => 'valid', 'is_preprod' => false]);

        $this->assertSame(Status::VALID, $order->fresh()->myinvois_status);

        // e.g. an hourly status sync touching a superseded submission
        $old->update(['status' => 'invalid', 'document_number' => 'INV-OLD']);

        $this->assertSame(Status::VALID, $order->fresh()->myinvois_status);
    }

    #[Test]
    public function saving_the_latest_submission_still_updates_the_parent() : void
    {
        $order = Order::create();

        $this->child($order, ['status' => 'invalid', 'is_preprod' => false]);
        $latest = $this->child($order, ['status' => 'submitted', 'is_preprod' => false]);

        $this->assertSame(Status::SUBMITTED, $order->fresh()->myinvois_status);

        $latest->update(['status' => 'valid']);

        $this->assertSame(Status::VALID, $order->fresh()->myinvois_status);
    }

    #[Test]
    public function a_newer_preprod_submission_does_not_affect_the_prod_status() : void
    {
        $order = Order::create();

        $this->child($order, ['status' => 'valid', 'is_preprod' => false]);
        $this->child($order, ['status' => 'invalid', 'is_preprod' => true]);

        $order = $order->fresh();

        $this->assertSame(Status::VALID, $order->myinvois_status);
        $this->assertSame(Status::INVALID, $order->myinvois_preprod_status);
    }

    #[Test]
    public function latest_is_resolved_per_environment() : void
    {
        $order = Order::create();

        // The prod row is older than the preprod row, but it is still the
        // latest PROD submission, so saving it must update the prod status.
        $prod = $this->child($order, ['status' => 'submitted', 'is_preprod' => false]);
        $this->child($order, ['status' => 'valid', 'is_preprod' => true]);

        $prod->update(['status' => 'valid']);

        $order = $order->fresh();

        $this->assertSame(Status::VALID, $order->myinvois_status);
        $this->assertSame(Status::VALID, $order->myinvois_preprod_status);
    }

    #[Test]
    public function a_null_is_preprod_row_counts_as_prod_like_the_latest_relation() : void
    {
        $order = Order::create();

        $old = $this->child($order, ['status' => 'invalid', 'is_preprod' => null]);
        $this->child($order, ['status' => 'valid', 'is_preprod' => false]);

        $old->update(['status' => 'invalid']);

        $this->assertSame(Status::VALID, $order->fresh()->myinvois_status);
    }

    #[Test]
    public function the_status_write_does_not_touch_other_parents() : void
    {
        $a = Order::create();
        $b = Order::create();

        $this->child($a, ['status' => 'valid', 'is_preprod' => false]);
        $this->child($b, ['status' => 'invalid', 'is_preprod' => false]);

        $this->assertSame(Status::VALID, $a->fresh()->myinvois_status);
        $this->assertSame(Status::INVALID, $b->fresh()->myinvois_status);
    }

    #[Test]
    public function a_status_change_fires_document_status_changed_from_any_path() : void
    {
        Event::fake([DocumentStatusChanged::class]);
        $order = Order::create();

        $doc = $this->child($order, ['status' => 'submitted', 'is_preprod' => false]);

        Event::assertDispatched(DocumentStatusChanged::class, fn ($e) => $e->document->is($doc)
            && $e->from === null && $e->to === Status::SUBMITTED);

        $doc->update(['status' => 'valid']);

        Event::assertDispatched(DocumentStatusChanged::class, fn ($e) => $e->from === Status::SUBMITTED && $e->to === Status::VALID);

        // a row with no parent still announces
        MyinvoisDocument::create(['status' => 'invalid']);
        Event::assertDispatchedTimes(DocumentStatusChanged::class, 3);
    }

    #[Test]
    public function a_save_that_leaves_the_status_alone_fires_nothing() : void
    {
        Event::fake([DocumentStatusChanged::class]);
        $doc = $this->child(Order::create(), ['status' => 'valid', 'is_preprod' => false]);

        $doc->update(['status' => 'valid', 'document_number' => 'INV-X']);
        $doc->update(['response' => ['a' => 1]]);

        Event::assertDispatchedTimes(DocumentStatusChanged::class, 1);
    }

    #[Test]
    public function a_superseded_row_still_announces_its_own_change_without_moving_the_parent() : void
    {
        Event::fake([DocumentStatusChanged::class]);
        $order = Order::create();
        $old = $this->child($order, ['status' => 'submitted', 'is_preprod' => false]);
        $this->child($order, ['status' => 'valid', 'is_preprod' => false]);

        $old->update(['status' => 'invalid']);

        Event::assertDispatched(DocumentStatusChanged::class, fn ($e) => $e->document->is($old) && $e->to === Status::INVALID);
        $this->assertSame(Status::VALID, $order->fresh()->myinvois_status);
    }

    #[Test]
    public function writing_the_parent_status_does_not_bump_its_updated_at() : void
    {
        $order = Order::create();
        $past = now()->subDays(3)->startOfSecond();

        DB::table('orders')->where('id', $order->id)->update(['updated_at' => $past]);

        $this->child($order, ['status' => 'valid', 'is_preprod' => false]);

        $fresh = $order->fresh();

        $this->assertSame(Status::VALID, $fresh->myinvois_status);
        $this->assertTrue($past->equalTo($fresh->updated_at), 'the parent updated_at must not move');
    }
}
