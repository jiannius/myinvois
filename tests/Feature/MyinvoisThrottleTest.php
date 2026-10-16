<?php

namespace Jiannius\Myinvois\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Jiannius\Myinvois\Myinvois;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * LHDN rate limits apply per client id and per endpoint, not per uid / uuid / TIN in the URL.
 */
class MyinvoisThrottleTest extends TestCase
{
    protected function myinvois(string $clientId = 'client-a') : Myinvois
    {
        return (new Myinvois)->setClientId($clientId)->setClientSecret('secret');
    }

    protected function fakeApi() : void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*' => Http::response([]),
        ]);
    }

    #[Test]
    public function the_limiter_is_shared_across_uids_of_the_same_endpoint() : void
    {
        $this->fakeApi();
        $m = $this->myinvois();

        $m->getSubmission('UID-1');
        $m->getSubmission('UID-2');
        $m->getSubmission('UID-3');

        $this->assertSame(3, RateLimiter::attempts($m->getThrottleKey('documentsubmissions/ANY')));
    }

    #[Test]
    public function the_limiter_is_separate_across_client_ids() : void
    {
        $this->fakeApi();

        $a = $this->myinvois('client-a');
        $b = $this->myinvois('client-b');

        $a->getSubmission('UID-1');
        $a->getSubmission('UID-1');
        $b->getSubmission('UID-1');

        $this->assertSame(2, RateLimiter::attempts($a->getThrottleKey('documentsubmissions/UID-1')));
        $this->assertSame(1, RateLimiter::attempts($b->getThrottleKey('documentsubmissions/UID-1')));
    }

    #[Test]
    public function different_endpoints_keep_their_own_limiter() : void
    {
        $m = $this->myinvois();

        $this->assertNotSame($m->getThrottleKey('documentsubmissions'), $m->getThrottleKey('documentsubmissions/UID-1'));
        $this->assertNotSame($m->getThrottleKey('documents/U1/raw'), $m->getThrottleKey('documents/U1/details'));
        $this->assertNotSame($m->getThrottleKey('documents/recent'), $m->getThrottleKey('documents/search'));
    }

    #[Test]
    #[DataProvider('idVariants')]
    public function ids_never_reach_the_key(string $a, string $b) : void
    {
        $m = $this->myinvois();

        $this->assertSame($m->getThrottleKey($a), $m->getThrottleKey($b));
    }

    public static function idVariants() : array
    {
        return [
            'submission' => ['documentsubmissions/AAA111', 'documentsubmissions/BBB222'],
            'raw' => ['documents/AAA111/raw', 'documents/BBB222/raw'],
            'details' => ['documents/AAA111/details', 'documents/BBB222/details'],
            'state' => ['documents/state/AAA111/state', 'documents/state/BBB222/state'],
            'validate tin' => ['taxpayer/validate/C111', 'taxpayer/validate/C222'],
        ];
    }
}
