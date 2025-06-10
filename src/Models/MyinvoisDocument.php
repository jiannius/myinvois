<?php

namespace Jiannius\Myinvois\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Jiannius\Myinvois\Enums\Status;
use Jiannius\Myinvois\Observers\MyinvoisDocumentObserver;

class MyinvoisDocument extends Model
{
    use HasFactory;
    use HasUlids;

    protected $guarded = [];

    protected $casts = [
        'request' => 'array',
        'response' => 'array',
        'status' => Status::class,
        'is_preprod' => 'boolean',
    ];

    protected static function booted() : void
    {
        static::observe(MyinvoisDocumentObserver::class);
    }

    public function parent() : MorphTo
    {
        return $this->morphTo();
    }

    public function getValidationLinkAttribute() : string
    {
        $longid = data_get($this->response, 'longId');

        if (!$longid) return '';

        $baseurl = $this->is_preprod ? 'https://preprod.myinvois.hasil.gov.my' : 'https://myinvois.hasil.gov.my';

        return "{$baseurl}/{$this->document_uuid}/share/{$longid}";
    }

    public function scopePreprod($query, $preprod = true) : void
    {
        $query->where('is_preprod', (bool) $preprod);
    }

    public function scopeStatus($query, $status) : void
    {
        if (!$status) return;

        if (is_array($status)) $query->whereIn('status', $status);
        else $query->where('status', $status);
    }

    public function getErrors()
    {
        return collect(data_get($this->response, 'validationResults.validationSteps'))
            ->pluck('error.innerError')
            ->filter()
            ->values()
            ->collapse()
            ->pluck('error')
            ->values()
            ->all();
    }

    public function isCancellable() : bool
    {
        return $this->status?->is('VALID') && $this->created_at->diffInHours(now()) < 72;
    }
}
