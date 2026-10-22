<?php

// Golden output of noki/laravel-xml-converter v1.0.3 Convert::xmlToArray() for the .xml beside it.

return array (
  'Invoice' => 
  array (
    'ID' => 'INV-XML-2',
    'IssueDate' => '2026-02-01',
    'IssueTime' => '08:00:00Z',
    'InvoiceTypeCode' => 
    array (
      '@attributes' => 
      array (
        'listVersionID' => '1.1',
      ),
      'value' => '01',
    ),
    'DocumentCurrencyCode' => 'MYR',
    'AllowanceCharge' => 
    array (
      0 => 
      array (
        'ChargeIndicator' => true,
        'AllowanceChargeReason' => 'Shipping',
        'Amount' => 
        array (
          '@attributes' => 
          array (
            'currencyID' => 'MYR',
          ),
          'value' => 10,
        ),
      ),
      1 => 
      array (
        'ChargeIndicator' => false,
        'AllowanceChargeReason' => 'Promo',
        'Amount' => 
        array (
          '@attributes' => 
          array (
            'currencyID' => 'MYR',
          ),
          'value' => 5,
        ),
      ),
    ),
    'PrepaidPayment' => 
    array (
      'ID' => 'PRE-1',
      'PaidAmount' => 
      array (
        '@attributes' => 
        array (
          'currencyID' => 'MYR',
        ),
        'value' => 20,
      ),
      'PaidDate' => '2026-01-15',
    ),
    'TaxTotal' => 
    array (
      'TaxSubtotal' => 
      array (
        'TaxableAmount' => 
        array (
          '@attributes' => 
          array (
            'currencyID' => 'MYR',
          ),
          'value' => 100,
        ),
        'TaxAmount' => 
        array (
          '@attributes' => 
          array (
            'currencyID' => 'MYR',
          ),
          'value' => 6,
        ),
        'Percent' => 6,
        'TaxCategory' => 
        array (
          'ID' => 
          array (
            '@attributes' => 
            array (
              'schemeID' => 'UN/ECE 5305',
              'schemeAgencyID' => '6',
            ),
            'value' => '01',
          ),
        ),
      ),
    ),
    'AccountingSupplierParty' => 
    array (
      'AdditionalAccountID' => 
      array (
        '@attributes' => 
        array (
          'schemeAgencyName' => 'CertEX',
        ),
        'value' => 'CPT-CCN-W-211111-KL-000002',
      ),
      'Party' => 
      array (
        'PartyLegalEntity' => 
        array (
          'RegistrationName' => 'XML Supplier',
        ),
        'PartyIdentification' => 
        array (
          'ID' => 
          array (
            '@attributes' => 
            array (
              'schemeID' => 'TIN',
            ),
            'value' => 'C111',
          ),
        ),
      ),
    ),
    'AccountingCustomerParty' => 
    array (
      'Party' => 
      array (
        'PartyLegalEntity' => 
        array (
          'RegistrationName' => 'XML Buyer',
        ),
        'PartyIdentification' => 
        array (
          'ID' => 
          array (
            '@attributes' => 
            array (
              'schemeID' => 'TIN',
            ),
            'value' => 'C222',
          ),
        ),
      ),
    ),
    'InvoiceLine' => 
    array (
      'ID' => 1,
      'InvoicedQuantity' => 
      array (
        '@attributes' => 
        array (
          'unitCode' => 'C62',
        ),
        'value' => 2,
      ),
      'Item' => 
      array (
        'Description' => 'XML Item',
        'OriginCountry' => 
        array (
          'IdentificationCode' => 
          array (
            '@attributes' => 
            array (
              'listID' => 'ISO3166-1',
            ),
            'value' => 'MYS',
          ),
        ),
      ),
      'Price' => 
      array (
        'PriceAmount' => 
        array (
          '@attributes' => 
          array (
            'currencyID' => 'MYR',
          ),
          'value' => 50,
        ),
      ),
    ),
  ),
);
