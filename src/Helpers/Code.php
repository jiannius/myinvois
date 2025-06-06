<?php

namespace Jiannius\Myinvois\Helpers;

class Code
{
    public static function __callStatic($name, $arguments)
    {
        $codeType = (string) str($name)->snake()->slug();
        $needle = head($arguments);
        $codes = collect(self::getJson($codeType));

        if ($needle) {
            $labelKey = match ($codeType) {
                'document-types', 'document-versions', 'taxes', 'classifications', 'msic' => 'Description',
                'currencies' => 'Currency',
                'payment-modes' => 'Payment Method',
                'states' => 'State',
                'countries' => 'Country',
                'units' => 'Name',
            };

            $valueKey = match ($codeType) {
                'document-versions' => 'Version',
                default => 'Code',
            };

            $needle = strtoupper($needle);
            $needle = $codeType === 'states' && in_array($needle, ['KUALA LUMPUR', 'PUTRAJAYA', 'LABUAN'])
                ? 'WILAYAH PERSEKUTUAN '.$needle
                : $needle;

            return data_get($codes->first(fn ($item) => strtoupper($item[$labelKey]) === $needle), $valueKey)
                ?? data_get($codes->first(fn ($item) => strtoupper($item[$valueKey]) === $needle), $labelKey);
        }
        else if ($arguments) {
            return;
        }

        return $codes;
    }

    public static function getJson($name)
    {
        $json = file_get_contents(__DIR__.'/../../json/codes/'.$name.'.json');

        return json_decode($json, true);
    }
}