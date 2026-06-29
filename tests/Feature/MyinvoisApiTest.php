<?php

namespace Jiannius\Myinvois\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Myinvois;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class MyinvoisApiTest extends TestCase
{
    protected function myinvois() : Myinvois
    {
        return (new Myinvois)->setClientId('id')->setClientSecret('secret');
    }

    /** Fake the token endpoint plus the given API route bodies. */
    protected function fakeApi(array $routes = []) : void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            ...$routes,
        ]);
    }

    // ---- endpoints & settings -----------------------------------------

    #[Test]
    public function it_builds_prod_and_preprod_endpoints() : void
    {
        $m = $this->myinvois();

        $this->assertSame(
            'https://preprod-api.myinvois.hasil.gov.my/api/v1.0/documents/recent',
            $m->setPreprod(true)->getEndpoint('documents/recent'),
        );
        $this->assertSame(
            'https://api.myinvois.hasil.gov.my/api/v1.0/documents/recent',
            $m->setPreprod(false)->getEndpoint('documents/recent'),
        );
    }

    #[Test]
    public function the_token_endpoint_skips_the_api_prefix() : void
    {
        $m = $this->myinvois()->setPreprod(true);

        $this->assertSame(
            'https://preprod-api.myinvois.hasil.gov.my/connect/token',
            $m->getEndpoint('/connect/token'),
        );
    }

    #[Test]
    public function it_defaults_to_preprod_outside_production() : void
    {
        // the testing environment is not production
        $this->assertTrue($this->myinvois()->getSettings('preprod'));
    }

    #[Test]
    public function explicit_setters_win_over_config() : void
    {
        config()->set('services.myinvois.client_id', 'from-config');

        $m = (new Myinvois)->setClientId('explicit');

        $this->assertSame('explicit', $m->getSettings('client_id'));
    }

    #[Test]
    public function on_behalf_of_formats_intermediary_tins() : void
    {
        $this->assertSame('IG123:BRN1', (new Myinvois)->setOnBehalfOf('IG123', 'BRN1')->getSettings('on_behalf_of'));
        $this->assertSame('C123', (new Myinvois)->setOnBehalfOf('C123', 'BRN1')->getSettings('on_behalf_of'));
        $this->assertSame('IG1:X', (new Myinvois)->setOnBehalfOf('IG1:X', 'BRN1')->getSettings('on_behalf_of'));
        $this->assertSame('IG123', (new Myinvois)->setOnBehalfOf('IG123')->getSettings('on_behalf_of'));
    }

    #[Test]
    public function on_behalf_of_is_nulled_when_it_equals_the_client_tin() : void
    {
        config()->set('services.myinvois.client_tin', 'C26561325060');

        $m = (new Myinvois)->setOnBehalfOf('C26561325060');

        $this->assertNull($m->getSettings('on_behalf_of'));
    }

    // ---- token ---------------------------------------------------------

    #[Test]
    public function it_fetches_and_caches_the_oauth_token() : void
    {
        $this->fakeApi();
        $m = $this->myinvois();

        $this->assertSame('tok', $m->getToken());
        $this->assertSame('tok', $m->getToken()); // served from cache

        Http::assertSentCount(1);
    }

    #[Test]
    public function it_throws_a_preprod_specific_message_without_sandbox_credentials() : void
    {
        // the testing environment defaults to preprod
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Missing MyInvois sandbox (preprod) Client ID / Client Secret');

        (new Myinvois)->getToken();
    }

    #[Test]
    public function it_throws_a_prod_specific_message_without_prod_credentials() : void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Missing MyInvois Client ID / Client Secret');

        (new Myinvois)->setPreprod(false)->getToken();
    }

    #[Test]
    public function preprod_does_not_fall_back_to_prod_credentials() : void
    {
        config()->set('services.myinvois.client_id', 'prod-id');
        config()->set('services.myinvois.client_secret', 'prod-secret');
        config()->set('services.myinvois.preprod_client_id', null);
        config()->set('services.myinvois.preprod_client_secret', null);

        $m = (new Myinvois)->setPreprod(true);

        $this->assertNull($m->getSettings('client_id'));
        $this->assertNull($m->getSettings('client_secret'));
    }

    #[Test]
    public function it_sends_the_onbehalfof_header_when_set() : void
    {
        $this->fakeApi();

        $this->myinvois()->setOnBehalfOf('IG123', 'BRN1')->getToken();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/connect/token')
            && $request->header('onbehalfof')[0] === 'IG123:BRN1');
    }

    #[Test]
    public function an_invalid_client_token_error_aborts_with_a_friendly_message() : void
    {
        Http::fake(['*/connect/token' => Http::response(['error' => 'invalid_client'], 400)]);

        try {
            $this->myinvois()->getToken();
            $this->fail('Expected an HttpException.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertStringContainsString('MyInvois rejected the API credentials', $e->getMessage());
        }
    }

    #[Test]
    public function an_unknown_token_error_surfaces_the_lhdn_code() : void
    {
        Http::fake(['*/connect/token' => Http::response(['error' => 'invalid_request'], 400)]);

        try {
            $this->myinvois()->getToken();
            $this->fail('Expected an HttpException.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertStringContainsString('MyInvois authentication failed (invalid_request)', $e->getMessage());
        }
    }

    // ---- read endpoints ------------------------------------------------

    #[Test]
    public function search_taxpayer_tin_returns_the_tin() : void
    {
        $this->fakeApi(['*taxpayer/search/tin*' => Http::response(['tin' => 'C999'])]);

        $tin = $this->myinvois()->searchTaxpayerTIN('BRN', '202101001341', 'ACME');

        $this->assertSame('C999', $tin);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/v1.0/taxpayer/search/tin'));
    }

    #[Test]
    public function validate_taxpayer_tin_is_true_on_ok() : void
    {
        $this->fakeApi(['*taxpayer/validate/*' => Http::response('', 200)]);

        $this->assertTrue($this->myinvois()->validateTaxpayerTIN('C123', '202101001341'));
    }

    #[Test]
    public function validate_taxpayer_tin_is_false_on_error() : void
    {
        $this->fakeApi(['*taxpayer/validate/*' => Http::response('', 404)]);

        $this->assertFalse($this->myinvois()->validateTaxpayerTIN('C123', '202101001341'));
    }

    #[Test]
    public function get_recent_and_search_documents_pass_through_json() : void
    {
        $this->fakeApi([
            '*documents/recent*' => Http::response(['result' => ['a']]),
            '*documents/search*' => Http::response(['result' => ['b']]),
        ]);

        $this->assertSame(['result' => ['a']], $this->myinvois()->getRecentDocuments());
        $this->assertSame(['result' => ['b']], $this->myinvois()->searchDocuments());
    }

    #[Test]
    public function get_document_fetches_the_raw_document() : void
    {
        $this->fakeApi(['*documents/UID1/raw*' => Http::response(['document' => 'raw'])]);

        $this->assertSame(['document' => 'raw'], $this->myinvois()->getDocument('UID1'));
    }

    #[Test]
    public function get_document_details_updates_the_local_record() : void
    {
        $doc = MyinvoisDocument::create(['document_uuid' => 'UID1', 'status' => 'submitted']);

        $this->fakeApi(['*documents/UID1/details*' => Http::response([
            'uuid' => 'UID1',
            'status' => 'Valid',
        ])]);

        $this->myinvois()->getDocumentDetails('UID1');

        $this->assertSame('valid', $doc->fresh()->status->value);
    }

    #[Test]
    public function a_403_response_throws_a_permissions_error() : void
    {
        $this->fakeApi(['*documents/recent*' => Http::response('', 403)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Permissions denied from MyInvois Portal');

        $this->myinvois()->getRecentDocuments();
    }

    // ---- state changes -------------------------------------------------

    #[Test]
    public function cancel_document_updates_the_local_record() : void
    {
        $doc = MyinvoisDocument::create(['document_uuid' => 'UID1', 'status' => 'valid']);

        $this->fakeApi(['*documents/state/UID1/state*' => Http::response([
            'uuid' => 'UID1',
            'status' => 'cancelled',
        ])]);

        $this->myinvois()->cancelDocument('UID1', 'Wrong amount');

        $this->assertSame('cancelled', $doc->fresh()->status->value);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), 'documents/state/UID1/state'));
    }

    #[Test]
    public function reject_document_posts_a_rejection() : void
    {
        $this->fakeApi(['*documents/state/UID1/state*' => Http::response(['status' => 'requested'])]);

        $result = $this->myinvois()->rejectDocument('UID1', 'Not mine');

        $this->assertSame(['status' => 'requested'], $result);
    }

    #[Test]
    public function get_submission_flips_local_document_status() : void
    {
        $doc = MyinvoisDocument::create([
            'document_uuid' => 'UID1',
            'submission_uid' => 'SUB1',
            'status' => 'submitted',
        ]);

        $this->fakeApi(['*documentsubmissions/SUB1*' => Http::response([
            'documentSummary' => [
                ['uuid' => 'UID1', 'submissionUid' => 'SUB1', 'status' => 'Valid'],
            ],
        ])]);

        $this->myinvois()->getSubmission('SUB1');

        $this->assertSame('valid', $doc->fresh()->status->value);
    }
}
