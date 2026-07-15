<?php

namespace Jiannius\Myinvois\Models\Observers;

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