<?php

namespace Jiannius\Myinvois\Events;

use Jiannius\Myinvois\Enums\Status;
use Jiannius\Myinvois\Models\MyinvoisDocument;

/**
 * A myinvois_documents row's `status` changed -- from any path: the submit job, the poll
 * job, the myinvois:sync sweep, getSubmission() / getDocumentDetails(), cancelDocument().
 * `$from` is null when the row was just created.
 */
class DocumentStatusChanged
{
    public function __construct(
        public MyinvoisDocument $document,
        public ?Status $from,
        public ?Status $to,
    ) {
    }
}
