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
 * 2. LHDN: documents/recent (Sent, the document type, a submission window around the attempt),
 *    a bounded number of pages, matching internalId and the exact dateTimeIssued.
 *
 * Then: one live (Submitted / Valid) match is adopted and nothing is sent; Invalid / Cancelled
 * matches only are recorded and the document is sent; several live matches, a failed lookup,
 * or no match within CHECK_MINUTES refuse; no match after that sends again, reusing the
 * attempt's issue time while it is under REUSE_MINUTES old (so LHDN's DV302 duplicate check
 * marks the copy invalid if the original was missed after all).
 */
class SubmitAttempts
{
    /** Minutes during which an attempt with no match is refused as "still checking". */
    public const CHECK_MINUTES = 10;

    /** Minutes during which a resend reuses the attempt's issue time (LHDN's DV302 window is 2 hours). */
    public const REUSE_MINUTES = 120;

    /** documents/recent pages read per lookup, and their size. */
    public const LOOKUP_PAGES = 5;

    public const PAGE_SIZE = 100;

    /** The submission window searched, in minutes before and after the attempt. */
    public const WINDOW_BEFORE = 5;

    public const WINDOW_AFTER = 30;

    public function __construct(protected Myinvois $client, protected bool $preprod)
    {
    }

    public static function available() : bool
    {
        return Schema::hasTable('myinvois_submit_attempts');
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
     * error. A document the response does not mention stays pending.
     *
     * @param  Collection<int, MyinvoisSubmitAttempt>  $attempts
     */
    public static function closeAfterResponse(Collection $attempts, Response $api) : void
    {
        $json = $api->json();

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
     * Resolve the pending attempts of the parents before anything is sent. Returns the
     * documents to send (with a reused issue time where it applies), or throws
     * MyinvoisPendingAttemptException. Throttling, authentication and permission failures of
     * the lookup are rethrown as they are: the POST would meet them too.
     *
     * @param  array<int, array>  $documents
     * @param  iterable<int, Model>  $parents
     * @return array<int, array>
     */
    public function recover(array $documents, iterable $parents) : array
    {
        $parents = collect($parents)->unique(fn ($parent) => $parent->getMorphClass().':'.$parent->getKey())->values();

        $pending = MyinvoisSubmitAttempt::query()
            ->pending()
            ->where('is_preprod', $this->preprod)
            ->where(function ($query) use ($parents) {
                foreach ($parents as $parent) {
                    $query->orWhere(fn ($q) => $q->where('parent_type', $parent->getMorphClass())->where('parent_id', (string) $parent->getKey()));
                }
            })
            ->orderBy('created_at')
            ->get();

        $refusals = [];
        $recovered = [];

        foreach ($pending->groupBy('document_number') as $number => $group) {
            $outcome = $this->resolve((string) $number, $group);

            if ($outcome[0] === 'refuse') $refusals[$outcome[1]][] = (string) $number;
            if ($outcome[0] === 'recovered') $recovered[(string) $number] = $outcome[1];
        }

        if ($refusals) throw $this->refusal($refusals, $pending);

        if ($recovered) {
            $numbers = array_keys($recovered);

            throw new MyinvoisPendingAttemptException(
                implode(', ', $numbers).' was already accepted by MyInvois in an earlier attempt; its record has been restored.',
                MyinvoisPendingAttemptException::RECOVERED,
                $this->references($pending->whereIn('document_number', $numbers)),
                $numbers,
                array_values(array_unique(array_filter($recovered))),
            );
        }

        return $this->reuseIssueTimes($documents, $parents);
    }

    /**
     * @param  Collection<int, MyinvoisSubmitAttempt>  $group  the pending attempts of one document
     * @return array{0: string, 1: ?string} ['recovered', submission uid] | ['refuse', reason] | ['send', null]
     */
    protected function resolve(string $number, Collection $group) : array
    {
        $first = $group->first();

        // 1. rows saved locally since the attempt
        $local = $this->localRows($number, $group);

        if ($local->isNotEmpty()) {
            $this->link($local, $group);

            $live = $local->first(fn ($row) => $row->status?->is(Status::SUBMITTED, Status::VALID));

            if ($live) {
                $this->closeGroup($group, MyinvoisSubmitAttempt::ADOPTED, $live);

                return ['recovered', $live->submission_uid];
            }

            $this->closeGroup($group, MyinvoisSubmitAttempt::FOUND_INVALID, $local->first());

            return ['send', null];
        }

        // 2. LHDN
        try {
            $matches = $this->lookup($first);
        }
        catch (MyinvoisThrottledException|MyinvoisAuthenticationException|MyinvoisPermissionException $e) {
            throw $e;
        }
        catch (MyinvoisException|LookupFailed) {
            return ['refuse', MyinvoisPendingAttemptException::LOOKUP_FAILED];
        }

        $statuses = $matches->map(fn ($entry) => Status::tryFrom(strtolower((string) data_get($entry, 'status'))));

        if ($statuses->contains(null)) return ['refuse', MyinvoisPendingAttemptException::LOOKUP_FAILED];

        $live = $matches->filter(fn ($entry, $i) => $statuses[$i]->is(Status::SUBMITTED, Status::VALID));

        if ($live->count() > 1) return ['refuse', MyinvoisPendingAttemptException::MULTIPLE_MATCHES];

        if ($live->count() === 1) {
            $entry = $live->first();
            $rows = $this->adopt($group, $entry);
            $this->closeGroup($group, MyinvoisSubmitAttempt::ADOPTED, $rows->first());

            return ['recovered', (string) data_get($entry, 'submissionUID')];
        }

        if ($matches->isNotEmpty()) {
            $entry = $matches->sortByDesc(fn ($entry) => (string) data_get($entry, 'dateTimeReceived'))->first();
            $rows = $this->adopt($group, $entry);
            $this->closeGroup($group, MyinvoisSubmitAttempt::FOUND_INVALID, $rows->first());

            return ['send', null];
        }

        if ($first->created_at->gt(now()->subMinutes(static::CHECK_MINUTES))) {
            return ['refuse', MyinvoisPendingAttemptException::STILL_CHECKING];
        }

        $this->closeGroup($group, MyinvoisSubmitAttempt::NOT_FOUND);

        return ['send', null];
    }

    /**
     * Rows of the document saved in this environment since the attempt, unlinked or already
     * linked to one of the attempt's parents.
     *
     * @return Collection<int, MyinvoisDocument>
     */
    protected function localRows(string $number, Collection $group) : Collection
    {
        $model = MyinvoisDocument::$useModel;
        $first = $group->first();

        return (new $model)->newQueryWithoutScopes()
            ->when(
                $this->preprod,
                fn ($q) => $q->where('is_preprod', true),
                fn ($q) => $q->where(fn ($q) => $q->where('is_preprod', false)->orWhereNull('is_preprod')),
            )
            ->where(fn ($q) => $q->where('is_inbound', false)->orWhereNull('is_inbound'))
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
        MyinvoisDocumentObserver::deferEvents(function () use ($rows, $group) {
            foreach ($rows as $row) {
                if ($row->parent_id !== null) continue;

                $attempt = $group->first(fn ($attempt) => in_array($row->document_number, (array) $attempt->row_numbers, true))
                    ?? ($group->count() === 1 ? $group->first() : null);

                if ($attempt) $row->update(['parent_type' => $attempt->parent_type, 'parent_id' => $attempt->parent_id]);
            }
        });
    }

    /**
     * The documents/recent entries of the attempt's document: internalId and the exact issue time.
     *
     * @return Collection<int, array>
     *
     * @throws LookupFailed|MyinvoisException
     */
    protected function lookup(MyinvoisSubmitAttempt $attempt) : Collection
    {
        if (! $attempt->issued_at) throw new LookupFailed('The attempt has no issue time to match.');

        $issued = Carbon::parse($attempt->issued_at);
        $from = $attempt->created_at->copy()->subMinutes(static::WINDOW_BEFORE);
        $to = $attempt->created_at->copy()->addMinutes(static::WINDOW_AFTER)->min(now());
        $matches = collect();

        for ($page = 1; $page <= static::LOOKUP_PAGES; $page++) {
            $api = $this->client->callApi(
                uri: 'documents/recent',
                data: array_filter([
                    'pageNo' => $page,
                    'pageSize' => static::PAGE_SIZE,
                    'submissionDateFrom' => $from->utc()->format('Y-m-d\TH:i:s\Z'),
                    'submissionDateTo' => $to->utc()->format('Y-m-d\TH:i:s\Z'),
                    'InvoiceDirection' => 'Sent',
                    'documentType' => $attempt->document_type,
                ], fn ($value) => $value !== null && $value !== ''),
                perMinute: 12,
            );

            $json = $api->json();
            $result = data_get($json, 'result');

            if (! $api->successful() || ! is_array($json) || ! is_array($result)) {
                throw new LookupFailed('documents/recent did not answer with a result list.');
            }

            foreach ($result as $entry) {
                if ((string) data_get($entry, 'internalId') !== $attempt->document_number) continue;
                if (! $this->sameInstant(data_get($entry, 'dateTimeIssued'), $issued)) continue;

                $matches->push($entry);
            }

            if ($page >= (int) (data_get($json, 'metadata.totalPages') ?? 1)) return $matches->values();
        }

        // more pages than we read: what we did not see could hold the match
        throw new LookupFailed('documents/recent has more pages than the lookup reads.');
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
     * time so the 72-hour cancellation window is not overstated.
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

        return MyinvoisDocumentObserver::deferEvents(function () use ($model, $group, $entry, $number, $consolidated, $received) {
            $rows = collect();

            foreach ($group as $attempt) {
                foreach ((array) ($attempt->row_numbers ?: [$number]) as $rowNumber) {
                    $rows->push($model::create([
                        'document_uuid' => data_get($entry, 'uuid'),
                        'submission_uid' => data_get($entry, 'submissionUID'),
                        'document_number' => $consolidated ? $rowNumber : $number,
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
        });
    }

    protected function received(array $entry) : Carbon
    {
        try {
            $value = data_get($entry, 'dateTimeReceived');

            return is_string($value) && $value !== '' ? Carbon::parse($value) : now();
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
            $number = (string) data_get($document, 'number');

            $latest = MyinvoisSubmitAttempt::query()
                ->where('is_preprod', $this->preprod)
                ->where('document_number', $number)
                ->where(function ($query) use ($parents) {
                    foreach ($parents as $parent) {
                        $query->orWhere(fn ($q) => $q->where('parent_type', $parent->getMorphClass())->where('parent_id', (string) $parent->getKey()));
                    }
                })
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
     * @param  array<string, array<int, string>>  $refusals  reason => document numbers
     */
    protected function refusal(array $refusals, Collection $pending) : MyinvoisPendingAttemptException
    {
        foreach ([
            MyinvoisPendingAttemptException::MULTIPLE_MATCHES,
            MyinvoisPendingAttemptException::LOOKUP_FAILED,
            MyinvoisPendingAttemptException::STILL_CHECKING,
        ] as $reason) {
            if (! isset($refusals[$reason])) continue;

            $numbers = $refusals[$reason];
            $list = implode(', ', $numbers);

            return new MyinvoisPendingAttemptException(
                match ($reason) {
                    MyinvoisPendingAttemptException::MULTIPLE_MATCHES => "MyInvois holds more than one e-invoice for {$list} from an earlier attempt. Check MyInvois and cancel the extra one before submitting again.",
                    MyinvoisPendingAttemptException::LOOKUP_FAILED => "An earlier submission of {$list} may have reached MyInvois, and MyInvois could not be checked. Please try again shortly.",
                    default => "An earlier submission of {$list} may have reached MyInvois. Still checking; please try again in a few minutes.",
                },
                $reason,
                $this->references($pending->whereIn('document_number', $numbers)),
                $numbers,
            );
        }

        throw new \LogicException('No refusal to report.');
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
