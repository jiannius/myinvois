<?php

namespace Jiannius\Myinvois\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Myinvois;
use Jiannius\Myinvois\Tests\Fixtures\CertFixture;
use Jiannius\Myinvois\Tests\Fixtures\DocumentFixture;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SubmitDocumentsTest extends TestCase
{
    protected function myinvois() : Myinvois
    {
        return (new Myinvois)
            ->setClientId('id')
            ->setClientSecret('secret')
            ->setPrivateKey(CertFixture::privateKey())
            ->setCertificate(CertFixture::certificate());
    }

    /** Fake token + a documentsubmissions body that serves both POST and the polling GET. */
    protected function fakeSubmission(array $accepted) : void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documentsubmissions*' => Http::response([
                'submissionUid' => 'SUB1',
                'acceptedDocuments' => $accepted,
                'documentSummary' => collect($accepted)->map(fn ($a) => [
                    'uuid' => $a['uuid'],
                    'submissionUid' => 'SUB1',
                    'status' => 'Valid',
                ])->all(),
            ]),
        ]);
    }

    #[Test]
    public function it_throws_without_a_private_key_or_certificate() : void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Missing private key / certificate');

        (new Myinvois)->setClientId('id')->setClientSecret('secret')
            ->submitDocuments([DocumentFixture::invoice()]);
    }

    #[Test]
    public function it_signs_and_posts_a_base64_document_with_a_matching_hash() : void
    {
        $this->fakeSubmission([['uuid' => 'UID1', 'invoiceCodeNumber' => 'INV-0001']]);

        $this->myinvois()->submitDocuments([DocumentFixture::invoice()]);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), 'documentsubmissions')) {
                return false;
            }

            $payload = $request['documents'][0] ?? null;
            if (! $payload) return false;

            $json = base64_decode($payload['document']);

            return $payload['format'] === 'JSON'
                && $payload['codeNumber'] === 'INV-0001'
                && $payload['documentHash'] === hash('sha256', $json)
                && str_contains($json, 'UBLExtensions'); // signature block embedded
        });
    }

    #[Test]
    public function it_persists_one_record_for_a_standard_document() : void
    {
        $this->fakeSubmission([['uuid' => 'UID1', 'invoiceCodeNumber' => 'INV-0001']]);

        $result = $this->myinvois()->submitDocuments([DocumentFixture::invoice()]);

        $this->assertArrayHasKey('myinvois_documents', $result);
        $this->assertSame(1, MyinvoisDocument::count());

        $doc = MyinvoisDocument::first();
        $this->assertSame('INV-0001', $doc->document_number);
        $this->assertSame('UID1', $doc->document_uuid);
        $this->assertSame('SUB1', $doc->submission_uid);
        $this->assertNull($doc->consolidate_number);
        // polling flipped submitted -> valid
        $this->assertSame('valid', $doc->status->value);
    }

    #[Test]
    public function it_persists_one_record_per_line_for_a_consolidated_document() : void
    {
        $this->fakeSubmission([['uuid' => 'UID1', 'invoiceCodeNumber' => 'INV-0001']]);

        $this->myinvois()->submitDocuments([DocumentFixture::consolidated()]);

        $this->assertSame(2, MyinvoisDocument::count());

        $numbers = MyinvoisDocument::pluck('document_number')->all();
        $this->assertContains('Widget', $numbers);
        $this->assertContains('Receipt 1001-2000', $numbers);

        $this->assertSame(
            ['INV-0001', 'INV-0001'],
            MyinvoisDocument::pluck('consolidate_number')->all(),
        );
    }
}
