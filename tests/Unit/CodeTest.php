<?php

namespace Jiannius\Myinvois\Tests\Unit;

use Jiannius\Myinvois\Helpers\Code;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CodeTest extends TestCase
{
    #[Test]
    public function it_resolves_country_value_and_label() : void
    {
        $this->assertSame('MYS', Code::countries()->value('Malaysia'));
        $this->assertSame('MALAYSIA', Code::countries()->label('MYS'));
    }

    #[Test]
    public function it_uppercases_the_country_needle() : void
    {
        // countries.json stores labels in upper-case; get() upper-cases the needle
        $this->assertSame('MYS', Code::countries()->value('malaysia'));
        $this->assertSame('MYS', Code::countries()->value('MYS'));
    }

    #[Test]
    public function it_auto_prefixes_wilayah_persekutuan_states() : void
    {
        $this->assertSame('14', Code::states()->value('Kuala Lumpur'));
        $this->assertSame('15', Code::states()->value('Labuan'));
        $this->assertSame('16', Code::states()->value('Putrajaya'));
    }

    #[Test]
    public function it_resolves_regular_states_without_prefixing() : void
    {
        $this->assertSame('01', Code::states()->value('Johor'));
        $this->assertSame('10', Code::states()->value('Selangor'));
    }

    #[Test]
    public function it_resolves_state_by_code() : void
    {
        $this->assertSame('Johor', Code::states()->label('01'));
    }

    #[Test]
    public function it_returns_null_for_unknown_needle() : void
    {
        $this->assertNull(Code::countries()->value('Atlantis'));
        $this->assertNull(Code::countries()->get(null));
        $this->assertNull(Code::states()->value(null));
    }

    #[Test]
    public function it_uses_version_as_the_value_key_for_document_versions() : void
    {
        // document-versions.json keys on "Version" not "Code"
        $this->assertSame('1.1', Code::documentVersions()->value('Invoice'));
    }

    #[Test]
    public function it_resolves_document_type_codes() : void
    {
        $this->assertSame('01', Code::documentTypes()->value('Invoice'));
        $this->assertSame('Credit Note', Code::documentTypes()->label('02'));
    }

    #[Test]
    public function it_resolves_tax_and_payment_and_classification_codes() : void
    {
        $this->assertSame('01', Code::taxes()->value('Sales Tax'));
        $this->assertSame('06', Code::taxes()->value('Not Applicable'));
        $this->assertSame('03', Code::paymentModes()->value('Bank Transfer'));
        $this->assertSame('022', Code::classifications()->value('Others'));
        $this->assertSame('004', Code::classifications()->value('004'));
    }

    #[Test]
    public function the_camelcased_method_maps_to_the_kebab_json_file() : void
    {
        // documentTypes -> document-types.json, paymentModes -> payment-modes.json
        $this->assertNotEmpty(Code::documentTypes()->all());
        $this->assertNotEmpty(Code::paymentModes()->all());
        $this->assertNotEmpty(Code::documentVersions()->all());
    }

    #[Test]
    public function all_returns_the_raw_code_collection() : void
    {
        $all = Code::countries()->all();

        $this->assertCount(251, $all);
    }
}
