<?php

namespace Jiannius\Myinvois\Exceptions;

/**
 * A parent has a pending submit attempt (an earlier POST timed out or the process died, so
 * LHDN may hold the document although no local row says so), and the lookup made before
 * resending refused the new submission. Nothing was sent. Check getReason():
 *
 * - `still_checking`    nothing found yet and the attempt is under 10 minutes old: try again later
 * - `lookup_failed`     MyInvois could not be asked (an outage, an error answer): try again later
 * - `multiple_matches`  MyInvois holds more than one live e-invoice for the number: a person must
 *                       check MyInvois (and cancel the extra) -- never resubmit automatically
 * - `recovered`         the earlier submission was found and its rows restored (linked to the
 *                       parent, SUBMITTED or VALID): it is submitted, there is nothing to resend
 *
 * A subclass of MyinvoisInFlightException, so code that already catches that keeps working.
 */
class MyinvoisPendingAttemptException extends MyinvoisInFlightException
{
    public const STILL_CHECKING = 'still_checking';

    public const LOOKUP_FAILED = 'lookup_failed';

    public const MULTIPLE_MATCHES = 'multiple_matches';

    public const RECOVERED = 'recovered';

    /**
     * @param  array<int, array{type: string, id: mixed}>  $references
     * @param  array<int, string>  $documentNumbers
     * @param  array<int, string>  $submissionUids  for `recovered`: the submissions restored
     */
    public function __construct(
        string $message,
        protected string $reason,
        array $references = [],
        protected array $documentNumbers = [],
        protected array $submissionUids = [],
    ) {
        parent::__construct($message, $references);
    }

    public function getReason() : string
    {
        return $this->reason;
    }

    /**
     * @return array<int, string>
     */
    public function getDocumentNumbers() : array
    {
        return $this->documentNumbers;
    }

    /**
     * @return array<int, string>
     */
    public function getSubmissionUids() : array
    {
        return $this->submissionUids;
    }
}
