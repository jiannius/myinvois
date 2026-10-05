<?php

namespace Jiannius\Myinvois\Exceptions;

/**
 * MyInvois rejected our identity: a 4xx from the OAuth token endpoint
 * (e.g. invalid_client) or a 401 from an API call. Retrying with the same
 * credentials will not help -- check the client id / secret and that they
 * match the selected environment (production vs sandbox).
 */
class MyinvoisAuthenticationException extends MyinvoisException
{
}
