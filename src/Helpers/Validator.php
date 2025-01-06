<?php

namespace Jiannius\Myinvois\Helpers;

class Validator
{
    public static $rules = [
        'number' => 'required',
        'issued_at' => 'required|date',
        'document_type' => 'required',
        'document_version' => 'required',
        'currency' => 'required',
        'supplier.name' => 'required',
        'supplier.tin' => 'required',
        'supplier.brn' => 'required_without:supplier.nric',
        'supplier.nric' => 'required_without:supplier.brn',
        'supplier.phone' => 'required',
        'supplier.msic_code' => 'required',
        'supplier.msic_description' => 'required',
        'supplier.address_line_1' => 'required',
        'supplier.city' => 'required',
        'supplier.country' => 'required',
        'supplier.state' => 'required',
        'buyer.name' => 'required',
        'buyer.tin' => 'required',
        'buyer.brn' => 'required_without:buyer.nric',
        'buyer.nric' => 'required_without:buyer.brn',
        'buyer.phone' => 'required',
        'buyer.address_line_1' => 'required',
        'buyer.city' => 'required',
        'buyer.country' => 'required',
        'buyer.state' => 'required',
        'subtotal' => 'required',
        'grand_total' => 'required',
        'payable_total' => 'required',
        'line_items' => 'required|array|min:1',
        'line_items.*.classifications' => 'required|array|min:1',
        'line_items.*.description' => 'required',
        'line_items.*.unit_price' => 'required',
        'line_items.*.subtotal' => 'required',
    ];
    
    public static $messages = [
        'number.required' => 'Document number is required',
        'issued_at.required' => 'Issue date is required',
        'issued_at.date' => 'Issue date is invalid',
        'document_type.required' => 'Document type is required',
        'document_version.required' => 'Document version is required',
        'currency.required' => 'Currency is required',
        'supplier.name.required' => 'Supplier name is required',
        'supplier.tin.required' => 'Supplier TIN is required',
        'supplier.brn.required_without' => 'Either supplier BRN or NRIC (for individual profile) is required',
        'supplier.nric.required_without' => 'Either supplier BRN or NRIC (for individual profile) is required',
        'supplier.phone.required' => 'Supplier phone is required',
        'supplier.msic_code.required' => 'Supplier MSIC code is required',
        'supplier.msic_description.required' => 'Supplier MSIC description is required',
        'supplier.address_line_1.required' => 'Supplier address line 1 is required',
        'supplier.city.required' => 'Supplier city is required',
        'supplier.country.required' => 'Supplier country is required',
        'supplier.state.required' => 'Supplier state is required',
        'buyer.name.required' => 'Buyer name is required',
        'buyer.tin.required' => 'Buyer TIN is required',
        'buyer.brn.required_without' => 'Either buyer BRN or NRIC (for individual profile) is required',
        'buyer.nric.required_without' => 'Either buyer BRN or NRIC (for individual profile) is required',
        'buyer.phone.required' => 'Buyer phone is required',
        'buyer.address_line_1.required' => 'Buyer address line 1 is required',
        'buyer.city.required' => 'Buyer city is required',
        'buyer.country.required' => 'Buyer country is required',
        'buyer.state.required' => 'Buyer state is required',
        'subtotal.required' => 'Subtotal is required',
        'grand_total.required' => 'Grand total is required',
        'payable_total.required' => 'Payable total is required',
        'line_items.required' => 'Line items is required',
        'line_items.array' => 'Line items is invalid',
        'line_items.min' => 'Should have minimum 1 line item',
        'line_items.*.classifications.required' => 'Line item classifications is required',
        'line_items.*.classifications.array' => 'Line item classifications is invalid',
        'line_items.*.classifications.min' => 'Should have minimum 1 line item classification',
        'line_items.*.description.required' => 'Line item description is required',
        'line_items.*.unit_price.required' => 'Line item unit price is required',
        'line_items.*.subtotal.required' => 'Line item subtotal is required',
    ];

    public static function build($document)
    {
        return validator($document, self::$rules, self::$messages);
    }
}