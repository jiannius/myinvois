<?php

namespace Jiannius\Myinvois\Testing;

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Jiannius\Myinvois\Helpers\UBL;
use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Myinvois;
use Jiannius\Myinvois\Support\SubmissionLock;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * In-memory test double for the MyInvois API, in the spirit of Laravel's
 * Mail::fake() / Bus::fake(). Activated via Myinvois::fake(), which swaps the
 * `myinvois` container binding so host-app code calling app('myinvois')->...
 * hits this instead of the network.
 *
 * It never signs, never makes an HTTP call, and never sleeps. submit / cancel /
 * reject are recorded for assertions and persist MyinvoisDocument rows exactly
 * like the real pipeline (consolidated-aware), so observers and parent-status
 * write-back behave normally. Read endpoints return canned JSON.
 *
 * It also honours forParents() (rows are stamped with their parent, and the same
 * in-flight lock and SUBMITTED / VALID refusal apply), and the queue jobs resolve
 * app('myinvois'), so Myinvois::queueSubmission() + the jobs run against it. For a
 * "submitted now, resolved later" flow use pollResolvesTo().
 */
class MyinvoisFake extends Myinvois
{
    /** @var array<int, array{documents: array, response: array}> */
    public array $submitted = [];

    /** @var array<int, array{uid: string, reason: ?string}> */
    public array $cancelled = [];

    /** @var array<int, array{uid: string, reason: ?string}> */
    public array $rejected = [];

    /** @var array<int, array{uri: string, method: string, data: array}> */
    public array $apiCalls = [];

    /** Status applied to persisted documents and reported in summaries. */
    protected string $status = 'valid';

    /** Submission UID stamped on faked submissions. */
    protected string $submissionUid = 'fake-submission';

    /** @var array<int, array{pattern: string, body: array, status: int}> */
    protected array $apiResponses = [];

    /**
     * When set, submissions are saved as `submitted` and only getSubmission() (the poll
     * job, the sweep) resolves them to this status.
     */
    protected ?string $pollStatus = null;

    /** @var array<int, string> LHDN-style validation messages for rows resolved to invalid. */
    protected array $validationErrors = ['Fake validation error'];

    // ---- configuration -------------------------------------------------

    /** Set the lifecycle status faked submissions resolve to. */
    public function resolveStatus(string $status) : static
    {
        $this->status = $status;
        return $this;
    }

    /**
     * Save faked submissions as `submitted` and resolve them to $status ('valid' or
     * 'invalid') only when getSubmission() is called -- what the poll job and the
     * myinvois:sync sweep do. For an `invalid` outcome pass the messages LHDN would give.
     *
     * @param  array<int, string>|null  $errors
     */
    public function pollResolvesTo(string $status, ?array $errors = null) : static
    {
        $this->pollStatus = $status;

        if ($errors !== null) $this->validationErrors = $errors;

        return $this;
    }

    /** Set the submission UID stamped on faked submissions. */
    public function withSubmissionUid(string $uid) : static
    {
        $this->submissionUid = $uid;
        return $this;
    }

    /** Register a canned JSON body for read endpoints matching a URI pattern. */
    public function respondToApi(string $uriPattern, array $body, int $status = 200) : static
    {
        $this->apiResponses[] = ['pattern' => $uriPattern, 'body' => $body, 'status' => $status];
        return $this;
    }

    // ---- overridden network surface ------------------------------------

    public function getToken()
    {
        return 'fake-token';
    }

    public function callApi($uri, $method = 'GET', $data = [], $perMinute = null)
    {
        $this->apiCalls[] = ['uri' => $uri, 'method' => strtolower((string) $method), 'data' => $data];

        foreach ($this->apiResponses as $stub) {
            if (Str::is($stub['pattern'], $uri)) {
                return $this->response($stub['body'], $stub['status']);
            }
        }

        return $this->response([], 200);
    }

