<?php

namespace Jiannius\Myinvois\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Jiannius\Myinvois\Events\SubmissionAccepted;
use Jiannius\Myinvois\Events\SubmissionFailed;
use Jiannius\Myinvois\Exceptions\MyinvoisException;
use Jiannius\Myinvois\Exceptions\MyinvoisInFlightException;
use Jiannius\Myinvois\Exceptions\MyinvoisThrottledException;
use Jiannius\Myinvois\Exceptions\MyinvoisUnavailableException;
use Jiannius\Myinvois\Myinvois;
use Jiannius\Myinvois\Support\ParentReference;
use Jiannius\Myinvois\Support\SubmissionLock;
use Throwable;

/**
 * Submits documents to LHDN on a queue worker. Dispatch it through
 * Myinvois::queueSubmission(), which validates, takes the in-flight locks and passes the
 * lock owner token along.
 *
 * The payload holds the flat documents, parent references (morph class + key), the preprod
 * flag, the lock token and the app's opaque context -- and is encrypted at rest. It never
 * holds a credential, key, certificate or token: the worker rebuilds the client from the
 * first parent through ResolvesMyinvoisClient.
 *
 * A failed outcome is an event (SubmissionFailed), never a failed queue job. Retries happen
 * only when LHDN definitely did not receive the request.
 */
