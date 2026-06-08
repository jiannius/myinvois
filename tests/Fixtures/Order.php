<?php

namespace Jiannius\Myinvois\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Jiannius\Myinvois\Models\Traits\HasMyinvoisDocument;

class Order extends Model
{
    use HasMyinvoisDocument;
    use HasUlids;

    protected $table = 'orders';

    protected $guarded = [];
}
