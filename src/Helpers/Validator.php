<?php

namespace Jiannius\Myinvois\Helpers;

use Jiannius\Myinvois\Enums\TinType;

class Validator
{
    public $document;

    public function build($document)
    {
        $this->document = $document;

        return validator(
            $this->document,
            $this->rules(),
            $this->messages(),
        );
    }

    public function rules()
    {
        return [
            'number' => 'required',
            'issued_at' => 'required|date',
            'document_type' => 'required',
            'document_version' => 'required',
            'currency' => 'required',
            ...$this->getSupplierRules(),
            ...$this->getBuyerRules(),
            'subtotal' => 'required',
            'grand_total' => 'required',
            'payable_total' => 'required',
            'taxes.*.code' => 'sometimes|required',
            'taxes.*.name' => 'sometimes|required',
            'line_items' => 'required|array|min:1',
            'line_items.*.classifications' => 'required|array|min:1',
            'line_items.*.description' => 'required',
            'line_items.*.unit_price' => 'required',
            'line_items.*.subtotal' => 'required',
            'line_items.*.taxes.*.code' => 'sometimes|required',
            'line_items.*.taxes.*.name' => 'sometimes|required',
        ];
    }

    public function messages()
    {
        return [
            'number.required' => 'Document number is required',
            'issued_at.required' => 'Issue date is required',
            'issued_at.date' => 'Issue date is invalid',
            'document_type.required' => 'Document type is required',
            'document_version.required' => 'Document version is required',
            'currency.required' => 'Currency is required',
            'supplier.name.required' => 'Supplier name is required',
            'supplier.tin.required' => 'Supplier TIN is required',
            'supplier.brn.required_without_all' => 'Either supplier BRN / NRIC / PASSPORT / ARMY is required',
            'supplier.nric.required_without_all' => 'Either supplier BRN / NRIC / PASSPORT / ARMY is required',
            'supplier.passport.required_without_all' => 'Either supplier BRN / NRIC / PASSPORT / ARMY is required',
            'supplier.army.required_without_all' => 'Either supplier BRN / NRIC / PASSPORT / ARMY is required',
            'supplier.phone.required' => 'Supplier phone is required',
            'supplier.email.email' => 'Supplier email is invalid',
            'supplier.msic_code.required' => 'Supplier MSIC code is required',
            'supplier.msic_description.required' => 'Supplier MSIC description is required',
            'supplier.address_line_1.required' => 'Supplier address line 1 is required',
            'supplier.city.required' => 'Supplier city is required',
            'supplier.country.required' => 'Supplier country is required',
            'supplier.state.required' => 'Supplier state is required',
            'buyer.name.required' => 'Buyer name is required',
            'buyer.tin.required' => 'Buyer TIN is required',
            'buyer.brn.required_without_all' => 'Either buyer BRN / NRIC / PASSPORT / ARMY is required',
            'buyer.nric.required_without_all' => 'Either buyer BRN / NRIC / PASSPORT / ARMY is required',
            'buyer.passport.required_without_all' => 'Either buyer BRN / NRIC / PASSPORT / ARMY is required',
            'buyer.army.required_without_all' => 'Either buyer BRN / NRIC / PASSPORT / ARMY is required',
            'buyer.phone.required' => 'Buyer phone is required',
            'buyer.email.email' => 'Buyer email is invalid',
            'buyer.address_line_1.required' => 'Buyer address line 1 is required',
            'buyer.city.required' => 'Buyer city is required',
            'buyer.country.required' => 'Buyer country is required',
            'buyer.state.required' => 'Buyer state is required',
            'subtotal.required' => 'Subtotal is required',
            'grand_total.required' => 'Grand total is required',
            'payable_total.required' => 'Payable total is required',
            'taxes.*.code.required' => 'Tax code is required.',
            'taxes.*.name.required' => 'Tax name is required.',
            'line_items.required' => 'Line items is required',
            'line_items.array' => 'Line items is invalid',
            'line_items.min' => 'Should have minimum 1 line item',
            'line_items.*.classifications.required' => 'Line item classifications is required',
            'line_items.*.classifications.array' => 'Line item classifications is invalid',
            'line_items.*.classifications.min' => 'Should have minimum 1 line item classification',
            'line_items.*.description.required' => 'Line item description is required',
            'line_items.*.unit_price.required' => 'Line item unit price is required',
            'line_items.*.subtotal.required' => 'Line item subtotal is required',
            'line_items.*.taxes.*.code.required' => 'Line item tax code is required.',
            'line_items.*.taxes.*.name' => 'Line item tax name is required.',
        ];
    }

    public function getSupplierRules()
    {
        $tintype = TinType::tryFrom(data_get($this->document, 'supplier.tin'));

        $rules = [
            'supplier.name' => 'required',
            'supplier.tin' => 'required',
        ];

        if ($tintype) return $rules;

        return [
            ...$rules,
            'supplier.brn' => 'required_without_all:supplier.nric,supplier.passport,supplier.army',
            'supplier.nric' => 'required_without_all:supplier.brn,supplier.passport,supplier.army',
            'supplier.passport' => 'required_without_all:supplier.brn,supplier.nric,supplier.army',
            'supplier.army' => 'required_without_all:supplier.brn,supplier.nric,supplier.passport',
            'supplier.phone' => 'required',
            'supplier.email' => 'nullable|email',
            'supplier.msic_code' => 'required',
            'supplier.msic_description' => 'required',
            'supplier.address_line_1' => 'required',
            'supplier.city' => 'required',
            'supplier.country' => [
                'required',
                function ($attribute, $value, $fail) {
                    if (!is_string(Code::countries()->value($value))) $fail('Invalid supplier country');
                },
            ],
            'supplier.state' => [
                'required',
                function ($attribute, $value, $fail) {
                    if (!is_string(Code::states()->value($value))) $fail('Invalid supplier state');
                },
            ],
        ];
    }

    public function getBuyerRules()
    {
        $tintype = TinType::tryFrom(data_get($this->document, 'buyer.tin'));

        $rules = [
            'buyer.name' => 'required',
            'buyer.tin' => 'required',
        ];

        if ($tintype) return $rules;

        return [
            ...$rules,
            'buyer.brn' => 'required_without_all:buyer.nric,buyer.passport,buyer.army',
            'buyer.nric' => 'required_without_all:buyer.brn,buyer.passport,buyer.army',
            'buyer.passport' => 'required_without_all:buyer.brn,buyer.nric,buyer.army',
            'buyer.army' => 'required_without_all:buyer.brn,buyer.nric,buyer.passport',
            'buyer.phone' => 'required',
            'buyer.email' => 'nullable|email',
            'buyer.address_line_1' => 'required',
            'buyer.city' => 'required',
            'buyer.country' => [
                'required',
                function ($attribute, $value, $fail) {
                    if (!is_string(Code::countries()->value($value))) $fail('Invalid buyer country');
                },
            ],
            'buyer.state' => [
                'required',
                function ($attribute, $value, $fail) {
                    if (!is_string(Code::states()->value($value))) $fail('Invalid buyer state');
                },
            ],
        ];
    }
}