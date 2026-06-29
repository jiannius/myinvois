<?php

namespace Jiannius\Myinvois;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Jiannius\Myinvois\Helpers\Sample;
use Jiannius\Myinvois\Helpers\Signature;
use Jiannius\Myinvois\Helpers\UBL;
use Jiannius\Myinvois\Helpers\Validator;
use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Testing\MyinvoisFake;

class Myinvois
{
    public $settings = [];

    public $baseUrl = [
        'prod' => 'https://api.myinvois.hasil.gov.my',
        'preprod' => 'https://preprod-api.myinvois.hasil.gov.my',
    ];

    public $failedCallback;

    /**
     * Swap the `myinvois` container binding for an in-memory fake (no network,
     * no signing, no sleeping). Host-app code calling app('myinvois')->... then
     * hits the fake. Returns it so tests can configure and assert against it.
     */
    public static function fake() : MyinvoisFake
    {
        $fake = new MyinvoisFake();

        app()->instance('myinvois', $fake);

        return $fake;
    }

    /**
     * Set the client id
     */
    public function setClientId($value)
    {
        $this->settings['client_id'] = $value;
        return $this;
    }

    /**
     * Set the client secret
     */
    public function setClientSecret($value)
    {
        $this->settings['client_secret'] = $value;
        return $this;
    }

    /**
     * Set the preprod
     */
    public function setPreprod($value)
    {
        $this->settings['preprod'] = $value;
        return $this;
    }

    /**
     * Set the on behalf of
     */
    public function setOnBehalfOf($tin, $brn = null)
    {
        $this->settings['on_behalf_of'] = $brn && str($tin)->is('IG*') && !str($tin)->is('*:*')
            ? "$tin:$brn"
            : $tin;

        return $this;
    }

    /**
     * Set the private key
     */
    public function setPrivateKey($value)
    {
        $this->settings['private_key'] = $value;
        return $this;
    }

    /**
     * Set the certificate
     */
    public function setCertificate($value)
    {
        $this->settings['certificate'] = $value;
        return $this;
    }

    /**
     * Set the failed callback
     */
    public function setFailedCallback($callback)
    {
        $this->failedCallback = $callback;
        return $this;
    }

    /**
     * Get the settings
     */
    public function getSettings($key = null)
    {
        $preprod = is_bool(data_get($this->settings, 'preprod')) ? data_get($this->settings, 'preprod') : (
            is_bool(config('services.myinvois.preprod')) ? config('services.myinvois.preprod') : !app()->environment('production')
        );

        // Preprod and prod are separate LHDN environments with separate credentials.
        // Never fall back from one to the other — a missing preprod credential must
        // fail loud (see getToken) instead of silently authenticating with prod creds
        // against the preprod endpoint, which LHDN rejects as a misleading "invalid_client".
        $clientId = data_get($this->settings, 'client_id') ?? (
            $preprod ? config('services.myinvois.preprod_client_id') : config('services.myinvois.client_id')
        );

        $clientSecret = data_get($this->settings, 'client_secret') ?? (
            $preprod ? config('services.myinvois.preprod_client_secret') : config('services.myinvois.client_secret')
        );

        $settings = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'on_behalf_of' => data_get($this->settings, 'on_behalf_of'),
            'private_key' => data_get($this->settings, 'private_key') ?? config('services.myinvois.private_key'),
            'certificate' => data_get($this->settings, 'certificate') ?? config('services.myinvois.certificate'),
            'preprod' => $preprod,
        ];

        if (data_get($settings, 'on_behalf_of') === config('services.myinvois.client_tin')) {
            $settings['on_behalf_of'] = null;
        }

        // dd($settings);

