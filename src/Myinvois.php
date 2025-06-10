<?php

namespace Jiannius\Myinvois;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Jiannius\Myinvois\Helpers\Sample;
use Jiannius\Myinvois\Helpers\Signature;
use Jiannius\Myinvois\Helpers\UBL;
use Jiannius\Myinvois\Helpers\Validator;
use Jiannius\Myinvois\Models\MyinvoisDocument;

class Myinvois
{
    public $settings = [];

    public $baseUrl = [
        'prod' => 'https://api.myinvois.hasil.gov.my',
        'preprod' => 'https://preprod-api.myinvois.hasil.gov.my',
    ];

    public $failedCallback;

    public function setClientId($value)
    {
        $this->settings['client_id'] = $value;
        return $this;
    }

    public function setClientSecret($value)
    {
        $this->settings['client_secret'] = $value;
        return $this;
    }

    public function setPreprod($value)
    {
        $this->settings['preprod'] = $value;
        return $this;
    }

    public function setOnBehalfOf($value)
    {
        $this->settings['on_behalf_of'] = $value;
        return $this;
    }

    public function setPrivateKey($value)
    {
        $this->settings['private_key'] = $value;
        return $this;
    }

    public function setCertificate($value)
    {
        $this->settings['certificate'] = $value;
        return $this;
    }

    public function setFailedCallback($callback)
    {
        $this->failedCallback = $callback;
        return $this;
    }

    public function getSettings($key = null)
    {
        $preprod = is_bool(data_get($this->settings, 'preprod')) ? data_get($this->settings, 'preprod') : (
            is_bool(env('MYINVOIS_PREPROD')) ? env('MYINVOIS_PREPROD') : !app()->environment('production')
        );

        $clientId = data_get($this->settings, 'client_id') ?? (
            $preprod ? (env('MYINVOIS_PREPROD_CLIENT_ID') ?? env('MYINVOIS_CLIENT_ID')) : env('MYINVOIS_CLIENT_ID')
        );

        $clientSecret = data_get($this->settings, 'client_secret') ?? (
            $preprod ? (env('MYINVOIS_PREPROD_CLIENT_SECRET') ?? env('MYINVOIS_CLIENT_SECRET')) : env('MYINVOIS_CLIENT_SECRET')
        );

        $settings = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'on_behalf_of' => data_get($this->settings, 'on_behalf_of'),
            'private_key' => data_get($this->settings, 'private_key') ?? env('MYINVOIS_PRIVATE_KEY'),
            'certificate' => data_get($this->settings, 'certificate') ?? env('MYINVOIS_CERTIFICATE'),
            'preprod' => $preprod,
        ];

        if (data_get($settings, 'on_behalf_of') === env('MYINVOIS_CLIENT_TIN')) {
            $settings['on_behalf_of'] = null;
        }

        // dd($settings);

