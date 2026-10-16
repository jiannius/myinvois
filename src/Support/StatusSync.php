<?php

namespace Jiannius\Myinvois\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Jiannius\Myinvois\Enums\Status;
use Jiannius\Myinvois\Exceptions\MyinvoisAuthenticationException;
use Jiannius\Myinvois\Exceptions\MyinvoisException;
use Jiannius\Myinvois\Exceptions\MyinvoisPermissionException;
use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Myinvois;

/**
 * The safety-net sweep behind `myinvois:sync`: ask LHDN again about submissions whose rows
 * are still `submitted` (a poll job can die on a deploy or a worker restart), and with
 * $includeValid also about recent VALID ones, since a buyer can still reject or the issuer
 * cancel for 72 hours.
 *
 * INVALID is final -- LHDN never turns an INVALID document into a VALID one -- so those
 * rows are never swept. Rows this run turns INVALID get LHDN's reasons stored (the poll
 * summary has none; Myinvois::fetchInvalidReasons() asks the document details, best effort).
 *
 * One getSubmission() call per submission: a consolidated e-invoice has one row per
 * invoice sharing a submission uid, and one answer updates them all. Statuses are written
 * through Myinvois::updateMyinvoisDocuments(), so the observer's latest-submission rule
 * still protects the parent, and the parent's updated_at is not touched.
 */
class StatusSync
{
    /**
     * The 72 hour window in which LHDN still lets a valid document be cancelled / rejected.
     */
    public const VALID_WINDOW_HOURS = 72;

    public function __construct(
        protected int $limit,
        protected bool $dryRun = false,
        protected bool $includeValid = false,
    ) {
    }

    /**
     * @return array{
     *     preprod: bool,
     *     candidates: int,
     *     checked: int,
     *     changed: int,
     *     skipped_no_parent: int,
     *     skipped_environment: int,
     *     skipped_auth: int,
     *     errors: array<int, string>,
     *     would_check: array<int, string>,
     * }
     */
    public function run() : array
    {
        $preprod = (bool) app('myinvois')->getSettings('preprod');
        $model = MyinvoisDocument::$useModel;

        $result = [
            'preprod' => $preprod,
            'candidates' => 0,
            'checked' => 0,
            'changed' => 0,
            'skipped_no_parent' => 0,
            'skipped_environment' => 0,
            'skipped_auth' => 0,
            'errors' => [],
            'would_check' => [],
        ];

        if (! Schema::hasTable((new $model)->getTable())) return $result;

        // rows that cannot be swept because nothing says which org / client they belong to;
        // only the ones that are still waiting for an answer are worth counting
        $result['skipped_no_parent'] = $this->eligible($preprod)
            ->where('status', Status::SUBMITTED->value)
            ->where(fn ($q) => $q->whereNull('parent_type')->orWhereNull('parent_id'))
            ->distinct()
            ->count('submission_uid');

        // one row per submission, newest first (ULIDs sort by time); ids only until we know
        // what to ask. Newest first so submissions that never resolve cannot fill the limit
        // run after run and starve the recent ones that are still worth asking about.
        $submissions = $this->candidates($preprod)
            ->toBase()
            ->selectRaw('submission_uid, max(id) as last_id')
            ->groupBy('submission_uid')
            ->orderByDesc('last_id')
            ->limit($this->limit)
            ->get();

        $result['candidates'] = $submissions->count();

        $failedClients = [];

        foreach ($submissions as $submission) {
            $row = (new $model)->newQueryWithoutScopes()->find($submission->last_id);
            $parent = $row ? ParentReference::resolve(['type' => $row->parent_type, 'id' => $row->parent_id]) : null;

            if (! $parent) {
                $result['skipped_no_parent']++;
                continue;
            }

            $client = Myinvois::clientFor($parent);

            // never ask the preprod portal about production uids (or the reverse)
            if ((bool) $client->getSettings('preprod') !== $preprod) {
                $result['skipped_environment']++;
                continue;
            }

            $clientKey = $client->getSettings('client_id').'|'.$client->getSettings('on_behalf_of');

            if (isset($failedClients[$clientKey])) {
                $result['skipped_auth']++;
                continue;
            }

            if ($this->dryRun) {
                $result['would_check'][] = $submission->submission_uid;
                continue;
            }

            $before = $this->statuses($submission->submission_uid);

            try {
                $client->getSubmission($submission->submission_uid);
            }
            catch (MyinvoisAuthenticationException|MyinvoisPermissionException $e) {
                // the same credentials will fail for every submission of this client
                $failedClients[$clientKey] = true;
                $result['errors'][] = $submission->submission_uid.': '.$e->getMessage();
                continue;
            }
            catch (MyinvoisException $e) {
                $result['errors'][] = $submission->submission_uid.': '.$e->getMessage();
                continue;
            }
            catch (\Throwable $e) {
                report($e);
                $result['errors'][] = $submission->submission_uid.': '.$e->getMessage();
                continue;
            }

            $after = $this->statuses($submission->submission_uid);

            $result['checked']++;
            $result['changed'] += collect($after)->filter(fn ($status, $id) => ($before[$id] ?? null) !== $status)->count();

            // LHDN gives the reasons only in the document details: store them for the rows
            // that just turned INVALID (best effort: a failure never stops the sweep)
            $client->fetchInvalidReasons(
                (new $model)->newQueryWithoutScopes()
                    ->whereKey(collect($after)
                        ->filter(fn ($status, $id) => $status === Status::INVALID->value && ($before[$id] ?? null) !== Status::INVALID->value)
                        ->keys()
                        ->all())
                    ->get()
            );
        }

        return $result;
    }

