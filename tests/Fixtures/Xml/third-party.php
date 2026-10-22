<?php

// Golden output of noki/laravel-xml-converter v1.0.3 Convert::xmlToArray() for the .xml beside it.

return array (
  'Invoice' => 
  array (
    'ID' => 'INV-XML-3',
    'IssueDate' => '2026-03-01',
    'IssueTime' => '08:00:00Z',
    'InvoiceTypeCode' => 
    array (
      '@attributes' => 
      array (
        'listVersionID' => '1.1',
      ),
      'value' => '01',
    ),
    'DocumentCurrencyCode' => 
    array (
      '@attributes' => 
      array (
        'listID' => 'ISO 4217',
      ),
      'value' => 'MYR',
    ),
    'InvoicePeriod' => 
    array (
      'Description' => 
      array (
        '@attributes' => 
        array (
          'languageID' => 'en',
        ),
        'value' => 'Monthly',
      ),
    ),
    'BillingReference' => 
    array (
      'InvoiceDocumentReference' => 
      array (
        'ID' => 
        array (
          '@attributes' => 
          array (
            'schemeID' => 'ORIG',
          ),
          'value' => 'ORIG-INV-1',
        ),
      ),
      'AdditionalDocumentReference' => 
      array (
        'ID' => 
        array (
          '@attributes' => 
          array (
            'schemeID' => 'CUS',
          ),
          'value' => 'BILL-REF-1',
        ),
      ),
    ),
    'AdditionalDocumentReference' => 
    array (
      'ID' => 
      array (
        '@attributes' => 
        array (
          'schemeID' => 'CustomsImportForm',
        ),
        'value' => 'CUSTOMS-1',
      ),
      'DocumentType' => 'CustomsImportForm',
      'DocumentDescription' => 'Customs import form',
    ),
    'PaymentMeans' => 
    array (
      'PaymentMeansCode' => 
      array (
        '@attributes' => 
        array (
          'listID' => 'UN/ECE 4461',
        ),
        'value' => '03',
      ),
    ),
    'PaymentTerms' => 
    array (
      'Note' => 
      array (
        '@attributes' => 
        array (
          'languageID' => 'en',
        ),
        'value' => 'Payment due in 30 days',
      ),
    ),
    'AllowanceCharge' => 
    array (
      'ChargeIndicator' => true,
      'AllowanceChargeReason' => 
      array (
        '@attributes' => 
        array (
          'languageID' => 'en',
        ),
        'value' => 'Shipping',
      ),
      'Amount' => 
      array (
        '@attributes' => 
        array (
          'currencyID' => 'MYR',
        ),
        'value' => 10,
      ),
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
          'value' => 0,
        ),
        'TaxCategory' => 
        array (
          'ID' => 
          array (
            '@attributes' => 
            array (
              'schemeID' => 'UN/ECE 5305',
            ),
            'value' => 'E',
          ),
          'TaxExemptionReason' => 
          array (
            '@attributes' => 
            array (
              'languageID' => 'en',
            ),
            'value' => 'Exempt under Schedule A',
          ),
        ),
      ),
    ),
    'AccountingSupplierParty' => 
    array (
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
        'PostalAddress' => 
        array (
          'CountrySubentityCode' => 
          array (
            '@attributes' => 
            array (
              'listName' => 'State',
            ),
            'value' => '01',
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
        'value' => 1,
      ),
      'Item' => 
      array (
        'Description' => 
        array (
          '@attributes' => 
          array (
            'languageID' => 'en',
          ),
          'value' => 'XML Item',
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
          'value' => 100,
        ),
      ),
      'AllowanceCharge' => 
      array (
        'AllowanceChargeReason' => 
        array (
          '@attributes' => 
          array (
            'languageID' => 'en',
          ),
          'value' => 'Line discount',
        ),
        'Amount' => 
        array (
          '@attributes' => 
          array (
            'currencyID' => 'MYR',
          ),
          'value' => 2,
        ),
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
            'value' => 0,
          ),
          'TaxCategory' => 
          array (
            'ID' => 
            array (
              '@attributes' => 
              array (
                'schemeID' => 'UN/ECE 5305',
              ),
              'value' => 'E',
            ),
            'TaxExemptionReason' => 
            array (
              '@attributes' => 
              array (
                'languageID' => 'en',
              ),
              'value' => 'Line exempt reason',
            ),
          ),
        ),
      ),
    ),
  ),
);
