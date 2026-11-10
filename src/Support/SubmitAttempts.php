<?php

namespace Jiannius\Myinvois\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Jiannius\Myinvois\Enums\Status;
use Jiannius\Myinvois\Exceptions\MyinvoisAuthenticationException;
use Jiannius\Myinvois\Exceptions\MyinvoisException;
use Jiannius\Myinvois\Exceptions\MyinvoisPendingAttemptException;
use Jiannius\Myinvois\Exceptions\MyinvoisPermissionException;
use Jiannius\Myinvois\Exceptions\MyinvoisThrottledException;
use Jiannius\Myinvois\Helpers\UBL;
use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Models\MyinvoisSubmitAttempt;
use Jiannius\Myinvois\Models\Observers\MyinvoisDocumentObserver;
use Jiannius\Myinvois\Myinvois;

/**
 * Pending submit attempts (jiannius/myinvois#13): recorded right before a submission POST
 * leaves, closed when LHDN answers definitely, and looked up before the same parent is
 * submitted again, so a submission LHDN accepted but nobody recorded is never sent twice.
 *
 * The lookup, per document of a pending attempt:
 * 1. local rows: an unlinked row of that number in this environment, saved since the attempt
 *    (a pre-v1.5 caller linked rows only after submitDocuments() returned);
 * 2. LHDN: documents/recent (Sent, per document type, one submission window covering every
 *    pending attempt of that type), a bounded number of pages, matching internalId and the
 *    exact dateTimeIssued.
 *
 * Every document is decided before anything is written. Any refusal (several live matches,
 * a failed lookup, no match within CHECK_MINUTES) refuses the whole call and changes nothing.
 * Otherwise: a live (Submitted / Valid) match is adopted and that document is not sent again;
 * Invalid / Cancelled matches only are recorded and the document is sent; no match after
 * CHECK_MINUTES sends again, reusing the attempt's issue time while it is under REUSE_MINUTES
 * old (so LHDN's DV302 duplicate check marks the copy invalid if the original was missed).
 */
class SubmitAttempts
{
    /** Minutes during which an attempt with no match is refused as "still checking". */
    public const CHECK_MINUTES = 10;

    /** Minutes during which a resend reuses the attempt's issue time (LHDN's DV302 window is 2 hours). */
    public const REUSE_MINUTES = 120;

    /** documents/recent pages read per document type, and their size. */
    public const LOOKUP_PAGES = 5;

    public const PAGE_SIZE = 100;

    /** The submission window searched, in minutes before the first and after the last attempt. */
    public const WINDOW_BEFORE = 5;

    public const WINDOW_AFTER = 30;

    /** Seconds added to a "still checking" wait, so the retry lands after the 10 minutes. */
    public const CHECK_SLACK = 5;

    protected static bool $available = false;

    /**
     * Submissions restored by the last recover(): submission uid => document numbers.
     *
     * @var array<string, array<int, string>>
     */
    protected array $recovered = [];

    public function __construct(protected Myinvois $client, protected bool $preprod)
    {
    }

    public static function available() : bool
    {
        // remembered once true: the table does not go away at runtime
        return static::$available = static::$available || Schema::connection((new MyinvoisSubmitAttempt)->getConnectionName())->hasTable('myinvois_submit_attempts');
    }

    /**
     * Whether a parent has an attempt nobody has resolved yet in the environment.
     */
    public static function pendingFor(Model $parent, bool $preprod) : bool
    {
        return static::available() && MyinvoisSubmitAttempt::query()->pending()->forParent($parent, $preprod)->exists();
    }

    // ---- recording -----------------------------------------------------

    /**
     * Record one pending attempt per document and parent.
     *
     * @param  array<int, array>  $documents
     * @param  array<int, array>  $entries  ParentRows::resolve() of the documents, parents as models
     * @param  array<string, ?string>  $issued  document number => the IssueDate + 'T' + IssueTime sent
     * @return Collection<int, MyinvoisSubmitAttempt>
     */
    public function record(array $documents, array $entries, array $issued) : Collection
    {
        // all or none: a half-written set would leave a parent pending for nothing
        return (new MyinvoisSubmitAttempt)->getConnection()->transaction(fn () => $this->createAttempts($documents, $entries, $issued));
    }

