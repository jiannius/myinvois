<?php

namespace Jiannius\Myinvois\Events;

/**
 * A submission did not end in VALID documents. A failed outcome is an event, not a failed
 * queue job: the job itself finishes normally. Check `$reason`:
 *
 * - `rejected`    LHDN refused documents in the POST (the whole batch, or some of it)
 * - `invalid`     LHDN accepted, then marked the documents INVALID (`$errors` are its messages)
 * - `in_flight`   another submission holds the lock, or the parent is already SUBMITTED / VALID
 * - `unavailable` LHDN / the token endpoint stayed unreachable through every retry; nothing was sent
 * - `environment` the client resolved on the worker is not the environment the job was queued for
 * - `error`       a non-retryable error (credentials, permission, a missing parent, a bug)
 * - `ambiguous`   a timeout / 5xx on the POST: LHDN may or may not have the documents. The lock is
 *                 kept until its TTL and the job is not retried -- check LHDN before resubmitting.
 *
 * Carries no buyer details.
 */
class SubmissionFailed
{
    public const REJECTED = 'rejected';

    public const INVALID = 'invalid';

    public const IN_FLIGHT = 'in_flight';

    public const UNAVAILABLE = 'unavailable';

    public const ENVIRONMENT = 'environment';

    public const ERROR = 'error';

    public const AMBIGUOUS = 'ambiguous';

    /**
     * @param  array<int, array{type: string, id: mixed}>  $parents
     * @param  array<int, string>  $documentNumbers
     * @param  array<int, string>  $errors  LHDN / SDK error messages
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public array $parents,
        public array $documentNumbers,
        public ?string $submissionUid,
        public array $errors,
        public string $reason,
        public bool $ambiguous = false,
        public array $context = [],
    ) {
    }
}
