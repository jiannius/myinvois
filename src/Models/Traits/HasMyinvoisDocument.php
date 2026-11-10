<?php

namespace Jiannius\Myinvois\Models\Traits;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Jiannius\Myinvois\Enums\Status;
use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Models\Observers\HasMyinvoisDocumentObserver;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

trait HasMyinvoisDocument
{
    /**
     * Initialize the trait
     */
    protected function initializeHasMyinvoisDocument()
    {
        $this->casts['myinvois_status'] = Status::class;
        $this->casts['myinvois_preprod_status'] = Status::class;
    }

    /**
     * Boot the trait
     */
    protected static function bootHasMyinvoisDocument()
    {
        // Deferred via whenBooted because L13 forbids `new static` while $booting is set,
        // and observe() instantiates the model. whenBooted runs after boot completes.
        static::whenBooted(fn () => static::observe(HasMyinvoisDocumentObserver::class));
    }

    /**
     * Get the myinvois documents
     */
    public function myinvoisDocuments() : MorphMany
    {
        return $this->morphMany(MyinvoisDocument::$useModel, 'parent')->where(fn ($q) => $q
            ->where('is_preprod', false)->orWhereNull('is_preprod')
        );
    }

    /**
     * Get the preprod myinvois documents
     */
    public function preprodMyinvoisDocuments() : MorphMany
    {
        return $this->morphMany(MyinvoisDocument::$useModel, 'parent')
            ->where('is_preprod', true);
    }

    /**
     * Get the latest myinvois document
     */
    public function latestMyinvoisDocument() : MorphOne
    {
        return $this->morphOne(MyinvoisDocument::$useModel, 'parent')->where(fn ($q) => $q
            ->where('is_preprod', false)->orWhereNull('is_preprod')
        )->latestOfMany();
    }

    /**
     * Get the preprod latest myinvois document
     */
    public function preprodLatestMyinvoisDocument() : MorphOne
    {
        return $this->morphOne(MyinvoisDocument::$useModel, 'parent')
            ->where('is_preprod', true)
            ->latestOfMany();
    }

    /**
     * Scope the query to only include myinvois documents that are submitted
     */
    public function scopeWithSubmittedMyinvoisDocument($query, $submitted = true, $preprod = false) : void
    {
        $status = [Status::SUBMITTED, Status::VALID];

        if ($preprod) {
            if ($submitted) $query->whereHas('preprodLatestMyinvoisDocument', fn ($q) => $q->whereIn('status', $status));
            else $query->whereDoesntHave('preprodLatestMyinvoisDocument', fn ($q) => $q->whereIn('status', $status));
        }
        else {
            if ($submitted) $query->whereHas('latestMyinvoisDocument', fn ($q) => $q->whereIn('status', $status));
            else $query->whereDoesntHave('latestMyinvoisDocument', fn ($q) => $q->whereIn('status', $status));
        }
    }

    /**
     * Check if the model is myinvois submitted
     */
    public function isSubmittedToMyinvois($submitted = true, $preprod = false) : bool
    {
        if (!$submitted) return !$this->isSubmittedToMyinvois(preprod: $preprod);

        return $preprod
            ? $this->preprodLatestMyinvoisDocument?->status?->is(Status::SUBMITTED, Status::VALID) ?? false
            : $this->latestMyinvoisDocument?->status?->is(Status::SUBMITTED, Status::VALID) ?? false;
    }

    /**
     * Whether a submission of this model may have reached MyInvois without being recorded
     * (a timeout, a 5xx, a process that died): see Myinvois::hasPendingAttempt(). Hold off
     * edits and voids while it is true; the next submission resolves it.
     */
    public function hasPendingMyinvoisAttempt(?bool $preprod = null) : bool
    {
        return \Jiannius\Myinvois\Myinvois::hasPendingAttempt($this, $preprod);
    }

    /**
     * Close this model's pending submit attempts after a person checked the MyInvois portal:
     * see Myinvois::releasePendingAttempts().
     */
    public function releasePendingMyinvoisAttempts(?bool $preprod = null, array $meta = []) : int
    {
        return \Jiannius\Myinvois\Myinvois::releasePendingAttempts($this, $preprod, $meta);
    }

    /**
     * Get the myinvois validation link
     */
    public function getMyinvoisValidationLink($preprod = false) : string
    {
        return $preprod
            ? $this->preprodLatestMyinvoisDocument?->validation_link ?? ''
            : $this->latestMyinvoisDocument?->validation_link ?? '';
    }

    /**
     * Get the myinvois qr code
     */
    public function getMyinvoisQrCode($preprod = false) : string
    {
        $url = $this->getMyinvoisValidationLink($preprod);

        if (!$url) return '';

        $qr = QrCode::size(256)->format('png')->generate($url);

        return 'data:image/png;base64,'.base64_encode($qr);
    }
}
