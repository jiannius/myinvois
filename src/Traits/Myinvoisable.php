<?php

namespace Jiannius\Myinvois\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Jiannius\Myinvois\Enums\Status;
use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Observers\MyinvoisableObserver;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

trait Myinvoisable
{
    protected function initializeMyinvoisable()
    {
        $this->casts['myinvois_status'] = Status::class;
        $this->casts['myinvois_preprod_status'] = Status::class;
    }

    protected static function bootMyinvoisable()
    {
        static::observe(MyinvoisableObserver::class);
    }

    public function myinvoisDocuments() : MorphMany
    {
        return $this->morphMany(MyinvoisDocument::class, 'parent')
            ->where('is_preprod', $this->isMyinvoisPreprod());
    }

    public function latestMyinvoisDocument() : MorphOne
    {
        return $this->morphOne(MyinvoisDocument::class, 'parent')
            ->where('is_preprod', $this->isMyinvoisPreprod())
            ->latestOfMany();
    }

    public function scopeMyinvoisSubmitted($query, $submitted = true) : void
    {
        if ($submitted) {
            $query->whereHas('latestMyinvoisDocument', fn ($q) => $q->whereIn('status', [Status::SUBMITTED, Status::VALID]));
        }
        else {
            $query->whereDoesntHave('latestMyinvoisDocument', fn ($q) => $q->whereIn('status', [Status::SUBMITTED, Status::VALID]));
        }
    }

    public function isMyinvoisPreprod() : bool
    {
        return false;
    }

    public function isMyinvoisSubmitted($submitted = true) : bool
    {
        if (!$submitted) return !$this->isMyinvoisSubmitted();

        return $this->latestMyinvoisDocument?->status?->is(Status::SUBMITTED, Status::VALID) ?? false;
    }

    public function getMyinvoisStatus()
    {
        return $this->isMyinvoisPreprod() ? $this->myinvois_preprod_status : $this->myinvois_status;
    }

    public function getMyinvoisValidationLink() : string
    {
        return $this->latestMyinvoisDocument?->validation_link ?? '';
    }

    public function getMyinvoisQrCode() : string
    {
        $url = $this->getMyinvoisValidationLink();

        if (!$url) return '';

        $qr = QrCode::size(256)->format('png')->generate($url);

        return 'data:image/png;base64,'.base64_encode($qr);
    }
}