        return $key ? data_get($settings, $key) : $settings;
    }

    /**
     * Get the endpoint
     */
    public function getEndpoint($uri)
    {
        $base = $this->getSettings('preprod') ? $this->baseUrl['preprod'] : $this->baseUrl['prod'];
        $prefix = str($uri)->startsWith('/') ? '' : '/api/v1.0/';

        return $base.$prefix.$uri;
    }

    /**
     * Get the token
     */
    public function getToken()
    {
        $clientId = $this->getSettings('client_id');
        $clientSecret = $this->getSettings('client_secret');
        $onBehalfOf = $this->getSettings('on_behalf_of');

        throw_if(!$clientId || !$clientSecret, \Exception::class, $this->getSettings('preprod')
            ? 'Missing MyInvois sandbox (preprod) Client ID / Client Secret'
            : 'Missing MyInvois Client ID / Client Secret');

        $cachekey = collect(['myinvois', $clientId, $onBehalfOf])->filter()->join('_');
        $cache = cache($cachekey);
        $token = data_get($cache, 'access_token');
        $expiry = data_get($cache, 'expired_at');

        if ($token && $expiry?->isFuture()) return $token;

        cache()->forget($cachekey);

        $url = $this->getEndpoint('/connect/token');

        $data = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'client_credentials',
            'scope' => 'InvoicingAPI',
        ];

        $http = Http::asForm();
        if ($onBehalfOf) $http->withHeaders(['onbehalfof' => $onBehalfOf]);

        $response = $http->post(url: $url, data: $data);

        if ($response->clientError()) abort($response->status(), $this->getTokenErrorMessage($response));

        $response->throw();

        cache()->put($cachekey, [
            ...$response->json(),
            'expired_at' => now()->addMinutes(50),
        ]);

        return $this->getToken();
    }

    /**
     * Build a human-friendly message for a failed /connect/token request.
     * LHDN returns either an OAuth-style {"error":"<code>"} body (e.g. invalid_client)
     * or a gateway-style {"statusCode":..,"message":".."} body on a 4xx.
     */
    protected function getTokenErrorMessage($response) : string
    {
        $code = data_get($response->json(), 'error') ?? data_get($response->json(), 'message');

        return match ($code) {
            'invalid_client' => 'MyInvois rejected the API credentials. Check that the MyInvois Client ID and Client Secret are correct and match the selected environment (production vs sandbox).',
            default => 'MyInvois authentication failed'.($code ? " ($code)" : " (HTTP {$response->status()})").'. Please try again or contact support.',
        };
    }

    /**
     * Call the API
     */
    public function callApi($uri, $method = 'GET', $data = [], $perMinute = null)
    {
        $method = strtolower($method);
        $token = $this->getToken();

        if (!$token) abort(500, 'Missing MyInvois API access token');

        if ($perMinute) {
            $timeout = 60 / $perMinute;
            $throttleKey = 'myinvois.'.str($uri)->replace('/', '-')->slug()->toString();
            $tooManyAttempts = RateLimiter::tooManyAttempts($throttleKey, $perMinute);

            if ($tooManyAttempts) {
                sleep($timeout);
                RateLimiter::clear($throttleKey);
            }
            else {
                RateLimiter::increment($throttleKey);
            }
        }

        $endpoint = $this->getEndpoint($uri);
        $result = Http::withToken($token)->$method($endpoint, $data);

        if ($result->failed()) {
            throw_if($result->status() === 403, 'Permissions denied from MyInvois Portal');
            if ($callback = $this->failedCallback) $result = $callback($result);
        }

        return $result;
    }

    /**
     * Search the taxpayer TIN
     */
    public function searchTaxpayerTIN($idType = null, $idValue = null, $taxpayerName = null)
    {
        $api = $this->callApi(
            uri: 'taxpayer/search/tin',
            data: [
                'idType' => $idType,
                'idValue' => $idValue,
                'taxpayerName' => $taxpayerName,
            ],
            perMinute: 60,
        );

        return data_get($api->json(), 'tin');
    }

    /**
     * Validate the taxpayer TIN
     */
    public function validateTaxpayerTIN($tin, $brn = null, $nric = null)
    {
        $idType = $brn ? 'BRN' : ($nric ? 'NRIC' : null);
        $idValue = $brn ?? $nric;

        $api = $this->callApi(
            uri: 'taxpayer/validate/'.$tin,
            data: [
                'idType' => $idType,
                'idValue' => $idValue,
            ],
        );

        return $api->ok();
    }

    /**
     * Get the recent documents
     */
    public function getRecentDocuments($data = [])
    {
        $api = $this->callApi(
            uri: 'documents/recent',
            data: $data,
            perMinute: 12,
        );

        return $api->json();
    }

    /**
     * Get the submission
     */
    public function getSubmission($uid, $pageNo = null, $pageSize = null)
    {
        $api = $this->callApi(
            uri: 'documentsubmissions/'.$uid,
            data: [
                'pageNo' => $pageNo,
                'pageSize' => $pageSize,
            ],
            perMinute: 60,
        );

        foreach (data_get($api->json(), 'documentSummary') ?? [] as $response) {
            $this->updateMyinvoisDocuments($response);
        }

        return $api->json();
    }

    /**
     * Get the document
     */
    public function getDocument($uid)
    {
        $api = $this->callApi(
            uri: 'documents/'.$uid.'/raw',
            perMinute: 60,
        );

        return $api->json();
    }

    /**
     * Get the document details
     */
    public function getDocumentDetails($uid)
    {
        $api = $this->callApi(
            uri: 'documents/'.$uid.'/details',
            perMinute: 60,
        );

        $this->updateMyinvoisDocuments($api->json());

        return $api->json();
    }

    /**
     * Search the documents
     */
    public function searchDocuments($data = [])
    {
        $api = $this->callApi(
            uri: 'documents/search',
            data: $data,
            perMinute: 12,
        );

        return $api->json();
    }

    /**
     * Submit the documents
     */
    public function submitDocuments($documents = [])
    {
        if ($documents === 'sample') $documents = [$this->getSample()];

        $pkey = $this->getSettings('private_key');
        $cert = $this->getSettings('certificate');

        throw_if(!$pkey || !$cert, \Exception::class, 'Missing private key / certificate');

        $api = $this->callApi(
            uri: 'documentsubmissions',
            method: 'POST',
            data: [
                'documents' => collect($documents)->map(function ($document) use ($pkey, $cert) {
                    $codeNumber = data_get($document, 'number');
                    $document = UBL::build($document);
                    $document = Signature::build($document, $pkey, $cert);
                    $json = json_encode($document);
                    $hash = hash('sha256', $json);
                    $base64 = base64_encode($json);

                    return [
                        'format' => 'JSON',
                        'document' => $base64,
                        'documentHash' => $hash,
                        'codeNumber' => $codeNumber,
                    ];
                })->toArray(),
            ],
            perMinute: 60,
        );

        if ($myinvoisDocuments = $this->createMyinvoisDocuments($api->json(), $documents)) {
            return [
                'myinvois_documents' => $myinvoisDocuments,
                'response' => $api->json(),
            ];
        }

        return $api->json();
    }

    /**
     * Cancel the document
     */
    public function cancelDocument($uid, $reason = null)
    {
        $api = $this->callApi(
            uri: 'documents/state/'.$uid.'/state',
            method: 'PUT',
            data: [
                'status' => 'cancelled',
                'reason' => $reason,
            ],
            perMinute: 12,
        );

        $this->updateMyinvoisDocuments([...$api->json(), 'reason' => $reason]);

        return $api->json();
    }

    /**
     * Reject the document
     */
    public function rejectDocument($uid, $reason = null)
    {
        $api = $this->callApi(
            uri: 'documents/state/'.$uid.'/state',
            data: [
                'status' => 'rejected',
                'reason' => $reason,
            ],
            perMinute: 12,
        );

        return $api->json();
    }

    /**
     * Create the myinvois documents
     */
    public function createMyinvoisDocuments($response, $documents)
    {
        if (!Schema::hasTable('myinvois_documents')) return;

        $submissionUid = data_get($response, 'submissionUid');
        $accepted = data_get($response, 'acceptedDocuments');

        if (!$accepted) return;

        $myinvoisDocuments = collect();
        $model = MyinvoisDocument::$useModel;

        foreach ($accepted as $data) {
            $document = collect($documents)->firstWhere('number', data_get($data, 'invoiceCodeNumber'));

            if (UBL::isConsolidated($document)) {
                foreach (data_get($document, 'line_items', []) as $lineItem) {
                    $myinvoisDocuments->push($model::create([
                        'document_uuid' => data_get($data, 'uuid'),
                        'submission_uid' => $submissionUid,
                        'document_number' => data_get($lineItem, 'description'),
                        'consolidate_number' => data_get($document, 'number'),
                        'status' => 'submitted',
                        'is_preprod' => $this->getSettings('preprod'),
                    ]));
                }
            }
            else {
                $myinvoisDocuments->push($model::create([
                    'document_uuid' => data_get($data, 'uuid'),
                    'submission_uid' => $submissionUid,
                    'document_number' => data_get($data, 'invoiceCodeNumber'),
                    'status' => 'submitted',
                    'is_preprod' => $this->getSettings('preprod'),
                ]));
            }
        }

        // immediately update the document status with max 2 retries
        $try = 1;
        $max = 3;
        while ($try <= $max) {
            sleep(2);
            $this->getSubmission($submissionUid);
            if ($model::where('submission_uid', $submissionUid)->where('status', 'submitted')->count()) $try++;
            else $try = $max + 1;
        }

        return $myinvoisDocuments;
    }

    /**
     * Update the myinvois documents
     */
    public function updateMyinvoisDocuments($response)
    {
        if (!Schema::hasTable('myinvois_documents')) return;

        $documentUuid = data_get($response, 'uuid');
        $submissionUid = data_get($response, 'submissionUid');
        $model = MyinvoisDocument::$useModel;
        $documents = $model::query()
            ->where('document_uuid', $documentUuid)
            ->when($submissionUid, fn ($q) => $q->where('submission_uid', $submissionUid));

        if (!$documents->count()) return;

        $documents->each(fn ($document) => $document->update([
            'status' => strtolower(data_get($response, 'status')),
            'response' => $response,
        ]));
    }

    /**
     * Get the validator
     */
    public function validator($document)
    {
        if ($document === 'sample') $document = $this->getSample();

        return app(Validator::class)->build($document);
    }

    /**
     * Get the sample
     */
    public function getSample()
    {
        return Sample::build();
    }
}