    protected function createAttempts(array $documents, array $entries, array $issued) : Collection
    {
        $attempts = collect();

        foreach ($documents as $document) {
            $number = (string) data_get($document, 'number');

            $byParent = collect($entries)
                ->filter(fn ($entry) => $entry['document'] === $number && $entry['parent'] instanceof Model)
                ->groupBy(fn ($entry) => $entry['parent']->getMorphClass().':'.$entry['parent']->getKey());

            foreach ($byParent as $rows) {
                $parent = $rows->first()['parent'];

                $attempts->push(MyinvoisSubmitAttempt::create([
                    'parent_type' => $parent->getMorphClass(),
                    'parent_id' => (string) $parent->getKey(),
                    'is_preprod' => $this->preprod,
                    'document_number' => $number,
                    'document_type' => data_get($document, 'document_type'),
                    'is_consolidate' => UBL::isConsolidated($document),
                    'row_numbers' => $rows->pluck('row')->values()->all(),
                    'issued_at' => $issued[$number] ?? null,
                    'status' => MyinvoisSubmitAttempt::PENDING,
                ]));
            }
        }

        return $attempts;
    }

    /**
     * Close the attempts LHDN answered definitely: accepted, rejected, or a whole-submission
     * error. A document the response does not mention stays pending, and so does everything
     * after a 408 (LHDN may still have taken the request).
     *
     * @param  Collection<int, MyinvoisSubmitAttempt>  $attempts
     */
    public static function closeAfterResponse(Collection $attempts, Response $api) : void
    {
        $json = $api->json();

        if ($api->status() === 408) return;

        if ($api->failed()) {
            static::closeAll($attempts, MyinvoisSubmitAttempt::REJECTED);

            return;
        }

        $accepted = collect(data_get($json, 'acceptedDocuments', []))->keyBy(fn ($item) => (string) data_get($item, 'invoiceCodeNumber'));
        $rejected = collect(data_get($json, 'rejectedDocuments', []))->keyBy(fn ($item) => (string) data_get($item, 'invoiceCodeNumber'));

        foreach ($attempts as $attempt) {
            if ($item = $accepted->get($attempt->document_number)) {
                $attempt->close(MyinvoisSubmitAttempt::ACCEPTED, [
                    'document_uuid' => data_get($item, 'uuid'),
                    'submission_uid' => data_get($json, 'submissionUid'),
                ]);
            }
            elseif ($rejected->has($attempt->document_number)) {
                $attempt->close(MyinvoisSubmitAttempt::REJECTED, ['submission_uid' => data_get($json, 'submissionUid')]);
            }
        }
    }

    /**
     * @param  Collection<int, MyinvoisSubmitAttempt>  $attempts
     */
    public static function closeAll(Collection $attempts, string $outcome) : void
    {
        foreach ($attempts as $attempt) {
            if ($attempt->status === MyinvoisSubmitAttempt::PENDING) $attempt->close($outcome);
        }
    }

    // ---- recovery ------------------------------------------------------

