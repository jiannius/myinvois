<?php

// Golden output of noki/laravel-xml-converter v1.0.3 Convert::xmlToArray() for the .xml beside it.

return array (
  'Invoice' => 
  array (
    'ID' => 'INV-XML-1',
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
    'PaymentMeans' => 
    array (
      'PaymentMeansCode' => '03',
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
          0 => 
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
          1 => 
          array (
            'ID' => 
            array (
              '@attributes' => 
              array (
                'schemeID' => 'BRN',
              ),
              'value' => 'B111',
            ),
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
          0 => 
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
          1 => 
          array (
            'ID' => 
            array (
              '@attributes' => 
              array (
                'schemeID' => 'BRN',
              ),
              'value' => 'B222',
            ),
          ),
        ),
      ),
    ),
    'LegalMonetaryTotal' => 
    array (
      'TaxExclusiveAmount' => 
      array (
        '@attributes' => 
        array (
          'currencyID' => 'MYR',
        ),
        'value' => 100,
      ),
      'TaxInclusiveAmount' => 
      array (
        '@attributes' => 
        array (
          'currencyID' => 'MYR',
        ),
        'value' => 106,
      ),
      'PayableAmount' => 
      array (
        '@attributes' => 
        array (
          'currencyID' => 'MYR',
        ),
        'value' => 106,
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
        'CommodityClassification' => 
        array (
          'ItemClassificationCode' => 
          array (
            '@attributes' => 
            array (
              'listID' => 'CLASS',
            ),
            'value' => '022',
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
      'ItemPriceExtension' => 
      array (
        'Amount' => 
        array (
          '@attributes' => 
          array (
            'currencyID' => 'MYR',
          ),
          'value' => 100,
        ),
      ),
    ),
  ),
);
