<?php

namespace Jiannius\Myinvois\Tests\Unit;

use Jiannius\Myinvois\Helpers\UBL;
use Jiannius\Myinvois\Tests\Fixtures\DocumentFixture;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class UblBuildTest extends TestCase
{
    protected array $ubl;

    protected function setUp() : void
    {
        parent::setUp();
        $this->ubl = UBL::build(DocumentFixture::invoice());
    }

    protected function node(string $path) : mixed
    {
        return data_get($this->ubl, $path);
    }

    #[Test]
    public function it_sets_the_ubl_namespaces() : void
    {
        $this->assertSame('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', $this->node('_D'));
        $this->assertSame('urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2', $this->node('_A'));
        $this->assertSame('urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2', $this->node('_B'));
    }

    #[Test]
    public function it_sets_essential_header_fields() : void
    {
        $this->assertSame('INV-0001', $this->node('Invoice.0.ID.0._'));
        $this->assertSame('2026-01-15', $this->node('Invoice.0.IssueDate.0._'));
        $this->assertStringStartsWith('09:30:00', $this->node('Invoice.0.IssueTime.0._'));
        $this->assertSame('01', $this->node('Invoice.0.InvoiceTypeCode.0._'));
        $this->assertSame('1.1', $this->node('Invoice.0.InvoiceTypeCode.0.listVersionID'));
    }

    #[Test]
    public function it_sets_currency_and_exchange_rate() : void
    {
        $this->assertSame('MYR', $this->node('Invoice.0.DocumentCurrencyCode.0._'));
        $this->assertSame('MYR', $this->node('Invoice.0.TaxCurrencyCode.0._'));
        $this->assertSame(4.5, $this->node('Invoice.0.TaxExchangeRate.0.CalculationRate.0._'));
        $this->assertSame('MYR', $this->node('Invoice.0.TaxExchangeRate.0.SourceCurrencyCode.0._'));
        $this->assertSame('MYR', $this->node('Invoice.0.TaxExchangeRate.0.TargetCurrencyCode.0._'));
    }

    #[Test]
    public function it_builds_the_supplier_party() : void
    {
        $p = 'Invoice.0.AccountingSupplierParty.0.Party.0.';
        $this->assertSame('Supplier Sdn Bhd', $this->node($p.'PartyLegalEntity.0.RegistrationName.0._'));
        $this->assertSame('C26561325060', $this->node($p.'PartyIdentification.0.ID.0._'));
        $this->assertSame('TIN', $this->node($p.'PartyIdentification.0.ID.0.schemeID'));
        $this->assertSame('202101001341', $this->node($p.'PartyIdentification.1.ID.0._'));
        $this->assertSame('BRN', $this->node($p.'PartyIdentification.1.ID.0.schemeID'));
        $this->assertSame('Lot 1', $this->node($p.'PostalAddress.0.AddressLine.0.Line.0._'));
        $this->assertSame('14', $this->node($p.'PostalAddress.0.CountrySubentityCode.0._'));
        $this->assertSame('MYS', $this->node($p.'PostalAddress.0.Country.0.IdentificationCode.0._'));
        $this->assertSame('46510', $this->node($p.'IndustryClassificationCode.0._'));
        $this->assertSame('Wholesale of computer hardware', $this->node($p.'IndustryClassificationCode.0.name'));
    }

    #[Test]
    public function it_sets_supplier_bank_account_and_certified_exporter() : void
    {
        $this->assertSame('1234567890', $this->node('Invoice.0.PaymentMeans.0.PayeeFinancialAccount.0.ID.0._'));
        $this->assertSame('CPT-CCN-W-211111-KL-000002', $this->node('Invoice.0.AccountingSupplierParty.0.AdditionalAccountID.0._'));
        $this->assertSame('CertEX', $this->node('Invoice.0.AccountingSupplierParty.0.AdditionalAccountID.0.schemeAgencyName'));
    }

    #[Test]
    public function it_builds_the_buyer_party_with_nric_precedence() : void
    {
        $p = 'Invoice.0.AccountingCustomerParty.0.Party.0.';
        $this->assertSame('Buyer Bhd', $this->node($p.'PartyLegalEntity.0.RegistrationName.0._'));
        $this->assertSame('C99999999090', $this->node($p.'PartyIdentification.0.ID.0._'));
        $this->assertSame('900101145678', $this->node($p.'PartyIdentification.1.ID.0._'));
        $this->assertSame('NRIC', $this->node($p.'PartyIdentification.1.ID.0.schemeID'));
    }

    #[Test]
    public function it_sets_payment_mode_and_terms() : void
    {
        $this->assertSame('03', $this->node('Invoice.0.PaymentMeans.0.PaymentMeansCode.0._'));
        $this->assertSame('Net 30', $this->node('Invoice.0.PaymentTerms.0.Note.0._'));
    }

    #[Test]
    public function it_sets_the_billing_period() : void
    {
        $this->assertSame('2026-01-01', $this->node('Invoice.0.InvoicePeriod.0.StartDate.0._'));
        $this->assertSame('2026-01-31', $this->node('Invoice.0.InvoicePeriod.0.EndDate.0._'));
        $this->assertSame('Monthly', $this->node('Invoice.0.InvoicePeriod.0.Description.0._'));
        $this->assertSame('BILL-REF-1', $this->node('Invoice.0.BillingReference.0.AdditionalDocumentReference.0.ID.0._'));
    }

    #[Test]
    public function it_maps_the_three_reference_types() : void
    {
        $r = 'Invoice.0.AdditionalDocumentReference.';
        // CUSTOMS: slashes become commas
        $this->assertSame('CustomsImportForm', $this->node($r.'0.DocumentType.0._'));
        $this->assertSame('K1,2026,123', $this->node($r.'0.ID.0._'));
        // INCOTERMS: raw value as ID
        $this->assertSame('CIF', $this->node($r.'1.ID.0._'));
        // FTA: fixed ID + description
        $this->assertSame('FreeTradeAgreement', $this->node($r.'2.DocumentType.0._'));
        $this->assertSame('FTA', $this->node($r.'2.ID.0._'));
        $this->assertSame('AANZFTA', $this->node($r.'2.DocumentDescription.0._'));
    }

    #[Test]
    public function it_sets_prepaid_payment() : void
    {
        $this->assertSame('PRE-1', $this->node('Invoice.0.PrepaidPayment.0.ID.0._'));
        $this->assertSame(50, $this->node('Invoice.0.PrepaidPayment.0.PaidAmount.0._'));
        $this->assertSame('2026-01-10', $this->node('Invoice.0.PrepaidPayment.0.PaidDate.0._'));
    }

    #[Test]
    public function it_maps_charges_then_discounts_with_charge_indicators() : void
    {
        $a = 'Invoice.0.AllowanceCharge.';
        $this->assertTrue($this->node($a.'0.ChargeIndicator.0._'));   // charge
        $this->assertSame(20, $this->node($a.'0.Amount.0._'));
        $this->assertSame('Service Charge', $this->node($a.'0.AllowanceChargeReason.0._'));
        $this->assertFalse($this->node($a.'1.ChargeIndicator.0._'));  // discount
        $this->assertSame(10, $this->node($a.'1.Amount.0._'));
        $this->assertSame('Festival Discount', $this->node($a.'1.AllowanceChargeReason.0._'));
    }

    #[Test]
    public function it_sets_document_level_totals() : void
    {
        $this->assertSame(30, $this->node('Invoice.0.TaxTotal.0.TaxAmount.0._'));
        $this->assertSame('01', $this->node('Invoice.0.TaxTotal.0.TaxSubtotal.0.TaxCategory.0.ID.0._'));
        $this->assertSame(500, $this->node('Invoice.0.LegalMonetaryTotal.0.TaxExclusiveAmount.0._'));
        $this->assertSame(530, $this->node('Invoice.0.LegalMonetaryTotal.0.TaxInclusiveAmount.0._'));
        $this->assertSame(530, $this->node('Invoice.0.LegalMonetaryTotal.0.PayableAmount.0._'));
    }

    #[Test]
    public function payable_total_falls_back_to_grand_total() : void
    {
        $ubl = UBL::build(DocumentFixture::invoice(['payable_total' => null]));
        $this->assertSame(530, data_get($ubl, 'Invoice.0.LegalMonetaryTotal.0.PayableAmount.0._'));
    }

    #[Test]
    public function it_builds_the_line_item() : void
    {
        $l = 'Invoice.0.InvoiceLine.0.';
        $this->assertSame('001', $this->node($l.'ID.0._'));
        $this->assertSame(2, $this->node($l.'InvoicedQuantity.0._'));
        $this->assertSame('C62', $this->node($l.'InvoicedQuantity.0.unitCode'));
        $this->assertSame('Widget', $this->node($l.'Item.0.Description.0._'));
        $this->assertSame(250, $this->node($l.'Price.0.PriceAmount.0._'));
        $this->assertSame('MYR', $this->node($l.'Price.0.PriceAmount.0.currencyID'));
        $this->assertSame('MYS', $this->node($l.'Item.0.OriginCountry.0.IdentificationCode.0._'));
        $this->assertSame(500, $this->node($l.'ItemPriceExtension.0.Amount.0._'));
        // LineExtensionAmount = subtotal - line discount = 500 - 30
        $this->assertSame(470, $this->node($l.'LineExtensionAmount.0._'));
    }

    #[Test]
    public function it_maps_line_classifications_and_tariffs() : void
    {
        $c = 'Invoice.0.InvoiceLine.0.Item.0.CommodityClassification.';
        // classification 022 -> resolved code, listID CLASS
        $this->assertSame('022', $this->node($c.'0.ItemClassificationCode.0._'));
        $this->assertSame('CLASS', $this->node($c.'0.ItemClassificationCode.0.listID'));
        // tariff -> listID PTC (code looked up in classifications map, hence null here)
        $this->assertSame('PTC', $this->node($c.'1.ItemClassificationCode.0.listID'));
    }

    #[Test]
    public function it_maps_line_taxes() : void
    {
        $t = 'Invoice.0.InvoiceLine.0.TaxTotal.0.TaxSubtotal.0.';
        $this->assertSame('01', $this->node($t.'TaxCategory.0.ID.0._'));
        $this->assertSame(6, $this->node($t.'Percent.0._'));
        $this->assertSame(30, $this->node($t.'TaxAmount.0._'));
        $this->assertSame(470, $this->node($t.'TaxableAmount.0._'));
    }

    #[Test]
    public function it_maps_the_line_level_discount() : void
    {
        $a = 'Invoice.0.InvoiceLine.0.AllowanceCharge.0.';
        $this->assertFalse($this->node($a.'ChargeIndicator.0._'));
        $this->assertSame(30, $this->node($a.'Amount.0._'));
        $this->assertSame('Line discount', $this->node($a.'AllowanceChargeReason.0._'));
        $this->assertSame(0.06, $this->node($a.'MultiplierFactorNumeric.0._'));
    }

    #[Test]
    public function it_builds_the_shipping_delivery_party_and_freight() : void
    {
        $ubl = UBL::build(DocumentFixture::invoice(['shipping' => [
            'name' => 'Recipient Name',
            'tin' => 'C77777777090',
            'address_line_1' => 'Jalan Ship 1',
            'city' => 'Klang',
            'state' => 'Selangor',
            'country' => 'Malaysia',
            'amount' => 25,
            'description' => 'Lalamove',
            'reference' => 'SHIP-1',
        ]]));

        $d = 'Invoice.0.Delivery.0.';
        $this->assertSame('Recipient Name', data_get($ubl, $d.'DeliveryParty.0.PartyLegalEntity.0.RegistrationName.0._'));
        $this->assertSame('C77777777090', data_get($ubl, $d.'DeliveryParty.0.PartyIdentification.0.ID.0._'));
        $this->assertSame('Jalan Ship 1', data_get($ubl, $d.'DeliveryParty.0.PostalAddress.0.AddressLine.0.Line.0._'));
        $this->assertSame('SHIP-1', data_get($ubl, $d.'Shipment.0.ID.0._'));
        $this->assertTrue(data_get($ubl, $d.'Shipment.0.FreightAllowanceCharge.0.ChargeIndicator.0._'));
        $this->assertSame(25, data_get($ubl, $d.'Shipment.0.FreightAllowanceCharge.0.Amount.0._'));
        $this->assertSame('MYR', data_get($ubl, $d.'Shipment.0.FreightAllowanceCharge.0.Amount.0.currencyID'));
        $this->assertSame('Lalamove', data_get($ubl, $d.'Shipment.0.FreightAllowanceCharge.0.AllowanceChargeReason.0._'));
    }

    #[Test]
    public function it_omits_the_exchange_rate_when_no_rate_is_given() : void
    {
        $ubl = UBL::build(DocumentFixture::invoice(['currency_rate' => null]));

        $this->assertNull(data_get($ubl, 'Invoice.0.TaxExchangeRate.0.CalculationRate.0._'));
    }

    // ---- consolidated variants ----------------------------------------

    #[Test]
    public function consolidated_flag_emits_004_class_on_every_line() : void
    {
        $ubl = UBL::build(DocumentFixture::consolidated());

        foreach ([0, 1] as $i) {
            $c = "Invoice.0.InvoiceLine.$i.Item.0.CommodityClassification.";
            $this->assertSame('004', data_get($ubl, $c.'0.ItemClassificationCode.0._'));
            $this->assertSame('CLASS', data_get($ubl, $c.'0.ItemClassificationCode.0.listID'));
            // no second (tariff) classification on consolidated lines
            $this->assertNull(data_get($ubl, $c.'1.ItemClassificationCode.0._'));
        }
    }

    #[Test]
    public function legacy_004_contract_is_treated_as_consolidated() : void
    {
        $ubl = UBL::build(DocumentFixture::legacy004());
        $c = 'Invoice.0.InvoiceLine.0.Item.0.CommodityClassification.';

        $this->assertSame('004', data_get($ubl, $c.'0.ItemClassificationCode.0._'));
        $this->assertSame('CLASS', data_get($ubl, $c.'0.ItemClassificationCode.0.listID'));
    }

    // ---- guards --------------------------------------------------------

    #[Test]
    public function it_throws_when_supplier_is_missing() : void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Missing supplier');

        UBL::build(DocumentFixture::invoice(['supplier' => null]));
    }

    #[Test]
    public function it_throws_when_buyer_is_missing() : void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Missing buyer');

        $doc = DocumentFixture::invoice();
        $doc['buyer'] = null;

        UBL::build($doc);
    }
}
