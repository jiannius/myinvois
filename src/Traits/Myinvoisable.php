<?php

namespace Jiannius\Myinvois\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Jiannius\Myinvois\Enums\Status;
use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Observers\MyinvoisableObserver;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

trait Myinvoisable
{
    public static function bootMyinvoisable()
    {
        static::observe(MyinvoisableObserver::class);
    }

    public function myinvoisDocuments() : MorphMany
    {
        return $this->morphMany(MyinvoisDocument::class, 'parent');
    }

    public function isSubmittableToMyinvois($flag = true, $preprod = false) : bool
    {
        if (!$flag) return !$this->isSubmittableToMyinvois();

        return !$this->myinvoisDocuments()->preprod($preprod)->count()
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

    public function getLastMyinvoisDocument($preprod = false)
    {
        return $this->myinvoisDocuments()->preprod($preprod)->latest()->first();
    }

    public function getMyinvoisStatus($preprod = false) : Status
    {
        return $this->getLastMyinvoisDocument($preprod)?->status;
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
