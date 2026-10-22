<?php

// Golden output of noki/laravel-xml-converter v1.0.3 Convert::xmlToArray() for the .xml beside it.

return array (
  'Invoice' => 
  array (
    'ID' => 'INV-EDGE-1',
    'Note' => 'Fish & Chips <deluxe>',
    'Code' => '007',
    'Zero' => 0,
    'Decimal' => 12.5,
    'Negative' => -3,
    'Flag' => true,
    'Off' => false,
    'Empty' => NULL,
    'EmptyWithAttribute' => 
    array (
      '@attributes' => 
      array (
        'currencyID' => 'MYR',
      ),
    ),
    'Amount' => 
    array (
      '@attributes' => 
      array (
        'currencyID' => 'MYR',
      ),
      'value' => 0.0,
    ),
    'Unicode' => 'Kedai Kopi 咖啡店 — café',
    'Party' => 
    array (
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
        2 => 
        array (
          'ID' => 
          array (
            '@attributes' => 
            array (
              'schemeID' => 'SST',
            ),
            'value' => 'NA',
          ),
        ),
      ),
      'Name' => 
      array (
        'Inner' => 'child',
        'value' => 'Mixed',
      ),
    ),
    'Single' => 
    array (
      'Only' => 1,
    ),
  ),
);
