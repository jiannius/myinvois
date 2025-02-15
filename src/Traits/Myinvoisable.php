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

    public function scopeIsSubmittableToMyinvois($query, $flag = true, $preprod = false) : void
    {
        if ($flag) {
            $query->where(fn ($q) => $q
                ->whereDoesntHave('myinvois_documents', fn ($q) => $q->preprod($preprod))
                ->orWhereHas('myinvois_documents', fn ($q) => $q->preprod($preprod)->whereIn('status', [Status::INVALID, Status::CANCELLED]))
            );
        }
        else {
            $query->whereHas('myinvois_documents', fn ($q) => $q->preprod($preprod)->whereIn('status', [Status::SUBMITTED, Status::VALID]));
        }
    }

    public function getLastMyinvoisDocument($preprod = false)
    {
        return $this->myinvois_documents()->preprod($preprod)->latest()->first();
    }

    public function isSubmittableToMyinvois($flag = true, $preprod = false) : bool
    {
        if (!$flag) return !$this->isSubmittableToMyinvois();

        return !$this->myinvois_documents()->preprod($preprod)->count()
            || $this->getLastMyinvoisDocument($preprod)->isSubmittable();
    }

    public function isSubmittedToMyinvois($flag = true, $preprod = false) : bool
    {
        if (!$flag) return !$this->isSubmittedToMyinvois();

        return $this->getLastMyinvoisDocument($preprod)?->isSubmitted() ?? false;
    }

    public function isCancellableFromMyinvois($flag = true, $preprod = false) : bool
    {
        if (!$flag) return !$this->isCancellableFromMyinvois();

        return $this->getLastMyinvoisDocument($preprod)?->isCancellable() ?? false;
    }

    public function isValidatedByMyinvois($flag = true, $preprod = false) : bool
    {
        if (!$flag) return !$this->isValidatedByMyinvois();

        return $this->getLastMyinvoisDocument($preprod)?->isValid() ?? false;
    }

    public function getMyinvoisValidationLink($preprod = false) : string
    {
        return $this->getLastMyinvoisDocument($preprod)?->validation_link ?? '';
    }

    public function getMyinvoisQrCode($preprod = false) : string
    {
        $url = $this->getMyinvoisValidationLink($preprod);

        if (!$url) return '';

        $qr = QrCode::size(256)->format('png')->generate($url);

        return 'data:image/png;base64,'.base64_encode($qr);
    }
}
