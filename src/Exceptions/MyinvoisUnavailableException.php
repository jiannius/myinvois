<?php

namespace Jiannius\Myinvois\Exceptions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * MyInvois could not be reached or could not serve the request right now:
 * a network failure / timeout, an HTTP 5xx, or an HTTP 429. These are
 * transient -- showing "try again later" and retrying is appropriate.
 *
 * A network failure carries a sanitised `getReason()` (the connection error
 * text with URL query strings stripped). The original ConnectionException is
 * deliberately NOT chained as previous: its Guzzle request holds the
 * Authorization header and, on the token endpoint, the client secret.
 */
class MyinvoisUnavailableException extends MyinvoisException
{
    public function __construct(
        string $message = '',
        ?int $status = null,
        ?string $endpoint = null,
        ?string $responseBody = null,
        protected ?int $retryAfter = null,
        protected ?string $reason = null,
    ) {
        parent::__construct($message, $status, $endpoint, $responseBody);
    }

    /**
     * Build the exception for a network failure / timeout.
     */
    public static function fromConnectionException(ConnectionException $e, string $message, ?string $endpoint = null) : static
    {
        return new static($message, endpoint: $endpoint, reason: static::sanitiseReason($e->getMessage()));
    }

    /**
     * Strip URL query strings / fragments from a connection error text -- the curl
     * message repeats the full URL, and the query can hold an NRIC or TIN.
     */
    protected static function sanitiseReason(string $reason) : string
    {
        return trim(preg_replace('/(https?:\/\/[^\s?#]+)[?#]\S*/i', '$1', $reason));
    }

    public static function fromResponse(Response $response, ?string $endpoint = null, ?string $message = null) : static
    {
        $retryAfter = $response->header('Retry-After');

        return new static(
            $message ?? ($response->status() === 429
                ? 'MyInvois is rate limiting requests (HTTP 429). Please try again shortly.'
                : "MyInvois is temporarily unavailable (HTTP {$response->status()}). Please try again later."),
            $response->status(),
            $endpoint,
            $response->body(),
            is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    /**
     * Why the connection failed (e.g. "cURL error 28: Operation timed out ..."),
     * with URL query strings removed. Null when the failure was an HTTP response.
     */
    public function getReason() : ?string
    {
        return $this->reason;
    }

    /**
     * Seconds MyInvois asked us to wait (Retry-After header), when sent.
     */
    public function getRetryAfter() : ?int
    {
        return $this->retryAfter;
    }
}
