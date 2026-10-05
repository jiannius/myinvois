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
    public function an_unusable_setter_value_falls_through_to_config_then_default() : void
    {
        // setTimeout(0) must not mean "wait forever"; it is ignored like an unusable config value
        config(['services.myinvois.timeout' => 45]);
        $m = $this->myinvois()->setTimeout(0)->setConnectTimeout(-1);

        $this->assertEquals(45, $m->getSettings('timeout'));
        $this->assertEquals(10, $m->getSettings('connect_timeout'));

        config(['services.myinvois.timeout' => null]);

        $this->assertEquals(60, $m->getSettings('timeout'));
    }

    #[Test]
    public function an_unusable_setter_value_is_not_sent_to_http() : void
    {
        $this->fakeAndRecordOptions();

        $this->myinvois()->setTimeout(0)->setConnectTimeout(0)->getRecentDocuments();

        foreach (['token', 'api'] as $call) {
            $this->assertEquals(10, $this->seen[$call]['connect_timeout'] ?? null);
            $this->assertEquals(60, $this->seen[$call]['timeout'] ?? null);
        }
    }

    /** Fake the submit POST and the follow-up poll GET, recording the Guzzle options of each. */
    protected function fakeSubmitAndPoll() : void
    {
        $this->seen = ['poll' => []];

        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documentsubmissions/SUB1*' => function ($request, $options) {
                $this->seen['poll'][] = $options;

                return Http::response(['documentSummary' => [['uuid' => 'UID1', 'submissionUid' => 'SUB1', 'status' => 'Valid']]]);
            },
            '*documentsubmissions' => function ($request, $options) {
                $this->seen['post'] = $options;

                return Http::response([
                    'submissionUid' => 'SUB1',
                    'acceptedDocuments' => [['uuid' => 'UID1', 'invoiceCodeNumber' => 'INV-0001']],
                ]);
            },
        ]);
    }

    protected function signingMyinvois() : Myinvois
    {
        return $this->myinvois()
            ->setPrivateKey(CertFixture::privateKey())
            ->setCertificate(CertFixture::certificate());
    }

    #[Test]
    public function the_post_submit_poll_uses_a_shorter_total_timeout_than_the_post() : void
    {
        $this->fakeSubmitAndPoll();

        $this->signingMyinvois()->submitDocuments([DocumentFixture::invoice()]);

        $this->assertEquals(60, $this->seen['post']['timeout'] ?? null);
        $this->assertEquals(10, $this->seen['post']['connect_timeout'] ?? null);
        $this->assertNotEmpty($this->seen['poll']);
        $this->assertEquals(15, $this->seen['poll'][0]['timeout'] ?? null);
        $this->assertEquals(10, $this->seen['poll'][0]['connect_timeout'] ?? null);
    }

    #[Test]
    public function a_configured_timeout_below_the_poll_cap_is_kept_for_the_poll() : void
    {
        $this->fakeSubmitAndPoll();

        $this->signingMyinvois()->setTimeout(8)->submitDocuments([DocumentFixture::invoice()]);

        $this->assertEquals(8, $this->seen['post']['timeout'] ?? null);
        $this->assertEquals(8, $this->seen['poll'][0]['timeout'] ?? null);
    }

    #[Test]
    public function a_direct_get_submission_call_keeps_the_full_timeout_even_after_a_poll() : void
    {
        $this->fakeSubmitAndPoll();
        $myinvois = $this->signingMyinvois();

        $myinvois->submitDocuments([DocumentFixture::invoice()]);
        $this->seen['poll'] = [];
        $myinvois->getSubmission('SUB1');

        $this->assertEquals(60, $this->seen['poll'][0]['timeout'] ?? null);
    }

    #[Test]
    public function the_poll_cap_is_restored_even_when_the_poll_fails() : void
    {
        $this->seen = ['calls' => []];
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documentsubmissions/SUB1*' => function ($request, $options) {
                $this->seen['calls'][] = $options;

                // the first call (the poll) fails, the second (a direct call) succeeds
                return count($this->seen['calls']) === 1 ? Http::response('down', 503) : Http::response([]);
            },
        ]);
        $myinvois = $this->myinvois();

        $myinvois->createMyinvoisDocuments(
            ['submissionUid' => 'SUB1', 'acceptedDocuments' => [['uuid' => 'UID1', 'invoiceCodeNumber' => 'INV-0001']]],
            [DocumentFixture::invoice()],
        );
        $myinvois->getSubmission('SUB1');

        $this->assertCount(2, $this->seen['calls']);
        $this->assertEquals(15, $this->seen['calls'][0]['timeout'] ?? null);
        $this->assertEquals(60, $this->seen['calls'][1]['timeout'] ?? null);
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
