<?php

namespace Jiannius\Myinvois\Exceptions;

/**
 * MyInvois answered 403: the credentials are valid but the taxpayer is not
 * allowed to do this (e.g. no intermediary permission on the portal).
 */
class MyinvoisPermissionException extends MyinvoisException
{
}
