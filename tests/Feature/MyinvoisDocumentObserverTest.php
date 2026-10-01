<?php

namespace Jiannius\Myinvois\Tests\Feature;

use Jiannius\Myinvois\Enums\Status;
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
}
