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
}
