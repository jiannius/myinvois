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

    public function code()
    {
        return match ($this) {
            static::SUBMITTED => 1,
            static::VALID => 2,
            static::INVALID => 3,
            static::CANCELLED => 4,
        };
    }

    public function is(...$value) : bool
    {
        return collect($value)->some(fn ($val) => $this->value === $val || $this->name === $val || $this === $val);
    }

    public function isNot(...$value) : bool
    {
        return !$this->is(...$value);
    }
}