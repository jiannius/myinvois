<?php

namespace Jiannius\Myinvois\Jobs;

/**
 * Thrown from SubmitDocuments' before-POST hook when another copy of the job has taken over
 * the run marker: this copy must stand down before its request leaves. Internal to the job,
 * never reaches the app. Deliberately not a MyinvoisException, so the client keeps the
 * in-flight lock (it is shared with the copy that took over) instead of freeing it.
 *
 * @internal
 */
class RunSupersededException extends \RuntimeException
{
}
