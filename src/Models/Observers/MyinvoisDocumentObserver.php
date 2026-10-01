<?php

namespace Jiannius\Myinvois\Models\Observers;

use Jiannius\Myinvois\Models\MyinvoisDocument;

class MyinvoisDocumentObserver
{
    /**
     * The model saved event
     */
    public function saved($document)
    {
        $document = $document->fresh();
        $parent = $document->parent;
        $status = $document->status?->value;

        if (!$parent) return;

        // A superseded submission (e.g. an old INVALID one re-saved by a status
        // sync) must not overwrite the status of the newer submission.
        if (!$this->isLatestSubmission($document)) return;

        $this->fillParentStatus(
            document: $document,
            parent: $parent,
            status: $status,
        );
    }

    /**
     * The model deleting event
     */
    public function deleting($document)
    {
        $document = $document->fresh();
        $parent = $document->parent;

        if (!$parent) return;

        $this->fillParentStatus(
            document: $document,
            parent: $parent,
            status: null,
        );
    }

    /**
     * Check if the document is the parent's latest submission for its
     * environment. Mirrors HasMyinvoisDocument::latestMyinvoisDocument() /
     * preprodLatestMyinvoisDocument(): latestOfMany() on the ULID primary key
     * (highest id wins), prod being is_preprod false OR null, preprod being
     * is_preprod true. Honours MyinvoisDocument::$useModel.
     */
    protected function isLatestSubmission($document) : bool
    {
        $model = MyinvoisDocument::$useModel;

        $latest = (new $model)->newQueryWithoutScopes()
            ->where('parent_type', $document->parent_type)
            ->where('parent_id', $document->parent_id)
            ->when(
                $document->is_preprod,
                fn ($q) => $q->where('is_preprod', true),
                fn ($q) => $q->where(fn ($q) => $q->where('is_preprod', false)->orWhereNull('is_preprod')),
            )
            ->max('id');

        return (string) $latest === (string) $document->getKey();
    }

    /**
     * Fill the parent status.
     *
     * Uses a key-targeted query update rather than save()/saveQuietly() so it
     * does NOT bump the parent's updated_at (and fires no model events):
     * submitting, syncing or cancelling an e-invoice is not a content edit of
     * the parent document and must not move its last-modified timestamp.
     * newQueryWithoutScopes keeps the update working regardless of the branch/
     * tenant scope context it runs in (web, queue, cron).
     */
    public function fillParentStatus($document, $parent, $status)
    {
        $column = $document->is_preprod ? 'myinvois_preprod_status' : 'myinvois_status';

        $parent->newQueryWithoutScopes()->whereKey($parent->getKey())->update([$column => $status]);

        // Keep the in-memory instance consistent without marking it dirty.
        $parent->setAttribute($column, $status)->syncOriginalAttribute($column);
    }
}