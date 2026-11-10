<?php

namespace Jiannius\Myinvois\Support;

/**
 * Internal: the documents/recent lookup of a pending attempt gave no usable answer. Never
 * leaves the package -- SubmitAttempts turns it into MyinvoisPendingAttemptException.
 */
class LookupFailed extends \RuntimeException
{
}
