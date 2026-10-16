<?php

namespace Jiannius\Myinvois\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
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
use Jiannius\Myinvois\Models\MyinvoisDocument;
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
     * States of the run marker (see claimRun()). In the cache the claimed state is stored as
     * `claimed:<run id>` and the accepted state as `accepted:<submission uid>`.
     */
    public const RUN_CLAIMED = 'claimed';

    public const RUN_POSTING = 'posting';

    public const RUN_ACCEPTED = 'accepted';

    public const RUN_DONE = 'done';

    /**
     * Seconds the `claimed` marker lives: one job timeout plus slack. Only a run that is
     * still before the POST can be waiting on it, and that run is allowed to proceed anyway.
     */
    public const CLAIMED_SECONDS = 150;

    /**
     * Seconds the `posting` and `done` markers live. They must outlast the queue's
     * `retry_after` (a redelivery can only come after it), whatever it is set to, or a
     * redelivery would find no marker and post again. Both are safe to keep: `posting`
     * reports an ambiguous failure, `done` is silent.
     */
    public const SETTLED_SECONDS = 86400;

    /**
     * Unlimited by the queue's own counter: a release() for the rate limiter also counts as
     * an attempt there. retryUntil() bounds the job instead, and retries are counted here.
     */
    public $tries = 0;

    /**
     * Safety net for an exception nothing here handles: the queue stops the job after this
     * many, instead of re-running it forever (`$tries = 0` never gives up on its own).
     */
    public $maxExceptions = 3;

    /**
     * Keep this below the queue connection's `retry_after`: a job that outlives it is handed
     * to a second worker while the first is still running (the run marker stops the second
     * from posting again, but it is better never to need it). CLAIMED_SECONDS follows it.
     */
    public $timeout = 120;

    /**
     * Identifies this run of handle() in the `claimed:<run id>` marker, so a copy that was
     * overtaken can tell before its POST that it no longer owns the run. Set in handle().
     */
    protected ?string $runId = null;

    /**
     * Whether this run already flipped the marker to `posting` (a 401 retry sends the POST
     * again through the same hook).
     */
    protected bool $postingSet = false;

    /**
     * Set when this run stood down because another copy owns the marker.
     */
    protected bool $superseded = false;

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
        // run only once the surrounding database transaction (if any) has committed, so the
        // worker never sees parents that do not exist yet (Queueable already declares the property)
        $this->afterCommit = true;
    }

    public function retryUntil() : DateTimeInterface
    {
        return now()->addHour();
    }

    /**
     * Seconds before the queue re-runs the job after an exception nothing handled.
     *
     * @return array<int, int>
     */
    public function backoff() : array
    {
        return static::RETRY_DELAYS;
    }

    public function handle() : void
    {
        $this->runId = bin2hex(random_bytes(8));
        $this->postingSet = false;
        $this->superseded = false;

        [$state, $detail] = $this->claimRun();

        // A second copy of this job (a queue whose retry_after is shorter than the run, or a
        // worker that died and the job was served again) must not post twice, and must not
        // be silent when the first copy may have reached LHDN.
        if ($state === static::RUN_DONE) return;

        if ($state === static::RUN_POSTING) {
            // the POST may have gone out and nobody saw the outcome: the lock is left alone
            // to expire, nothing is posted, and the app is told once
            $this->announceStopped();
            $this->setRunState(static::RUN_DONE);

            return;
        }

        if ($state === static::RUN_ACCEPTED) {
            // LHDN accepted and the rows are saved; the worker died before the app was told
            $this->finishAccepted((string) $detail);
            $this->setRunState(static::RUN_DONE);

            return;
        }

        // `claimed`: this run, or a copy that died before it posted (or is only slow). It
        // proceeds and now owns the marker; a slower copy that proceeded earlier finds the
        // marker is no longer its own right before its POST and stands down (see postingHook()).
        try {
            $this->submit();
        }
        catch (Throwable $e) {
            // Escaped on its own (the queue backs off and retries): if nothing was sent the
            // retry must be free to run, so the marker goes. After the POST started it stays
            // `posting`, and the retry reports an ambiguous failure instead of posting again.
            $this->forgetRun();

            throw $e;
        }

        // Overtaken by another copy. Returning normally would ack the job, and a copy that
        // shares the job row (the database driver) would lose it: if that copy then dies
        // before its POST, nothing would redeliver. So this one stays queued and re-checks the
        // marker after the other copy's `claimed` marker would have lapsed: `done` / `accepted`
        // / `posting` are handled as always, an absent or stale `claimed` means it died and
        // this copy submits. A release is not an exception, so $maxExceptions is untouched,
        // $tries is unlimited and retryUntil() still bounds it; it ends as soon as a re-check
        // finds the marker settled or proceeds itself.
        if ($this->superseded) {
            $this->release(static::CLAIMED_SECONDS);

            return;
        }

        // finished (accepted, failed or ambiguous): a later redelivery is a silent no-op.
        // Absent marker = the job released itself for a retry, which must run again. A marker
        // that is another copy's claim belongs to that copy: leave it.
        try {
            $this->finishRun();
        }
        catch (Throwable $e) {
            report($e);
        }
    }

    protected function submit() : void
    {
        try {
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
        }
        catch (Throwable $e) {
            // nothing was sent: a client that cannot be resolved is a definite failure
            report($e);
            SubmissionLock::release($this->parents, $this->preprod, $this->lockOwner);
            $this->announce(SubmissionFailed::ERROR, [$e->getMessage()]);

            return;
        }

        try {
            // forParents() also refuses parents that resolve to more than one client
            $result = $client
                ->forParents($models, $this->lockOwner)
                ->withoutPolling()
                ->beforePost(fn () => $this->postingHook())
                ->submitDocuments($this->documents);
        }
        catch (RunSupersededException) {
            // another copy owns the run and has (or will) report: no announcement, and the
            // lock stays -- it is shared with that copy, whose own run frees it. handle()
            // releases this job for a later re-check instead of acking it.
            $this->superseded = true;

            return;
        }
        catch (MyinvoisInFlightException $e) {
            $this->announce(SubmissionFailed::IN_FLIGHT, [$e->getMessage()]);

            return;
        }
        catch (MyinvoisThrottledException $e) {
            // our own limiter is capped and nothing was sent: wait for a slot, never sleep
            $this->retryLater(max(1, $e->getRetryAfter() ?? 1));

            return;
        }
        catch (MyinvoisException $e) {
            $this->handleFailure($e);

            return;
        }
        catch (Throwable $e) {
            report($e);

            // the lock is kept when the POST had started, freed otherwise (a no-op when
            // submitDocuments() already freed it)
            if (! $client->postStarted()) SubmissionLock::release($this->parents, $this->preprod, $this->lockOwner);

            $this->announce(SubmissionFailed::ERROR, [$e->getMessage()], ambiguous: $client->postStarted());

            return;
        }

        try {
            $this->markAccepted($result);
            $this->handleResult($result);
        }
        catch (Throwable $e) {
            // LHDN has the documents and the rows are saved: there is nothing left to retry,
            // and a rerun would only report a false failure
            report($e);
        }
    }

    /**
     * The queue gave up on the job (a worker timeout, the retryUntil cap, a crash). The POST
     * may have been in progress, so the lock is left to expire.
     */
    public function failed(Throwable $e) : void
    {
        report($e);

        $this->announceStopped();
    }

    protected function announceStopped() : void
    {
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
                $this->retryLater($e->getRetryAfter() ?? static::RETRY_DELAYS[$retry - 1]);

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

        $this->announceAccepted(
            $rows->pluck('document_number')->filter()->unique()->values()->all(),
            $rows->first()->submission_uid,
        );
    }

    /**
     * Dispatch the poll, then tell the app.
     *
     * @param  array<int, string>  $numbers
     */
    protected function announceAccepted(array $numbers, ?string $uid) : void
    {
        // The package's own follow-up comes first and a listener cannot stop it: the poll is
        // dispatched, then the app is told. If the dispatch itself fails the sweep is the net.
        try {
            PollSubmission::dispatch($uid, $this->referencesFor($numbers), $this->preprod, $this->context)
                ->onConnection(config('services.myinvois.queue_connection'))
                ->onQueue(config('services.myinvois.queue'));
        }
        catch (Throwable $e) {
            report($e);
        }

        $this->fire(new SubmissionAccepted($this->referencesFor($numbers), $numbers, $uid, $this->context));
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

        $this->fire(new SubmissionFailed(
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
     * Tell the app. A throwing listener is reported and goes no further: it must not undo
     * or repeat work the package has already done.
     */
    protected function fire(object $event) : void
    {
        try {
            event($event);
        }
        catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Claim the run and return [state, detail] to act on. The marker is keyed on the lock
     * owner token (unique per queueSubmission() call) and moves
     * claimed:<run id> -> posting -> accepted:<submission uid> -> done:
     *
     * - `claimed`: a run is before the POST, or died there, or is only slow. Proceed, and
     *   take the marker over with this run's id.
     * - `posting`: the POST was (about to be) sent. Never post again; report ambiguous.
     * - `accepted`: LHDN accepted and the rows are saved. Finish the announcement.
     * - `done`: a definite outcome was reached. Silent no-op.
     *
     * Runs under the marker lock, so it cannot interleave with another copy's posting check.
     *
     * @return array{0: string, 1: ?string}
     */
    protected function claimRun() : array
    {
        return $this->withMarkerLock(function () {
            $store = $this->store();
            $claimed = $this->claimedMarker();

            if ($store->add($this->runKey(), $claimed, static::CLAIMED_SECONDS)) {
                return [static::RUN_CLAIMED, null];
            }

            [$state, $detail] = $this->parseMarker($store->get($this->runKey()));

            if (in_array($state, [static::RUN_POSTING, static::RUN_ACCEPTED, static::RUN_DONE], true)) {
                return [$state, $detail];
            }

            // another run's `claimed` (a crashed or slower copy), or expired between the add
            // and the get: run, and own the marker
            $store->put($this->runKey(), $claimed, static::CLAIMED_SECONDS);

            return [static::RUN_CLAIMED, null];
        });
    }

    /**
     * Runs right before the submission POST leaves, from inside the client. If another copy
     * took the marker over since this run claimed it, this run stands down: that copy was
     * served while this one was slow (a token fetch) and may already have posted.
     *
     * An absent marker (it expired) is still this run's: a copy that took over would have
     * written its own.
     */
    protected function postingHook() : void
    {
        if ($this->postingSet) return;

        $this->withMarkerLock(function () {
            $raw = $this->store()->get($this->runKey());

            if ($raw !== null && $raw !== $this->claimedMarker()) {
                throw new RunSupersededException('Another copy of this submission job took over the run.');
            }

            $this->setRunState(static::RUN_POSTING);
        });

        $this->postingSet = true;
    }

    /**
     * LHDN accepted and the rows are saved: remember it, with the submission uid, so a
     * redelivery can finish the announcement instead of reporting an ambiguous failure.
     * Only moves `posting` on; if a copy already settled the marker it stays as it is.
     *
     * @param  mixed  $result  what submitDocuments() returned
     */
    protected function markAccepted($result) : void
    {
        $rows = collect(is_array($result) ? data_get($result, 'myinvois_documents', []) : []);

        if ($rows->isEmpty() || ! ($uid = $rows->first()->submission_uid)) return;

        try {
            $this->withMarkerLock(function () use ($uid) {
                if ($this->store()->get($this->runKey()) === static::RUN_POSTING) {
                    $this->setRunState(static::RUN_ACCEPTED, $uid);
                }
            });
        }
        catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * A redelivery found `accepted`: dispatch the poll and tell the app, from the rows.
     */
    protected function finishAccepted(string $uid) : void
    {
        $numbers = ($this->documentModel())::query()
            ->where('submission_uid', $uid)
            ->pluck('document_number')
            ->filter()->unique()->values()->all();

        if (! $numbers) {
            $this->announceStopped();

            return;
        }

        $this->announceAccepted($numbers, $uid);
    }

    /**
     * The end of a run: `done`, unless the marker is gone (the job released itself for a
     * retry) or settled / owned by someone else.
     */
    protected function finishRun() : void
    {
        $this->withMarkerLock(function () {
            $raw = $this->store()->get($this->runKey());
            [$state] = $this->parseMarker($raw);

            if ($raw === $this->claimedMarker() || in_array($state, [static::RUN_POSTING, static::RUN_ACCEPTED], true)) {
                $this->setRunState(static::RUN_DONE);
            }
        });
    }

    protected function claimedMarker() : string
    {
        return static::RUN_CLAIMED.':'.$this->runId;
    }

    /**
     * @return array{0: ?string, 1: ?string} state and its detail (run id / submission uid)
     */
    protected function parseMarker(mixed $raw) : array
    {
        if (! is_string($raw) || $raw === '') return [null, null];

        [$state, $detail] = array_pad(explode(':', $raw, 2), 2, null);

        return [$state, $detail];
    }

    protected function setRunState(string $state, ?string $detail = null) : void
    {
        $this->store()->put(
            $this->runKey(),
            match ($state) {
                static::RUN_CLAIMED => $this->claimedMarker(),
                static::RUN_ACCEPTED => static::RUN_ACCEPTED.':'.$detail,
                default => $state,
            },
            $state === static::RUN_CLAIMED ? static::CLAIMED_SECONDS : static::SETTLED_SECONDS,
        );
    }

    /**
     * Drop the marker, but only while it is still this run's: its `claimed:<runId>`, and
     * with $afterPostStarted also the `posting` this very run set (a 429 on the POST: LHDN
     * definitely did not take it, so the retry must run). After the POST started an
     * escaping exception keeps `posting`, and another copy's marker is never ours to delete.
     */
    protected function forgetRun(bool $afterPostStarted = false) : void
    {
        try {
            $this->withMarkerLock(function () use ($afterPostStarted) {
                $raw = $this->store()->get($this->runKey());

                if ($raw === $this->claimedMarker() || ($afterPostStarted && $this->postingSet && $raw === static::RUN_POSTING)) {
                    $this->store()->forget($this->runKey());
                }
            });
        }
        catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Run $callback holding a short cache lock, so a get-then-put on the marker is atomic
     * against the other copies of this job.
     */
    protected function withMarkerLock(\Closure $callback) : mixed
    {
        return $this->store()->lock($this->runKey().':guard', 10)->block(5, $callback);
    }

    protected function documentModel() : string
    {
        return MyinvoisDocument::$useModel;
    }

    protected function store() : Repository
    {
        return Cache::store(config('services.myinvois.lock_store'));
    }

    /**
     * Release the job for another attempt (LHDN did not take the request). This run's marker
     * is dropped so the next attempt is not mistaken for a redelivery; another copy's is not.
     */
    protected function retryLater(int $seconds) : void
    {
        $this->forgetRun(afterPostStarted: true);

        $this->release($seconds);
    }

    protected function runKey() : string
    {
        return 'myinvois:submit-run:'.$this->lockOwner;
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