    public function submitDocuments($documents = [])
    {
        if ($documents === 'sample') $documents = [$this->getSample()];

        $documents = collect($documents)->values()->all();

        $this->assertParentsCover($documents);

        // the same in-flight lock + SUBMITTED / VALID refusal as the real client
        $preprod = (bool) $this->getSettings('preprod');
        $parents = $this->parentsOf($documents);
        $owner = $this->lockOwner;

        if ($parents) {
            try {
                if ($owner) SubmissionLock::ensure($parents, $preprod, $owner);
                else $owner = SubmissionLock::acquire($parents, $preprod);

                SubmissionLock::assertSubmittable($parents, $preprod);
            }
            catch (\Throwable $e) {
                if ($owner) SubmissionLock::release($parents, $preprod, $owner);

                throw $e;
            }
        }

        try {
            $response = $this->makeSubmissionResponse($documents);

            $this->submitted[] = ['documents' => $documents, 'response' => $response];

            $created = $this->persistSubmitted($response, $documents);
        }
        finally {
            if ($owner) SubmissionLock::release($parents, $preprod, $owner);
        }

        return $created->isNotEmpty()
            ? ['myinvois_documents' => $created, 'response' => $response]
            : $response;
    }

    /**
     * Resolves `submitted` rows to the status set with pollResolvesTo(); with none set
     * the rows already hold their final status, so this just reports them. A canned
     * respondToApi('documentsubmissions/*') body still wins.
     */
    public function getSubmission($uid, $pageNo = null, $pageSize = null)
    {
        foreach ($this->apiResponses as $stub) {
            if (Str::is($stub['pattern'], 'documentsubmissions/'.$uid)) return parent::getSubmission($uid, $pageNo, $pageSize);
        }

        $this->apiCalls[] = ['uri' => 'documentsubmissions/'.$uid, 'method' => 'get', 'data' => ['pageNo' => $pageNo, 'pageSize' => $pageSize]];

        if (! Schema::hasTable('myinvois_documents')) return ['submissionUid' => $uid, 'documentSummary' => []];

        $summary = (MyinvoisDocument::$useModel)::query()
            ->where('submission_uid', $uid)
            ->get()
            ->groupBy('document_uuid')
            ->map(function ($rows, $uuid) use ($uid) {
                $status = $this->pollStatus && $rows->first()->status?->is('submitted')
                    ? $this->pollStatus
                    : $rows->first()->status?->value;

                $item = $this->summaryFor($uuid, $uid, $status);

                if ($status) $this->updateMyinvoisDocuments($item);

                return $item;
            })
            ->values()
            ->all();

        return ['submissionUid' => $uid, 'documentSummary' => $summary];
    }

    /** The post-submit status poll never sleeps. */
    protected function pollAfterSubmit($submissionUid) : void
    {
        //
    }

    public function cancelDocument($uid, $reason = null)
    {
        $this->cancelled[] = ['uid' => $uid, 'reason' => $reason];

        $response = ['uuid' => $uid, 'status' => 'cancelled', 'reason' => $reason];

        if (Schema::hasTable('myinvois_documents')) {
            (MyinvoisDocument::$useModel)::query()
                ->where('document_uuid', $uid)
                ->get()
                ->each(fn ($doc) => $doc->update(['status' => 'cancelled', 'response' => $response]));
        }

        return $response;
    }

    public function rejectDocument($uid, $reason = null)
    {
        $this->rejected[] = ['uid' => $uid, 'reason' => $reason];

        return ['uuid' => $uid, 'status' => 'rejected', 'reason' => $reason];
    }

    // ---- assertions ----------------------------------------------------

    public function assertSubmitted(?callable $callback = null) : void
    {
        if ($callback === null) {
            PHPUnit::assertNotEmpty($this->submitted, 'Expected documents to be submitted to MyInvois, but none were.');
            return;
        }

        $passed = collect($this->submitted)->contains(fn ($s) => $callback($s['documents'], $s['response']));

        PHPUnit::assertTrue($passed, 'Expected a matching submitDocuments() call, but none was recorded.');
    }

    public function assertNothingSubmitted() : void
    {
        PHPUnit::assertEmpty($this->submitted, 'Expected no documents to be submitted to MyInvois, but some were.');
    }

    public function assertSubmittedCount(int $count) : void
    {
        PHPUnit::assertCount($count, $this->submitted, "Expected $count submitDocuments() calls.");
    }

    public function assertDocumentSubmitted(string $number) : void
    {
        $found = collect($this->submitted)
            ->flatMap(fn ($s) => $s['documents'])
            ->contains(fn ($doc) => data_get($doc, 'number') === $number);

        PHPUnit::assertTrue($found, "Expected document [$number] to be submitted, but it was not.");
    }

