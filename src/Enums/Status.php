<?php

namespace Jiannius\Myinvois\Enums;

enum Status : string
{
    case SUBMITTED = 'submitted';
    case VALID = 'valid';
    case INVALID = 'invalid';
    case CANCELLED = 'cancelled';

    public function color()
    {
        return match ($this) {
            static::SUBMITTED => 'blue',
            static::VALID => 'green',
            static::INVALID => 'red',
            static::CANCELLED => 'gray',
        };
    }

    public function label()
    {
        return str()->title($this->value);
    }
}