<?php

namespace Jiannius\Myinvois\Exceptions;

/**
 * A parent model cannot be submitted right now: another submission for it is in flight
 * (its lock is held), or its latest submission is already SUBMITTED or VALID.
 *
 * Raised before anything is sent to MyInvois, so it is always safe to tell the user to
 * wait / refresh rather than resubmit.
 */
class MyinvoisInFlightException extends MyinvoisException
{
    /**
     * @param  array<int, array{type: string, id: mixed}>  $references  the parents that were refused
     */
    public function __construct(string $message = '', protected array $references = [])
    {
        parent::__construct($message);
    }

    /**
     * The parents that were refused, as morph class + key (never the models).
     *
     * @return array<int, array{type: string, id: mixed}>
     */
    public function getParentReferences() : array
    {
        return $this->references;
    }
}
