<?php

namespace Jiannius\Myinvois\Events;

/**
 * LHDN accepted the submission (the HTTP POST succeeded) and the rows are saved as
 * `submitted`. The verdict (valid / invalid) comes later: SubmissionSucceeded or
 * SubmissionFailed. Carries no buyer details.
 */
class SubmissionAccepted
{
    /**
     * @param  array<int, array{type: string, id: mixed}>  $parents  morph class + key, never models
     * @param  array<int, string>  $documentNumbers  the myinvois_documents `document_number`s
     * @param  array<string, mixed>  $context  what the app passed to queueSubmission()
     */
    public function __construct(
        public array $parents,
        public array $documentNumbers,
        public ?string $submissionUid,
        public array $context = [],
    ) {
    }
}
