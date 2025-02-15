<?php

namespace Jiannius\Myinvois\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Jiannius\Myinvois\Enums\Status;

class MyinvoisDocument extends Model
{
    use HasFactory;
    use HasUlids;

    protected $guarded = [];

    protected $casts = [
        'request' => 'array',
        'response' => 'array',
        'polled_at' => 'datetime',
        'status' => Status::class,
        'is_preprod' => 'boolean',
    ];

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

    public function isSubmittable() : bool
    {
        return in_array($this->status, [Status::INVALID, Status::CANCELLED]);
    }

    public function isSubmitted() : bool
    {
        return $this->status === Status::SUBMITTED;
    }

    public function isValid() : bool
    {
        return $this->status === Status::VALID;
    }

    public function isInvalid() : bool
    {
        return $this->status === Status::INVALID;
    }

    public function isCancelled() : bool
    {
        return $this->status === Status::CANCELLED;
    }

    public function isCancellable() : bool
    {
        return $this->status === Status::VALID && $this->created_at->diffInHours(now()) < 72;
    }
}
