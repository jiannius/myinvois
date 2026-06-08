<?php

namespace Jiannius\Myinvois\Tests\Unit;

use Jiannius\Myinvois\Helpers\UBL;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class UblSanitizeTest extends TestCase
{
    #[Test]
    public function it_trims_and_collapses_whitespace() : void
    {
        $this->assertSame('hello world', UBL::sanitize('  hello   world  '));
    }

    #[Test]
    public function it_strips_zero_width_characters() : void
    {
        $this->assertSame('zerowidth', UBL::sanitize("zero\u{200B}width"));
        $this->assertSame('bom', UBL::sanitize("\u{FEFF}bom"));
    }

    #[Test]
    public function it_strips_control_characters() : void
    {
        // tab (\x09) and newline (\x0A) are control chars and are removed
        $this->assertSame('abc', UBL::sanitize("a\tb\nc"));
        $this->assertSame('ab', UBL::sanitize("a\x00b"));
    }

    #[Test]
    public function it_normalises_unicode_spaces_to_a_regular_space() : void
    {
        // non-breaking space (\u{00A0}) is a \p{Z} separator -> single space
        $this->assertSame('a b', UBL::sanitize("a\u{00A0}b"));
    }

    #[Test]
    public function it_passes_through_falsy_values_untouched() : void
    {
        $this->assertNull(UBL::sanitize(null));
        $this->assertSame('', UBL::sanitize(''));
    }
}
