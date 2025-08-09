<?php

namespace Jiannius\Myinvois\Helpers;

class Code
{
    public static function __callStatic($name, $arguments)
    {
        $codeType = (string) str($name)->snake()->slug();
        $codes = collect(self::getJson($codeType));

        // create a collection with standardized value label pair
        $collection = $codes->map(fn ($item) => [
            'value' => $item[self::getValueKey($codeType)],
            'label' => $item[self::getLabelKey($codeType)],
        ]);

        return new class ($codeType, $codes, $collection) {
            public $codeType;
            public $codes;
            public $collection;

            public function __construct($codeType, $codes, $collection)
            {
                $this->codeType = $codeType;
                $this->codes = $codes;
                $this->collection = $collection;
            }

            public function all()
            {
                return $this->codes;
            }

            public function get($needle)
            {
                if ($this->codeType === 'countries') {
                    $needle = strtoupper($needle);
                }
                else if ($this->codeType === 'states' && in_array(strtoupper($needle), ['KUALA LUMPUR', 'LABUAN', 'PUTRAJAYA'])) {
                    $needle = str()->headline('WILAYAH PERSEKUTUAN '.$needle);
                }

                return $this->collection->first(fn ($item) => $item['value'] === $needle)
                    ?? $this->collection->first(fn ($item) => $item['label'] === $needle);
            }

            public function value($needle)
            {
                return data_get($this->get($needle), 'value');
            }

            public function label($needle)
            {
                return data_get($this->get($needle), 'label');
            }
        };
    }

    public static function getLabelKey($codeType)
    {
        return match ($codeType) {
            'document-types', 'document-versions', 'taxes', 'classifications', 'msic' => 'Description',
            'currencies' => 'Currency',
            'payment-modes' => 'Payment Method',
            'states' => 'State',
            'countries' => 'Country',
            'units' => 'Name',
        };
    }

    public static function getValueKey($codeType)
    {
        return match ($codeType) {
           'document-versions' => 'Version',
            default => 'Code',
    };
    }

    public static function getJson($name)
    {
        $json = file_get_contents(__DIR__.'/../../json/codes/'.$name.'.json');

        return json_decode($json, true);
    }
}