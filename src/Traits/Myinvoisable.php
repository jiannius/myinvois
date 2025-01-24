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

    public function scopeIsSubmittable($query, $submittable = true) : void
    {
        if ($submittable) {
            $query->where(fn ($q) => $q
                ->doesntHave('myinvois_documents')
                ->orWhereHas('myinvois_documents', fn ($q) => $q->whereIn('status', ['invalid', 'cancelled']))
            );
        }
        else {
            $query->whereHas('myinvois_documents', fn ($q) => $q->whereIn('status', ['submitted', 'valid']));
        }
    }

    public function isSubmittable() : bool
    {
        return in_array($this->status, ['invalid', 'cancelled']);
    }
}
