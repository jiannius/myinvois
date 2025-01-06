<?php

namespace Jiannius\Myinvois\Helpers;

class Code
{
    public static function getJson($name)
    {
        $json = file_get_contents(__DIR__.'/../../json/codes/'.$name.'.json');

        return json_decode($json, true);
    }

    public static function documentTypes($name = null)
    {
        $types = collect(self::getJson('document-types'));

        return $name
            ? data_get($types->firstWhere('Description', $name), 'Code') ?? $name
            : $types;
    }

    public static function documentVersions($name = null)
    {
        $versions = collect(self::getJson('document-versions'));

        return $name
            ? data_get($versions->firstWhere('Description', $name), 'Version') ?? $name
            : $versions;
    }

    public static function currencies($name = null)
    {
        $currencies = collect(self::getJson('currencies'));

        return $name
            ? data_get($currencies->firstWhere('Currency', $name), 'Code') ?? $name
            : $currencies;
    }

    public static function paymentModes($name = null)
    {
        $paymentModes = collect(self::getJson('payment-modes'));

        return $name
            ? data_get($paymentModes->firstWhere('Payment Method', $name), 'Code') ?? $name
            : $paymentModes;
    }

    public static function states($name = null)
    {
        $states = collect(self::getJson('states'));

        return $name
            ? data_get($states->firstWhere('State', $name), 'Code') ?? $name
            : $states;
    }

    public static function countries($name = null)
    {
        $countries = collect(self::getJson('countries'));

        return $name
            ? data_get($countries->firstWhere('Country', strtoupper($name)), 'Code') ?? $name
            : $countries;
    }

    public static function taxes($name = null)
    {
        $taxes = collect(self::getJson('taxes'));

        return $name
            ? data_get($taxes->firstWhere('Description', $name), 'Code') ?? $name
            : $taxes;
    }

    public static function units($name = null)
    {
        $units = collect(self::getJson('units'));

        return $name
            ? data_get($units->firstWhere('Name', $name), 'Code') ?? $name
            : $units;
    }

    public static function classifications($name = null)
    {
        $classifications = collect(self::getJson('classifications'));

        return $name
            ? data_get($classifications->firstWhere('Description', $name), 'Code') ?? $name
            : $classifications;
    }

    public static function msic($name = null)
    {
        $msic = collect(self::getJson('msic'));

        return $name
            ? data_get($msic->firstWhere('Description', $name), 'Code') ?? $name
            : $msic;
    }
}