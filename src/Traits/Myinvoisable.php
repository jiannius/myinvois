<?php

namespace Jiannius\Myinvois\Traits;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Jiannius\Myinvois\Enums\Status;
use Jiannius\Myinvois\Models\MyinvoisDocument;

trait Myinvoisable
{
    public function myinvois_documents() : HasMany
    {
        return $this->hasMany(MyinvoisDocument::class);
    }

    public function scopeIsSubmittableToMyinvois($query, $submittable = true) : void
    {
        if ($submittable) {
            $query->where(fn ($q) => $q
                ->doesntHave('myinvois_documents')
                ->orWhereHas('myinvois_documents', fn ($q) => $q->whereIn('status', [Status::INVALID, Status::CANCELLED]))
            );
        }
        else {
            $query->whereHas('myinvois_documents', fn ($q) => $q->whereIn('status', [Status::SUBMITTED, Status::VALID]));
        }
    }

    public function isSubmittableToMyinvois($submittable = true) : bool
    {
        if ($submittable) {
            return !$this->myinvois_documents()->count()
                || in_array($this->myinvois_documents()->latest()->first()->status, [Status::INVALID, Status::CANCELLED]);
        }
        else {
            return !$this->isSubmittableToMyinvois();
        }
    }

    public function isSyncableWithMyinvois($syncable = true) : bool
    {
        if ($syncable) {
            return optional($this->myinvois_documents()->latest()->first())->status === Status::SUBMITTED;
        }
        else {
            return !$this->isSyncableWithMyinvois();
        }
    }
}
