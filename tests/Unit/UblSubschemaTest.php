<?php

namespace Jiannius\Myinvois\Tests\Unit;

use Jiannius\Myinvois\Helpers\UBL;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class UblSubschemaTest extends TestCase
{
    // ---- TIN subschema -------------------------------------------------

    #[Test]
    public function tin_subschema_maps_tin_and_brn() : void
    {
        $out = UBL::getDocumentTINSubschema(['tin' => 'C123', 'brn' => 'B456']);

        $this->assertSame('C123', $out['PartyIdentification.0.ID.0._']);
        $this->assertSame('TIN', $out['PartyIdentification.0.ID.0.schemeID']);
        $this->assertSame('B456', $out['PartyIdentification.1.ID.0._']);
        $this->assertSame('BRN', $out['PartyIdentification.1.ID.0.schemeID']);
    }

    #[Test]
    public function nric_takes_precedence_over_brn() : void
    {
        $out = UBL::getDocumentTINSubschema(['tin' => 'T', 'nric' => 'N1', 'brn' => 'B1']);

        $this->assertSame('NRIC', $out['PartyIdentification.1.ID.0.schemeID']);
        $this->assertSame('N1', $out['PartyIdentification.1.ID.0._']);
    }

    #[Test]
    public function passport_and_army_are_supported() : void
    {
        $passport = UBL::getDocumentTINSubschema(['tin' => 'T', 'passport' => 'P1']);
        $this->assertSame('PASSPORT', $passport['PartyIdentification.1.ID.0.schemeID']);
        $this->assertSame('P1', $passport['PartyIdentification.1.ID.0._']);

        $army = UBL::getDocumentTINSubschema(['tin' => 'T', 'army' => 'A1']);
        $this->assertSame('ARMY', $army['PartyIdentification.1.ID.0.schemeID']);
        $this->assertSame('A1', $army['PartyIdentification.1.ID.0._']);
    }

    #[Test]
    public function missing_identifiers_fall_back_to_na() : void
    {
        $out = UBL::getDocumentTINSubschema([]);

        $this->assertSame('NA', $out['PartyIdentification.0.ID.0._']);  // TIN
        $this->assertSame('NA', $out['PartyIdentification.1.ID.0._']);  // BRN default
        $this->assertSame('NA', $out['PartyIdentification.2.ID.0._']);  // SST
        $this->assertSame('NA', $out['PartyIdentification.3.ID.0._']);  // TTX
        $this->assertSame('SST', $out['PartyIdentification.2.ID.0.schemeID']);
        $this->assertSame('TTX', $out['PartyIdentification.3.ID.0.schemeID']);
    }

    // ---- contact subschema ---------------------------------------------

    #[Test]
    public function contact_normalises_the_phone_with_a_leading_plus() : void
    {
        $out = UBL::getDocumentContactSubschema(['phone' => '+60-3-12345678', 'email' => 'a@b.com']);

        $this->assertSame('+60312345678', $out['Contact.0.Telephone.0._']);
        $this->assertSame('a@b.com', $out['Contact.0.ElectronicMail.0._']);
    }

    #[Test]
    public function contact_phone_defaults_to_na_and_blank_email_is_dropped() : void
    {
        $out = UBL::getDocumentContactSubschema([]);

        $this->assertSame('NA', $out['Contact.0.Telephone.0._']);
        $this->assertArrayNotHasKey('Contact.0.ElectronicMail.0._', $out);
    }

    // ---- address subschema ---------------------------------------------

    #[Test]
    public function address_maps_lines_state_code_and_country() : void
    {
        $out = UBL::getDocumentAddressSubschema([
            'address_line_1' => 'Lot 1',
            'city' => 'Kuala Lumpur',
            'postcode' => '50480',
            'state' => 'Kuala Lumpur',
            'country' => 'Malaysia',
        ]);

        $this->assertSame('Lot 1', $out['PostalAddress.0.AddressLine.0.Line.0._']);
        $this->assertSame('Kuala Lumpur', $out['PostalAddress.0.CityName.0._']);
        $this->assertSame('50480', $out['PostalAddress.0.PostalZone.0._']);
        $this->assertSame('14', $out['PostalAddress.0.CountrySubentityCode.0._']);
        $this->assertSame('MYS', $out['PostalAddress.0.Country.0.IdentificationCode.0._']);
        $this->assertSame('ISO3166-1', $out['PostalAddress.0.Country.0.IdentificationCode.0.listID']);
        $this->assertSame('6', $out['PostalAddress.0.Country.0.IdentificationCode.0.listAgencyID']);
    }

    #[Test]
    public function address_falls_back_to_na_line_and_not_applicable_state() : void
    {
        $out = UBL::getDocumentAddressSubschema([]);

        $this->assertSame('NA', $out['PostalAddress.0.AddressLine.0.Line.0._']);
        $this->assertSame('17', $out['PostalAddress.0.CountrySubentityCode.0._']); // Not Applicable
        $this->assertArrayNotHasKey('PostalAddress.0.AddressLine.1.Line.0._', $out); // blank dropped
        $this->assertArrayNotHasKey('PostalAddress.0.Country.0.IdentificationCode.0._', $out);
    }

    #[Test]
    public function address_keeps_an_unknown_state_string_as_is() : void
    {
        $out = UBL::getDocumentAddressSubschema(['state' => 'Atlantis']);

        $this->assertSame('Atlantis', $out['PostalAddress.0.CountrySubentityCode.0._']);
    }

    // ---- taxes subschema -----------------------------------------------

    #[Test]
    public function taxes_default_to_not_applicable_when_empty() : void
    {
        $out = UBL::getDocumentTaxesSubschema([], 'MYR');

        $this->assertSame(0, $out['TaxTotal.0.TaxAmount.0._']);
        $this->assertSame('06', $out['TaxTotal.0.TaxSubtotal.0.TaxCategory.0.ID.0._']); // Not Applicable
        $this->assertSame('OTH', $out['TaxTotal.0.TaxSubtotal.0.TaxCategory.0.TaxScheme.0.ID.0._']);
    }

    #[Test]
    public function taxes_sum_amounts_and_emit_percent_rate() : void
    {
        $out = UBL::getDocumentTaxesSubschema([
            ['code' => '01', 'name' => 'Sales Tax', 'amount' => 30, 'taxable_amount' => 470, 'rate' => 6],
        ], 'MYR');

        $this->assertSame(30, $out['TaxTotal.0.TaxAmount.0._']);
        $this->assertSame('MYR', $out['TaxTotal.0.TaxAmount.0.currencyID']);
        $this->assertSame('01', $out['TaxTotal.0.TaxSubtotal.0.TaxCategory.0.ID.0._']);
        $this->assertSame(470, $out['TaxTotal.0.TaxSubtotal.0.TaxableAmount.0._']);
        $this->assertSame(30, $out['TaxTotal.0.TaxSubtotal.0.TaxAmount.0._']);
        $this->assertSame(6, $out['TaxTotal.0.TaxSubtotal.0.Percent.0._']);
    }

    #[Test]
    public function taxes_emit_fixed_rate_unit_measures() : void
    {
        $out = UBL::getDocumentTaxesSubschema([[
            'code' => '03',
            'amount' => 10,
            'taxable_amount' => 0,
            'fixed_rate_base_unit_measure' => 1,
            'fixed_rate_base_unit_measure_code' => 'C62',
            'fixed_rate_per_unit_amount' => 10,
        ]], 'MYR');

        $this->assertSame(1, $out['TaxTotal.0.TaxSubtotal.0.BaseUnitMeasure.0._']);
        $this->assertSame('C62', $out['TaxTotal.0.TaxSubtotal.0.BaseUnitMeasure.0.unitCode']);
        $this->assertSame(10, $out['TaxTotal.0.TaxSubtotal.0.PerUnitAmount.0._']);
        $this->assertArrayNotHasKey('TaxTotal.0.TaxSubtotal.0.Percent.0._', $out);
    }

    #[Test]
    public function taxes_emit_an_exemption_reason() : void
    {
        $out = UBL::getDocumentTaxesSubschema([[
            'code' => 'E', 'amount' => 0, 'taxable_amount' => 100, 'exemption_reason' => 'Exempt goods',
        ]], 'MYR');

        $this->assertSame('Exempt goods', $out['TaxTotal.0.TaxSubtotal.0.TaxCategory.0.TaxExemptionReason.0._']);
    }
}
