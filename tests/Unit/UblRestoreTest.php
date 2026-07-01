<?php

namespace Jiannius\Myinvois\Tests\Unit;

use Jiannius\Myinvois\Helpers\UBL;
use Jiannius\Myinvois\Tests\Fixtures\DocumentFixture;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class UblRestoreTest extends TestCase
{
    #[Test]
    public function it_restores_a_built_json_document_back_to_the_flat_shape() : void
    {
        $built = UBL::build(DocumentFixture::invoice());
        $flat = UBL::restoreJson($built);

        $this->assertSame('INV-0001', $flat['number']);
        $this->assertSame('Invoice', $flat['document_type']);   // code -> label
        $this->assertSame('1.1', $flat['document_version']);
        $this->assertSame('MYR', $flat['currency']);
        $this->assertSame(4.5, $flat['currency_rate']);

        $this->assertSame('Supplier Sdn Bhd', $flat['supplier']['name']);
        $this->assertSame('C26561325060', $flat['supplier']['tin']);
        $this->assertSame('202101001341', $flat['supplier']['brn']);
        $this->assertSame('Buyer Bhd', $flat['buyer']['name']);

        $this->assertSame(500, $flat['subtotal']);
        $this->assertSame(530, $flat['grand_total']);
        $this->assertSame(530, $flat['payable_total']);

        $this->assertCount(1, $flat['line_items']);
        $this->assertSame('Widget', $flat['line_items'][0]['description']);
        $this->assertSame(2, $flat['line_items'][0]['qty']);
        $this->assertSame(250, $flat['line_items'][0]['unit_price']);

        $this->assertSame('01', $flat['taxes'][0]['code']);
        $this->assertSame('Sales Tax', $flat['taxes'][0]['name']);
        $this->assertSame('2026-01-01', $flat['billing']['start_at']);
    }

    #[Test]
    public function restore_dispatches_a_json_string_to_restore_json() : void
    {
        $json = json_encode(UBL::build(DocumentFixture::invoice()));
        $flat = UBL::restore($json);

        $this->assertSame('INV-0001', $flat['number']);
        $this->assertSame('MYR', $flat['currency']);
    }

    #[Test]
    public function restore_dispatches_an_xml_string_to_restore_xml() : void
    {
        $flat = UBL::restore($this->xmlFixture());

        $this->assertSame('INV-XML-1', $flat['number']);
        $this->assertSame('Invoice', $flat['document_type']);
        $this->assertSame('MYR', $flat['currency']);
        $this->assertSame('XML Supplier', $flat['supplier']['name']);
        $this->assertSame('C111', $flat['supplier']['tin']);
        $this->assertSame('XML Buyer', $flat['buyer']['name']);
        // xmlToArray casts numeric text to int, so compare loosely
        $this->assertEquals(100, $flat['subtotal']);
        $this->assertEquals(106, $flat['grand_total']);

        $this->assertCount(1, $flat['line_items']);
        $this->assertSame('XML Item', $flat['line_items'][0]['description']);
        $this->assertEquals(50, $flat['line_items'][0]['unit_price']);
        $this->assertEquals('022', $flat['line_items'][0]['classifications'][0]['code']);

        // InvoicedQuantity has a unitCode attribute → qty must restore as the scalar
        // value, not the ['value'=>.., '@attributes'=>..] array (which crashes on save
        // with "Array to string conversion"). uom comes from the attribute.
        $this->assertEquals(2, $flat['line_items'][0]['qty']);
        $this->assertIsNotArray($flat['line_items'][0]['qty']);
        $this->assertSame('C62', $flat['line_items'][0]['uom']);

        // a PaymentMeansCode in the XML must restore without an undefined-array-key
        // warning and resolve to the normalised payment-mode code
        $this->assertSame('03', $flat['payment_mode']);
    }

    #[Test]
    public function it_restores_allowance_charge_and_prepaid_amounts_as_scalars() : void
    {
        $flat = UBL::restore($this->xmlFixtureWithCharges());

        // AllowanceCharge / PrepaidPayment Amount elements carry a currencyID attribute,
        // so xmlToArray shapes them as ['value'=>.., '@attributes'=>..]. They must restore
        // as the scalar amount, not the array (which crashes on save with
        // "Array to string conversion").
        $this->assertCount(1, $flat['charges']);
        $this->assertEquals(10, $flat['charges'][0]['amount']);
        $this->assertIsNotArray($flat['charges'][0]['amount']);
        $this->assertSame('Shipping', $flat['charges'][0]['description']);

        $this->assertCount(1, $flat['discounts']);
        $this->assertEquals(5, $flat['discounts'][0]['amount']);
        $this->assertIsNotArray($flat['discounts'][0]['amount']);
        $this->assertSame('Promo', $flat['discounts'][0]['description']);

        $this->assertEquals(20, $flat['prepaid']['amount']);
        $this->assertIsNotArray($flat['prepaid']['amount']);

        // OriginCountry IdentificationCode may carry a listID attribute in third-party XML
        $this->assertEquals('MYS', $flat['line_items'][0]['country']);
        $this->assertIsNotArray($flat['line_items'][0]['country']);

        // AdditionalAccountID (certex) carries a schemeAgencyName attribute
        $this->assertEquals('CPT-CCN-W-211111-KL-000002', $flat['supplier']['certex']);
        $this->assertIsNotArray($flat['supplier']['certex']);

        // TaxCategory ID may carry a schemeID attribute in third-party XML and is saved
        // to a string column on import
        $this->assertEquals('01', $flat['taxes'][0]['code']);
        $this->assertIsNotArray($flat['taxes'][0]['code']);
    }

    #[Test]
    public function it_restores_attributed_text_and_single_references_as_scalars() : void
    {
        $flat = UBL::restore($this->xmlFixtureThirdParty());

        // BillingReference ID carries a schemeID attribute → read .value, not the array.
        $this->assertSame('BILL-REF-1', $flat['billing']['reference']);
        $this->assertIsNotArray($flat['billing']['reference']);

        // A single AdditionalDocumentReference is shaped by xmlToArray as an assoc array (not a
        // numeric list); it must restore as ONE reference, not one bogus entry per child key.
        $this->assertCount(1, $flat['references']);
        $this->assertSame('CUSTOMS-1', $flat['references'][0]['reference']);
        $this->assertSame('CustomsImportForm', $flat['references'][0]['type']);
        $this->assertSame('Customs import form', $flat['references'][0]['description']);

        // PaymentTerms Note carries a languageID attribute → read .value.
        $this->assertSame('Payment due in 30 days', $flat['payment_term']);
        $this->assertIsNotArray($flat['payment_term']);

        // CountrySubentityCode carries an attribute → the (string) cast must not crash on the
        // ['value'=>.., '@attributes'=>..] array ("Array to string conversion").
        $this->assertSame('01', $flat['supplier']['state']);
        $this->assertIsNotArray($flat['supplier']['state']);

        // TaxExemptionReason carries a languageID attribute, invoice-level and line-level.
        $this->assertSame('Exempt under Schedule A', $flat['taxes'][0]['exemption_reason']);
        $this->assertIsNotArray($flat['taxes'][0]['exemption_reason']);
        $this->assertSame('Line exempt reason', $flat['line_items'][0]['taxes'][0]['exemption_reason']);
        $this->assertIsNotArray($flat['line_items'][0]['taxes'][0]['exemption_reason']);

        // DocumentCurrencyCode (listID) → saved to a string column, so the array would be fatal.
        $this->assertSame('MYR', $flat['currency']);
        $this->assertIsNotArray($flat['currency']);

        // InvoiceDocumentReference ID (schemeID) → original_number, a string column.
        $this->assertSame('ORIG-INV-1', $flat['original_number']);
        $this->assertIsNotArray($flat['original_number']);

        // InvoicePeriod Description (languageID) → billing.frequency.
        $this->assertSame('Monthly', $flat['billing']['frequency']);
        $this->assertIsNotArray($flat['billing']['frequency']);

        // PaymentMeansCode (listID) → normalised payment-mode code.
        $this->assertSame('03', $flat['payment_mode']);
        $this->assertIsNotArray($flat['payment_mode']);

        // AllowanceChargeReason (languageID) → charge description and line-discount description.
        $this->assertSame('Shipping', $flat['charges'][0]['description']);
        $this->assertIsNotArray($flat['charges'][0]['description']);
        $this->assertSame('Line discount', $flat['line_items'][0]['discount']['description']);
        $this->assertIsNotArray($flat['line_items'][0]['discount']['description']);

        // Item Description (languageID) → saved to a string column, so the array would be fatal.
        $this->assertSame('XML Item', $flat['line_items'][0]['description']);
        $this->assertIsNotArray($flat['line_items'][0]['description']);
    }

    protected function xmlFixture() : string
    {
        return <<<'XML'
        <Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2">
            <ID>INV-XML-1</ID>
            <IssueDate>2026-02-01</IssueDate>
            <IssueTime>08:00:00Z</IssueTime>
            <InvoiceTypeCode listVersionID="1.1">01</InvoiceTypeCode>
            <DocumentCurrencyCode>MYR</DocumentCurrencyCode>
            <PaymentMeans>
                <PaymentMeansCode>03</PaymentMeansCode>
            </PaymentMeans>
            <AccountingSupplierParty>
                <Party>
                    <PartyLegalEntity><RegistrationName>XML Supplier</RegistrationName></PartyLegalEntity>
                    <PartyIdentification><ID schemeID="TIN">C111</ID></PartyIdentification>
                    <PartyIdentification><ID schemeID="BRN">B111</ID></PartyIdentification>
                </Party>
            </AccountingSupplierParty>
            <AccountingCustomerParty>
                <Party>
                    <PartyLegalEntity><RegistrationName>XML Buyer</RegistrationName></PartyLegalEntity>
                    <PartyIdentification><ID schemeID="TIN">C222</ID></PartyIdentification>
                    <PartyIdentification><ID schemeID="BRN">B222</ID></PartyIdentification>
                </Party>
            </AccountingCustomerParty>
            <LegalMonetaryTotal>
                <TaxExclusiveAmount currencyID="MYR">100</TaxExclusiveAmount>
                <TaxInclusiveAmount currencyID="MYR">106</TaxInclusiveAmount>
                <PayableAmount currencyID="MYR">106</PayableAmount>
            </LegalMonetaryTotal>
            <InvoiceLine>
                <ID>1</ID>
                <InvoicedQuantity unitCode="C62">2</InvoicedQuantity>
                <Item>
                    <Description>XML Item</Description>
                    <CommodityClassification>
                        <ItemClassificationCode listID="CLASS">022</ItemClassificationCode>
                    </CommodityClassification>
                </Item>
                <Price><PriceAmount currencyID="MYR">50</PriceAmount></Price>
                <ItemPriceExtension><Amount currencyID="MYR">100</Amount></ItemPriceExtension>
            </InvoiceLine>
        </Invoice>
        XML;
    }

    protected function xmlFixtureWithCharges() : string
    {
        return <<<'XML'
        <Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2">
            <ID>INV-XML-2</ID>
            <IssueDate>2026-02-01</IssueDate>
            <IssueTime>08:00:00Z</IssueTime>
            <InvoiceTypeCode listVersionID="1.1">01</InvoiceTypeCode>
            <DocumentCurrencyCode>MYR</DocumentCurrencyCode>
            <AllowanceCharge>
                <ChargeIndicator>true</ChargeIndicator>
                <AllowanceChargeReason>Shipping</AllowanceChargeReason>
                <Amount currencyID="MYR">10</Amount>
            </AllowanceCharge>
            <AllowanceCharge>
                <ChargeIndicator>false</ChargeIndicator>
                <AllowanceChargeReason>Promo</AllowanceChargeReason>
                <Amount currencyID="MYR">5</Amount>
            </AllowanceCharge>
            <PrepaidPayment>
                <ID>PRE-1</ID>
                <PaidAmount currencyID="MYR">20</PaidAmount>
                <PaidDate>2026-01-15</PaidDate>
            </PrepaidPayment>
            <TaxTotal>
                <TaxSubtotal>
                    <TaxableAmount currencyID="MYR">100</TaxableAmount>
                    <TaxAmount currencyID="MYR">6</TaxAmount>
                    <Percent>6</Percent>
                    <TaxCategory>
                        <ID schemeID="UN/ECE 5305" schemeAgencyID="6">01</ID>
                    </TaxCategory>
                </TaxSubtotal>
            </TaxTotal>
            <AccountingSupplierParty>
                <AdditionalAccountID schemeAgencyName="CertEX">CPT-CCN-W-211111-KL-000002</AdditionalAccountID>
                <Party>
                    <PartyLegalEntity><RegistrationName>XML Supplier</RegistrationName></PartyLegalEntity>
                    <PartyIdentification><ID schemeID="TIN">C111</ID></PartyIdentification>
                </Party>
            </AccountingSupplierParty>
            <AccountingCustomerParty>
                <Party>
                    <PartyLegalEntity><RegistrationName>XML Buyer</RegistrationName></PartyLegalEntity>
                    <PartyIdentification><ID schemeID="TIN">C222</ID></PartyIdentification>
                </Party>
            </AccountingCustomerParty>
            <InvoiceLine>
                <ID>1</ID>
                <InvoicedQuantity unitCode="C62">2</InvoicedQuantity>
                <Item>
                    <Description>XML Item</Description>
                    <OriginCountry><IdentificationCode listID="ISO3166-1">MYS</IdentificationCode></OriginCountry>
                </Item>
                <Price><PriceAmount currencyID="MYR">50</PriceAmount></Price>
            </InvoiceLine>
        </Invoice>
        XML;
    }

    protected function xmlFixtureThirdParty() : string
    {
        // Every text/code/id element below carries an XML attribute (languageID/listID/schemeID),
        // the way a third-party UBL generator may emit them — xmlToArray then shapes each as
        // ['value'=>.., '@attributes'=>..]. restoreXml must read .value, not the array.
        return <<<'XML'
        <Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2">
            <ID>INV-XML-3</ID>
            <IssueDate>2026-03-01</IssueDate>
            <IssueTime>08:00:00Z</IssueTime>
            <InvoiceTypeCode listVersionID="1.1">01</InvoiceTypeCode>
            <DocumentCurrencyCode listID="ISO 4217">MYR</DocumentCurrencyCode>
            <InvoicePeriod>
                <Description languageID="en">Monthly</Description>
            </InvoicePeriod>
            <BillingReference>
                <InvoiceDocumentReference>
                    <ID schemeID="ORIG">ORIG-INV-1</ID>
                </InvoiceDocumentReference>
                <AdditionalDocumentReference>
                    <ID schemeID="CUS">BILL-REF-1</ID>
                </AdditionalDocumentReference>
            </BillingReference>
            <AdditionalDocumentReference>
                <ID schemeID="CustomsImportForm">CUSTOMS-1</ID>
                <DocumentType>CustomsImportForm</DocumentType>
                <DocumentDescription>Customs import form</DocumentDescription>
            </AdditionalDocumentReference>
            <PaymentMeans>
                <PaymentMeansCode listID="UN/ECE 4461">03</PaymentMeansCode>
            </PaymentMeans>
            <PaymentTerms>
                <Note languageID="en">Payment due in 30 days</Note>
            </PaymentTerms>
            <AllowanceCharge>
                <ChargeIndicator>true</ChargeIndicator>
                <AllowanceChargeReason languageID="en">Shipping</AllowanceChargeReason>
                <Amount currencyID="MYR">10</Amount>
            </AllowanceCharge>
            <TaxTotal>
                <TaxSubtotal>
                    <TaxableAmount currencyID="MYR">100</TaxableAmount>
                    <TaxAmount currencyID="MYR">0</TaxAmount>
                    <TaxCategory>
                        <ID schemeID="UN/ECE 5305">E</ID>
                        <TaxExemptionReason languageID="en">Exempt under Schedule A</TaxExemptionReason>
                    </TaxCategory>
                </TaxSubtotal>
            </TaxTotal>
            <AccountingSupplierParty>
                <Party>
                    <PartyLegalEntity><RegistrationName>XML Supplier</RegistrationName></PartyLegalEntity>
                    <PartyIdentification><ID schemeID="TIN">C111</ID></PartyIdentification>
                    <PostalAddress>
                        <CountrySubentityCode listName="State">01</CountrySubentityCode>
                    </PostalAddress>
                </Party>
            </AccountingSupplierParty>
            <AccountingCustomerParty>
                <Party>
                    <PartyLegalEntity><RegistrationName>XML Buyer</RegistrationName></PartyLegalEntity>
                    <PartyIdentification><ID schemeID="TIN">C222</ID></PartyIdentification>
                </Party>
            </AccountingCustomerParty>
            <InvoiceLine>
                <ID>1</ID>
                <InvoicedQuantity unitCode="C62">1</InvoicedQuantity>
                <Item>
                    <Description languageID="en">XML Item</Description>
                </Item>
                <Price><PriceAmount currencyID="MYR">100</PriceAmount></Price>
                <AllowanceCharge>
                    <AllowanceChargeReason languageID="en">Line discount</AllowanceChargeReason>
                    <Amount currencyID="MYR">2</Amount>
                </AllowanceCharge>
                <TaxTotal>
                    <TaxSubtotal>
                        <TaxableAmount currencyID="MYR">100</TaxableAmount>
                        <TaxAmount currencyID="MYR">0</TaxAmount>
                        <TaxCategory>
                            <ID schemeID="UN/ECE 5305">E</ID>
                            <TaxExemptionReason languageID="en">Line exempt reason</TaxExemptionReason>
                        </TaxCategory>
                    </TaxSubtotal>
                </TaxTotal>
            </InvoiceLine>
        </Invoice>
        XML;
    }
}
