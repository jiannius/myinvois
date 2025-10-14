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
     * Fill the parent status
     */
    public function fillParentStatus($document, $parent, $status)
    {
        if ($document->is_preprod) $parent->fill(['myinvois_preprod_status' => $status])->saveQuietly();
        else $parent->fill(['myinvois_status' => $status])->saveQuietly();
    }
}