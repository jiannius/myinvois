<?php

namespace Jiannius\Myinvois\Observers;

class MyinvoisableObserver
{
    public function deleting($myinvoisable)
    {
        $myinvoisable->myinvoisDocuments()->delete();
    }
}