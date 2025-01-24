<?php

namespace Jiannius\Myinvois\Helpers;

class UBL
{
    public static function build($data)
    {
        $schema = [];
        $schema = self::getDocumentEssentialSchema($schema, $data);
        $schema = self::getDocumentCurrencySchema($schema, $data);
        $schema = self::getDocumentBillingPeriodSchema($schema, $data);
        $schema = self::getDocumentReferencesSchema($schema, $data);
        $schema = self::getDocumentSupplierSchema($schema, $data);
        $schema = self::getDocumentBuyerSchema($schema, $data);
        $schema = self::getDocumentShippingSchema($schema, $data);
        $schema = self::getDocumentPaymentModeSchema($schema, $data);
        $schema = self::getDocumentPrepaidSchema($schema, $data);
        $schema = self::getDocumentChargesAndDiscountsSchema($schema, $data);
        $schema = self::getDocumentTotalsSchema($schema, $data);
        $schema = self::getDocumentLineItemsSchema($schema, $data);
        
        return $schema;
    }

    public static function getDocumentEssentialSchema($schema, $data)
    {
        data_set($schema, '_D', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
        data_set($schema, '_A', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        data_set($schema, '_B', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        data_set($schema, 'Invoice.0.ID.0._', data_get($data, 'number'));
        data_set($schema, 'Invoice.0.IssueDate.0._', data_get($data, 'issued_at')->toDateString());
        data_set($schema, 'Invoice.0.IssueTime.0._', data_get($data, 'issued_at')->format('H:i:sp'));
        data_set($schema, 'Invoice.0.InvoiceTypeCode.0._', data_get($data, 'document_type'));
        data_set($schema, 'Invoice.0.InvoiceTypeCode.0.listVersionID', data_get($data, 'document_version'));
    
        return $schema;
    }

    public static function getDocumentCurrencySchema($schema, $data)
    {
        $currency = data_get($data, 'currency');
        $rate = data_get($data, 'currency_rate');
    
        data_set($schema, 'Invoice.0.DocumentCurrencyCode.0._', $currency);
        data_set($schema, 'Invoice.0.TaxCurrencyCode.0._', $currency);
    
        if ($rate) {
            data_set($schema, 'Invoice.0.TaxExchangeRate.0.CalculationRate.0._', $rate);
            data_set($schema, 'Invoice.0.TaxExchangeRate.0.SourceCurrencyCode.0._', 'DocumentCurrencyCode');
            data_set($schema, 'Invoice.0.TaxExchangeRate.0.TargetCurrencyCode.0._', 'MYR');
        }
    
        return $schema;
    }

    public static function getDocumentSupplierSchema($schema, $data)
    {
        $supplier = data_get($data, 'supplier');

        throw_if(!$supplier, \Exception::class, 'Missing supplier');

        data_set($schema, 'Invoice.0.AccountingSupplierParty.0.Party.0.PartyLegalEntity.0.RegistrationName.0._', data_get($supplier, 'name'));

        foreach (self::getDocumentTINSubschema($supplier) as $key => $val) {
            data_set($schema, 'Invoice.0.AccountingSupplierParty.0.Party.0.'.$key, $val);
        }

        foreach (self::getDocumentAddressSubschema($supplier) as $key => $val) {
            data_set($schema, 'Invoice.0.AccountingSupplierParty.0.Party.0.'.$key, $val);
        }

        foreach (self::getDocumentContactSubschema($supplier) as $key => $val) {
            data_set($schema, 'Invoice.0.AccountingSupplierParty.0.Party.0.'.$key, $val);
        }

        if ($acc = data_get($supplier, 'bank_account_number')) {
            data_set($schema, 'Invoice.0.PaymentMeans.0.PayeeFinancialAccount.0.ID.0._', $acc);
        }

        if ($certex = data_get($supplier, 'certex')) { // authorized certified exporter
            data_set($schema, 'Invoice.0.AccountingSupplierParty.0.AdditionalAccountID.0._', $certex);
            data_set($schema, 'Invoice.0.AccountingSupplierParty.0.AdditionalAccountID.0.schemeAgencyName', 'CertEX');
        }

        // Malaysia Standard Industrial Classification
        if ($msicCode = data_get($supplier, 'msic_code')) {
            data_set($schema, 'Invoice.0.AccountingSupplierParty.0.Party.0.IndustryClassificationCode.0._', $msicCode);

            if ($msicDescription = data_get($supplier, 'msic_description')) {
                data_set($schema, 'Invoice.0.AccountingSupplierParty.0.Party.0.IndustryClassificationCode.0.name', $msicDescription);
            }
        }

        return $schema;
    }

    public static function getDocumentBuyerSchema($schema, $data)
    {
        $buyer = data_get($data, 'buyer');

        throw_if(!$buyer, \Exception::class, 'Missing buyer');

        data_set($schema, 'Invoice.0.AccountingCustomerParty.0.Party.0.PartyLegalEntity.0.RegistrationName.0._', data_get($buyer, 'name'));

        foreach (self::getDocumentTINSubschema($buyer) as $key => $val) {
            data_set($schema, 'Invoice.0.AccountingCustomerParty.0.Party.0.'.$key, $val);
        }

        foreach (self::getDocumentAddressSubschema($buyer) as $key => $val) {
            data_set($schema, 'Invoice.0.AccountingCustomerParty.0.Party.0.'.$key, $val);
        }

        foreach (self::getDocumentContactSubschema($buyer) as $key => $val) {
            data_set($schema, 'Invoice.0.AccountingCustomerParty.0.Party.0.'.$key, $val);
        }

        return $schema;
    }

    public static function getDocumentBillingPeriodSchema($schema, $data)
    {
        $billing = data_get($data, 'billing');

        foreach(collect([
            'Invoice.0.InvoicePeriod.0.StartDate.0._' => optional(data_get($billing, 'start_at'))->toDateString(),
            'Invoice.0.InvoicePeriod.0.EndDate.0._' => optional(data_get($billing, 'end_at'))->toDateString(),
            'Invoice.0.InvoicePeriod.0.Description.0._' => data_get($billing, 'frequency'), // Daily, Weekly, Biweekly, Monthly, Bimonthly, Quarterly, Half-yearly, Yearly, Others / Not Applicable
            'Invoice.0.BillingReference.0.AdditionalDocumentReference.0.ID.0._' => data_get($billing, 'reference'),
        ])->filter() as $key => $val) {
            data_set($schema, $key, $val);
        }

        return $schema;
    }

    public static function getDocumentReferencesSchema($schema, $data)
    {
        $refs = data_get($data, 'references');

        if (!$refs) return $schema;

        foreach ($refs as $i => $ref) {
            if ($num = data_get($ref, 'reference')) {
                data_set($schema, 'Invoice.0.AdditionalDocumentReference.'.$i.'.ID.0._', $num);
            }
            if ($type = data_get($ref, 'type')) {
                data_set($schema, 'Invoice.0.AdditionalDocumentReference.'.$i.'.DocumentType.0._', match ($type) {
                    'CUSTOMS' => 'CustomsImportForm',
                    'FTA' => 'FreeTradeAgreement',
                    default => $type,
                });
            }
            if ($desc = data_get($ref, 'description')) {
                data_set($schema, 'Invoice.0.AdditionalDocumentReference.'.$i.'.DocumentDescription.0._', $desc);
            }
        }

        return $schema;
    }

    public static function getDocumentShippingSchema($schema, $data)
    {
        $shipping = data_get($data, 'shipping');
        $currency = data_get($data, 'currency');

        if (!$shipping) return $schema;

        if ($name = data_get($shipping, 'name')) {
            data_set($schema, 'Invoice.0.Delivery.0.DeliveryParty.0.PartyLegalEntity.0.RegistrationName.0._', $name);
        }

        foreach (self::getDocumentTINSubschema($shipping) as $key => $val) {
            data_set($schema, 'Invoice.0.Delivery.0.DeliveryParty.0.'.$key, $val);
        }

        foreach (self::getDocumentAddressSubschema($shipping) as $key => $val) {
            data_set($schema, 'Invoice.0.Delivery.0.DeliveryParty.0.'.$key, $val);
        }

        if ($ref = data_get($shipping, 'reference')) {
            data_set($schema, 'Invoice.0.Delivery.0.Shipment.0.ID.0._', $ref);
        }

        if ($amount = data_get($shipping, 'amount')) {
            data_set($schema, 'Invoice.0.Delivery.0.Shipment.0.FreightAllowanceCharge.0.ChargeIndicator.0._', true);
            data_set($schema, 'Invoice.0.Delivery.0.Shipment.0.FreightAllowanceCharge.0.Amount.0._', $amount);
            data_set($schema, 'Invoice.0.Delivery.0.Shipment.0.FreightAllowanceCharge.0.Amount.0.currencyID', $currency);
        }

        if ($desc = data_get($shipping, 'description')) {
            data_set($schema, 'Invoice.0.Delivery.0.Shipment.0.FreightAllowanceCharge.0.AllowanceChargeReason.0._', $desc);
        }

        return $schema;
    }

    public static function getDocumentPrepaidSchema($schema, $data)
    {
        $prepaid = data_get($data, 'prepaid');
        $currency = data_get($data, 'currency');

        if ($ref = data_get($prepaid, 'reference')) {
            data_set($schema, 'Invoice.0.PrepaidPayment.0.ID.0._', $ref);
        }

        if ($amount = data_get($prepaid, 'amount')) {
            data_set($schema, 'Invoice.0.PrepaidPayment.0.PaidAmount.0._', $amount);
            data_set($schema, 'Invoice.0.PrepaidPayment.0.PaidAmount.0.currencyID', $currency);
        }

        if ($dt = data_get($prepaid, 'paid_at')) {
            data_set($schema, 'Invoice.0.PrepaidPayment.0.PaidDate.0._', $dt->toDateString());
            data_set($schema, 'Invoice.0.PrepaidPayment.0.PaidTime.0._', $dt->format('H:i:sp'));
        }

        return $schema;
    }

    public static function getDocumentPaymentModeSchema($schema, $data)
    {
        if ($paymode = data_get($data, 'payment_mode')) {
            data_set($schema, 'Invoice.0.PaymentMeans.0.PaymentMeansCode.0._', $paymode);
        }

        if ($payterm = data_get($data, 'payment_term')) {
            data_set($schema, 'Invoice.0.PaymentTerms.0.Note.0._', $payterm);
        }

        return $schema;
    }

    public static function getDocumentChargesAndDiscountsSchema($schema, $data)
    {
        $charges = data_get($data, 'charges', []);
        $discounts = data_get($data, 'discounts', []);
        $currency = data_get($data, 'currency');
        $items = collect($charges)->concat(
            collect($discounts)->map(fn ($discount) => [...$discount, 'is_discount' => true])
        );

        foreach ($items as $i => $item) {
            data_set($schema, 'Invoice.0.AllowanceCharge.'.$i.'.ChargeIndicator.0._', data_get($item, 'is_discount') ? false : true);

            if ($amount = data_get($item, 'amount')) {
                data_set($schema, 'Invoice.0.AllowanceCharge.'.$i.'.Amount.0._', $amount);
                data_set($schema, 'Invoice.0.AllowanceCharge.'.$i.'.Amount.0.currencyID', Code::currencies($currency));
            }

            if ($desc = data_get($item, 'description')) {
                data_set($schema, 'Invoice.0.AllowanceCharge.'.$i.'.AllowanceChargeReason.0._', $desc);
            }
        }

        return $schema;
    }

    public static function getDocumentTotalsSchema($schema, $data)
    {
        $currency = data_get($data, 'currency');

        foreach (self::getDocumentTaxesSubschema(data_get($data, 'taxes', []), $currency) as $key => $val) {
            data_set($schema, 'Invoice.0.'.$key, $val);
        }

        $subtotal = data_get($data, 'subtotal');
        $grandTotal = data_get($data, 'grand_total');
        $payableTotal = data_get($data, 'payable_total') ?: $grandTotal;

        data_set($schema, 'Invoice.0.LegalMonetaryTotal.0.TaxExclusiveAmount.0._', $subtotal);
        data_set($schema, 'Invoice.0.LegalMonetaryTotal.0.TaxExclusiveAmount.0.currencyID', $currency);

        data_set($schema, 'Invoice.0.LegalMonetaryTotal.0.TaxInclusiveAmount.0._', $grandTotal);
        data_set($schema, 'Invoice.0.LegalMonetaryTotal.0.TaxInclusiveAmount.0.currencyID', $currency);

        data_set($schema, 'Invoice.0.LegalMonetaryTotal.0.PayableAmount.0._', $payableTotal);
        data_set($schema, 'Invoice.0.LegalMonetaryTotal.0.PayableAmount.0.currencyID', $currency);

        return $schema;
    }

    public static function getDocumentLineItemsSchema($schema, $data)
    {
        $currency = data_get($data, 'currency');

        foreach (data_get($data, 'line_items', []) as $i => $item) {
            data_set($schema, 'Invoice.0.InvoiceLine.'.$i.'.ID.0._', (string) str($i + 1)->padLeft(3, '0'));
            data_set($schema, 'Invoice.0.InvoiceLine.'.$i.'.InvoicedQuantity.0._', data_get($item, 'qty'));
            data_set($schema, 'Invoice.0.InvoiceLine.'.$i.'.InvoicedQuantity.0.unitCode', data_get($item, 'uom'));
            data_set($schema, 'Invoice.0.InvoiceLine.'.$i.'.Item.0.Description.0._', data_get($item, 'description'));
            data_set($schema, 'Invoice.0.InvoiceLine.'.$i.'.Price.0.PriceAmount.0._', data_get($item, 'unit_price'));
            data_set($schema, 'Invoice.0.InvoiceLine.'.$i.'.Price.0.PriceAmount.0.currencyID', $currency);
            
            if ($country = data_get($item, 'country')) {
                data_set($schema, 'Invoice.0.InvoiceLine.'.$i.'.Item.0.OriginCountry.0.IdentificationCode.0._', $country);
            }

            foreach (collect(data_get($item, 'classifications'))->concat(
                collect(data_get($item, 'tariffs'))->map(fn ($tariff) => [...$tariff, 'is_tariff' => true])
            ) as $j => $classification) {
                data_set($schema, 'Invoice.0.InvoiceLine.'.$i.'.Item.0.CommodityClassification.'.$j.'.ItemClassificationCode.0._', data_get($classification, 'code'));
                data_set($schema, 'Invoice.0.InvoiceLine.'.$i.'.Item.0.CommodityClassification.'.$j.'.ItemClassificationCode.0.listID', data_get($classification, 'is_tariff') ? 'PTC' : 'CLASS');
            }

            foreach (self::getDocumentTaxesSubschema(data_get($item, 'taxes', []), $currency) as $key => $val) {
                data_set($schema, 'Invoice.0.InvoiceLine.'.$i.'.'.$key, $val);
            }

            // subtotal - qty * unit price
            data_set($schema, 'Invoice.0.InvoiceLine.'.$i.'.ItemPriceExtension.0.Amount.0._', data_get($item, 'subtotal'));
            data_set($schema, 'Invoice.0.InvoiceLine.'.$i.'.ItemPriceExtension.0.Amount.0.currencyID', $currency);
            
            // total excluding tax - subtotal - discount
            data_set($schema, 'Invoice.0.InvoiceLine.'.$i.'.LineExtensionAmount.0._', data_get($item, 'subtotal') - data_get($item, 'discount.amount', 0));
            data_set($schema, 'Invoice.0.InvoiceLine.'.$i.'.LineExtensionAmount.0.currencyID', $currency);

            if (data_get($item, 'discount')) {
                data_set($schema, 'Invoice.0.InvoiceLine.'.$i.'.AllowanceCharge.0.ChargeIndicator.0._', false);
                data_set($schema, 'Invoice.0.InvoiceLine.'.$i.'.AllowanceCharge.0.Amount.0._', data_get($item, 'discount.amount'));
                data_set($schema, 'Invoice.0.InvoiceLine.'.$i.'.AllowanceCharge.0.Amount.0.currencyID', $currency);
                
                if (data_get($item, 'discount.description')) {
                    data_set($schema, 'Invoice.0.InvoiceLine.'.$i.'.AllowanceCharge.0.AllowanceChargeReason.0._', data_get($item, 'discount.description'));
                }

                if (data_get($item, 'discount.rate')) {
                    data_set($schema, 'Invoice.0.InvoiceLine.'.$i.'.AllowanceCharge.0.MultiplierFactorNumeric.0._', data_get($item, 'discount.rate'));
                }
            }
        }

        return $schema;
    }

    public static function getDocumentTINSubschema($data)
    {
        return collect([
            ['TIN', data_get($data, 'tin')],
            (
                data_get($data, 'nric')
                ? ['NRIC', data_get($data, 'nric')]
                : ['BRN', data_get($data, 'brn')]
            ),
            ['SST', data_get($data, 'sst')],
            ['TTX', data_get($data, 'ttx')],
        ])->mapWithKeys(fn ($val, $i) => [
            'PartyIdentification.'.$i.'.ID.0._' => data_get($val, 1) ?? 'NA',
            'PartyIdentification.'.$i.'.ID.0.schemeID' => data_get($val, 0),
        ])->toArray();
    }

    public static function getDocumentContactSubschema($data)
    {
        return collect([
            'Contact.0.Telephone.0._' => data_get($data, 'phone') ?? 'NA',
            'Contact.0.ElectronicMail.0._' => data_get($data, 'email') ?? 'NA',
        ])->filter()->toArray();
    }

    public static function getDocumentAddressSubschema($data)
    {
        $schema = collect([
            'PostalAddress.0.AddressLine.0.Line.0._' => data_get($data, 'address_line_1'),
            'PostalAddress.0.AddressLine.1.Line.0._' => data_get($data, 'address_line_2'),
            'PostalAddress.0.AddressLine.2.Line.0._' => data_get($data, 'address_line_3'),
            'PostalAddress.0.CityName.0._' => data_get($data, 'city'),
            'PostalAddress.0.PostalZone.0._' => data_get($data, 'postcode'),
            'PostalAddress.0.CountrySubentityCode.0._' => Code::states(data_get($data, 'state')),
        ])->filter();

        if ($country = data_get($data, 'country')) {
            $schema->put('PostalAddress.0.Country.0.IdentificationCode.0._', Code::countries($country));
            $schema->put('PostalAddress.0.Country.0.IdentificationCode.0.listID', 'ISO3166-1');
            $schema->put('PostalAddress.0.Country.0.IdentificationCode.0.listAgencyID', '6');
        }

        return $schema->toArray();
    }

    public static function getDocumentTaxesSubschema($taxes, $currency)
    {
        if (!$taxes) return [];

        $schema = collect();
        $total = collect($taxes)->sum('amount');

        $schema->put('TaxTotal.0.TaxAmount.0._', $total);
        $schema->put('TaxTotal.0.TaxAmount.0.currencyID', $currency);

        foreach ($taxes as $i => $tax) {
            $schema->put('TaxTotal.0.TaxSubtotal.'.$i.'.TaxCategory.0.ID.0._', data_get($tax, 'code'));
            $schema->put('TaxTotal.0.TaxSubtotal.'.$i.'.TaxCategory.0.TaxScheme.0.ID.0._', 'OTH');
            $schema->put('TaxTotal.0.TaxSubtotal.'.$i.'.TaxCategory.0.TaxScheme.0.ID.0.schemeID', 'UN/ECE 5153');
            $schema->put('TaxTotal.0.TaxSubtotal.'.$i.'.TaxCategory.0.TaxScheme.0.ID.0.schemeAgencyID', '6');
    
            if ($reason = data_get($tax, 'exemption_reason')) {
                $schema->put('TaxTotal.0.TaxSubtotal.'.$i.'.TaxCategory.0.TaxExemptionReason.0._', $reason);
            }
    
            $schema->put('TaxTotal.0.TaxSubtotal.'.$i.'.TaxableAmount.0._', data_get($tax, 'taxable_amount') ?? 0);
            $schema->put('TaxTotal.0.TaxSubtotal.'.$i.'.TaxableAmount.0.currencyID', $currency);
    
            $schema->put('TaxTotal.0.TaxSubtotal.'.$i.'.TaxAmount.0._', data_get($tax, 'amount') ?? 0);
            $schema->put('TaxTotal.0.TaxSubtotal.'.$i.'.TaxAmount.0.currencyID', $currency);
    
            if ($rate = data_get($tax, 'rate')) {
                $schema->put('TaxTotal.0.TaxSubtotal.'.$i.'.Percent.0._', $rate);
            }
            else if (data_get($tax, 'fixed_rate_base_unit_measure')) {
                $schema->put('TaxTotal.0.TaxSubtotal.'.$i.'.BaseUnitMeasure.0._', data_get($tax, 'fixed_rate_base_unit_measure'));
                $schema->put('TaxTotal.0.TaxSubtotal.'.$i.'.BaseUnitMeasure.0.unitCode', data_get($tax, 'fixed_rate_base_unit_measure_code'));
                $schema->put('TaxTotal.0.TaxSubtotal.'.$i.'.PerUnitAmount.0._', data_get($tax, 'fixed_rate_per_unit_amount'));
                $schema->put('TaxTotal.0.TaxSubtotal.'.$i.'.PerUnitAmount.0.currencyID', $currency);
            }
        }

        return $schema;
    }
}
