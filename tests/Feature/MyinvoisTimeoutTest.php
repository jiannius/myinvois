<?php

namespace Jiannius\Myinvois\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Jiannius\Myinvois\Exceptions\MyinvoisUnavailableException;
use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Myinvois;
use Jiannius\Myinvois\Tests\Fixtures\CertFixture;
use Jiannius\Myinvois\Tests\Fixtures\DocumentFixture;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every MyInvois HTTP call (the token request and the API calls) must carry a
 * connect timeout and a total timeout, so a hung LHDN connection cannot block the
 * host indefinitely. See jiannius/myinvois#7.
 */
class MyinvoisTimeoutTest extends TestCase
{
    /** @var array<string, array> Guzzle options seen per route, keyed 'token' / 'api'. */
    protected array $seen = [];

    protected function myinvois() : Myinvois
    {
        return (new Myinvois)->setClientId('id')->setClientSecret('secret');
    }

    /** Fake the token + recent-documents routes, recording the Guzzle options each is sent with. */
    protected function fakeAndRecordOptions() : void
    {
        $this->seen = [];

        Http::fake([
            '*/connect/token' => function ($request, $options) {
                $this->seen['token'] = $options;

                return Http::response(['access_token' => 'tok', 'expires_in' => 3600]);
            },
            '*documents/recent*' => function ($request, $options) {
                $this->seen['api'] = $options;

                return Http::response(['result' => []]);
            },
        ]);
    }

    #[Test]
    public function the_token_and_api_calls_have_conservative_default_timeouts() : void
    {
        $this->fakeAndRecordOptions();

        $this->myinvois()->getRecentDocuments();

        foreach (['token', 'api'] as $call) {
            $this->assertEquals(10, $this->seen[$call]['connect_timeout'] ?? null, "$call connect_timeout");
            $this->assertEquals(60, $this->seen[$call]['timeout'] ?? null, "$call timeout");
        }
    }

    #[Test]
    public function the_setters_override_the_defaults() : void
    {
        $this->fakeAndRecordOptions();

        $this->myinvois()->setConnectTimeout(3)->setTimeout(20)->getRecentDocuments();

        foreach (['token', 'api'] as $call) {
            $this->assertEquals(3, $this->seen[$call]['connect_timeout'] ?? null);
            $this->assertEquals(20, $this->seen[$call]['timeout'] ?? null);
        }
    }

    #[Test]
    public function the_config_keys_override_the_defaults() : void
    {
        config(['services.myinvois.connect_timeout' => 5, 'services.myinvois.timeout' => 90]);
        $this->fakeAndRecordOptions();

        $this->myinvois()->getRecentDocuments();

        foreach (['token', 'api'] as $call) {
            $this->assertEquals(5, $this->seen[$call]['connect_timeout'] ?? null);
            $this->assertEquals(90, $this->seen[$call]['timeout'] ?? null);
        }
    }

    #[Test]
    public function a_setter_wins_over_config() : void
    {
        config(['services.myinvois.connect_timeout' => 5, 'services.myinvois.timeout' => 90]);
        $this->fakeAndRecordOptions();

        $this->myinvois()->setConnectTimeout(2)->setTimeout(15)->getRecentDocuments();

        $this->assertEquals(2, $this->seen['api']['connect_timeout'] ?? null);
        $this->assertEquals(15, $this->seen['api']['timeout'] ?? null);
    }

    #[Test]
    #[DataProvider('unusableValues')]
    public function an_unusable_configured_value_falls_back_to_the_default(mixed $value) : void
    {
        config(['services.myinvois.connect_timeout' => $value, 'services.myinvois.timeout' => $value]);
        $this->fakeAndRecordOptions();

        $this->myinvois()->getRecentDocuments();

        foreach (['token', 'api'] as $call) {
            $this->assertEquals(10, $this->seen[$call]['connect_timeout'] ?? null);
            $this->assertEquals(60, $this->seen[$call]['timeout'] ?? null);
        }
    }

    public static function unusableValues() : array
    {
        return [
            'zero (would mean wait forever)' => [0],
            'negative' => [-5],
            'non-numeric string' => ['soon'],
            'empty string' => [''],
            'array' => [[30]],
        ];
    }

    #[Test]
    public function a_numeric_string_from_env_is_accepted() : void
    {
        config(['services.myinvois.timeout' => '45']);

        $this->assertEquals(45, $this->myinvois()->getSettings('timeout'));
    }

    #[Test]
    public function a_timeout_on_submit_documents_throws_unavailable_and_saves_nothing() : void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documentsubmissions*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 60001 milliseconds'),
        ]);

        $myinvois = $this->myinvois()
            ->setPrivateKey(CertFixture::privateKey())
            ->setCertificate(CertFixture::certificate());

        try {
            $myinvois->submitDocuments([DocumentFixture::invoice()]);
            $this->fail('Expected MyinvoisUnavailableException.');
        } catch (MyinvoisUnavailableException $e) {
            $this->assertStringContainsString('timed out', $e->getReason());
        }

        // the submission may still have reached LHDN, but we have no uuid to record
        $this->assertSame(0, MyinvoisDocument::count());
    }
}
