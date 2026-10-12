<?php

namespace Jiannius\Myinvois\Events;

/**
 * LHDN validated the documents (status VALID). Fired by the poll job, once per submission.
 * If the poll job gave up before LHDN answered, the status sweep (myinvois:sync) still
 * resolves the rows and DocumentStatusChanged fires -- but this event does not.
 */
class SubmissionSucceeded
{
    /**
     * @param  array<int, array{type: string, id: mixed}>  $parents
     * @param  array<int, string>  $documentNumbers
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public array $parents,
        public array $documentNumbers,
        public ?string $submissionUid,
        public array $context = [],
    ) {
    }
}
