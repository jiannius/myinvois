<?php

namespace Jiannius\Myinvois\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class MigrationTest extends TestCase
{
    #[Test]
    public function it_creates_the_myinvois_documents_table() : void
    {
        $this->assertTrue(Schema::hasTable('myinvois_documents'));
    }

    #[Test]
    public function the_table_has_the_expected_columns() : void
    {
        $this->assertTrue(Schema::hasColumns('myinvois_documents', [
            'id',
            'document_uuid',
            'submission_uid',
            'document_number',
            'consolidate_number',
            'status',
            'request',
            'response',
            'is_preprod',
            'parent_type',
            'parent_id',
            'created_at',
            'updated_at',
        ]));
    }
}
