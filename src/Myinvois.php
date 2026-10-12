<?php

namespace Jiannius\Myinvois;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Jiannius\Myinvois\Contracts\ResolvesMyinvoisClient;
use Jiannius\Myinvois\Exceptions\MyinvoisAuthenticationException;
use Jiannius\Myinvois\Exceptions\MyinvoisException;
use Jiannius\Myinvois\Exceptions\MyinvoisPermissionException;
use Jiannius\Myinvois\Exceptions\MyinvoisThrottledException;
use Jiannius\Myinvois\Exceptions\MyinvoisUnavailableException;
use Jiannius\Myinvois\Helpers\Sample;
use Jiannius\Myinvois\Helpers\Signature;
use Jiannius\Myinvois\Helpers\UBL;
use Jiannius\Myinvois\Helpers\Validator;
use Jiannius\Myinvois\Jobs\SubmitDocuments;
use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Models\Observers\MyinvoisDocumentObserver;
use Jiannius\Myinvois\Support\ParentReference;
use Jiannius\Myinvois\Support\SubmissionLock;
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
     * Default HTTP timeouts, in seconds. The total timeout is deliberately generous so a
     * slow but valid documentsubmissions POST of a large batch is not cut off.
     */
    public const DEFAULT_CONNECT_TIMEOUT = 10;

    public const DEFAULT_TIMEOUT = 60;

    /**
     * Total timeout (seconds) for the post-submit status poll, so the worst case of
     * submitDocuments() stays reasonable. The configured timeout wins when it is lower.
     */
    public const POLL_TIMEOUT = 15;

    /**
     * Temporary ceiling on the total timeout, set only while the post-submit poll runs.
     */
    protected int|float|null $timeoutCap = null;

    /**
     * The parent models of the documents being submitted, keyed by the number of the
     * myinvois_documents row each one owns (see forParents()).
     *
     * @var array<string, Model>
     */
    protected array $parents = [];

    /**
     * Owner token of a lock the caller (a queue job) already holds for $parents.
     */
    protected ?string $lockOwner = null;

    /**
     * Whether submitDocuments() polls for the verdict inline once LHDN accepts.
     */
    protected bool $polls = true;

    /**
     * Whether callApi() sleeps when the package's rate limiter is capped (the sync
     * default) or throws MyinvoisThrottledException so a queue job can release() instead.
     */
    protected bool $sleepsOnThrottle = true;

    /**
     * Set once submitDocuments() reaches the point of sending the POST, so a caller that
     * catches an unexpected exception can tell whether LHDN may have received the batch.
     */
    protected bool $postStarted = false;

    /**
     * Set with beforePost(): called right before the submission POST is sent.
     */
    protected ?\Closure $beforePost = null;

    /**
     * $beforePost while submitDocuments() has its POST in flight; sendRequest() calls it.
     * Null for every other request.
     */
    protected ?\Closure $beforeSend = null;

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
     * A fresh client configured for a parent model: app('myinvois') (so a fake applies),
     * run through the parent's ResolvesMyinvoisClient contract when it has one. This is
     * how the queue jobs and the status sweep get per-org credentials back on a worker.
     * Parents without the contract use the plain config cascade.
     */
    public static function clientFor(Model $parent) : static
    {
        $client = app('myinvois');

        if ($parent instanceof ResolvesMyinvoisClient) {
            $configured = $parent->configureMyinvoisClient($client);

            if ($configured instanceof self) $client = $configured;
        }

        return $client;
    }

    /**
     * Link the documents of the next submitDocuments() call to their parent models.
     *
     * $parents maps each myinvois_documents row's `document_number` to its model: the
     * invoice number, or for a consolidated document each line item's description. The
     * rows are created with parent_type / parent_id stamped, and the submission takes the
     * in-flight lock of every parent (MyinvoisInFlightException when one is held, or its
     * latest submission is SUBMITTED / VALID).
     *
     * All the parents must resolve (clientFor()) to the same client -- client id,
     * on-behalf-of and environment -- or InvalidArgumentException is thrown: a submission is
     * signed and sent with one client's credentials, so a mixed batch would go out under the
     * wrong org.
     *
     * $lockOwner is for the queue job whose dispatcher already took the locks: they are
     * then re-checked (and re-taken if they lapsed) instead of acquired, and freed on the
     * same terms as locks taken here.
     *
     * @param  array<string, Model>|\Illuminate\Support\Collection  $parents
     */
    public function forParents($parents, ?string $lockOwner = null) : static
    {
        $parents = collect($parents)->all();

        // one submission is signed and sent with one client's credentials
        static::assertSingleClient($parents);

        $this->parents = $parents;
        $this->lockOwner = $lockOwner;

        return $this;
    }

    /**
     * Skip the inline status poll after a submission is accepted: the rows are saved as
     * `submitted` and resolved later (the poll job, or the myinvois:sync sweep).
     */
    public function withoutPolling(bool $without = true) : static
    {
        $this->polls = ! $without;

        return $this;
    }

    /**
     * Never sleep on the rate limiter: when it is capped, throw MyinvoisThrottledException
     * (nothing was sent) carrying the seconds to wait. For queue jobs, which must release()
     * rather than block a worker.
     */
    public function withoutThrottleSleep(bool $without = true) : static
    {
        $this->sleepsOnThrottle = ! $without;

        return $this;
    }

    /**
     * Run $callback right before submitDocuments() sends the POST -- after building, signing,
     * the lock checks, the token and the rate limiter, so it only runs when the request
     * really is about to leave. A queue job uses it to record that LHDN may now have the batch.
     */
    public function beforePost(?\Closure $callback) : static
    {
        $this->beforePost = $callback;

        return $this;
    }

    /**
     * Whether the last submitDocuments() got as far as sending the POST to MyInvois.
     */
    public function postStarted() : bool
    {
        return $this->postStarted;
    }

    /**
     * Submit documents on a queue worker instead of inside the request.
     *
     * $documents is the flat contract (see Helpers/Sample.php); validate it first.
     * $parents maps each row's `document_number` to its model -- the invoice number, or for
     * a consolidated document each line item's description -- and every row must have one
     * (InvalidArgumentException otherwise). All documents in one call must be submitted by
     * the same client (org); queue one call per org.
     *
     * Takes the in-flight lock of every parent and checks none is already SUBMITTED / VALID
     * (MyinvoisInFlightException otherwise), then dispatches Jobs\SubmitDocuments and returns.
     * The outcome arrives as events (SubmissionAccepted / Succeeded / Failed) carrying
     * $context, an opaque array of scalars the app can use to find its way back (a user id,
     * a batch id). The job payload holds the documents, parent references, the lock token and
     * $context -- never a credential, key or token: the worker rebuilds the client from the
     * first parent via ResolvesMyinvoisClient.
     *
     * @param  array<int, array>  $documents
     * @param  array<string, Model>  $parents
     * @param  array<string, scalar|array>  $context
     */
    public static function queueSubmission(array $documents, array $parents, array $context = []) : void
    {
        $documents = array_values($documents);

        if (! $documents) throw new \InvalidArgumentException('No documents to submit');

        $missing = collect($documents)
            ->flatMap(fn ($document) => static::rowNumbers($document))
            ->reject(fn ($number) => ($parents[$number] ?? null) instanceof Model)
            ->unique()
            ->values();

        if ($missing->isNotEmpty()) {
            throw new \InvalidArgumentException('No parent model given for: '.$missing->join(', '));
        }

        $models = collect($documents)
            ->flatMap(fn ($document) => static::rowNumbers($document))
            ->mapWithKeys(fn ($number) => [$number => $parents[$number]]);

        // the job signs and sends everything with the first parent's client: refuse a batch
        // that spans clients before taking any lock
        static::assertSingleClient($models);

        $preprod = (bool) static::clientFor($models->first())->getSettings('preprod');
        $owner = SubmissionLock::acquire($models, $preprod);

        try {
            // The job runs only after the surrounding transaction commits (afterCommit) and is
            // discarded when it rolls back: nothing would ever free the locks, and the caller
            // could not queue the same documents again until they expire.
            if (DB::transactionLevel() > 0) {
                DB::afterRollBack(fn () => SubmissionLock::release($models, $preprod, $owner));
            }

            SubmissionLock::assertSubmittable($models, $preprod);

            SubmitDocuments::dispatch(
                $documents,
                $models->map(fn ($parent) => ParentReference::make($parent))->all(),
                $preprod,
                $owner,
                $context,
            )
                ->onConnection(config('services.myinvois.queue_connection'))
                ->onQueue(config('services.myinvois.queue'));
        }
        catch (\Throwable $e) {
            SubmissionLock::release($models, $preprod, $owner);

            throw $e;
        }
    }

    /**
     * Refuse parents that resolve (clientFor()) to different clients. The client is
     * identified by its client id, on-behalf-of and environment.
     *
     * @param  iterable<Model>  $parents
     *
     * @throws \InvalidArgumentException
     */
    public static function assertSingleClient(iterable $parents) : void
    {
        $identities = [];
        $seen = [];

        foreach ($parents as $parent) {
            $key = ParentReference::make($parent);
            $key = $key['type'].':'.$key['id'];

            if (isset($seen[$key])) continue;

            $seen[$key] = true;
            $settings = static::clientFor($parent)->getSettings();

            $identities[json_encode([
                data_get($settings, 'client_id'),
                data_get($settings, 'on_behalf_of'),
                (bool) data_get($settings, 'preprod'),
            ])] = true;
        }

        if (count($identities) > 1) {
            throw new \InvalidArgumentException(
                'All documents in one submission must be for the same client (client id, on-behalf-of and environment). Submit one batch per org.'
            );
        }
    }

    /**
     * The myinvois_documents row numbers a flat document will produce: its own number, or
     * for a consolidated document the description of each line item.
     *
     * @return array<int, string>
     */
    public static function rowNumbers($document) : array
    {
        if (UBL::isConsolidated($document)) {
            return collect(data_get($document, 'line_items', []))->pluck('description')->filter()->unique()->values()->all();
        }

        return array_values(array_filter([data_get($document, 'number')]));
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
     * Set the connect timeout in seconds (how long to wait to establish the connection)
     */
    public function setConnectTimeout($seconds)
    {
        $this->settings['connect_timeout'] = $seconds;
        return $this;
    }

    /**
     * Set the total request timeout in seconds
     */
    public function setTimeout($seconds)
    {
        $this->settings['timeout'] = $seconds;
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
            'connect_timeout' => $this->resolveTimeout('connect_timeout', static::DEFAULT_CONNECT_TIMEOUT),
            'timeout' => $this->resolveTimeout('timeout', static::DEFAULT_TIMEOUT),
        ];

        if (data_get($settings, 'on_behalf_of') === config('services.myinvois.client_tin')) {
            $settings['on_behalf_of'] = null;
        }

        // dd($settings);

        return $key ? data_get($settings, $key) : $settings;
    }

    /**
     * Resolve a timeout in seconds: setter -> config('services.myinvois.<key>') -> default.
     * Anything that is not a positive number (0 would mean "wait forever") is ignored.
     */
    protected function resolveTimeout(string $key, int $default) : int|float
    {
        foreach ([data_get($this->settings, $key), config('services.myinvois.'.$key)] as $value) {
            if (is_numeric($value) && $value > 0) return $value + 0;
        }

        return $default;
    }

    /**
     * The total timeout for the next request: the resolved setting, lowered to the
     * temporary cap (see pollSubmission()) when one is active -- never raised by it.
     */
    protected function getRequestTimeout() : int|float
    {
        $timeout = $this->getSettings('timeout');

        return $this->timeoutCap === null ? $timeout : min($timeout, $this->timeoutCap);
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
     * Get the cache key the access token is stored under
     */
    protected function getTokenCacheKey() : string
    {
        return collect([
            'myinvois',
            $this->getSettings('client_id'),
            $this->getSettings('on_behalf_of'),
        ])->filter()->join('_');
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

        $cachekey = $this->getTokenCacheKey();
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

        $http = Http::asForm()
            ->connectTimeout($this->getSettings('connect_timeout'))
            ->timeout($this->getRequestTimeout());

        if ($onBehalfOf) $http->withHeaders(['onbehalfof' => $onBehalfOf]);

        try {
            $response = $http->post(url: $url, data: $data);
        }
        catch (ConnectionException $e) {
            throw MyinvoisUnavailableException::fromConnectionException($e, 'Could not reach MyInvois to authenticate. Please try again later.', $url);
        }

        // 408 (request timeout) and 429 (throttled) are transient, like a 5xx -- not a credentials problem
        if ($response->clientError() && !in_array($response->status(), [408, 429])) {
            throw MyinvoisAuthenticationException::fromResponse($response, $url, $this->getTokenErrorMessage($response));
        }

        if ($response->failed()) {
            throw MyinvoisUnavailableException::fromResponse($response, $url);
        }

        $json = $response->json();

        if (!is_array($json) || !data_get($json, 'access_token')) {
            throw MyinvoisUnavailableException::fromResponse(
                $response,
                $url,
                "MyInvois returned an unexpected response while authenticating (HTTP {$response->status()}). Please try again later.",
            );
        }

        cache()->put($cachekey, [
            ...$json,
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
        $json = $response->json();
        $code = data_get($json, 'error') ?? data_get($json, 'message');

        // only a plain string is safe to show -- the body is not ours to trust
        if (!is_string($code) || $code === '') $code = null;

        return match ($code) {
            'invalid_client' => 'MyInvois rejected the API credentials. Check that the MyInvois Client ID and Client Secret are correct and match the selected environment (production vs sandbox).',
            default => 'MyInvois authentication failed'.($code ? " ($code)" : " (HTTP {$response->status()})").'. Please try again or contact support.',
        };
    }

    /**
     * URI segments that name an endpoint, as opposed to an id (submission uid, document
     * uuid, TIN) embedded in the path. Anything else becomes a placeholder in the throttle
     * key, so the limiter is shared by every id of the same endpoint.
     */
    protected const THROTTLE_LITERALS = [
        'taxpayer', 'search', 'tin', 'validate', 'documents', 'recent', 'raw', 'details',
        'state', 'documentsubmissions',
    ];

    /**
     * Get the rate limiter key for an API uri: one per endpoint (ids stripped) and per
     * client id -- LHDN rate limits apply per client id, whatever the uid being asked for.
     */
    public function getThrottleKey($uri) : string
    {
        $endpoint = collect(explode('/', trim((string) $uri, '/')))
            ->map(fn ($segment) => in_array($segment, static::THROTTLE_LITERALS, true) ? $segment : 'id')
            ->join('-');

        return 'myinvois.'.$this->getSettings('client_id').'.'.$endpoint;
    }

    /**
     * Call the API
     */
    public function callApi($uri, $method = 'GET', $data = [], $perMinute = null)
    {
        $method = strtolower($method);
        $token = $this->getToken();

        if (!$token) throw new MyinvoisAuthenticationException('Missing MyInvois API access token');

        if ($perMinute) {
            $timeout = 60 / $perMinute;
            $throttleKey = $this->getThrottleKey($uri);
            $tooManyAttempts = RateLimiter::tooManyAttempts($throttleKey, $perMinute);

            if ($tooManyAttempts) {
                if (! $this->sleepsOnThrottle) {
                    throw new MyinvoisThrottledException(
                        'The MyInvois rate limit is reached. Please try again shortly.',
                        status: 429,
                        endpoint: $this->getEndpoint($uri),
                        retryAfter: max(1, RateLimiter::availableIn($throttleKey)),
                    );
                }

                sleep($timeout);
                RateLimiter::clear($throttleKey);
            }
            else {
                RateLimiter::increment($throttleKey);
            }
        }

        $endpoint = $this->getEndpoint($uri);

        $result = $this->sendRequest($method, $endpoint, $data, $token);

        // a 401 means the token we hold is no longer good (revoked, rotated secret, or
        // expired earlier than our 50 minute cache). Drop it and retry exactly once with
        // a fresh one -- no loop: a second 401 falls through to throwIfUnrecoverable().
        if ($result->status() === 401) {
            cache()->forget($this->getTokenCacheKey());
            $result = $this->sendRequest($method, $endpoint, $data, $this->getToken());
        }

        if ($result->failed()) {
            if ($result->status() === 403) {
                throw MyinvoisPermissionException::fromResponse($result, $endpoint, 'Permissions denied from MyInvois Portal');
            }

            // a callback that returns nothing (or anything that is not a response) did not
            // recover the call -- keep the original failed response rather than lose it
            if ($callback = $this->failedCallback) {
                $replacement = $callback($result);

                if ($replacement instanceof Response) $result = $replacement;
            }

            $this->throwIfUnrecoverable($result, $endpoint);
        }

        return $result;
    }

    /**
     * Send one authenticated request, turning a network failure into a typed exception.
     * The original ConnectionException is deliberately not chained: it carries the
     * Guzzle request (Authorization header, form body) and the full URL with its query.
     */
    protected function sendRequest(string $method, string $endpoint, $data, string $token) : Response
    {
        if ($this->beforeSend) ($this->beforeSend)();

        try {
            return Http::withToken($token)
                ->connectTimeout($this->getSettings('connect_timeout'))
                ->timeout($this->getRequestTimeout())
                ->$method($endpoint, $data);
        }
        catch (ConnectionException $e) {
            throw MyinvoisUnavailableException::fromConnectionException($e, 'Could not reach MyInvois. Please try again later.', $endpoint);
        }
    }

    /**
     * Turn a failed response the failed callback did not recover into a typed
     * exception, so a failure is never reported as success.
     *
     * Only auth (401), throttling (429) and server (5xx) failures throw. Any
     * other 4xx (400/404/422...) is an application-level answer from LHDN --
     * e.g. a validation rejection with a JSON body -- and is still returned to
     * the caller as before.
     */
    protected function throwIfUnrecoverable($result, string $endpoint) : void
    {
        if (!$result instanceof Response || !$result->failed()) return;

        $status = $result->status();

        if ($status === 401) {
            throw MyinvoisAuthenticationException::fromResponse($result, $endpoint, 'MyInvois rejected the access token again after refreshing it (HTTP 401). Check the Client ID / Client Secret and that they match the selected environment (production vs sandbox).');
        }

        if ($status === 429 || $status >= 500) {
            throw MyinvoisUnavailableException::fromResponse($result, $endpoint);
        }
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

        $json = $api->json();

        // never write a failed (4xx) or empty / non-JSON response back onto the local record
        if ($api->successful() && is_array($json)) $this->updateMyinvoisDocuments($json);

        return $json;
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

        $preprod = (bool) $this->getSettings('preprod');
        $parents = $this->parentsOf($documents);
        $owner = $this->lockOwner;
        $this->postStarted = false;

        try {
            $pkey = $this->getSettings('private_key');
            $cert = $this->getSettings('certificate');

            throw_if(!$pkey || !$cert, \Exception::class, 'Missing private key / certificate');

            $this->assertParentsCover($documents);
        }
        catch (\Throwable $e) {
            // a definite failure before anything is sent: a lock the caller (a queue job)
            // already took for us is freed now, not left to expire. Owner-checked, so a
            // lock someone else holds is untouched.
            if ($owner && $parents) SubmissionLock::release($parents, $preprod, $owner);

            throw $e;
        }

        if ($parents) {
            try {
                if ($owner) SubmissionLock::ensure($parents, $preprod, $owner);
                else $owner = SubmissionLock::acquire($parents, $preprod);

                SubmissionLock::assertSubmittable($parents, $preprod);
            }
            catch (\Throwable $e) {
                // only frees locks this owner holds; a lock someone else took is left alone
                if ($owner) SubmissionLock::release($parents, $preprod, $owner);

                throw $e;
            }
        }

        // The lock is freed once the outcome is known: rows written, or a definite failure.
        // After an ambiguous failure (a timeout / 5xx: LHDN may have accepted the batch) it
        // is kept until its TTL so nobody resubmits blindly.
        $keepLock = false;

        try {
            $payload = collect($documents)->map(function ($document) use ($pkey, $cert) {
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
            })->toArray();

            $this->postStarted = true;
            $this->beforeSend = $this->beforePost;

            try {
                $api = $this->callApi(
                    uri: 'documentsubmissions',
                    method: 'POST',
                    data: ['documents' => $payload],
                    perMinute: 60,
                );
            }
            finally {
                $this->beforeSend = null;
            }

            // accepted by LHDN: from here a failure to save the rows must not free the lock
            if (data_get($api->json(), 'acceptedDocuments')) $keepLock = true;

            $myinvoisDocuments = $this->persistMyinvoisDocuments($api->json(), $documents);

            $keepLock = false;
        }
        catch (\Throwable $e) {
            // a plain \Exception is a configuration error (missing client id / secret), thrown
            // before anything was sent
            if ($this->postStarted && ($e instanceof MyinvoisException ? $e->mayHaveReachedMyinvois() : $e::class !== \Exception::class)) {
                $keepLock = true;
            }

            throw $e;
        }
        finally {
            if ($owner && ! $keepLock) SubmissionLock::release($parents, $preprod, $owner);
        }

        if ($myinvoisDocuments) {
            if ($this->polls) $this->pollAfterSubmit(data_get($api->json(), 'submissionUid'));

            return [
                'myinvois_documents' => $myinvoisDocuments,
                'response' => $api->json(),
            ];
        }

        return $api->json();
    }

    /**
     * The parents the given documents' rows belong to (what the submission locks).
     *
     * @return array<int, Model>
     */
    protected function parentsOf($documents) : array
    {
        return collect($documents)
            ->flatMap(fn ($document) => static::rowNumbers($document))
            ->map(fn ($number) => $this->parents[$number] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Every row of the documents must have a parent when forParents() is used -- checked
     * before any network call, so a half-linked submission can never be created.
     *
     * @throws \InvalidArgumentException
     */
    protected function assertParentsCover($documents) : void
    {
        if (!$this->parents) return;

        $missing = collect($documents)
            ->flatMap(fn ($document) => static::rowNumbers($document))
            ->reject(fn ($number) => isset($this->parents[$number]))
            ->unique()
            ->values();

        if ($missing->isNotEmpty()) {
            throw new \InvalidArgumentException('No parent model given for: '.$missing->join(', '));
        }
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

        $json = $api->json();

        // never write a failed (4xx) or empty / non-JSON response back onto the local record
        if ($api->successful() && is_array($json)) $this->updateMyinvoisDocuments([...$json, 'reason' => $reason]);

        return $json;
    }

    /**
     * Reject the document (buyer side, on a received document).
     *
     * Deliberately does not write local state: the local row may not exist or may be an
     * inbound document, LHDN answers with a "requested" state the supplier still has to
     * act on, and `rejected` is not a `Status` case.
     */
    public function rejectDocument($uid, $reason = null)
    {
        // LHDN marks `reason` as required; fail before any network call
        if (blank($reason)) throw new \InvalidArgumentException('A rejection reason is required by LHDN');

        $api = $this->callApi(
            uri: 'documents/state/'.$uid.'/state',
            method: 'PUT',
            data: [
                'status' => 'rejected',
                'reason' => $reason,
            ],
            perMinute: 12,
        );

        return $api->json();
    }

    /**
     * Create the myinvois documents, then poll LHDN for the verdict (unless
     * withoutPolling() was called).
     */
    public function createMyinvoisDocuments($response, $documents)
    {
        $myinvoisDocuments = $this->persistMyinvoisDocuments($response, $documents);

        if ($myinvoisDocuments && $this->polls) $this->pollAfterSubmit(data_get($response, 'submissionUid'));

        return $myinvoisDocuments;
    }

    /**
     * The parent_type / parent_id of a row, from forParents().
     */
    protected function parentAttributes($number) : array
    {
        $parent = $this->parents[$number] ?? null;

        return $parent ? ['parent_type' => $parent->getMorphClass(), 'parent_id' => $parent->getKey()] : [];
    }

    /**
     * Save one `submitted` row per accepted document (per line item for a consolidated
     * one), stamped with its parent when forParents() was used.
     */
    protected function persistMyinvoisDocuments($response, $documents)
    {
        // status events fire once every row is written, and a listener cannot stop the loop
        return MyinvoisDocumentObserver::deferEvents(fn () => $this->createMyinvoisRows($response, $documents));
    }

    protected function createMyinvoisRows($response, $documents)
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
                        ...$this->parentAttributes(data_get($lineItem, 'description')),
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
                    ...$this->parentAttributes(data_get($data, 'invoiceCodeNumber')),
                ]));
            }
        }

        return $myinvoisDocuments;
    }

    /**
     * Immediately update the document status with max 2 retries.
     */
    protected function pollAfterSubmit($submissionUid) : void
    {
        $model = MyinvoisDocument::$useModel;
        $try = 1;
        $max = 3;

        while ($try <= $max) {
            sleep(2);

            // the documents are already accepted and saved locally at this point, so a
            // failed poll must never throw -- the caller would lose the submission uid
            // and may resubmit an accepted invoice. Leave the rows `submitted`; a later
            // getSubmission() call flips them.
            try {
                $this->pollSubmission($submissionUid);
            }
            catch (MyinvoisException) {
                break;
            }

            if ($model::where('submission_uid', $submissionUid)->where('status', 'submitted')->count()) $try++;
            else $try = $max + 1;
        }
    }

    /**
     * getSubmission() with a short total timeout (POLL_TIMEOUT, or the configured one if
     * lower) for the post-submit poll. The cap is scoped to this call and always restored.
     */
    protected function pollSubmission($submissionUid)
    {
        $previous = $this->timeoutCap;
        $this->timeoutCap = $previous === null ? static::POLL_TIMEOUT : min($previous, static::POLL_TIMEOUT);

        try {
            return $this->getSubmission($submissionUid);
        }
        finally {
            $this->timeoutCap = $previous;
        }
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

        // one event batch for all the rows of the submission, after the last is written
        MyinvoisDocumentObserver::deferEvents(fn () => $documents->each(fn ($document) => $document->update([
            'status' => strtolower(data_get($response, 'status')),
            'response' => $response,
        ])));
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
