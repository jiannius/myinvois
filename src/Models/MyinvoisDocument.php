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

    public function getPublicUrlAttribute() : string
    {
        $longid = data_get($this->response, 'longId');

        if (!$longid) return '';

        $baseurl = $this->is_preprod ? 'https://preprod.myinvois.hasil.gov.my' : 'https://myinvois.hasil.gov.my';

        return "{$baseurl}/{$this->document_uuid}/share/{$longid}";
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
}
