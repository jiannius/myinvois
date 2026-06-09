<?php

namespace Jiannius\Myinvois\Tests\Feature;

use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Myinvois;
use Jiannius\Myinvois\Testing\MyinvoisFake;
use Jiannius\Myinvois\Tests\Fixtures\DocumentFixture;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;

class MyinvoisFakeTest extends TestCase
{
    #[Test]
    public function fake_swaps_the_container_binding() : void
    {
        $fake = Myinvois::fake();

        $this->assertInstanceOf(MyinvoisFake::class, $fake);
        $this->assertSame($fake, app('myinvois'));
    }

    #[Test]
    public function submitting_records_the_call_and_persists_a_row_without_keys_or_network() : void
    {
        $fake = Myinvois::fake();

        // no client id/secret, no private key, no certificate set
        $result = app('myinvois')->submitDocuments([DocumentFixture::invoice()]);

        $this->assertArrayHasKey('myinvois_documents', $result);
        $this->assertSame(1, MyinvoisDocument::count());

        $doc = MyinvoisDocument::first();
        $this->assertSame('INV-0001', $doc->document_number);
        $this->assertSame('valid', $doc->status->value);

        $fake->assertSubmitted();
        $fake->assertSubmittedCount(1);
        $fake->assertDocumentSubmitted('INV-0001');
    }

    #[Test]
    public function assert_submitted_accepts_a_matching_closure() : void
    {
        $fake = Myinvois::fake();

        app('myinvois')->submitDocuments([DocumentFixture::invoice()]);

        $fake->assertSubmitted(fn ($documents, $response) => data_get($documents, '0.number') === 'INV-0001'
            && data_get($response, 'submissionUid') === 'fake-submission');
    }

    #[Test]
    public function assert_nothing_submitted_passes_when_idle_and_fails_after_a_submit() : void
    {
        $fake = Myinvois::fake();
        $fake->assertNothingSubmitted();

        app('myinvois')->submitDocuments([DocumentFixture::invoice()]);

        $this->expectException(AssertionFailedError::class);
        $fake->assertNothingSubmitted();
    }

    #[Test]
    public function a_consolidated_submission_persists_one_row_per_line() : void
    {
        $fake = Myinvois::fake();

        app('myinvois')->submitDocuments([DocumentFixture::consolidated()]);

        $this->assertSame(2, MyinvoisDocument::count());
        $this->assertEqualsCanonicalizing(
            ['Widget', 'Receipt 1001-2000'],
            MyinvoisDocument::pluck('document_number')->all(),
        );
        $this->assertSame(['INV-0001', 'INV-0001'], MyinvoisDocument::pluck('consolidate_number')->all());
    }

    #[Test]
    public function the_resolved_status_is_configurable() : void
    {
        Myinvois::fake()->resolveStatus('invalid');

        app('myinvois')->submitDocuments([DocumentFixture::invoice()]);

        $this->assertSame('invalid', MyinvoisDocument::first()->status->value);
    }

    #[Test]
    public function cancelling_records_the_call_and_flips_the_local_status() : void
    {
        $fake = Myinvois::fake();
        MyinvoisDocument::create(['document_uuid' => 'UID1', 'status' => 'valid']);

        app('myinvois')->cancelDocument('UID1', 'Wrong amount');

        $fake->assertCancelled('UID1');
        $this->assertSame('cancelled', MyinvoisDocument::first()->status->value);
    }

    #[Test]
    public function rejecting_records_the_call() : void
    {
        $fake = Myinvois::fake();

        app('myinvois')->rejectDocument('UID1', 'Not mine');

        $fake->assertRejected('UID1');
        $fake->assertNotCancelled();
    }

    #[Test]
    public function read_endpoints_return_canned_responses() : void
    {
        Myinvois::fake()->respondToApi('taxpayer/search/*', ['tin' => 'C999']);

        $this->assertSame('C999', app('myinvois')->searchTaxpayerTIN('BRN', '202101001341'));
    }

    #[Test]
    public function validate_taxpayer_tin_defaults_to_true_and_honours_a_canned_error() : void
    {
        Myinvois::fake();
        $this->assertTrue(app('myinvois')->validateTaxpayerTIN('C123'));

        Myinvois::fake()->respondToApi('taxpayer/validate/*', [], 404);
        $this->assertFalse(app('myinvois')->validateTaxpayerTIN('C123'));
    }
}
