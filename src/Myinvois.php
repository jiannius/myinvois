<?php

namespace Jiannius\Myinvois;

use Illuminate\Support\Facades\Http;
use Jiannius\Myinvois\Helpers\Sample;
use Jiannius\Myinvois\Helpers\Signature;
use Jiannius\Myinvois\Helpers\UBL;
use Jiannius\Myinvois\Helpers\Validator;

class Myinvois
{
    public $settings = [
        'client_id' => null,
        'client_secret' => null,
        'on_behalf_of' => null,
        'private_key' => null,
        'certificate' => null,
        'preprod' => null,
    ];

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

    public function getSettings($key)
    {
        return match ($key) {
            'client_id' => $this->settings['client_id'] ?? env('MYINVOIS_CLIENT_ID'),
            'client_secret' => $this->settings['client_secret'] ?? env('MYINVOIS_CLIENT_SECRET'),
            'preprod' => is_bool($this->settings['preprod'])
                ? $this->settings['preprod']
                : !app()->environment('production'),
            default => $this->settings[$key],
        };
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

        $response = Http::withHeaders([
            'onbehalfof' => $onBehalfOf,
        ])->asForm()->post(
            url: $this->getEndpoint('/connect/token'),
            data: [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'client_credentials',
                'scope' => 'InvoicingAPI',
            ],
        );

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

        return $api->json();
    }

    public function cancelDocument($uid, $reason = null)
    {
        $api = $this->callApi(
            uri: 'documents/state/'.$uid.'/state',
            data: [
                'status' => 'cancelled',
                'reason' => $reason,
            ],
        );

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
