<?php

namespace Jiannius\Myinvois\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Jiannius\Myinvois\Enums\Status;
use Jiannius\Myinvois\Events\SubmissionFailed;
use Jiannius\Myinvois\Events\SubmissionSucceeded;
use Jiannius\Myinvois\Exceptions\MyinvoisAuthenticationException;
use Jiannius\Myinvois\Exceptions\MyinvoisException;
use Jiannius\Myinvois\Exceptions\MyinvoisPermissionException;
use Jiannius\Myinvois\Exceptions\MyinvoisThrottledException;
use Jiannius\Myinvois\Exceptions\MyinvoisUnavailableException;
use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Myinvois;
use Jiannius\Myinvois\Support\ParentReference;
use Throwable;

/**
 * Asks LHDN for the verdict on an accepted submission until its rows are final (VALID /
 * INVALID / CANCELLED), backing off between polls. Dispatched by SubmitDocuments once LHDN
 * accepts. Idempotent: it only reads, and announces the outcome once.
 *
 * After the last step (about 19 minutes) it stops; the myinvois:sync sweep picks up what is
 * still `submitted`. Like SubmitDocuments it rebuilds its client from a parent, so the
 * payload carries no credentials.
 */
class PollSubmission implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Seconds to wait after the 1st, 2nd, ... poll that still finds the rows `submitted`
     * (cumulatively about 19 minutes). A wait for the rate limiter or a 429 uses a step too.
     */
    public const DELAYS = [10, 20, 40, 60, 120, 300, 600];

    /**
     * Unlimited by the queue's own counter; the schedule above and retryUntil() bound the job.
     */
    public $tries = 0;

    /**
     * Safety net for an exception nothing here handles: stop after this many instead of
     * re-running forever (`$tries = 0`).
     */
    public $maxExceptions = 3;

    public $timeout = 60;

    /**
     * @param  array<int, array{type: string, id: mixed}>  $parents  parent references of the submission's rows
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $submissionUid,
        public array $parents,
        public bool $preprod,
        public array $context = [],
    ) {
        // run only once the surrounding database transaction (if any) has committed, so the
        // worker never sees parents that do not exist yet (Queueable already declares the property)
        $this->afterCommit = true;
    }

    public function retryUntil() : DateTimeInterface
    {
        return now()->addHours(2);
    }

    /**
     * Seconds before the queue re-runs the job after an exception nothing handled.
     *
     * @return array<int, int>
     */
    public function backoff() : array
    {
        return SubmitDocuments::RETRY_DELAYS;
    }

    public function handle() : void
    {
        try {
            $parent = collect($this->parents)->map(fn ($reference) => ParentReference::resolve($reference))->filter()->first();

            // nothing to build a client from: the sweep resolves the rows if the parent is back
            if (! $parent) return;

            $client = Myinvois::clientFor($parent)->withoutThrottleSleep();

            if ((bool) $client->getSettings('preprod') !== $this->preprod) return;
        }
        catch (Throwable $e) {
            // a client that cannot be resolved will not resolve on the next poll either
            report($e);

            return;
        }

        try {
            $client->getSubmission($this->submissionUid);
        }
        catch (MyinvoisThrottledException|MyinvoisUnavailableException $e) {
            // our own limiter, or a 429: wait for it. Any other outage just means "not yet".
            if ($e instanceof MyinvoisThrottledException || $e->getStatus() === 429) {
                $this->release(max(1, $e->getRetryAfter() ?? 30));

                return;
            }
        }
        catch (MyinvoisAuthenticationException|MyinvoisPermissionException) {
            // polling cannot fix credentials; the sweep reports it
            return;
        }
        catch (MyinvoisException) {
            // not yet
        }
        catch (Throwable $e) {
            // not a LHDN problem (configuration, a bug): report it and leave the rows to the sweep
            report($e);

            return;
        }

        $rows = $this->rows();

        if ($rows->isEmpty()) return;

        if ($rows->contains(fn ($row) => $row->status?->is(Status::SUBMITTED))) {
            $delay = static::DELAYS[$this->attempts() - 1] ?? null;

            // out of steps: leave it to the sweep
            if ($delay !== null) $this->release($delay);

            return;
        }

        // the verdict poll carries no reasons: fetch them before announcing (best effort),
        // so a job killed here is retried and still announces
        $client->fetchInvalidReasons($rows);

        $this->announce($this->rows());
    }

    /**
     * @return \Illuminate\Support\Collection<int, MyinvoisDocument>
     */
    protected function rows()
    {
        $model = MyinvoisDocument::$useModel;

        return (new $model)->newQueryWithoutScopes()->where('submission_uid', $this->submissionUid)->get();
    }

    /**
     * Every row is final: tell the app, once.
     *
     * @param  \Illuminate\Support\Collection<int, MyinvoisDocument>  $rows
     */
    protected function announce($rows) : void
    {
        $key = 'myinvois:announced:'.($this->preprod ? 'preprod' : 'prod').':'.$this->submissionUid;

        if (! Cache::store(config('services.myinvois.lock_store'))->add($key, true, 86400)) return;

        $invalid = $rows->filter(fn ($row) => $row->status?->is(Status::INVALID));
        $valid = $rows->filter(fn ($row) => $row->status?->is(Status::VALID));

        if ($invalid->isNotEmpty()) {
            $this->fire(new SubmissionFailed(
                $this->referencesOf($invalid),
                $this->numbersOf($invalid),
                $this->submissionUid,
                $invalid->flatMap(fn ($row) => $row->getErrors())->filter(fn ($error) => is_string($error) && $error !== '')->unique()->values()->all(),
                SubmissionFailed::INVALID,
                false,
                $this->context,
            ));
        }

        if ($valid->isNotEmpty()) {
            $this->fire(new SubmissionSucceeded(
                $this->referencesOf($valid),
                $this->numbersOf($valid),
                $this->submissionUid,
                $this->context,
            ));
        }
    }

    /**
     * A throwing listener is reported, never rethrown: the announcement is already marked
     * as made, so a retry of the job would not repeat it anyway.
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

    protected function numbersOf($rows) : array
    {
        return $rows->pluck('document_number')->filter()->unique()->values()->all();
    }

    protected function referencesOf($rows) : array
    {
        return $rows
            ->filter(fn ($row) => $row->parent_type && $row->parent_id)
            ->map(fn ($row) => ['type' => $row->parent_type, 'id' => $row->parent_id])
            ->unique(fn ($reference) => $reference['type'].':'.$reference['id'])
            ->values()
            ->all();
    }
}