    /**
     * Resolve the pending attempts of the parents before anything is sent, and return the
     * documents still to send: without those whose earlier submission was restored, and with
     * a reused issue time where it applies. recovered() then says what was restored.
     *
     * Throws MyinvoisPendingAttemptException when any document refuses (nothing is written
     * then), or `recovered` when every document was restored (nothing left to send).
     * Throttling, authentication and permission failures of the lookup are rethrown as they
     * are, also before anything is written: the POST would meet them too.
     *
     * @param  array<int, array>  $documents
     * @param  iterable<int, Model>  $parents
     * @param  array<string, mixed>  $parentKeys  the forParents() map, to tell which documents a restored parent owns
     * @return array<int, array>
     */
    public function recover(array $documents, iterable $parents, array $parentKeys = []) : array
    {
        $this->recovered = [];
        $parents = collect($parents)->unique(fn ($parent) => $this->parentKey($parent))->values();

        $pending = $this->attemptsOf($parents)->pending()->orderBy('created_at')->get();

        if ($pending->isEmpty()) return $this->reuseIssueTimes($documents, $parents);

        // a base collection: an Eloquent one would filter only() / except() by model key
        $groups = $pending->groupBy('document_number')->toBase();

        // phase 1: decide every document, writing nothing
        $local = $groups->map(fn ($group, $number) => $this->localRows((string) $number, $group));
        $remote = $this->lookupAll($groups->filter(fn ($group, $number) => $local[$number]->isEmpty()));

        $decisions = $groups->map(fn ($group, $number) => $local[$number]->isNotEmpty()
            ? $this->decideLocal($local[$number])
            : $this->decideRemote($group, $remote[$number]));

        $refusals = $decisions->filter(fn ($decision) => $decision['action'] === 'refuse');

        if ($refusals->isNotEmpty()) throw $this->refusal($refusals, $groups);

        // phase 2: apply them together
        $model = MyinvoisDocument::$useModel;

        // the rows in one transaction; the attempts are closed only once it committed (they
        // may live on another connection): a crash in between leaves them pending, and the
        // next submission finds the rows locally
        $closes = (new $model)->getConnection()->transaction(fn () => MyinvoisDocumentObserver::deferEvents(function () use ($decisions, $groups, $local) {
            $closes = [];

            foreach ($decisions as $number => $decision) {
                $closes[] = $this->apply($decision, $groups[$number], $local[$number]);
            }

            return $closes;
        }));

        foreach ($closes as $close) $close();

        $this->recovered = $decisions
            ->filter(fn ($decision) => $decision['live'])
            ->groupBy(fn ($decision) => (string) $decision['uid'], preserveKeys: true)
            ->map(fn ($decisions) => $decisions->keys()->map(fn ($number) => (string) $number)->values()->all())
            ->all();

        $restoredNumbers = $decisions->filter(fn ($decision) => $decision['live'])->keys()->map(fn ($number) => (string) $number)->all();

        if (! $restoredNumbers) return $this->reuseIssueTimes($documents, $parents);

        // a document whose parent now holds the restored submission is not sent again
        $restoredParents = $pending->whereIn('document_number', $restoredNumbers)
            ->map(fn ($attempt) => $attempt->parent_type.':'.$attempt->parent_id)
            ->unique()->all();

        $remaining = collect($documents)->reject(function ($document) use ($restoredNumbers, $restoredParents, $parentKeys) {
            if (in_array((string) data_get($document, 'number'), $restoredNumbers, true)) return true;

            foreach ($this->parentsOfDocument($document, $parentKeys) as $parent) {
                if (in_array($this->parentKey($parent), $restoredParents, true)) return true;
            }

            return false;
        })->values()->all();

        if (! $remaining) {
            throw new MyinvoisPendingAttemptException(
                implode(', ', $restoredNumbers).' was already accepted by MyInvois in an earlier attempt; its record has been restored.',
                MyinvoisPendingAttemptException::RECOVERED,
                $this->references($pending->whereIn('document_number', $restoredNumbers)),
                $restoredNumbers,
                array_keys($this->recovered),
            );
        }

        return $this->reuseIssueTimes($remaining, $parents);
    }

    /**
     * Submissions the last recover() restored: submission uid => document numbers.
     *
     * @return array<string, array<int, string>>
     */
    public function recovered() : array
    {
        return $this->recovered;
    }

    /**
     * Local rows found: link them; a live one means the document is submitted.
     *
     * @return array{action: string, live: bool, uid: ?string, entry: ?array, reason: ?string}
     */
    protected function decideLocal(Collection $rows) : array
    {
        $live = $rows->first(fn ($row) => $row->status?->is(Status::SUBMITTED, Status::VALID));

        return [
            'action' => 'link',
            'live' => (bool) $live,
            'uid' => ($live ?? $rows->first())->submission_uid,
            'entry' => null,
            'reason' => null,
        ];
    }

