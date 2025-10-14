<?php

namespace Jiannius\Myinvois\Models\Observers;

class HasMyinvoisDocumentObserver
{
    /**
     * Delete the myinvois documents when the model is deleted
     */
    public function deleting($model)
    {
        $model->myinvoisDocuments()->delete();
        $model->preprodMyinvoisDocuments()->delete();
    }
}