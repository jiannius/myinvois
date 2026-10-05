<?php

namespace Jiannius\Myinvois\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Base class for every failure the SDK raises while talking to MyInvois.
 *
 * Extends RuntimeException so callers that already catch \RuntimeException
 * or \Exception keep working. Catch this type to handle any MyInvois API
 * failure in one place, or a subclass to tell the causes apart.
 *
 * Only plain data is kept (status, endpoint, raw body) -- never the
 * request or response object -- so the exception cannot leak the bearer
 * token if it is serialised or dumped.
 */
class MyinvoisException extends RuntimeException
{
    public function __construct(
        string $message = '',
        protected ?int $status = null,
        protected ?string $endpoint = null,
        protected ?string $responseBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    /**
     * Build the exception from a failed HTTP response.
     */
    public static function fromResponse(Response $response, ?string $endpoint = null, ?string $message = null, ?Throwable $previous = null) : static
    {
        return new static(
            $message ?? "MyInvois request failed (HTTP {$response->status()})",
            $response->status(),
            $endpoint,
            $response->body(),
            $previous,
        );
    }

    /**
     * The HTTP status MyInvois answered with, or null when there was no
     * response (e.g. a network failure).
     */
    public function getStatus() : ?int
    {
        return $this->status;
    }

    /**
     * The full URL that was called, when known.
     */
    public function getEndpoint() : ?string
    {
        return $this->endpoint;
    }

    /**
     * The raw response body (may be HTML or empty on a gateway error).
     */
    public function getResponseBody() : ?string
    {
        return $this->responseBody;
    }

    /**
     * The response body decoded as JSON, or null when it is empty or not JSON.
     */
    public function getResponseData() : ?array
    {
        if ($this->responseBody === null || $this->responseBody === '') return null;

        $data = json_decode($this->responseBody, true);

        return is_array($data) ? $data : null;
    }
}
