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
    }

    public function retryUntil() : DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle() : void
    {
        $parent = collect($this->parents)->map(fn ($reference) => ParentReference::resolve($reference))->filter()->first();

        // nothing to build a client from: the sweep resolves the rows if the parent is back
        if (! $parent) return;

        $client = Myinvois::clientFor($parent)->withoutThrottleSleep();

        if ((bool) $client->getSettings('preprod') !== $this->preprod) return;

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

        $rows = $this->rows();

        if ($rows->isEmpty()) return;

        if ($rows->contains(fn ($row) => $row->status?->is(Status::SUBMITTED))) {
            $delay = static::DELAYS[$this->attempts() - 1] ?? null;

            // out of steps: leave it to the sweep
            if ($delay !== null) $this->release($delay);

            return;
        }

        $this->announce($rows);
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
            event(new SubmissionFailed(
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
            event(new SubmissionSucceeded(
                $this->referencesOf($valid),
                $this->numbersOf($valid),
                $this->submissionUid,
                $this->context,
            ));
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
