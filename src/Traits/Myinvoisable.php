<?php

namespace Jiannius\Myinvois\Traits;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Jiannius\Myinvois\Models\MyinvoisDocument;

trait Myinvoisable
{
    public function myinvois_documents() : HasMany
    {
        return $this->hasMany(MyinvoisDocument::class);
    }
}
