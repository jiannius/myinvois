<?php

namespace Jiannius\Myinvois\Tests\Fixtures;

use Jiannius\Myinvois\Contracts\ResolvesMyinvoisClient;
use Jiannius\Myinvois\Myinvois;

/**
 * An Order that knows which MyInvois client submits it, the way a host app's invoice
 * resolves its org. Credentials come from the model (here fixed), never from a job payload.
 */
class OrgOrder extends Order implements ResolvesMyinvoisClient
{
    public function configureMyinvoisClient(Myinvois $client) : Myinvois
    {
        return $client
            ->setClientId('org-client')
            ->setClientSecret('org-secret')
            ->setOnBehalfOf('C-ORG-TIN')
            ->setPreprod(false)
            ->setPrivateKey(CertFixture::privateKey())
            ->setCertificate(CertFixture::certificate());
    }
}
