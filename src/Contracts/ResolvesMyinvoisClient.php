<?php

namespace Jiannius\Myinvois\Contracts;

use Jiannius\Myinvois\Myinvois;

/**
 * Implemented on the parent model (the invoice, refund, ... a MyinvoisDocument belongs to)
 * so the package can rebuild the right MyInvois client later, on a queue worker or in the
 * status sweep, without anything secret ever being put in a job payload.
 *
 * Set whatever differs per submitter on the client: on-behalf-of (TIN / BRN), preprod,
 * and a per-org client id / secret / private key / certificate. Anything left unset falls
 * back to the usual config('services.myinvois.*') cascade.
 */
interface ResolvesMyinvoisClient
{
    /**
     * Configure the given (fresh) client for this model and return it.
     */
    public function configureMyinvoisClient(Myinvois $client) : Myinvois;
}
