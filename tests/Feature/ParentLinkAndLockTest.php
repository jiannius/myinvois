<?php

namespace Jiannius\Myinvois\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Jiannius\Myinvois\Enums\Status;
use Jiannius\Myinvois\Exceptions\MyinvoisInFlightException;
use Jiannius\Myinvois\Exceptions\MyinvoisUnavailableException;
use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Myinvois;
use Jiannius\Myinvois\Support\SubmissionLock;
use Jiannius\Myinvois\Tests\Fixtures\CertFixture;
use Jiannius\Myinvois\Tests\Fixtures\DocumentFixture;
use Jiannius\Myinvois\Tests\Fixtures\Order;
use Jiannius\Myinvois\Tests\Fixtures\OrgOrder;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * forParents(): rows are stamped with their parent when LHDN accepts, and the
 * submission holds the in-flight lock of every parent (jiannius/myinvois queued submission).
 */
class ParentLinkAndLockTest extends TestCase
{
    protected function myinvois() : Myinvois
    {
        return (new Myinvois)
            ->setClientId('id')
            ->setClientSecret('secret')
            ->setPreprod(false)
            ->setPrivateKey(CertFixture::privateKey())
            ->setCertificate(CertFixture::certificate());
    }

    protected function accept(array $accepted, string $status = 'Valid') : void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documentsubmissions*' => Http::response([
                'submissionUid' => 'SUB1',
                'acceptedDocuments' => $accepted,
                'documentSummary' => collect($accepted)->map(fn ($a) => [
                    'uuid' => $a['uuid'], 'submissionUid' => 'SUB1', 'status' => $status,
                ])->all(),
            ]),
        ]);
    }

    protected function lockIsFree(Order $order, bool $preprod = false) : bool
    {
        $lock = Cache::lock(SubmissionLock::key($order, $preprod), 5);

        if (! $lock->get()) return false;

        $lock->release();

        return true;
    }

    // ---- linking -------------------------------------------------------

    #[Test]
    public function rows_are_stamped_with_their_parent_the_moment_lhdn_accepts() : void
    {
        $this->accept([['uuid' => 'UID1', 'invoiceCodeNumber' => 'INV-0001']]);
        $order = Order::create();

        $result = $this->myinvois()
            ->forParents(['INV-0001' => $order])
            ->withoutPolling()
            ->submitDocuments([DocumentFixture::invoice()]);

        $row = MyinvoisDocument::first();

        $this->assertSame(Order::class, $row->parent_type);
        $this->assertSame($order->id, $row->parent_id);
        $this->assertSame('submitted', $row->status->value);
        $this->assertTrue($row->parent->is($order));
        $this->assertSame(Status::SUBMITTED, $order->fresh()->myinvois_status);
        $this->assertCount(1, $result['myinvois_documents']);
    }

    #[Test]
    public function a_consolidated_document_links_each_line_to_its_own_parent() : void
    {
        $this->accept([['uuid' => 'UID1', 'invoiceCodeNumber' => 'INV-0001']]);
        $a = Order::create();
        $b = Order::create();

        $this->myinvois()
            ->forParents(['Widget' => $a, 'Receipt 1001-2000' => $b])
            ->withoutPolling()
            ->submitDocuments([DocumentFixture::consolidated()]);

        $this->assertSame($a->id, MyinvoisDocument::where('document_number', 'Widget')->value('parent_id'));
        $this->assertSame($b->id, MyinvoisDocument::where('document_number', 'Receipt 1001-2000')->value('parent_id'));
        $this->assertSame(['INV-0001', 'INV-0001'], MyinvoisDocument::pluck('consolidate_number')->all());
    }

    #[Test]
    public function a_row_without_a_parent_is_refused_before_any_network_call() : void
    {
        Http::fake();
        $order = Order::create();

        try {
            $this->myinvois()->forParents(['Widget' => $order])->submitDocuments([DocumentFixture::consolidated()]);
            $this->fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Receipt 1001-2000', $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertTrue($this->lockIsFree($order));
    }

    #[Test]
    public function without_for_parents_nothing_is_stamped_and_no_lock_is_taken() : void
    {
        $this->accept([['uuid' => 'UID1', 'invoiceCodeNumber' => 'INV-0001']]);

        $this->myinvois()->withoutPolling()->submitDocuments([DocumentFixture::invoice()]);

        $this->assertNull(MyinvoisDocument::first()->parent_type);
    }

    #[Test]
    public function without_polling_skips_the_status_poll() : void
    {
        $this->accept([['uuid' => 'UID1', 'invoiceCodeNumber' => 'INV-0001']]);

        $this->myinvois()->withoutPolling()->submitDocuments([DocumentFixture::invoice()]);

        // the token and the POST only: no GET documentsubmissions/SUB1
        Http::assertSentCount(2);
        $this->assertSame('submitted', MyinvoisDocument::first()->status->value);
    }

    // ---- lock ----------------------------------------------------------

    #[Test]
    public function a_held_lock_refuses_the_submission_before_anything_is_sent() : void
    {
        $this->accept([['uuid' => 'UID1', 'invoiceCodeNumber' => 'INV-0001']]);
        $order = Order::create();

        $owner = SubmissionLock::acquire([$order], false);

        try {
            $this->myinvois()->forParents(['INV-0001' => $order])->submitDocuments([DocumentFixture::invoice()]);
            $this->fail('Expected MyinvoisInFlightException.');
        } catch (MyinvoisInFlightException $e) {
            $this->assertSame([['type' => Order::class, 'id' => $order->id]], $e->getParentReferences());
        }

        Http::assertNothingSent();
        $this->assertSame(0, MyinvoisDocument::count());

        // the first holder's lock is untouched by the refused attempt
        $this->assertFalse($this->lockIsFree($order));
        SubmissionLock::release([$order], false, $owner);
        $this->assertTrue($this->lockIsFree($order));
    }

    #[Test]
    public function the_lock_is_released_once_the_rows_are_written() : void
    {
        $this->accept([['uuid' => 'UID1', 'invoiceCodeNumber' => 'INV-0001']]);
        $order = Order::create();

        $this->myinvois()->forParents(['INV-0001' => $order])->withoutPolling()->submitDocuments([DocumentFixture::invoice()]);

        $this->assertTrue($this->lockIsFree($order));
    }

    #[Test]
    public function a_second_submission_of_a_submitted_or_valid_parent_is_refused() : void
    {
        $this->accept([['uuid' => 'UID1', 'invoiceCodeNumber' => 'INV-0001']]);

        foreach (['submitted', 'valid'] as $status) {
            $order = Order::create();
            MyinvoisDocument::create(['parent_type' => Order::class, 'parent_id' => $order->id, 'status' => $status, 'is_preprod' => false]);

            try {
                $this->myinvois()->forParents(['INV-0001' => $order])->submitDocuments([DocumentFixture::invoice()]);
                $this->fail("Expected MyinvoisInFlightException for $status.");
            } catch (MyinvoisInFlightException) {
            }

            $this->assertTrue($this->lockIsFree($order), "the lock must not leak after a $status refusal");
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function an_invalid_or_cancelled_latest_submission_may_be_resubmitted() : void
    {
        $this->accept([['uuid' => 'UID1', 'invoiceCodeNumber' => 'INV-0001']]);

        foreach (['invalid', 'cancelled'] as $status) {
            $order = Order::create();
            MyinvoisDocument::create(['parent_type' => Order::class, 'parent_id' => $order->id, 'status' => $status, 'is_preprod' => false]);

            $this->myinvois()->forParents(['INV-0001' => $order])->withoutPolling()->submitDocuments([DocumentFixture::invoice()]);

            $this->assertSame('submitted', MyinvoisDocument::where('parent_id', $order->id)->orderByDesc('id')->first()->status->value);
        }
    }

    #[Test]
    public function the_latest_submission_in_the_same_environment_decides() : void
    {
        $this->accept([['uuid' => 'UID1', 'invoiceCodeNumber' => 'INV-0001']]);
        $order = Order::create();

        // a VALID preprod row must not block a production submission
        MyinvoisDocument::create(['parent_type' => Order::class, 'parent_id' => $order->id, 'status' => 'valid', 'is_preprod' => true]);

        $this->myinvois()->forParents(['INV-0001' => $order])->withoutPolling()->submitDocuments([DocumentFixture::invoice()]);

        $this->assertSame(1, MyinvoisDocument::where('is_preprod', false)->count());
    }

    #[Test]
    public function a_consolidated_submission_takes_all_its_locks_or_none() : void
    {
        $this->accept([['uuid' => 'UID1', 'invoiceCodeNumber' => 'INV-0001']]);
        $a = Order::create();
        $b = Order::create();

        SubmissionLock::acquire([$b], false);

        try {
            $this->myinvois()
                ->forParents(['Widget' => $a, 'Receipt 1001-2000' => $b])
                ->submitDocuments([DocumentFixture::consolidated()]);
            $this->fail('Expected MyinvoisInFlightException.');
        } catch (MyinvoisInFlightException) {
        }

        $this->assertTrue($this->lockIsFree($a), 'the lock taken first must be handed back');
        Http::assertNothingSent();
    }

    #[Test]
    public function locks_are_separate_per_environment() : void
    {
        $order = Order::create();

        $this->assertNotSame(SubmissionLock::key($order, true), SubmissionLock::key($order, false));

        SubmissionLock::acquire([$order], true);

        $this->assertTrue($this->lockIsFree($order, false));
    }

    #[Test]
    public function a_rejection_releases_the_lock() : void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documentsubmissions*' => Http::response(['rejectedDocuments' => [['invoiceCodeNumber' => 'INV-0001']]], 200),
        ]);
        $order = Order::create();

        $this->myinvois()->forParents(['INV-0001' => $order])->submitDocuments([DocumentFixture::invoice()]);

        $this->assertSame(0, MyinvoisDocument::count());
        $this->assertTrue($this->lockIsFree($order));
    }

    #[Test]
    public function a_timeout_on_the_post_keeps_the_lock_until_its_ttl() : void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documentsubmissions*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
        ]);
        $order = Order::create();

        try {
            $this->myinvois()->forParents(['INV-0001' => $order])->submitDocuments([DocumentFixture::invoice()]);
            $this->fail('Expected MyinvoisUnavailableException.');
        } catch (MyinvoisUnavailableException $e) {
            $this->assertTrue($e->mayHaveReachedMyinvois());
        }

        $this->assertFalse($this->lockIsFree($order));
    }

    #[Test]
    public function a_5xx_on_the_post_keeps_the_lock_until_its_ttl() : void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documentsubmissions*' => Http::response('', 503),
        ]);
        $order = Order::create();

        $this->expectException(MyinvoisUnavailableException::class);

        try {
            $this->myinvois()->forParents(['INV-0001' => $order])->submitDocuments([DocumentFixture::invoice()]);
        } finally {
            $this->assertFalse($this->lockIsFree($order));
        }
    }

    #[Test]
    public function a_429_definitely_did_not_reach_lhdn_so_the_lock_is_released() : void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*documentsubmissions*' => Http::response('', 429, ['Retry-After' => '7']),
        ]);
        $order = Order::create();

        try {
            $this->myinvois()->forParents(['INV-0001' => $order])->submitDocuments([DocumentFixture::invoice()]);
            $this->fail('Expected MyinvoisUnavailableException.');
        } catch (MyinvoisUnavailableException $e) {
            $this->assertFalse($e->mayHaveReachedMyinvois());
        }

        $this->assertTrue($this->lockIsFree($order));
    }

    #[Test]
    public function an_unreachable_token_endpoint_definitely_did_not_reach_lhdn_so_the_lock_is_released() : void
    {
        Http::fake(['*/connect/token' => fn () => throw new ConnectionException('cURL error 28')]);
        $order = Order::create();

        try {
            $this->myinvois()->forParents(['INV-0001' => $order])->submitDocuments([DocumentFixture::invoice()]);
            $this->fail('Expected MyinvoisUnavailableException.');
        } catch (MyinvoisUnavailableException $e) {
            $this->assertFalse($e->mayHaveReachedMyinvois());
        }

        $this->assertTrue($this->lockIsFree($order));
    }

    #[Test]
    public function a_lock_the_caller_owns_is_used_and_released_the_same_way() : void
    {
        $this->accept([['uuid' => 'UID1', 'invoiceCodeNumber' => 'INV-0001']]);
        $order = Order::create();
        $owner = SubmissionLock::acquire([$order], false);

        $this->assertFalse($this->lockIsFree($order));

        $this->myinvois()->forParents(['INV-0001' => $order], $owner)->withoutPolling()->submitDocuments([DocumentFixture::invoice()]);

        $this->assertSame(1, MyinvoisDocument::count());
        $this->assertTrue($this->lockIsFree($order));
    }

    #[Test]
    public function a_caller_owned_lock_that_lapsed_is_retaken_unless_someone_else_has_it() : void
    {
        $this->accept([['uuid' => 'UID1', 'invoiceCodeNumber' => 'INV-0001']]);
        $order = Order::create();

        // lapsed: nobody holds it now
        $this->myinvois()->forParents(['INV-0001' => $order], 'stale-owner')->withoutPolling()->submitDocuments([DocumentFixture::invoice()]);
        $this->assertSame(1, MyinvoisDocument::count());

        // taken by someone else
        $other = Order::create();
        SubmissionLock::acquire([$other], false);

        $this->expectException(MyinvoisInFlightException::class);
        $this->myinvois()->forParents(['INV-0001' => $other], 'stale-owner')->submitDocuments([DocumentFixture::invoice()]);
    }

    // ---- client resolution --------------------------------------------

    #[Test]
    public function client_for_applies_the_parents_contract() : void
    {
        $client = Myinvois::clientFor(OrgOrder::create());

        $this->assertSame('org-client', $client->getSettings('client_id'));
        $this->assertSame('org-secret', $client->getSettings('client_secret'));
        $this->assertSame('C-ORG-TIN', $client->getSettings('on_behalf_of'));
        $this->assertFalse($client->getSettings('preprod'));
    }

    #[Test]
    public function client_for_falls_back_to_the_config_cascade_without_the_contract() : void
    {
        config()->set('services.myinvois.preprod', true);
        config()->set('services.myinvois.preprod_client_id', 'cfg-preprod');

        $client = Myinvois::clientFor(Order::create());

        $this->assertTrue($client->getSettings('preprod'));
        $this->assertSame('cfg-preprod', $client->getSettings('client_id'));
    }

    #[Test]
    public function client_for_returns_a_fresh_client_each_time() : void
    {
        $order = OrgOrder::create();

        $this->assertNotSame(Myinvois::clientFor($order), Myinvois::clientFor($order));
    }

    #[Test]
    public function client_for_uses_the_fake_when_one_is_installed() : void
    {
        $fake = Myinvois::fake();

        $this->assertSame($fake, Myinvois::clientFor(Order::create()));
    }
}
