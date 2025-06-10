<?php

namespace Jiannius\Myinvois\Observers;

class MyinvoisDocumentObserver
{
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

    public function fillParentStatus($document, $parent, $status)
    {
        if ($document->is_preprod) $parent->fill(['myinvois_preprod_status' => $status])->saveQuietly();
        else $parent->fill(['myinvois_status' => $status])->saveQuietly();
    }
}