<?php

namespace Jiannius\Myinvois\Traits;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Jiannius\Myinvois\Enums\Status;
use Jiannius\Myinvois\Models\MyinvoisDocument;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

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
        return $submittable
            ? !$this->myinvois_documents()->count() || $this->myinvois_documents()->latest()->first()->isSubmittable()
            : !$this->isSubmittableToMyinvois();
    }

    public function isSubmittedToMyinvois($submitted = true) : bool
    {
        return $submitted
            ? optional($this->myinvois_documents()->latest()->first())->isSubmitted() ?? false
            : !$this->isSubmittedToMyinvois();
    }

    public function isCancellableFromMyinvois($cancellable = true) : bool
    {
        $last = $this->myinvois_documents()->latest()->first();

        return $cancellable
            ? $last?->isCancellable()
            : !$this->isCancellableFromMyinvois();
    }

    public function isValidatedByMyinvois($validated = true) : bool
    {
        return $validated
            ? optional($this->myinvois_documents()->latest()->first())->isValid() ?? false
            : !$this->isValidatedByMyinvois();
    }

    public function getMyinvoisValidationLink() : string
    {
        return optional($this->myinvois_documents()->latest()->first())->validation_link ?? '';
    }

    public function getMyinvoisQrCode() : string
    {
        $url = $this->getMyinvoisValidationLink();

        if (!$url) return '';

        $qr = QrCode::size(256)->format('png')->generate($url);

        return 'data:image/png;base64,'.base64_encode($qr);
    }
}
