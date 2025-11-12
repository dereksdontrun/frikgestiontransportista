<?php
class FrikCarrierRate extends ObjectModel
{
    public $id_rate;
    public $id_carrier_reference; // id_reference estable del carrier
    public $carrier_name; // etiqueta visible (GLS/UPS/...)
    public $country_iso;
    public $weight_min_kg;
    public $weight_max_kg;
    public $avg_price_eur;
    public $active;
    public $date_upd;


    public static $definition = [
        'table' => 'frikgestiontransportista_carrier_rates',
        'primary' => 'id_rate',
        'fields' => [
            'id_carrier_reference' => ['type' => self::TYPE_INT, 'required' => true],
            'carrier_name' => ['type' => self::TYPE_STRING, 'required' => true, 'size' => 64],
            'country_iso' => ['type' => self::TYPE_STRING, 'required' => true, 'size' => 2],
            'weight_min_kg' => ['type' => self::TYPE_FLOAT, 'required' => true],
            'weight_max_kg' => ['type' => self::TYPE_FLOAT, 'required' => true],
            'avg_price_eur' => ['type' => self::TYPE_FLOAT, 'required' => true],
            'active' => ['type' => self::TYPE_BOOL, 'required' => true],
            'date_upd' => ['type' => self::TYPE_DATE],
        ]
    ];
}