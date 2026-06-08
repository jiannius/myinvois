<?php

namespace Jiannius\Myinvois\Tests\Unit;

use Jiannius\Myinvois\Helpers\Validator;
use Jiannius\Myinvois\Tests\Fixtures\DocumentFixture;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ValidatorTest extends TestCase
{
    protected function validate(array $doc) : \Illuminate\Validation\Validator
    {
        return (new Validator)->build($doc);
    }

    #[Test]
    public function a_well_formed_invoice_passes() : void
    {
        $v = $this->validate(DocumentFixture::invoice());

        $this->assertTrue($v->passes(), $v->errors()->first());
    }

    #[Test]
    public function it_reports_a_friendly_message_for_a_missing_number() : void
    {
        $v = $this->validate(DocumentFixture::invoice(['number' => null]));

        $this->assertTrue($v->fails());
        $this->assertSame('Document number is required', $v->errors()->first('number'));
    }

    #[Test]
    public function a_credit_note_requires_the_original_document() : void
    {
        $v = $this->validate(DocumentFixture::invoice(['document_type' => '02'])); // Credit Note

        $this->assertTrue($v->fails());
        $this->assertSame(
            'Credit Note / Debit Note must have original document UUID',
            $v->errors()->first('original_number'),
        );
    }

    #[Test]
    public function a_credit_note_with_the_original_document_passes() : void
    {
        $v = $this->validate(DocumentFixture::invoice([
            'document_type' => '02',
            'original_number' => 'INV-ORIG',
            'original_document_uuid' => 'UUID-123',
        ]));

        $this->assertTrue($v->passes(), $v->errors()->first());
    }

    #[Test]
    public function a_special_supplier_tin_skips_the_detailed_supplier_rules() : void
    {
        $doc = DocumentFixture::invoice();
        $doc['supplier']['tin'] = 'EI00000000030'; // foreign supplier
        unset($doc['supplier']['msic_code'], $doc['supplier']['address_line_1']);

        $v = $this->validate($doc);

        $this->assertFalse($v->errors()->has('supplier.msic_code'));
        $this->assertFalse($v->errors()->has('supplier.address_line_1'));
    }

    #[Test]
    public function general_public_tin_cannot_be_used_on_a_standard_document() : void
    {
        $doc = DocumentFixture::invoice();
        $doc['buyer']['tin'] = 'EI00000000010'; // general public

        $v = $this->validate($doc);

        $this->assertTrue($v->fails());
        $this->assertSame(
            'Document with General TIN cannot be submitted as standard document',
            $v->errors()->first('buyer.tin'),
        );
    }

    #[Test]
    public function general_public_tin_is_allowed_on_a_consolidated_document() : void
    {
        $doc = DocumentFixture::invoice(['is_consolidate' => true]);
        $doc['buyer']['tin'] = 'EI00000000010';

        $v = $this->validate($doc);

        $this->assertTrue($v->passes(), $v->errors()->first());
    }

    #[Test]
    public function a_consolidated_document_with_004_on_every_line_passes() : void
    {
        // classifications are mandatory on every line; for consolidated it is 004
        $v = $this->validate(DocumentFixture::consolidated());

        $this->assertTrue($v->passes(), $v->errors()->first());
    }

    #[Test]
    public function an_invalid_country_is_rejected_by_the_closure_rule() : void
    {
        $v = $this->validate(DocumentFixture::invoice(['supplier' => ['country' => 'Atlantis']]));

        $this->assertTrue($v->fails());
        $this->assertContains('Invalid supplier country', $v->errors()->get('supplier.country'));
    }

    #[Test]
    public function an_invalid_state_is_rejected_by_the_closure_rule() : void
    {
        $v = $this->validate(DocumentFixture::invoice(['buyer' => ['state' => 'Nowhere']]));

        $this->assertTrue($v->fails());
        $this->assertContains('Invalid buyer state', $v->errors()->get('buyer.state'));
    }
}
