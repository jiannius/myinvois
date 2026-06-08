<?php

namespace Jiannius\Myinvois\Tests\Unit;

use Jiannius\Myinvois\Helpers\UBL;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class UblConsolidatedTest extends TestCase
{
    #[Test]
    public function the_flag_marks_a_document_consolidated() : void
    {
        $this->assertTrue(UBL::isConsolidated(['is_consolidate' => true]));
        $this->assertTrue(UBL::isConsolidated(['is_consolidate' => true, 'line_items' => []]));
    }

    #[Test]
    public function the_legacy_004_classification_on_every_line_is_consolidated() : void
    {
        $doc = ['line_items' => [
            ['classifications' => [['code' => '004']]],
            ['classifications' => [['code' => '004']]],
        ]];

        $this->assertTrue(UBL::isConsolidated($doc));
    }

    #[Test]
    public function a_mixed_batch_of_classifications_is_not_consolidated() : void
    {
        $doc = ['line_items' => [
            ['classifications' => [['code' => '004']]],
            ['classifications' => [['code' => '022']]],
        ]];

        $this->assertFalse(UBL::isConsolidated($doc));
    }

    #[Test]
    public function a_normal_classification_is_not_consolidated() : void
    {
        $doc = ['line_items' => [['classifications' => [['code' => '022']]]]];

        $this->assertFalse(UBL::isConsolidated($doc));
    }

    #[Test]
    public function an_empty_document_is_not_consolidated() : void
    {
        $this->assertFalse(UBL::isConsolidated([]));
        $this->assertFalse(UBL::isConsolidated(['line_items' => []]));
    }
}
