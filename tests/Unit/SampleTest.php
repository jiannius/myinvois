<?php

namespace Jiannius\Myinvois\Tests\Unit;

use Jiannius\Myinvois\Helpers\Sample;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SampleTest extends TestCase
{
    #[Test]
    public function it_builds_a_normalized_sample_document() : void
    {
        $doc = Sample::build();

        $this->assertStringStartsWith('INV', $doc['number']);
        $this->assertSame('01', $doc['document_type']);   // Invoice
        $this->assertSame('1.1', $doc['document_version']);
        $this->assertSame('MYR', $doc['currency']);
        $this->assertSame('C26561325060', $doc['supplier']['tin']);
        $this->assertSame('EI00000000020', $doc['buyer']['tin']); // foreign buyer
        $this->assertCount(1, $doc['line_items']);
    }
}
