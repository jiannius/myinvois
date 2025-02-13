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
        'prod' => 'https://myinvois.hasil.gov.my',
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
        $settings = [
            'client_id' => data_get($this->settings, 'client_id') ?? env('MYINVOIS_CLIENT_ID'),
            'client_secret' => data_get($this->settings, 'client_secret') ?? env('MYINVOIS_CLIENT_SECRET'),
            'on_behalf_of' => data_get($this->settings, 'on_behalf_of'),
            'private_key' => data_get($this->settings, 'private_key') ?? env('MYINVOIS_PRIVATE_KEY'),
            'certificate' => data_get($this->settings, 'certificate') ?? env('MYINVOIS_CERTIFICATE'),
            'preprod' => is_bool(data_get($this->settings, 'preprod')) ? data_get($this->settings, 'preprod') : (
                is_bool(env('MYINVOIS_PREPROD')) ? env('MYINVOIS_PREPROD') : !app()->environment('production')
            ),
        ];

        if (data_get($settings, 'on_behalf_of') === env('MYINVOIS_CLIENT_TIN')) {
            $settings['on_behalf_of'] = null;
        }

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
        $response->throw();

        cache()->put($cachekey, [
            ...$response->json(),
            'expired_at' => now()->addMinutes(50),
        ]);

        return $this->getToken();
    }

    public function callApi($uri, $method = 'GET', $data = []) : mixed
    {
        $method = strtolower($method);
        $token = $this->getToken();

        if (!$token) abort(500, 'Missing MyInvois API access token');

        $res = Http::withToken($token)->$method($this->getEndpoint($uri), $data);

        if ($res->failed() && ($callback = $this->failedCallback)) {
            return $callback($res);
        }

        return $res;
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
        );

        return data_get($api->json(), 'tin');
    }

    public function validateTaxpayerTIN($tin, $idType, $idValue)
    {
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
        );

        return $api->json();
    }

    public function getDocument($uid)
    {
        $api = $this->callApi(
            uri: 'documents/'.$uid.'/raw',
        );

        return $api->json();
    }

    public function getDocumentDetails($uid)
    {
        $api = $this->callApi(
            uri: 'documents/'.$uid.'/details',
        );

        if (Schema::hasTable('myinvois_documents')) {
            $this->updateSubmittedDocument($api->json());
        }

        return $api->json();
    }

    public function searchDocuments($data = [])
    {
        $api = $this->callApi(
            uri: 'documents/search',
            data: $data,
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
        );

        if (Schema::hasTable('myinvois_documents')) {
            return [
                'documents' => $this->createSubmittedDocuments($api->json()),
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
        );

        if (Schema::hasTable('myinvois_documents')) {
            $this->updateSubmittedDocument([...$api->json(), 'reason' => $reason]);
        }

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
        );

        return $api->json();
    }

    public function createSubmittedDocuments($response)
    {
        $submissionUid = data_get($response, 'submissionUid');
        $accepted = data_get($response, 'acceptedDocuments');

        return $accepted
            ? collect($accepted)->map(fn ($data) => 
                MyinvoisDocument::create([
                    'document_uuid' => data_get($data, 'uuid'),
                    'submission_uid' => $submissionUid,
                    'document_number' => data_get($data, 'invoiceCodeNumber'),
                    'status' => 'submitted',
                    'is_preprod' => $this->getSettings('preprod'),
                ])
            )
            : null;
    }

    public function updateSubmittedDocument($response)
    {
        $documentUuid = data_get($response, 'uuid');
        $submissionUid = data_get($response, 'submissionUid');
        $document = MyinvoisDocument::query()
            ->where('document_uuid', $documentUuid)
            ->when($submissionUid, fn ($q) => $q->where('submission_uid', $submissionUid))
            ->latest()
            ->first();

        if (!$document) return;

        $document->fill([
            'status' => strtolower(data_get($response, 'status')),
            'response' => $response,
        ])->save();
    }

    public function validator($document)
    {
        if ($document === 'sample') $document = $this->getSample();

        return Validator::build($document);
    }

    public function getSample()
    {
        return Sample::build();
    }
}