class SubmitDocuments implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Seconds to wait before each retry of a request LHDN definitely did not receive (a
     * token failure or a 429), when LHDN gave no Retry-After. Three retries at most.
     */
    public const RETRY_DELAYS = [30, 60, 120];

    /**
     * Unlimited by the queue's own counter: a release() for the rate limiter also counts as
     * an attempt there. retryUntil() bounds the job instead, and retries are counted here.
     */
    public $tries = 0;

    public $timeout = 120;

    /**
     * @param  array<int, array>  $documents  the flat document contract
     * @param  array<string, array{type: string, id: mixed}>  $parents  row `document_number` => parent reference
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public array $documents,
        public array $parents,
        public bool $preprod,
        public string $lockOwner,
        public array $context = [],
    ) {
    }

    public function retryUntil() : DateTimeInterface
    {
        return now()->addHour();
    }

    public function handle() : void
    {
        $models = $this->resolveParents();

        if ($models === null) {
            SubmissionLock::release($this->parents, $this->preprod, $this->lockOwner);
            $this->announce(SubmissionFailed::ERROR, ['A document to submit no longer exists.']);

            return;
        }

        $client = Myinvois::clientFor(reset($models))->withoutThrottleSleep();

        if ((bool) $client->getSettings('preprod') !== $this->preprod) {
            SubmissionLock::release($this->parents, $this->preprod, $this->lockOwner);
            $this->announce(SubmissionFailed::ENVIRONMENT, [
                'The MyInvois environment of this document changed after it was queued. Submit it again.',
            ]);

            return;
        }

        try {
            $result = $client->forParents($models, $this->lockOwner)->withoutPolling()->submitDocuments($this->documents);
        }
        catch (MyinvoisInFlightException $e) {
            $this->announce(SubmissionFailed::IN_FLIGHT, [$e->getMessage()]);

            return;
        }
        catch (MyinvoisThrottledException $e) {
            // our own limiter is capped and nothing was sent: wait for a slot, never sleep
            $this->release(max(1, $e->getRetryAfter() ?? 1));

            return;
        }
        catch (MyinvoisException $e) {
            $this->handleFailure($e);

            return;
        }
        catch (Throwable $e) {
            report($e);

            // whether the lock was kept is decided where it was taken: kept when the POST
            // had started, freed otherwise
            $this->announce(SubmissionFailed::ERROR, [$e->getMessage()], ambiguous: $client->postStarted());

            return;
        }

        $this->handleResult($result);
    }

    /**
     * The queue gave up on the job (a worker timeout, the retryUntil cap, a crash). The POST
     * may have been in progress, so the lock is left to expire.
     */
    public function failed(Throwable $e) : void
    {
        report($e);

        $this->announce(SubmissionFailed::AMBIGUOUS, [
            'The submission job was stopped before it finished. Check MyInvois before submitting again.',
        ], ambiguous: true);
    }

    protected function handleFailure(MyinvoisException $e) : void
    {
        // a timeout / 5xx on the POST: LHDN may have the documents. No retry, the lock stays
        // until its TTL so nobody resubmits blindly.
        if ($e->mayHaveReachedMyinvois()) {
            $this->announce(SubmissionFailed::AMBIGUOUS, [$e->getMessage()], ambiguous: true);

            return;
        }

        // a token failure or a 429: LHDN did not receive the request, so retrying is safe
        if ($e instanceof MyinvoisUnavailableException) {
            $retry = $this->countRetry();

            if ($retry <= count(static::RETRY_DELAYS)) {
                $this->release($e->getRetryAfter() ?? static::RETRY_DELAYS[$retry - 1]);

                return;
            }

            $this->announce(SubmissionFailed::UNAVAILABLE, [$e->getMessage()]);

            return;
        }

        // credentials rejected, permission denied: retrying will not help
        $this->announce(SubmissionFailed::ERROR, [$e->getMessage()]);
    }

    /**
     * @param  mixed  $result  what submitDocuments() returned
     */
    protected function handleResult($result) : void
    {
        $rows = collect(is_array($result) ? data_get($result, 'myinvois_documents', []) : []);
        $response = is_array($result) && array_key_exists('response', $result) ? $result['response'] : $result;
        $rejected = collect(data_get($response, 'rejectedDocuments', []));

        if ($rejected->isNotEmpty()) {
            $numbers = $rejected->flatMap(function ($item) {
                $number = data_get($item, 'invoiceCodeNumber');
                $document = collect($this->documents)->firstWhere('number', $number);

                return $document ? Myinvois::rowNumbers($document) : array_filter([$number]);
            })->all();

            $this->announce(
                SubmissionFailed::REJECTED,
                $rejected->flatMap(fn ($item) => $this->errorMessages($item))->unique()->values()->all(),
                $numbers,
                data_get($response, 'submissionUid'),
            );
        }
        elseif ($rows->isEmpty()) {
            // a whole-batch rejection (HTTP 4xx) comes back as the LHDN error body
            $this->announce(SubmissionFailed::REJECTED, $this->errorMessages($response) ?: ['MyInvois did not accept the submission.']);
        }

        if ($rows->isEmpty()) return;

        $numbers = $rows->pluck('document_number')->filter()->unique()->values()->all();
        $uid = $rows->first()->submission_uid;

        event(new SubmissionAccepted($this->referencesFor($numbers), $numbers, $uid, $this->context));

        PollSubmission::dispatch($uid, $this->referencesFor($numbers), $this->preprod, $this->context)
            ->onConnection(config('services.myinvois.queue_connection'))
            ->onQueue(config('services.myinvois.queue'));
    }

    /**
     * LHDN's error messages from a rejected-document item or a whole error body.
     *
     * @return array<int, string>
     */
    protected function errorMessages($body) : array
    {
        return collect([
            data_get($body, 'error.message'),
            data_get($body, 'message'),
            ...(array) data_get($body, 'error.details.*.message', []),
        ])->filter(fn ($message) => is_string($message) && $message !== '')->unique()->values()->all();
    }

    /**
     * @param  array<int, string>  $errors
     * @param  array<int, string>|null  $numbers  defaults to every row of the job
     */
    protected function announce(string $reason, array $errors, ?array $numbers = null, ?string $submissionUid = null, bool $ambiguous = false) : void
    {
        $numbers ??= array_keys($this->parents);

        event(new SubmissionFailed(
            $this->referencesFor($numbers),
            array_values($numbers),
            $submissionUid,
            $errors,
            $reason,
            $ambiguous,
            $this->context,
        ));
    }

    /**
     * @param  array<int, string>  $numbers
     * @return array<int, array{type: string, id: mixed}>
     */
    protected function referencesFor(array $numbers) : array
    {
        return collect($numbers)
            ->map(fn ($number) => $this->parents[$number] ?? null)
            ->filter()
            ->unique(fn ($reference) => $reference['type'].':'.$reference['id'])
            ->values()
            ->all();
    }

    /**
     * Load the parents, keyed by row number; null when one of them no longer exists.
     *
     * @return array<string, Model>|null
     */
    protected function resolveParents() : ?array
    {
        $models = [];
        $loaded = [];

        foreach ($this->parents as $number => $reference) {
            $key = $reference['type'].':'.$reference['id'];
            $loaded[$key] ??= ParentReference::resolve($reference);

            if (! $loaded[$key]) return null;

            $models[$number] = $loaded[$key];
        }

        return $models ?: null;
    }

    /**
     * Count one retry of this submission and return the running total.
     */
    protected function countRetry() : int
    {
        $store = Cache::store(config('services.myinvois.lock_store'));
        $key = 'myinvois:submit-retries:'.$this->lockOwner;

        $store->add($key, 0, 86400);

        return (int) $store->increment($key);
    }
}
