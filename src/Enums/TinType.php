<?php

namespace Jiannius\Myinvois\Enums;

enum TinType : string
{
    case GENERAL_PUBLIC = 'EI00000000010';
    case FOREIGN_BUYER = 'EI00000000020';
    case FOREIGN_SUPPLIER = 'EI00000000030';
    case GOVERNMENT = 'EI00000000040';

    public function label()
    {
        return str($this->name)->slug()->headline()->toString();
    }
}