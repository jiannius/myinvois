<?php

namespace Jiannius\Myinvois\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MyinvoisDocument extends Model
{
    use HasFactory;
    use HasUlids;

    protected $casts = [
        'request' => 'array',
        'response' => 'array',
        'polled_at' => 'datetime',
    ];
}