        return $key ? data_get($settings, $key) : $settings;
    }

    public function getEndpoint($uri)
    {
        $base = $this->getSettings('preprod') ? $this->baseUrl['preprod'] : $this->baseUrl['prod'];
        $prefix = str($uri)->startsWith('/') ? '' : '/api/v1.0/';

        return $base.$prefix.$uri;
    }

    public function getToken()
    {
        $clientId = $this->getSettings('client_id');
        $clientSecret = $this->getSettings('client_secret');
        $onBehalfOf = $this->getSettings('on_behalf_of');

        throw_if(!$clientId || !$clientSecret, \Exception::class, 'Missing Client ID / Client Secret');

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

        if ($response->clientError()) abort($response->status(), 'MyInvois Portal Unauthorized');

        $response->throw();

        cache()->put($cachekey, [
            ...$response->json(),
            'expired_at' => now()->addMinutes(50),
        ]);

        return $this->getToken();
    }

    public function callApi($uri, $method = 'GET', $data = [], $timeout = null) : mixed
    {
        $method = strtolower($method);
        $token = $this->getToken();

        if (!$token) abort(500, 'Missing MyInvois API access token');

        // check the completion time for last job
        // apply timeout if necessary to deal with rate limiting
        $key = 'myinvois_last_job_'.(string) str($uri)->replace('/', '_');
        $cache = cache($key);
        $result = null;

        while (!$result) {
            if (
                $cache
                && ($lastJobAt = data_get($cache, 'completed_at'))
                && ($lastJobTimeout = data_get($cache, 'timeout'))
                && ($nextJobAt = $lastJobAt && $timeout ? $lastJobAt->addSeconds($lastJobTimeout) : null)
                && $nextJobAt->gt(now())
            ) {
                sleep($nextJobAt->diffInSeconds(now()));
            }
            else {
                $endpoint = $this->getEndpoint($uri);
                $result = Http::withToken($token)->$method($endpoint, $data);

                if ($result->failed()) {
                    throw_if($result->status() === 403, 'Permissions denied from MyInvois Portal');
                    if ($callback = $this->failedCallback) $result = $callback($result);
                }

                if ($timeout) {
                    cache([$key => [
                        'endpoint' => $endpoint,
                        'method' => $method,
                        'completed_at' => now(),
                        'timeout' => $timeout,
                    ]]);
                }
                else {
                    cache()->forget($key);
                }
            }
        }

        return $result;
    }

    public function searchTaxpayerTIN($idType = null, $idValue = null, $taxpayerName = null)
    {
        $api = $this->callApi(
            uri: 'taxpayer/search/tin',
            data: [
                'idType' => $idType,
                'idValue' => $idValue,
                'taxpayerName' => $taxpayerName,
            ],
            timeout: 1,
        );

        return data_get($api->json(), 'tin');
    }

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

    public function getRecentDocuments($data = [])
    {
        $api = $this->callApi(
            uri: 'documents/recent',
            data: $data,
            timeout: 5,
        );

        return $api->json();
    }

    public function getSubmission($uid, $pageNo = null, $pageSize = null)
    {
        $api = $this->callApi(
            uri: 'documentsubmissions/'.$uid,
            data: [
                'pageNo' => $pageNo,
                'pageSize' => $pageSize,
            ],
            timeout: 1,
        );

        foreach (data_get($api->json(), 'documentSummary') ?? [] as $response) {
            $this->updateMyinvoisDocuments($response);
        }

        return $api->json();
    }

    public function getDocument($uid)
    {
        $api = $this->callApi(
            uri: 'documents/'.$uid.'/raw',
            timeout: 1,
        );

        return $api->json();
    }

    public function getDocumentDetails($uid)
    {
        $api = $this->callApi(
            uri: 'documents/'.$uid.'/details',
            timeout: 1,
        );

        $this->updateMyinvoisDocuments($api->json());

        return $api->json();
    }

    public function searchDocuments($data = [])
    {
        $api = $this->callApi(
            uri: 'documents/search',
            data: $data,
            timeout: 5,
        );

        return $api->json();
    }

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
            timeout: 1,
        );

        if ($myinvoisDocuments = $this->createMyinvoisDocuments($api->json(), $documents)) {
            return [
                'myinvois_documents' => $myinvoisDocuments,
                'response' => $api->json(),
            ];
        }

        return $api->json();
    }

    public function cancelDocument($uid, $reason = null)
    {
        $api = $this->callApi(
            uri: 'documents/state/'.$uid.'/state',
            method: 'PUT',
            data: [
                'status' => 'cancelled',
                'reason' => $reason,
            ],
            timeout: 5,
        );

        $this->updateMyinvoisDocuments([...$api->json(), 'reason' => $reason]);

        return $api->json();
    }

    public function rejectDocument($uid, $reason = null)
    {
        $api = $this->callApi(
            uri: 'documents/state/'.$uid.'/state',
            data: [
                'status' => 'rejected',
                'reason' => $reason,
            ],
            timeout: 5,
        );

        return $api->json();
    }

    public function createMyinvoisDocuments($response, $documents)
    {
        if (!Schema::hasTable('myinvois_documents')) return;

        $submissionUid = data_get($response, 'submissionUid');
        $accepted = data_get($response, 'acceptedDocuments');

        if (!$accepted) return;

        // if all line items classifications are 004, then is a consolidated submission
        $classifications = collect($documents)->pluck('line_items')->collapse()->pluck('classifications')->collapse()->unique()->values();
        $isConsolidated = $classifications->count() === 1 && data_get($classifications->first(), 'code') === '004';
        $myinvoisDocuments = collect();

        foreach ($accepted as $data) {
            if ($isConsolidated) {
                $document = collect($documents)->firstWhere('number', data_get($data, 'invoiceCodeNumber'));
                $lineItems = data_get($document, 'line_items', []);

                foreach ($lineItems as $lineItem) {
                    $myinvoisDocuments->push(MyinvoisDocument::create([
                        'document_uuid' => data_get($data, 'uuid'),
                        'submission_uid' => $submissionUid,
                        'document_number' => data_get($lineItem, 'description'),
                        'status' => 'submitted',
                        'is_preprod' => $this->getSettings('preprod'),
                    ]));
                }
            }
            else {
                $myinvoisDocuments->push(MyinvoisDocument::create([
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
            if (MyinvoisDocument::where('submission_uid', $submissionUid)->where('status', 'submitted')->count()) $try++;
            else $try = $max + 1;
        }

        return $myinvoisDocuments;
    }

    public function updateMyinvoisDocuments($response)
    {
        if (!Schema::hasTable('myinvois_documents')) return;

        $documentUuid = data_get($response, 'uuid');
        $submissionUid = data_get($response, 'submissionUid');
        $documents = MyinvoisDocument::query()
            ->where('document_uuid', $documentUuid)
            ->when($submissionUid, fn ($q) => $q->where('submission_uid', $submissionUid));

        if (!$documents->count()) return;

        $documents->each(fn ($document) => $document->update([
            'status' => strtolower(data_get($response, 'status')),
            'response' => $response,
        ]));
    }

    public function validator($document)
    {
        if ($document === 'sample') $document = $this->getSample();

        return app(Validator::class)->build($document);
    }

    public function getSample()
    {
        return Sample::build();
    }
}
