<?php

namespace Jiannius\Myinvois\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Tests\Fixtures\Order;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class HasMyinvoisDocumentTest extends TestCase
{
    protected int $tick = 0;

    /**
     * Create a document with a strictly increasing ULID (rows made in the same
     * millisecond could otherwise tie on the lexical max the relations rely on).
     */
    protected function doc(Order $order, array $attrs = []) : MyinvoisDocument
    {
        return MyinvoisDocument::create([
            'id' => (string) Str::ulid(Carbon::parse('2026-01-01 00:00:00')->addSeconds(++$this->tick)),
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
    public function production_latest_survives_a_newer_preprod_row() : void
    {
        $order = Order::create();
        $older = $this->doc($order, ['status' => 'valid', 'is_preprod' => false]);
        $this->doc($order, ['status' => 'invalid', 'is_preprod' => true]);

        $this->assertSame($older->id, Order::find($order->id)->latestMyinvoisDocument?->id);
    }

    #[Test]
    public function preprod_latest_survives_a_newer_production_row() : void
    {
        $order = Order::create();
        $older = $this->doc($order, ['status' => 'valid', 'is_preprod' => true]);
        $this->doc($order, ['status' => 'invalid', 'is_preprod' => false]);

        $this->assertSame($older->id, Order::find($order->id)->preprodLatestMyinvoisDocument?->id);
    }

    #[Test]
    public function a_null_preprod_row_is_the_production_latest_beside_a_newer_preprod_row() : void
    {
        $order = Order::create();
        $null = $this->doc($order, ['status' => 'valid', 'is_preprod' => null]);
        $preprod = $this->doc($order, ['status' => 'valid', 'is_preprod' => true]);

        $order = Order::find($order->id);

        $this->assertSame($null->id, $order->latestMyinvoisDocument?->id);
        $this->assertSame($preprod->id, $order->preprodLatestMyinvoisDocument?->id);
    }

    #[Test]
    public function the_latest_row_within_one_environment_is_still_the_highest_id() : void
    {
        $order = Order::create();
        $this->doc($order, ['status' => 'invalid', 'is_preprod' => false]);
        $this->doc($order, ['status' => 'invalid', 'is_preprod' => null]);
        $newest = $this->doc($order, ['status' => 'valid', 'is_preprod' => false]);
        $this->doc($order, ['status' => 'invalid', 'is_preprod' => true]);
        $newestPreprod = $this->doc($order, ['status' => 'valid', 'is_preprod' => true]);

        $order = Order::find($order->id);

        $this->assertSame($newest->id, $order->latestMyinvoisDocument->id);
        $this->assertSame($newestPreprod->id, $order->preprodLatestMyinvoisDocument->id);
    }

    #[Test]
    public function is_submitted_to_myinvois_is_not_hidden_by_the_other_environment() : void
    {
        $order = Order::create();
        $this->doc($order, ['status' => 'valid', 'is_preprod' => false]);
        $this->doc($order, ['status' => 'invalid', 'is_preprod' => true]);

        $order = Order::find($order->id);
        $this->assertTrue($order->isSubmittedToMyinvois());
        $this->assertFalse($order->isSubmittedToMyinvois(submitted: false));
        $this->assertFalse($order->isSubmittedToMyinvois(preprod: true));
        $this->assertTrue($order->isSubmittedToMyinvois(submitted: false, preprod: true));

        $other = Order::create();
        $this->doc($other, ['status' => 'submitted', 'is_preprod' => true]);
        $this->doc($other, ['status' => 'invalid', 'is_preprod' => false]);

        $other = Order::find($other->id);
        $this->assertTrue($other->isSubmittedToMyinvois(preprod: true));
        $this->assertFalse($other->isSubmittedToMyinvois(submitted: false, preprod: true));
        $this->assertFalse($other->isSubmittedToMyinvois());
        $this->assertTrue($other->isSubmittedToMyinvois(submitted: false));
    }

    #[Test]
    public function scope_with_submitted_myinvois_document_is_not_hidden_by_the_other_environment() : void
    {
        // submitted in production, a newer invalid row in preprod
        $prodSubmitted = Order::create();
        $this->doc($prodSubmitted, ['status' => 'valid', 'is_preprod' => false]);
        $this->doc($prodSubmitted, ['status' => 'invalid', 'is_preprod' => true]);

        // submitted in preprod, a newer invalid row in production
        $preprodSubmitted = Order::create();
        $this->doc($preprodSubmitted, ['status' => 'valid', 'is_preprod' => true]);
        $this->doc($preprodSubmitted, ['status' => 'invalid', 'is_preprod' => false]);

        $ids = fn (bool $submitted, bool $preprod) => Order::query()
            ->withSubmittedMyinvoisDocument($submitted, $preprod)->pluck('id');

        $this->assertEqualsCanonicalizing([$prodSubmitted->id], $ids(true, false)->all());
        $this->assertEqualsCanonicalizing([$preprodSubmitted->id], $ids(false, false)->all());
        $this->assertEqualsCanonicalizing([$preprodSubmitted->id], $ids(true, true)->all());
        $this->assertEqualsCanonicalizing([$prodSubmitted->id], $ids(false, true)->all());
    }

    #[Test]
    public function eager_loading_picks_the_latest_row_within_each_environment() : void
    {
        $a = Order::create();
        $this->doc($a, ['status' => 'valid', 'is_preprod' => null]);
        $this->doc($a, ['status' => 'valid', 'is_preprod' => true]);
        $this->doc($a, ['status' => 'invalid', 'is_preprod' => true]);
        $aProdNewer = $this->doc($a, ['status' => 'invalid', 'is_preprod' => false]);

        $b = Order::create();
        $bPreprod = $this->doc($b, ['status' => 'valid', 'is_preprod' => true]);
        $this->doc($b, ['status' => 'invalid', 'is_preprod' => false]);

        $c = Order::create();
        $bProd = $this->doc($c, ['status' => 'valid', 'is_preprod' => false]);
        $this->doc($c, ['status' => 'invalid', 'is_preprod' => true]);

        $orders = Order::with(['latestMyinvoisDocument', 'preprodLatestMyinvoisDocument'])
            ->whereKey([$a->id, $b->id, $c->id])->get()->keyBy('id');

        $this->assertSame($aProdNewer->id, $orders[$a->id]->latestMyinvoisDocument->id);
        $this->assertSame(
            MyinvoisDocument::where('parent_id', $a->id)->where('is_preprod', true)->max('id'),
            $orders[$a->id]->preprodLatestMyinvoisDocument->id,
        );

        $this->assertSame($bPreprod->id, $orders[$b->id]->preprodLatestMyinvoisDocument->id);
        $this->assertNotNull($orders[$b->id]->latestMyinvoisDocument);

        $this->assertSame($bProd->id, $orders[$c->id]->latestMyinvoisDocument->id);
        $this->assertNotNull($orders[$c->id]->preprodLatestMyinvoisDocument);
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
