<?php

namespace Jiannius\Myinvois\Tests\Fixtures;

/**
 * Clean, fully-controlled normalized document inputs (the flat array contract
 * documented in Helpers/Sample.php). Unlike Sample::build() these use no
 * time()/now() so build output is deterministic and assertable.
 */
class DocumentFixture
{
    /**
     * A standard, feature-rich invoice exercising most UBL sections.
     */
    public static function invoice(array $overrides = []) : array
    {
        $doc = [
            'number' => 'INV-0001',
            'issued_at' => '2026-01-15 09:30:00',
            'document_type' => '01',      // Invoice
            'document_version' => '1.1',
            'currency' => 'MYR',
            'currency_rate' => 4.5,
            'payment_mode' => '03',       // Bank Transfer
            'payment_term' => 'Net 30',

            'billing' => [
                'start_at' => '2026-01-01',
                'end_at' => '2026-01-31',
                'frequency' => 'Monthly',
                'reference' => 'BILL-REF-1',
            ],

            'references' => [
                ['type' => 'CUSTOMS', 'value' => 'K1/2026/123'],
                ['type' => 'INCOTERMS', 'value' => 'CIF'],
                ['type' => 'FTA', 'value' => 'AANZFTA'],
            ],

            'supplier' => [
                'name' => 'Supplier Sdn Bhd',
                'tin' => 'C26561325060',
                'brn' => '202101001341',
                'sst' => 'SST-001',
                'ttx' => 'TTX-001',
                'email' => 'supplier@example.com',
                'phone' => '+60-3-12345678',
                'bank_account_number' => '1234567890',
                'address_line_1' => 'Lot 1',
                'address_line_2' => 'Level 2',
                'address_line_3' => 'Tower 3',
                'postcode' => '50480',
                'city' => 'Kuala Lumpur',
                'state' => 'Kuala Lumpur',
                'country' => 'Malaysia',
                'certex' => 'CPT-CCN-W-211111-KL-000002',
                'msic_code' => '46510',
                'msic_description' => 'Wholesale of computer hardware',
            ],

            'buyer' => [
                'name' => 'Buyer Bhd',
                'tin' => 'C99999999090',
                'nric' => '900101145678',
                'email' => 'buyer@example.com',
                'phone' => '0123456789',
                'address_line_1' => 'Jalan Buyer 1',
                'postcode' => '43000',
                'city' => 'Kajang',
                'state' => 'Selangor',
                'country' => 'Malaysia',
            ],

            'prepaid' => [
                'amount' => 50,
                'paid_at' => '2026-01-10 08:00:00',
                'reference' => 'PRE-1',
            ],

            'charges' => [
                ['amount' => 20, 'description' => 'Service Charge'],
            ],

            'discounts' => [
                ['amount' => 10, 'description' => 'Festival Discount'],
            ],

            'taxes' => [
                ['code' => '01', 'name' => 'Sales Tax', 'amount' => 30, 'taxable_amount' => 470],
            ],

            'subtotal' => 500,
            'grand_total' => 530,
            'payable_total' => 530,

            'line_items' => [
                [
                    'qty' => 2,
                    'uom' => 'C62',
                    'description' => 'Widget',
                    'unit_price' => 250,
                    'country' => 'Malaysia',
                    'classifications' => [
                        ['code' => '022'],
                    ],
                    'tariffs' => [
                        ['code' => '9999.00.00'],
                    ],
                    'taxes' => [
                        ['code' => '01', 'name' => 'Sales Tax', 'amount' => 30, 'taxable_amount' => 470, 'rate' => 6],
                    ],
                    'subtotal' => 500,
                    'discount' => [
                        'amount' => 30,
                        'description' => 'Line discount',
                        'rate' => 0.06,
                    ],
                ],
            ],
        ];

        return array_replace_recursive($doc, $overrides);
    }

    /**
     * A consolidated invoice via the canonical `is_consolidate` flag.
     */
    public static function consolidated(array $overrides = []) : array
    {
        $doc = static::invoice();
        unset($doc['line_items'][0]['classifications'], $doc['line_items'][0]['tariffs']);
        $doc['is_consolidate'] = true;
        $doc['line_items'][1] = [
            'qty' => 1,
            'description' => 'Receipt 1001-2000',
            'unit_price' => 300,
            'subtotal' => 300,
        ];

        return array_replace_recursive($doc, $overrides);
    }

    /**
     * A consolidated invoice via the legacy contract: every line tagged 004.
     */
    public static function legacy004(array $overrides = []) : array
    {
        $doc = static::invoice();
        unset($doc['line_items'][0]['tariffs']);
        $doc['line_items'][0]['classifications'] = [['code' => '004']];

        return array_replace_recursive($doc, $overrides);
    }
}