    /**
     * @param  Collection<int, array>|null  $matches  null when the lookup failed
     * @return array{action: string, live: bool, uid: ?string, entry: ?array, reason: ?string}
     */
    protected function decideRemote(Collection $group, ?Collection $matches) : array
    {
        $decision = ['action' => 'refuse', 'live' => false, 'uid' => null, 'entry' => null, 'reason' => MyinvoisPendingAttemptException::LOOKUP_FAILED];

        if ($matches === null) return $decision;

        // a status the package does not know (LHDN documents only the four) is not taken as a
        // match: logged, and the document is treated like one LHDN does not hold. A resend then
        // reuses the issue time (under REUSE_MINUTES), so DV302 still catches a real copy.
        $matches = $matches->filter(function ($entry) {
            if (Status::tryFrom(strtolower((string) data_get($entry, 'status')))) return true;

            \Illuminate\Support\Facades\Log::warning('MyInvois: a recent document with an unknown status was not taken as a match.', [
                'document_uuid' => data_get($entry, 'uuid'),
                'status' => data_get($entry, 'status'),
            ]);

            return false;
        })->values();

        $statuses = $matches->map(fn ($entry) => Status::from(strtolower((string) data_get($entry, 'status'))));

        $live = $matches->filter(fn ($entry, $i) => $statuses[$i]->is(Status::SUBMITTED, Status::VALID));

        if ($live->count() > 1) return [...$decision, 'reason' => MyinvoisPendingAttemptException::MULTIPLE_MATCHES];

        if ($live->count() === 1) {
            $entry = $live->first();

            return ['action' => 'adopt', 'live' => true, 'uid' => (string) data_get($entry, 'submissionUID'), 'entry' => $entry, 'reason' => null];
        }

        if ($matches->isNotEmpty()) {
            $entry = $matches->sortByDesc(fn ($entry) => (string) data_get($entry, 'dateTimeReceived'))->first();

            return ['action' => 'adopt', 'live' => false, 'uid' => (string) data_get($entry, 'submissionUID'), 'entry' => $entry, 'reason' => null];
        }

        if ($group->first()->created_at->gt(now()->subMinutes(static::CHECK_MINUTES))) {
            return [...$decision, 'reason' => MyinvoisPendingAttemptException::STILL_CHECKING];
        }

        return ['action' => 'not_found', 'live' => false, 'uid' => null, 'entry' => null, 'reason' => null];
    }

    /**
     * Write a decision's rows and return how to close its attempts (run after the commit).
     */
    protected function apply(array $decision, Collection $group, Collection $local) : \Closure
    {
        $outcome = $decision['live'] ? MyinvoisSubmitAttempt::ADOPTED : MyinvoisSubmitAttempt::FOUND_INVALID;

        if ($decision['action'] === 'link') {
            $this->link($local, $group);
            $row = $local->first();

            return fn () => $this->closeGroup($group, $outcome, $row);
        }

        if ($decision['action'] === 'adopt') {
            $row = $this->adopt($group, $decision['entry'])->first();

            return fn () => $this->closeGroup($group, $outcome, $row);
        }

        return fn () => $this->closeGroup($group, MyinvoisSubmitAttempt::NOT_FOUND);
    }

