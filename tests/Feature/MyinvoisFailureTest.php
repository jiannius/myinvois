<?php

namespace Jiannius\Myinvois\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Jiannius\Myinvois\Exceptions\MyinvoisAuthenticationException;
use Jiannius\Myinvois\Exceptions\MyinvoisException;
use Jiannius\Myinvois\Exceptions\MyinvoisPermissionException;
use Jiannius\Myinvois\Exceptions\MyinvoisUnavailableException;
use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Myinvois;
use Jiannius\Myinvois\Tests\Fixtures\CertFixture;
use Jiannius\Myinvois\Tests\Fixtures\DocumentFixture;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every LHDN / network failure must surface as a typed SDK exception (or, for
 * ordinary 4xx rejections, as the response body) -- never a TypeError, never a
 * failed response reported as success. See jiannius/myinvois#4.
 */
class MyinvoisFailureTest extends TestCase
{
    protected function myinvois() : Myinvois
    {
        return (new Myinvois)->setClientId('id')->setClientSecret('secret');
    }

    protected function fakeApi(array $routes = []) : void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            ...$routes,
        ]);
    }

    protected function okResponse(array $body) : \Illuminate\Http\Client\Response
    {
        return new \Illuminate\Http\Client\Response(Http::response($body)->wait());
    }

    // ---- 5xx -----------------------------------------------------------

    #[Test]
    public function cancel_document_on_a_5xx_html_body_throws_unavailable_not_a_type_error() : void
    {
        $doc = MyinvoisDocument::create(['document_uuid' => 'UID', 'status' => 'valid']);
        $this->fakeApi(['*/documents/state/*' => Http::response('<html>Service Unavailable</html>', 503)]);

        try {
            $this->myinvois()->cancelDocument('UID', 'Wrong amount');
            $this->fail('Expected MyinvoisUnavailableException.');
        } catch (MyinvoisUnavailableException $e) {
            $this->assertSame(503, $e->getStatus());
            $this->assertStringContainsString('/documents/state/UID/state', $e->getEndpoint());
            $this->assertSame('<html>Service Unavailable</html>', $e->getResponseBody());
            $this->assertNull($e->getResponseData());
            // the HTML never leaks into the user-facing message
            $this->assertStringNotContainsString('<html>', $e->getMessage());
        }

        $this->assertSame('valid', $doc->fresh()->status->value);
    }

    #[Test]
    public function cancel_document_on_a_5xx_empty_body_throws_unavailable() : void
    {
        $this->fakeApi(['*/documents/state/*' => Http::response('', 500)]);

        $this->expectException(MyinvoisUnavailableException::class);

        $this->myinvois()->cancelDocument('UID');
    }

    #[Test]
    public function get_document_details_on_a_5xx_throws_instead_of_reporting_success() : void
    {
        $doc = MyinvoisDocument::create(['document_uuid' => 'UID', 'status' => 'submitted']);
        $this->fakeApi(['*/documents/*/details' => Http::response('', 503)]);

        try {
            $this->myinvois()->getDocumentDetails('UID');
            $this->fail('Expected MyinvoisUnavailableException.');
        } catch (MyinvoisUnavailableException $e) {
            $this->assertSame(503, $e->getStatus());
        }

        $this->assertSame('submitted', $doc->fresh()->status->value);
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('readers')]
    public function every_json_reader_throws_on_a_5xx(string $method, array $args) : void
    {
        $this->fakeApi(['*/api/v1.0/*' => Http::response('<html>Bad Gateway</html>', 502)]);

        $this->expectException(MyinvoisUnavailableException::class);

        $this->myinvois()->$method(...$args);
    }

    public static function readers() : array
    {
        return [
            'searchTaxpayerTIN' => ['searchTaxpayerTIN', ['BRN', '202101001341', 'ACME']],
            'validateTaxpayerTIN' => ['validateTaxpayerTIN', ['C123', '202101001341']],
            'getRecentDocuments' => ['getRecentDocuments', []],
            'getSubmission' => ['getSubmission', ['SUB1']],
            'getDocument' => ['getDocument', ['UID']],
            'getDocumentDetails' => ['getDocumentDetails', ['UID']],
            'searchDocuments' => ['searchDocuments', []],
            'rejectDocument' => ['rejectDocument', ['UID', 'no']],
            'cancelDocument' => ['cancelDocument', ['UID', 'no']],
        ];
    }

    #[Test]
    public function submit_documents_on_a_5xx_throws_and_creates_no_local_rows() : void
    {
        $this->fakeApi(['*documentsubmissions*' => Http::response('<html>oops</html>', 503)]);

        try {
            $this->myinvois()
                ->setPrivateKey(CertFixture::privateKey())
                ->setCertificate(CertFixture::certificate())
                ->submitDocuments([DocumentFixture::invoice()]);
            $this->fail('Expected MyinvoisUnavailableException.');
        } catch (MyinvoisUnavailableException $e) {
            $this->assertSame(503, $e->getStatus());
        }

        $this->assertSame(0, MyinvoisDocument::count());
    }

    // ---- polling after a successful submission ---------------------------

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('pollingFailures')]
    public function a_failing_status_poll_after_an_accepted_submission_does_not_throw(int $pollStatus) : void
    {
        $this->fakeApi(['*documentsubmissions*' => fn ($request) => $request->method() === 'POST'
            ? Http::response(['submissionUid' => 'SUB1', 'acceptedDocuments' => [['uuid' => 'UID1', 'invoiceCodeNumber' => 'INV-0001']]], 202)
            : Http::response('<html>nope</html>', $pollStatus)]);

        $result = $this->myinvois()
            ->setPrivateKey(CertFixture::privateKey())
            ->setCertificate(CertFixture::certificate())
            ->submitDocuments([DocumentFixture::invoice()]);

        $this->assertArrayHasKey('myinvois_documents', $result);
        $this->assertSame('SUB1', data_get($result, 'response.submissionUid'));
        $this->assertCount(1, $result['myinvois_documents']);
        $this->assertSame(1, MyinvoisDocument::count());
        $this->assertSame('submitted', MyinvoisDocument::first()->status->value);
    }

    public static function pollingFailures() : array
    {
        return [
            '502' => [502],
            '429' => [429],
            '401' => [401],
            '403' => [403],
        ];
    }

    // ---- 429 -----------------------------------------------------------

    #[Test]
    public function a_429_throws_unavailable_with_the_retry_after_hint() : void
    {
        $this->fakeApi(['*documents/recent*' => Http::response('', 429, ['Retry-After' => '30'])]);

        try {
            $this->myinvois()->getRecentDocuments();
            $this->fail('Expected MyinvoisUnavailableException.');
        } catch (MyinvoisUnavailableException $e) {
            $this->assertSame(429, $e->getStatus());
            $this->assertSame(30, $e->getRetryAfter());
        }
    }

    // ---- 401 / 403 -----------------------------------------------------

    #[Test]
    public function a_403_throws_a_permission_exception_with_the_original_message() : void
    {
        $this->fakeApi(['*/documents/*' => Http::response([], 403)]);

        try {
            $this->myinvois()->getDocument('UID');
            $this->fail('Expected MyinvoisPermissionException.');
        } catch (MyinvoisPermissionException $e) {
            $this->assertSame('Permissions denied from MyInvois Portal', $e->getMessage());
            $this->assertSame(403, $e->getStatus());
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }
    }

    #[Test]
    public function a_403_does_not_run_the_failed_callback() : void
    {
        $this->fakeApi(['*documents/recent*' => Http::response('', 403)]);
        $called = false;

        try {
            $this->myinvois()->setFailedCallback(function ($r) use (&$called) {
                $called = true;
                return $r;
            })->getRecentDocuments();
        } catch (MyinvoisPermissionException) {
        }

        $this->assertFalse($called);
    }

    protected function requestsTo(string $needle) : int
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), $needle))->count();
    }

    #[Test]
    public function a_401_drops_the_cached_token_and_retries_once_with_a_fresh_one() : void
    {
        $this->fakeApi(['*documents/recent*' => Http::sequence()
            ->push(['message' => 'expired'], 401)
            ->push(['result' => ['ok']], 200)]);
        $called = 0;

        $result = $this->myinvois()
            ->setFailedCallback(function ($r) use (&$called) {
                $called++;
                return $r;
            })
            ->getRecentDocuments();

        $this->assertSame(['result' => ['ok']], $result);
        $this->assertSame(2, $this->requestsTo('/connect/token'));
        $this->assertSame(2, $this->requestsTo('documents/recent'));
        // the retry recovered, so there was no failure to call back about
        $this->assertSame(0, $called);
    }

    #[Test]
    public function a_second_401_throws_an_authentication_exception_and_is_not_retried_again() : void
    {
        $this->fakeApi(['*documents/recent*' => Http::sequence()
            ->push(['message' => 'expired'], 401)
            ->push(['message' => 'still bad'], 401)
            ->push(['result' => ['never reached']], 200)]);
        $called = 0;

        try {
            $this->myinvois()
                ->setFailedCallback(function ($r) use (&$called) {
                    $called++;
                    return $r;
                })
                ->getRecentDocuments();
            $this->fail('Expected MyinvoisAuthenticationException.');
        } catch (MyinvoisAuthenticationException $e) {
            $this->assertSame(401, $e->getStatus());
            $this->assertSame(['message' => 'still bad'], $e->getResponseData());
            $this->assertStringContainsString('after refreshing', $e->getMessage());
        }

        $this->assertSame(2, $this->requestsTo('documents/recent'));
        $this->assertSame(2, $this->requestsTo('/connect/token'));
        // failedCallback runs once, on the final response
        $this->assertSame(1, $called);
    }

    #[Test]
    public function a_403_is_not_retried() : void
    {
        $this->fakeApi(['*documents/recent*' => Http::response('', 403)]);

        try {
            $this->myinvois()->getRecentDocuments();
        } catch (MyinvoisPermissionException) {
        }

        $this->assertSame(1, $this->requestsTo('documents/recent'));
        $this->assertSame(1, $this->requestsTo('/connect/token'));
    }

    // ---- token ---------------------------------------------------------

    #[Test]
    public function a_token_4xx_throws_an_authentication_exception_with_the_friendly_message() : void
    {
        Http::fake(['*/connect/token' => Http::response(['error' => 'invalid_client'], 400)]);

        try {
            $this->myinvois()->getToken();
            $this->fail('Expected MyinvoisAuthenticationException.');
        } catch (MyinvoisAuthenticationException $e) {
            $this->assertSame(400, $e->getStatus());
            $this->assertStringContainsString('MyInvois rejected the API credentials', $e->getMessage());
            $this->assertStringContainsString('/connect/token', $e->getEndpoint());
            $this->assertSame(['error' => 'invalid_client'], $e->getResponseData());
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }
    }

    #[Test]
    public function a_token_4xx_with_a_non_json_body_does_not_crash() : void
    {
        Http::fake(['*/connect/token' => Http::response('<html>Forbidden</html>', 403)]);

        try {
            $this->myinvois()->getToken();
            $this->fail('Expected MyinvoisAuthenticationException.');
        } catch (MyinvoisAuthenticationException $e) {
            $this->assertSame(403, $e->getStatus());
            $this->assertStringContainsString('HTTP 403', $e->getMessage());
        }
    }

    #[Test]
    public function a_token_5xx_throws_unavailable() : void
    {
        Http::fake(['*/connect/token' => Http::response('<html>down</html>', 503)]);

        try {
            $this->myinvois()->getToken();
            $this->fail('Expected MyinvoisUnavailableException.');
        } catch (MyinvoisUnavailableException $e) {
            $this->assertSame(503, $e->getStatus());
        }
    }

    #[Test]
    public function a_token_429_throws_unavailable() : void
    {
        Http::fake(['*/connect/token' => Http::response('', 429)]);

        $this->expectException(MyinvoisUnavailableException::class);

        $this->myinvois()->getToken();
    }

    #[Test]
    public function a_token_408_throws_unavailable_like_a_429() : void
    {
        Http::fake(['*/connect/token' => Http::response('', 408)]);

        try {
            $this->myinvois()->getToken();
            $this->fail('Expected MyinvoisUnavailableException.');
        } catch (MyinvoisUnavailableException $e) {
            $this->assertSame(408, $e->getStatus());
        }
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('oddTokenErrorBodies')]
    public function a_token_4xx_with_a_non_string_error_field_falls_back_to_the_status(array $body) : void
    {
        Http::fake(['*/connect/token' => Http::response($body, 400)]);

        try {
            $this->myinvois()->getToken();
            $this->fail('Expected MyinvoisAuthenticationException.');
        } catch (MyinvoisAuthenticationException $e) {
            $this->assertStringContainsString('(HTTP 400)', $e->getMessage());
        }
    }

    public static function oddTokenErrorBodies() : array
    {
        return [
            'array error' => [['error' => ['a', 'b']]],
            'object message' => [['message' => ['detail' => 'x']]],
            'numeric error' => [['error' => 42]],
        ];
    }

    #[Test]
    public function a_2xx_token_response_without_an_access_token_throws_instead_of_crashing() : void
    {
        Http::fake(['*/connect/token' => Http::response('<html>captive portal</html>', 200)]);

        $this->expectException(MyinvoisUnavailableException::class);

        $this->myinvois()->getToken();
    }

    #[Test]
    public function a_failed_token_request_is_not_cached() : void
    {
        Http::fake(['*/connect/token' => Http::sequence()
            ->push(['error' => 'invalid_client'], 400)
            ->push(['access_token' => 'tok', 'expires_in' => 3600])]);

        try {
            $this->myinvois()->getToken();
        } catch (MyinvoisAuthenticationException) {
        }

        $this->assertSame('tok', $this->myinvois()->getToken());
    }

    // ---- network -------------------------------------------------------

    /**
     * Build the exception Laravel throws for a network failure: a Laravel
     * ConnectionException wrapping a real Guzzle ConnectException that carries the
     * PSR-7 request (bearer token / client secret) and a message with the full URL.
     */
    protected function laravelConnectionException(string $url, array $headers = [], string $body = '') : ConnectionException
    {
        $guzzle = new \GuzzleHttp\Exception\ConnectException(
            "cURL error 28: Operation timed out after 30001 milliseconds (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for {$url}",
            new \GuzzleHttp\Psr7\Request('GET', $url, $headers, $body),
        );

        return new ConnectionException($guzzle->getMessage(), 0, $guzzle);
    }

    #[Test]
    public function a_network_failure_on_an_api_call_throws_unavailable_without_chaining_the_original() : void
    {
        $url = 'https://preprod-api.myinvois.hasil.gov.my/api/v1.0/taxpayer/search/tin?idType=NRIC&idValue=900101015555';
        $this->fakeApi(['*taxpayer/search/tin*' => fn () => throw $this->laravelConnectionException(
            $url,
            ['Authorization' => 'Bearer tok'],
        )]);

        try {
            $this->myinvois()->searchTaxpayerTIN('NRIC', '900101015555');
            $this->fail('Expected MyinvoisUnavailableException.');
        } catch (MyinvoisUnavailableException $e) {
            $this->assertNull($e->getStatus());
            $this->assertStringContainsString('taxpayer/search/tin', $e->getEndpoint());
            $this->assertStringNotContainsString('?', $e->getEndpoint());
            // the Guzzle exception (and with it the request + Authorization header) is not reachable
            $this->assertNull($e->getPrevious());
            $this->assertStringContainsString('timed out', $e->getReason());

            foreach ([$e->getMessage(), $e->getReason(), $e->getEndpoint(), (string) $e->getResponseBody()] as $exposed) {
                $this->assertStringNotContainsString('900101015555', $exposed);
                $this->assertStringNotContainsString('idValue', $exposed);
                $this->assertStringNotContainsString('tok', $exposed);
            }
        }
    }

    #[Test]
    public function a_network_failure_on_the_token_request_throws_unavailable_without_the_client_secret() : void
    {
        Http::fake(['*/connect/token' => fn () => throw $this->laravelConnectionException(
            'https://preprod-api.myinvois.hasil.gov.my/connect/token?client_secret=s3cr3t',
            [],
            'client_id=id&client_secret=s3cr3t',
        )]);

        try {
            $this->myinvois()->getToken();
            $this->fail('Expected MyinvoisUnavailableException.');
        } catch (MyinvoisUnavailableException $e) {
            $this->assertNull($e->getPrevious());
            $this->assertStringContainsString('/connect/token', $e->getEndpoint());

            foreach ([$e->getMessage(), $e->getReason(), $e->getEndpoint()] as $exposed) {
                $this->assertStringNotContainsString('s3cr3t', $exposed);
            }
        }
    }

    #[Test]
    public function a_network_failure_reason_without_a_url_is_kept_as_is() : void
    {
        $this->fakeApi(['*documents/recent*' => fn () => throw new ConnectionException('cURL error 6: Could not resolve host: api.example')]);

        try {
            $this->myinvois()->getRecentDocuments();
            $this->fail('Expected MyinvoisUnavailableException.');
        } catch (MyinvoisUnavailableException $e) {
            $this->assertSame('cURL error 6: Could not resolve host: api.example', $e->getReason());
        }
    }

    // ---- ordinary 4xx keep returning the body (backwards compatible) ----

    #[Test]
    public function a_4xx_rejection_on_submit_still_returns_the_lhdn_body() : void
    {
        $body = ['rejectedDocuments' => [['invoiceCodeNumber' => 'INV-0001', 'error' => ['code' => 'X']]]];
        $this->fakeApi(['*documentsubmissions*' => Http::response($body, 422)]);

        $result = $this->myinvois()
            ->setPrivateKey(CertFixture::privateKey())
            ->setCertificate(CertFixture::certificate())
            ->submitDocuments([DocumentFixture::invoice()]);

        $this->assertSame($body, $result);
        $this->assertSame(0, MyinvoisDocument::count());
    }

    #[Test]
    public function a_4xx_on_cancel_returns_the_error_body_and_leaves_the_local_record_alone() : void
    {
        $doc = MyinvoisDocument::create(['document_uuid' => 'UID', 'status' => 'valid']);
        $body = ['error' => ['code' => 'OperationPeriodOver', 'message' => 'too late']];
        $this->fakeApi(['*/documents/state/*' => Http::response($body, 400)]);

        $this->assertSame($body, $this->myinvois()->cancelDocument('UID'));
        $this->assertSame('valid', $doc->fresh()->status->value);
    }

    #[Test]
    public function a_4xx_on_cancel_with_a_non_json_body_does_not_crash() : void
    {
        $this->fakeApi(['*/documents/state/*' => Http::response('Not Found', 404)]);

        $this->assertNull($this->myinvois()->cancelDocument('UID'));
    }

    #[Test]
    public function a_2xx_cancel_with_an_empty_body_does_not_write_to_local_records() : void
    {
        $doc = MyinvoisDocument::create(['document_uuid' => null, 'status' => 'valid']);
        $this->fakeApi(['*/documents/state/*' => Http::response('', 200)]);

        $this->myinvois()->cancelDocument('UID', 'Wrong amount');

        $this->assertSame('valid', $doc->fresh()->status->value);
    }

    #[Test]
    public function a_2xx_get_document_details_with_a_non_json_body_does_not_write_to_local_records() : void
    {
        $doc = MyinvoisDocument::create(['document_uuid' => null, 'status' => 'submitted']);
        $this->fakeApi(['*/documents/*/details' => Http::response('OK', 200)]);

        $this->myinvois()->getDocumentDetails('UID');

        $this->assertSame('submitted', $doc->fresh()->status->value);
    }

    #[Test]
    public function a_404_on_get_document_details_does_not_touch_local_records() : void
    {
        $doc = MyinvoisDocument::create(['document_uuid' => 'UID', 'status' => 'submitted']);
        $this->fakeApi(['*/documents/*/details' => Http::response(['status' => 'Invalid'], 404)]);

        $this->myinvois()->getDocumentDetails('UID');

        $this->assertSame('submitted', $doc->fresh()->status->value);
    }

    #[Test]
    public function a_taxpayer_tin_404_still_means_not_found() : void
    {
        $this->fakeApi([
            '*taxpayer/search/tin*' => Http::response('', 404),
            '*taxpayer/validate/*' => Http::response('', 404),
        ]);

        $this->assertNull($this->myinvois()->searchTaxpayerTIN('BRN', '1'));
        $this->assertFalse($this->myinvois()->validateTaxpayerTIN('C1', '1'));
    }

    // ---- failed callback -----------------------------------------------

    #[Test]
    public function the_failed_callback_still_runs_for_a_4xx_and_its_return_value_replaces_the_response() : void
    {
        $this->fakeApi(['*documents/recent*' => Http::response(['error' => 'x'], 400)]);
        $seen = null;

        $result = $this->myinvois()->setFailedCallback(function ($response) use (&$seen) {
            $seen = $response->status();
            return $this->okResponse(['result' => 'replaced']);
        })->getRecentDocuments();

        $this->assertSame(400, $seen);
        $this->assertSame(['result' => 'replaced'], $result);
    }

    #[Test]
    public function the_failed_callback_runs_on_a_5xx_and_a_still_failed_response_then_throws() : void
    {
        $this->fakeApi(['*documents/recent*' => Http::response('', 503)]);
        $seen = null;

        try {
            $this->myinvois()->setFailedCallback(function ($response) use (&$seen) {
                $seen = $response->status();
                return $response;
            })->getRecentDocuments();
            $this->fail('Expected MyinvoisUnavailableException.');
        } catch (MyinvoisUnavailableException) {
        }

        $this->assertSame(503, $seen);
    }

    #[Test]
    public function a_failed_callback_that_recovers_a_5xx_suppresses_the_exception() : void
    {
        $this->fakeApi(['*documents/recent*' => Http::response('', 503)]);

        $result = $this->myinvois()->setFailedCallback(
            fn () => $this->okResponse(['result' => 'fallback'])
        )->getRecentDocuments();

        $this->assertSame(['result' => 'fallback'], $result);
    }

    // ---- hierarchy -----------------------------------------------------

    #[Test]
    public function every_exception_is_a_myinvois_exception_and_a_runtime_exception() : void
    {
        foreach ([
            MyinvoisUnavailableException::class,
            MyinvoisAuthenticationException::class,
            MyinvoisPermissionException::class,
        ] as $class) {
            $e = new $class('x');

            $this->assertInstanceOf(MyinvoisException::class, $e);
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }
    }

    #[Test]
    public function the_exception_exposes_the_decoded_error_body() : void
    {
        $e = new MyinvoisException('x', 400, 'https://x/y', '{"error":{"code":"A"}}');

        $this->assertSame(400, $e->getStatus());
        $this->assertSame(400, $e->getCode());
        $this->assertSame('https://x/y', $e->getEndpoint());
        $this->assertSame(['error' => ['code' => 'A']], $e->getResponseData());
        $this->assertNull((new MyinvoisException('x'))->getResponseData());
    }
}
