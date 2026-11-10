<?php

namespace Jiannius\Myinvois\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Jiannius\Myinvois\Events\SubmissionAccepted;
use Jiannius\Myinvois\Jobs\SubmitDocuments;
use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Myinvois;
use Jiannius\Myinvois\Tests\Fixtures\CertFixture;
use Jiannius\Myinvois\Tests\Fixtures\DocumentFixture;
use Jiannius\Myinvois\Tests\Fixtures\Order;
use Jiannius\Myinvois\Tests\Fixtures\OrgOrder;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * jiannius/myinvois#13: a consolidated document's parent can be keyed by its document
 * number, and a line description shared by two documents of one batch is never guessed.
 */
class ConsolidatedParentKeyTest extends TestCase
{
    protected function myinvois() : Myinvois
    {
        return (new Myinvois)
            ->setClientId('id')
            ->setClientSecret('secret')
            ->setPreprod(false)
            ->setPrivateKey(CertFixture::privateKey())
            ->setCertificate(CertFixture::certificate());
    }

    protected function consolidated(string $number) : array
    {
        $document = DocumentFixture::consolidated(['number' => $number]);
        $document['line_items'] = [[
            'qty' => 1,
            'description' => 'Audit Services',
            'unit_price' => 100,
            'subtotal' => 100,
            'classifications' => [['code' => '004']],
        ]];

        return $document;
    }

    protected function acceptBoth() : void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documentsubmissions*' => Http::response([
                'submissionUid' => 'SUB1',
                'acceptedDocuments' => [
                    ['uuid' => 'UIDA', 'invoiceCodeNumber' => 'CON-A'],
                    ['uuid' => 'UIDB', 'invoiceCodeNumber' => 'CON-B'],
                ],
            ], 202),
        ]);
    }

    #[Test]
    public function two_consolidated_documents_sharing_a_line_description_each_link_to_their_own_parent() : void
    {
        $this->acceptBoth();
        $a = Order::create();
        $b = Order::create();

        $this->myinvois()
            ->forParents(['CON-A' => $a, 'CON-B' => $b])
            ->withoutPolling()
            ->submitDocuments([$this->consolidated('CON-A'), $this->consolidated('CON-B')]);

        $this->assertSame($a->id, MyinvoisDocument::where('consolidate_number', 'CON-A')->value('parent_id'));
        $this->assertSame($b->id, MyinvoisDocument::where('consolidate_number', 'CON-B')->value('parent_id'));
        $this->assertSame(['Audit Services', 'Audit Services'], MyinvoisDocument::pluck('document_number')->all());
    }

    #[Test]
    public function a_line_description_shared_by_two_documents_is_refused_before_anything_is_sent() : void
    {
        Http::fake();
        $order = Order::create();

        try {
            $this->myinvois()
                ->forParents(['Audit Services' => $order])
                ->submitDocuments([$this->consolidated('CON-A'), $this->consolidated('CON-B')]);
            $this->fail('Expected InvalidArgumentException.');
        }
        catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Audit Services', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function a_document_number_key_wins_over_a_line_key() : void
    {
        $this->acceptBoth();
        $a = Order::create();
        $b = Order::create();
        $other = Order::create();

        // 'Audit Services' also names a line of CON-A, but CON-A has its own key
        $this->myinvois()
            ->forParents(['CON-A' => $a, 'CON-B' => $b, 'Audit Services' => $other])
            ->withoutPolling()
            ->submitDocuments([$this->consolidated('CON-A'), $this->consolidated('CON-B')]);

        $this->assertSame([$a->id, $b->id], MyinvoisDocument::orderBy('consolidate_number')->pluck('parent_id')->all());
    }

    #[Test]
    public function queued_documents_keyed_by_number_link_and_announce_both_parents() : void
    {
        Event::fake([SubmissionAccepted::class]);
        $this->acceptBoth();
        $a = OrgOrder::create();
        $b = OrgOrder::create();

        Queue::fake();
        Myinvois::queueSubmission([$this->consolidated('CON-A'), $this->consolidated('CON-B')], ['CON-A' => $a, 'CON-B' => $b]);

        $job = Queue::pushed(SubmitDocuments::class)->first();
        $job->withFakeQueueInteractions();
        $job->handle();

        $this->assertSame($a->id, MyinvoisDocument::where('consolidate_number', 'CON-A')->value('parent_id'));
        $this->assertSame($b->id, MyinvoisDocument::where('consolidate_number', 'CON-B')->value('parent_id'));

        Event::assertDispatched(SubmissionAccepted::class, fn ($e) => collect($e->parents)->pluck('id')->sort()->values()->all() === collect([$a->id, $b->id])->sort()->values()->all());
    }

    #[Test]
    public function the_fake_honours_document_number_keys() : void
    {
        $fake = Myinvois::fake();
        $a = Order::create();
        $b = Order::create();

        $fake->forParents(['CON-A' => $a, 'CON-B' => $b])->submitDocuments([$this->consolidated('CON-A'), $this->consolidated('CON-B')]);

        $this->assertSame([$a->id, $b->id], MyinvoisDocument::orderBy('consolidate_number')->pluck('parent_id')->all());
    }
}