    /**
     * Rows of the document saved in this environment since the attempt, unlinked or already
     * linked to one of the attempt's parents.
     *
     * @return Collection<int, MyinvoisDocument>
     */
    protected function localRows(string $number, Collection $group) : Collection
    {
        $first = $group->first();

        return $this->documentQuery()
            ->where('created_at', '>=', $first->created_at)
            ->where(fn ($q) => $first->is_consolidate
                ? $q->where('consolidate_number', $number)
                : $q->where('document_number', $number)->whereNull('consolidate_number'))
            ->where(function ($q) use ($group) {
                $q->whereNull('parent_id');

                foreach ($group as $attempt) {
                    $q->orWhere(fn ($q) => $q->where('parent_type', $attempt->parent_type)->where('parent_id', $attempt->parent_id));
                }
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * Link unlinked rows to the attempt whose rows they are.
     *
     * @param  Collection<int, MyinvoisDocument>  $rows
     */
    protected function link(Collection $rows, Collection $group) : void
    {
        foreach ($rows as $row) {
            if ($row->parent_id !== null) continue;

            $attempt = $group->first(fn ($attempt) => in_array($row->document_number, (array) $attempt->row_numbers, true))
                ?? ($group->count() === 1 ? $group->first() : null);

            if ($attempt) $row->update(['parent_type' => $attempt->parent_type, 'parent_id' => $attempt->parent_id]);
        }
    }

    /**
     * Ask documents/recent once per document type for every group that needs it.
     *
     * @param  Collection<string, Collection<int, MyinvoisSubmitAttempt>>  $groups  document number => attempts
     * @return array<string, Collection<int, array>|null>  document number => matches, null when the lookup failed
     *
     * @throws MyinvoisThrottledException|MyinvoisAuthenticationException|MyinvoisPermissionException
     */
    protected function lookupAll(Collection $groups) : array
    {
        $results = [];

        foreach ($groups->groupBy(fn ($group) => (string) $group->first()->document_type, preserveKeys: true) as $type => $byNumber) {
            try {
                $entries = $this->recentDocuments($type, $byNumber);
            }
            catch (MyinvoisThrottledException|MyinvoisAuthenticationException|MyinvoisPermissionException $e) {
                throw $e;
            }
            catch (MyinvoisException|LookupFailed) {
                foreach ($byNumber as $number => $group) $results[(string) $number] = null;

                continue;
            }

            foreach ($byNumber as $number => $group) {
                $issued = $group->first()->issued_at ? Carbon::parse($group->first()->issued_at) : null;

                // without an issue time (a document built without one) the number alone, in the
                // attempt's window, has to do
                $results[(string) $number] = $entries
                    ->filter(fn ($entry) => (string) data_get($entry, 'internalId') === (string) $number
                        && ($issued === null || $this->sameInstant(data_get($entry, 'dateTimeIssued'), $issued)))
                    ->values();
            }
        }

        return $results;
    }

    /**
     * Every documents/recent entry of a type in a window covering the groups' attempts.
     *
     * @param  Collection<string, Collection<int, MyinvoisSubmitAttempt>>  $groups
     * @return Collection<int, array>
     *
     * @throws LookupFailed|MyinvoisException
     */
    protected function recentDocuments(string $type, Collection $groups) : Collection
    {
        $from = $groups->map(fn ($group) => $this->windowStart($group))->min();
        $last = $groups->flatten(1)->max('created_at');
        $to = $last->copy()->addMinutes(static::WINDOW_AFTER)->min(now());
        $entries = collect();

        for ($page = 1; $page <= static::LOOKUP_PAGES; $page++) {
            $api = $this->client->callApi(
                uri: 'documents/recent',
                data: array_filter([
                    'pageNo' => $page,
                    'pageSize' => static::PAGE_SIZE,
                    'submissionDateFrom' => $from->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                    'submissionDateTo' => $to->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                    'InvoiceDirection' => 'Sent',
                    'documentType' => $type,
                ], fn ($value) => $value !== null && $value !== ''),
                perMinute: 12,
            );

            $json = $api->json();
            $result = data_get($json, 'result');

            if (! $api->successful() || ! is_array($json) || ! is_array($result)) {
                throw new LookupFailed('documents/recent did not answer with a result list.');
            }

            $entries->push(...array_values($result));

            if ($page >= (int) (data_get($json, 'metadata.totalPages') ?? 1)) return $entries;
        }

        // more pages than we read: what we did not see could hold the match
        throw new LookupFailed('documents/recent has more pages than the lookup reads.');
    }

    /**
     * Where to start looking for a group: before its first attempt, or before the first
     * attempt that sent the same issue time (a resend reusing it), whose original may have
     * landed late.
     */
    protected function windowStart(Collection $group) : Carbon
    {
        $first = $group->first();
        $start = $first->created_at;

        if ($first->issued_at) {
            $earliest = MyinvoisSubmitAttempt::query()
                ->where('is_preprod', $this->preprod)
                ->where('document_number', $first->document_number)
                ->where('issued_at', $first->issued_at)
                ->where('parent_type', $first->parent_type)
                ->where('parent_id', $first->parent_id)
                ->where('created_at', '>=', $first->created_at->copy()->subMinutes(static::REUSE_MINUTES))
                ->min('created_at');

            if ($earliest) $start = Carbon::parse($earliest)->min($start);
        }

        return $start->copy()->subMinutes(static::WINDOW_BEFORE);
    }

    protected function sameInstant($value, Carbon $issued) : bool
    {
        if (! is_string($value) || $value === '') return false;

        try {
            return Carbon::parse($value)->equalTo($issued);
        }
        catch (\Throwable) {
            return false;
        }
    }

    /**
     * Write the rows of a document LHDN holds, in the usual shape (one per line item for a
     * consolidated document), linked to the attempts' parents, created at LHDN's received
     * time so the 72-hour cancellation window is not overstated. Rows of that document uuid
     * that already exist (unlinked, or a second copy of this job) are linked, not duplicated.
     *
     * @param  array<string, mixed>  $entry  a documents/recent item
     * @return Collection<int, MyinvoisDocument>
     */
    protected function adopt(Collection $group, array $entry) : Collection
    {
        $model = MyinvoisDocument::$useModel;
        $number = $group->first()->document_number;
        $consolidated = (bool) $group->first()->is_consolidate;
        $received = $this->received($entry);
        $uuid = data_get($entry, 'uuid');

        $existing = $uuid ? $this->documentQuery()->where('document_uuid', $uuid)->orderBy('id')->get() : collect();
        $rows = collect();

        foreach ($group as $attempt) {
            foreach ((array) ($attempt->row_numbers ?: [$number]) as $rowNumber) {
                $rowNumber = $consolidated ? $rowNumber : $number;

                $row = $existing->first(fn ($row) => $row->document_number === $rowNumber
                    && ($row->parent_id === null || ($row->parent_type === $attempt->parent_type && (string) $row->parent_id === $attempt->parent_id)));

                if ($row) {
                    $existing = $existing->reject(fn ($other) => $other->is($row));

                    if ($row->parent_id === null) $row->update(['parent_type' => $attempt->parent_type, 'parent_id' => $attempt->parent_id]);

                    $rows->push($row);

                    continue;
                }

                $rows->push($model::create([
                    'document_uuid' => $uuid,
                    'submission_uid' => data_get($entry, 'submissionUID'),
                    'document_number' => $rowNumber,
                    'consolidate_number' => $consolidated ? $number : null,
                    'status' => strtolower((string) data_get($entry, 'status')),
                    'is_preprod' => $this->preprod,
                    'response' => $entry,
                    'parent_type' => $attempt->parent_type,
                    'parent_id' => $attempt->parent_id,
                    'created_at' => $received,
                ]));
            }
        }

        return $rows;
    }

    protected function received(array $entry) : Carbon
    {
        try {
            $value = data_get($entry, 'dateTimeReceived');

            // in the app's timezone: Eloquent stores a date as its wall-clock time, so a UTC
            // instance would be read back hours off in a non-UTC app
            return is_string($value) && $value !== '' ? Carbon::parse($value)->setTimezone(now()->getTimezone()) : now();
        }
        catch (\Throwable) {
            return now();
        }
    }

    protected function closeGroup(Collection $group, string $outcome, ?MyinvoisDocument $row = null) : void
    {
        foreach ($group as $attempt) {
            $attempt->close($outcome, $row ? ['document_uuid' => $row->document_uuid, 'submission_uid' => $row->submission_uid] : []);
        }
    }

    /**
     * A document resent within REUSE_MINUTES of an attempt that found nothing on LHDN keeps
     * that attempt's issue time.
     *
     * @param  array<int, array>  $documents
     * @return array<int, array>
     */
    protected function reuseIssueTimes(array $documents, Collection $parents) : array
    {
        foreach ($documents as $i => $document) {
            $latest = $this->attemptsOf($parents)
                ->where('document_number', (string) data_get($document, 'number'))
                ->latest('created_at')
                ->latest('id')
                ->first();

            if ($latest?->outcome === MyinvoisSubmitAttempt::NOT_FOUND
                && $latest->issued_at
                && $latest->created_at->gt(now()->subMinutes(static::REUSE_MINUTES))) {
                $documents[$i]['issued_at'] = Carbon::parse($latest->issued_at);
            }
        }

        return $documents;
    }

    /**
     * @param  Collection<string, array>  $refusals  document number => decision
     */
    protected function refusal(Collection $refusals, Collection $groups) : MyinvoisPendingAttemptException
    {
        foreach ([
            MyinvoisPendingAttemptException::MULTIPLE_MATCHES,
            MyinvoisPendingAttemptException::LOOKUP_FAILED,
            MyinvoisPendingAttemptException::STILL_CHECKING,
        ] as $reason) {
            $numbers = $refusals->filter(fn ($decision) => $decision['reason'] === $reason)->keys()->map(fn ($number) => (string) $number)->values()->all();

            if (! $numbers) continue;

            $list = implode(', ', $numbers);
            $attempts = $groups->only($numbers)->flatten(1);

            // still checking: the seconds until the youngest refused attempt is CHECK_MINUTES old
            $retryAfter = $reason === MyinvoisPendingAttemptException::STILL_CHECKING
                ? max(1, (int) now()->diffInSeconds($attempts->max('created_at')->copy()->addMinutes(static::CHECK_MINUTES), true)) + static::CHECK_SLACK
                : null;

            return new MyinvoisPendingAttemptException(
                match ($reason) {
                    MyinvoisPendingAttemptException::MULTIPLE_MATCHES => "MyInvois holds more than one e-invoice for {$list} from an earlier attempt. Check MyInvois and cancel the extra one before submitting again.",
                    MyinvoisPendingAttemptException::LOOKUP_FAILED => "An earlier submission of {$list} may have reached MyInvois, and MyInvois could not be checked. Please try again shortly.",
                    default => "An earlier submission of {$list} may have reached MyInvois. Still checking; please try again in a few minutes.",
                },
                $reason,
                $this->references($attempts),
                $numbers,
                retryAfter: $retryAfter,
            );
        }

        throw new \LogicException('No refusal to report.');
    }

    /**
     * The parents a document's rows belong to, from the forParents() map.
     *
     * @return array<int, Model>
     */
    protected function parentsOfDocument(array $document, array $parentKeys) : array
    {
        try {
            return collect(ParentRows::resolve([$document], $parentKeys))->pluck('parent')->filter(fn ($parent) => $parent instanceof Model)->all();
        }
        catch (\InvalidArgumentException) {
            return [];
        }
    }

    /**
     * Attempts of the parents in this environment.
     */
    protected function attemptsOf(Collection $parents)
    {
        return MyinvoisSubmitAttempt::query()
            ->where('is_preprod', $this->preprod)
            ->where(function ($query) use ($parents) {
                foreach ($parents as $parent) {
                    $query->orWhere(fn ($q) => $q->where('parent_type', $parent->getMorphClass())->where('parent_id', (string) $parent->getKey()));
                }
            });
    }

    /**
     * Outbound myinvois_documents rows in this environment.
     */
    protected function documentQuery()
    {
        $model = MyinvoisDocument::$useModel;

        return (new $model)->newQueryWithoutScopes()
            ->when(
                $this->preprod,
                fn ($q) => $q->where('is_preprod', true),
                fn ($q) => $q->where(fn ($q) => $q->where('is_preprod', false)->orWhereNull('is_preprod')),
            )
            ->where(fn ($q) => $q->where('is_inbound', false)->orWhereNull('is_inbound'));
    }

    protected function parentKey(Model $parent) : string
    {
        return $parent->getMorphClass().':'.$parent->getKey();
    }

    /**
     * @return array<int, array{type: string, id: mixed}>
     */
    protected function references(Collection $attempts) : array
    {
        return $attempts
            ->map(fn ($attempt) => ['type' => $attempt->parent_type, 'id' => $attempt->parent_id])
            ->unique(fn ($reference) => $reference['type'].':'.$reference['id'])
            ->values()
            ->all();
    }
}
