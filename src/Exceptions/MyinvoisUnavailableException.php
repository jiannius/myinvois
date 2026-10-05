<?php

namespace Jiannius\Myinvois\Exceptions;

use Illuminate\Http\Client\Response;
use Throwable;

/**
 * MyInvois could not be reached or could not serve the request right now:
 * a network failure / timeout, an HTTP 5xx, or an HTTP 429. These are
 * transient -- showing "try again later" and retrying is appropriate.
 *
 * A network failure carries the original ConnectionException as previous.
 */
class MyinvoisUnavailableException extends MyinvoisException
{
    public function __construct(
        string $message = '',
        ?int $status = null,
        ?string $endpoint = null,
        ?string $responseBody = null,
        ?Throwable $previous = null,
        protected ?int $retryAfter = null,
    ) {
        parent::__construct($message, $status, $endpoint, $responseBody, $previous);
    }

    public static function fromResponse(Response $response, ?string $endpoint = null, ?string $message = null, ?Throwable $previous = null) : static
    {
        $retryAfter = $response->header('Retry-After');

        return new static(
            $message ?? ($response->status() === 429
                ? 'MyInvois is rate limiting requests (HTTP 429). Please try again shortly.'
                : "MyInvois is temporarily unavailable (HTTP {$response->status()}). Please try again later."),
            $response->status(),
            $endpoint,
            $response->body(),
            $previous,
            is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    /**
     * Seconds MyInvois asked us to wait (Retry-After header), when sent.
     */
    public function getRetryAfter() : ?int
    {
        return $this->retryAfter;
    }
}
