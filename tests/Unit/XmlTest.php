<?php

namespace Jiannius\Myinvois\Tests\Unit;

use InvalidArgumentException;
use Jiannius\Myinvois\Helpers\Xml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class XmlTest extends TestCase
{
    public static function fixtures() : array
    {
        return collect(glob(__DIR__.'/../Fixtures/Xml/*.xml'))
            ->mapWithKeys(fn ($path) => [basename($path) => [$path]])
            ->all();
    }

    // The .php beside each fixture is noki/laravel-xml-converter's output, captured before
    // the package was dropped. assertSame is strict, so int/float/bool/null casts must match too.
    #[Test]
    #[DataProvider('fixtures')]
    public function it_matches_the_former_noki_output(string $path) : void
    {
        $expected = require preg_replace('/\.xml$/', '.php', $path);

        $this->assertSame($expected, Xml::toArray(file_get_contents($path)));
    }

    #[Test]
    public function it_throws_on_invalid_xml_and_restores_libxml_error_handling() : void
    {
        libxml_use_internal_errors(false);

        try {
            Xml::toArray('<Invoice><ID>1</Invoice>');
            $this->fail('Expected InvalidArgumentException');
        }
        catch (InvalidArgumentException $e) {
            $this->assertStringStartsWith('Invalid XML: ', $e->getMessage());
        }

        $this->assertFalse(libxml_use_internal_errors());
    }

    #[Test]
    public function it_returns_an_empty_array_for_an_empty_root() : void
    {
        $this->assertSame([], Xml::toArray('<Invoice/>'));
    }
}