    public function assertCancelled(?string $uid = null) : void
    {
        if ($uid === null) {
            PHPUnit::assertNotEmpty($this->cancelled, 'Expected a document to be cancelled, but none was.');
            return;
        }

        $found = collect($this->cancelled)->contains(fn ($c) => $c['uid'] === $uid);

        PHPUnit::assertTrue($found, "Expected document [$uid] to be cancelled, but it was not.");
    }

    public function assertNotCancelled() : void
    {
        PHPUnit::assertEmpty($this->cancelled, 'Expected no document to be cancelled, but one was.');
    }

    public function assertRejected(?string $uid = null) : void
    {
        if ($uid === null) {
            PHPUnit::assertNotEmpty($this->rejected, 'Expected a document to be rejected, but none was.');
            return;
        }

        $found = collect($this->rejected)->contains(fn ($r) => $r['uid'] === $uid);

        PHPUnit::assertTrue($found, "Expected document [$uid] to be rejected, but it was not.");
    }

    // ---- internals -----------------------------------------------------

    protected function response(array $body, int $status) : Response
    {
        return new Response(new Psr7Response($status, ['Content-Type' => 'application/json'], json_encode($body)));
    }

    protected function makeSubmissionResponse(array $documents) : array
    {
        $accepted = [];
        $summary = [];

        foreach ($documents as $i => $document) {
            $uuid = 'fake-uuid-'.($i + 1);

            $accepted[] = [
                'uuid' => $uuid,
                'invoiceCodeNumber' => data_get($document, 'number'),
            ];

            $summary[] = $this->summaryFor($uuid, $this->submissionUid, $this->pollStatus ? 'submitted' : $this->status);
        }

        return [
            'submissionUid' => $this->submissionUid,
            'acceptedDocuments' => $accepted,
            'documentSummary' => $summary,
        ];
    }

    /** A documentSummary entry as LHDN reports it. */
    protected function summaryFor(string $uuid, string $submissionUid, ?string $status) : array
    {
        $summary = [
            'uuid' => $uuid,
            'submissionUid' => $submissionUid,
            'status' => ucfirst((string) $status),
            'longId' => 'fake-longid-'.$uuid,
        ];

        if ($status === 'invalid') {
            $summary['validationResults'] = [
                'status' => 'Invalid',
                'validationSteps' => [[
                    'status' => 'Invalid',
                    'error' => ['innerError' => collect($this->validationErrors)->map(fn ($message) => ['error' => $message])->all()],
                ]],
            ];
        }

        return $summary;
    }

    protected function persistSubmitted(array $response, array $documents) : Collection
    {
        $created = collect();

        if (! Schema::hasTable('myinvois_documents')) return $created;

        $model = MyinvoisDocument::$useModel;
        $submissionUid = data_get($response, 'submissionUid');

        foreach (data_get($response, 'acceptedDocuments', []) as $data) {
            $document = collect($documents)->firstWhere('number', data_get($data, 'invoiceCodeNumber'));
            $summary = collect(data_get($response, 'documentSummary'))->firstWhere('uuid', data_get($data, 'uuid'));

            if (UBL::isConsolidated($document)) {
                foreach (data_get($document, 'line_items', []) as $lineItem) {
                    $created->push($model::create([
                        'document_uuid' => data_get($data, 'uuid'),
                        'submission_uid' => $submissionUid,
                        'document_number' => data_get($lineItem, 'description'),
                        'consolidate_number' => data_get($document, 'number'),
                        'status' => $this->pollStatus ? 'submitted' : $this->status,
                        'is_preprod' => $this->getSettings('preprod'),
                        'response' => $summary,
                        ...$this->parentAttributes(data_get($lineItem, 'description')),
                    ]));
                }
            }
            else {
                $created->push($model::create([
                    'document_uuid' => data_get($data, 'uuid'),
                    'submission_uid' => $submissionUid,
                    'document_number' => data_get($data, 'invoiceCodeNumber'),
                    'status' => $this->pollStatus ? 'submitted' : $this->status,
                    'is_preprod' => $this->getSettings('preprod'),
                    'response' => $summary,
                    ...$this->parentAttributes(data_get($data, 'invoiceCodeNumber')),
                ]));
            }
        }

        return $created;
    }
}
