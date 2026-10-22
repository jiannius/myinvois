<?php

namespace Jiannius\Myinvois\Helpers;

use InvalidArgumentException;
use SimpleXMLElement;

/**
 * XML string -> array, in the shape UBL::restoreXml() reads.
 *
 * A port of the one code path this package used from noki/laravel-xml-converter
 * (Convert::xmlToArray() with its defaults: namespace prefixes stripped, CDATA merged
 * as text, no schema validation). That package was dropped because its auto-discovered
 * service provider called Storage::exists() on the host app's default disk on every
 * boot. Keep the output identical: tests/Unit/XmlTest.php pins it.
 */
class Xml
{
    public static function toArray(string $xml) : array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $element = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA);

            if ($element === false) {
                $error = libxml_get_last_error();
                throw new InvalidArgumentException('Invalid XML: '.($error ? $error->message : 'Unknown error'));
            }
        }
        finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $result = self::collapse(self::convert($element));

        return empty($result) ? [] : [$element->getName() => $result];
    }

    /**
     * Children keyed by local name (repeated names become a list), attributes under
     * '@attributes', text under 'value' when the element also has children/attributes,
     * a bare cast scalar otherwise.
     */
    protected static function convert(SimpleXMLElement $xml) : mixed
    {
        $result = [];
        $namespaces = $xml->getNamespaces(true);

        // With namespaces only namespaced children are walked, as in the original.
        $groups = $namespaces ? array_map(fn ($uri) => $xml->children($uri), $namespaces) : [$xml->children()];

        foreach ($groups as $children) {
            foreach ($children as $child) {
                $name = $child->getName();
                $entry = self::convert($child);

                if (!isset($result[$name])) $result[$name] = [];

                if (is_array($entry)) $result[$name][] = $entry;
                else $result[$name] = $entry;
            }
        }

        foreach ($xml->attributes() as $name => $value) {
            $result['@attributes'][$name] = (string) $value;
        }

        $text = trim((string) $xml);

        if (empty($result)) return self::cast($text);
        if ($text !== '') $result['value'] = self::cast($text);

        return $result;
    }

    /**
     * Unwrap single-item lists recursively.
     */
    protected static function collapse(mixed $data) : mixed
    {
        if (!is_array($data)) return $data;
        if (count($data) === 1 && array_key_exists(0, $data)) return self::collapse($data[0]);

        foreach ($data as $key => $value) {
            $data[$key] = self::collapse($value);
        }

        return $data;
    }

    /**
     * '' -> null, true/false -> bool, numeric -> int/float (leading-zero codes stay strings).
     */
    protected static function cast(string $value) : mixed
    {
        if ($value === '') return null;

        $lower = strtolower($value);

        if ($lower === 'true') return true;
        if ($lower === 'false') return false;

        if (is_numeric($value)) {
            if (preg_match('/^0[0-9]+$/', $value)) return $value;

            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return $value;
    }
}
