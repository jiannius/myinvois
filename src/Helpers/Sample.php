<?php

namespace Jiannius\Myinvois\Helpers;

class Sample
{
    public static function build()
    {
        return [
            'number' => 'INV'.time(),
            'issued_at' => now(),
            'document_type' => Code::documentTypes('Invoice'),
            'document_version' => Code::documentVersions('Invoice'),
            'currency' => Code::currencies('MYR'),
            'payment_mode' => Code::paymentModes('Bank Transfer'),
            'payment_term' => 'Payment method is cash',
            'billing' => [
                'start_at' => now(),
                'end_at' => now()->addDays(30),
                'frequency' => 'Monthly',
                'reference' => 'E12345678912',
            ],
            'references' => [
                [
                    'reference' => 'E12345678912',
                    'type' => 'CUSTOMS',
                ],
                [
                    'reference' => 'F23489723894',
                    'type' => 'FTA',
                    'description' => 'ASEAN-Australia-New Zealand FTA (AANZFTA)',
                ],
                [
                    'reference' => 'E12345678912,E23456789123',
                    'type' => 'K2',
                ],
                [
                    'reference' => 'CIF',
                ],
            ],
            'supplier' => [
                'name' => 'JIANNIUS TECHNOLOGIES SDN. BHD.',
                'tin' => 'C26561325060',
                'brn' => '202101001341',
                'email' => 'hello@jiannius.com',
                'phone' => '+60-123456789',
                'bank_account_number' => '1234567890123',
                'address_line_1' => 'Lot 66',
                'address_line_2' => 'Bangunan Merdeka',
                'address_line_3' => 'Persiaran Jaya',
                'postcode' => '50480',
                'city' => 'Kuala Lumpur',
                'state' => Code::states('Wilayah Persekutuan Kuala Lumpur'),
                'country' => Code::countries('Malaysia'),
                'certex' => 'CPT-CCN-W-211111-KL-000002',
                'msic_code' => '46510',
                'msic_description' => 'Wholesale of computer hardware, software and peripherals',

            ],
            'buyer' => [
                'name' => 'Foreign Country Buyer',
                'email' => 'buyer@email.com',
                'phone' => '+60-123456789',
                'tin' => 'EI00000000020',
                'address_line_1' => 'Lot 66',
                'address_line_2' => 'Bangunan Merdeka',
                'address_line_3' => 'Persiaran Jaya',
                'postcode' => '50480',
                'city' => 'Kuala Lumpur',
                'state' => Code::states('Wilayah Persekutuan Kuala Lumpur'),
                'country' => Code::countries('Malaysia'),
            ],
            // 'shipping' => [
            //     'name' => 'Recipient\'s Name',
            //     'tin' => 'UU28934723894723894',
            //     'address_line_1' => 'Lot 66',
            //     'address_line_2' => 'Bangunan Merdeka',
            //     'address_line_3' => 'Persiaran Jaya',
            //     'postcode' => '50480',
            //     'city' => 'Kuala Lumpur',
            //     'state' => Code::states('Wilayah Persekutuan Kuala Lumpur'),
            //     'country' => Code::countries('Malaysia'),
            //     'amount' => 25.00,
            //     'description' => 'Lalamove',
            //     'reference' => 'L121321',
            // ],
            'prepaid' => [
                'amount' => 50,
                'paid_at' => now()->subDays(10),
                'reference' => 'P92342394',
            ],
            'charges' => [
                [
                    'amount' => 20,
                    'description' => 'Service Charge',
                ],
                [
                    'amount' => 30.50,
                    'description' => 'Labour Charge',
                ],
            ],
            'discounts' => [
                [
                    'amount' => 100,
                    'description' => 'Festival Discount',
                ],
            ],
            'taxes' => [
                [
                    'code' => Code::taxes('Sales Tax'),
                    'name' => 'Sales Tax',
                    'amount' => 30,
                ],
            ],
            'subtotal' => 500,
            'grand_total' => 530,
            'payable_total' => 530,
            'line_items' => [
                [
                    'qty' => 1,
                    'uom' => Code::units('outfit'),
                    'description' => 'Line item 1 description',
                    'unit_price' => 500.00,
                    'country' => null,
                    'classifications' => [
                        ['code' => Code::classifications('Others')],
                    ],
                    // 'tariffs' => [
                    //     ['code' => '22223334444'],
                    //     ['code' => '22223337777'],
                    // ],
                    'taxes' => [
                        [
                            'code' => Code::taxes('Sales Tax'),
                            'name' => 'Sales Tax',
                            'amount' => 30,
                            'taxable_amount' => 470,
                        ],
                    ],
                    'subtotal' => 500.00,
                    'discount' => [
                        'amount' => 0,
                        'description' => null,
                        'rate' => null,
                    ],            
                ],
            ],
        ];
    }
}