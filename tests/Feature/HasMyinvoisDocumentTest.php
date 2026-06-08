<?php

namespace Jiannius\Myinvois\Tests\Feature;

use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Tests\Fixtures\Order;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class HasMyinvoisDocumentTest extends TestCase
{
    protected function doc(Order $order, array $attrs = []) : MyinvoisDocument
    {
        return MyinvoisDocument::create([
            'parent_type' => Order::class,
            'parent_id' => $order->id,
            ...$attrs,
        ]);
    }

    #[Test]
    public function relations_separate_prod_and_preprod_documents() : void
    {
        $order = Order::create();
        $this->doc($order, ['status' => 'valid', 'is_preprod' => false]);
        $this->doc($order, ['status' => 'invalid', 'is_preprod' => false]);
        $this->doc($order, ['status' => 'valid', 'is_preprod' => true]);

        $this->assertSame(2, $order->myinvoisDocuments()->count());
        $this->assertSame(1, $order->preprodMyinvoisDocuments()->count());
    }

    #[Test]
    public function prod_relation_includes_documents_with_null_preprod() : void
    {
        $order = Order::create();
        $this->doc($order, ['status' => 'valid', 'is_preprod' => null]);

        $this->assertSame(1, $order->myinvoisDocuments()->count());
    }

    #[Test]
    public function is_submitted_to_myinvois_reflects_the_latest_document() : void
    {
        $order = Order::create();

        $this->assertFalse($order->isSubmittedToMyinvois());

        $this->doc($order, ['status' => 'valid', 'is_preprod' => false]);

        $this->assertTrue($order->fresh()->isSubmittedToMyinvois());
        $this->assertFalse($order->fresh()->isSubmittedToMyinvois(submitted: false));
    }

    #[Test]
    public function is_submitted_to_myinvois_supports_the_preprod_channel() : void
    {
        $order = Order::create();
        $this->doc($order, ['status' => 'submitted', 'is_preprod' => true]);

        $this->assertTrue($order->fresh()->isSubmittedToMyinvois(preprod: true));
        $this->assertFalse($order->fresh()->isSubmittedToMyinvois(preprod: false));
    }

    #[Test]
    public function scope_with_submitted_myinvois_document_filters_parents() : void
    {
        $submitted = Order::create();
        $this->doc($submitted, ['status' => 'valid', 'is_preprod' => false]);

        $notSubmitted = Order::create();
        $this->doc($notSubmitted, ['status' => 'invalid', 'is_preprod' => false]);

        $ids = Order::query()->withSubmittedMyinvoisDocument()->pluck('id');

        $this->assertTrue($ids->contains($submitted->id));
        $this->assertFalse($ids->contains($notSubmitted->id));
    }

    #[Test]
    public function it_exposes_the_validation_link_and_qr_code() : void
    {
        $order = Order::create();
        $this->doc($order, [
            'document_uuid' => 'U1',
            'status' => 'valid',
            'is_preprod' => false,
            'response' => ['longId' => 'LONG1'],
        ]);

        $order = $order->fresh();

        $this->assertSame('https://myinvois.hasil.gov.my/U1/share/LONG1', $order->getMyinvoisValidationLink());
        $this->assertStringStartsWith('data:image/png;base64,', $order->getMyinvoisQrCode());
    }

    #[Test]
    public function qr_code_is_empty_without_a_validation_link() : void
    {
        $order = Order::create();

        $this->assertSame('', $order->getMyinvoisQrCode());
    }

    #[Test]
    public function deleting_the_parent_cascades_to_its_documents() : void
    {
        $order = Order::create();
        $this->doc($order, ['status' => 'valid', 'is_preprod' => false]);
        $this->doc($order, ['status' => 'valid', 'is_preprod' => true]);

        $this->assertSame(2, MyinvoisDocument::count());

        $order->delete();

        $this->assertSame(0, MyinvoisDocument::count());
    }
}