    /**
     * Rows that could be swept at all: ours (not received), with something to ask LHDN
     * about, in this environment.
     */
    protected function eligible(bool $preprod) : Builder
    {
        $model = MyinvoisDocument::$useModel;

        return (new $model)->newQueryWithoutScopes()
            ->whereNotNull('document_uuid')
            ->whereNotNull('submission_uid')
            ->when(Schema::hasColumn((new $model)->getTable(), 'is_inbound'), fn ($q) => $q
                ->where(fn ($q) => $q->where('is_inbound', false)->orWhereNull('is_inbound'))
            )
            ->tap(fn ($q) => $this->environment($q, $preprod));
    }

    /**
     * The rows worth asking about: still SUBMITTED, plus (with $includeValid) the parent's
     * latest VALID row while it is inside the 72 hour window.
     */
    protected function candidates(bool $preprod) : Builder
    {
        $model = MyinvoisDocument::$useModel;
        $table = (new $model)->getTable();

        return $this->eligible($preprod)
            ->whereNotNull('parent_type')
            ->whereNotNull('parent_id')
            ->where(function ($q) use ($preprod, $table) {
                $q->where('status', Status::SUBMITTED->value);

                if (! $this->includeValid) return;

                $q->orWhere(fn ($q) => $q
                    ->where('status', Status::VALID->value)
                    ->where('created_at', '>=', now()->subHours(static::VALID_WINDOW_HOURS))
                    // only the parent's latest submission counts (highest ULID, as the observer)
                    ->whereIn('id', fn ($sub) => $sub
                        ->from($table)
                        ->selectRaw('max(id)')
                        ->whereNotNull('parent_type')
                        ->tap(fn ($sub) => $this->environment($sub, $preprod))
                        ->groupBy('parent_type', 'parent_id')
                    )
                );
            });
    }

    /**
     * Preprod runs only touch preprod rows; production touches false or null (rows from
     * before the column existed).
     */
    protected function environment($query, bool $preprod) : void
    {
        if ($preprod) {
            $query->where('is_preprod', true);
        }
        else {
            $query->where(fn ($q) => $q->where('is_preprod', false)->orWhereNull('is_preprod'));
        }
    }

    /**
     * @return array<string, string|null>  row id => status value
     */
    protected function statuses(string $submissionUid) : array
    {
        $model = MyinvoisDocument::$useModel;

        return (new $model)->newQueryWithoutScopes()
            ->where('submission_uid', $submissionUid)
            ->get()
            ->mapWithKeys(fn ($row) => [$row->getKey() => $row->status?->value])
            ->all();
    }
}
