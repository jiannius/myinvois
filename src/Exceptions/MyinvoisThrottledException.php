<?php

namespace Jiannius\Myinvois\Exceptions;

/**
 * The package's own rate limiter is capped and the client was told not to sleep (a queue
 * job): nothing was sent. `getRetryAfter()` is the number of seconds until a slot frees up.
 * It is a MyinvoisUnavailableException (status 429) so generic handlers still apply.
 */
class MyinvoisThrottledException extends MyinvoisUnavailableException
{
}
