<?php
class FrikCarrierRate extends ObjectModel
{
    /** DB fields */
    public $id_frikgestiontransportista_carrier_rates;
    public $id_carrier_reference;
    public $carrier_name;
    public $country_iso;
    public $weight_min_kg;
    public $weight_max_kg;
    public $avg_price_eur;
    public $active;
    public $date_upd;

    public static $definition = array(
        'table'   => 'frikgestiontransportista_carrier_rates', // SIN prefijo
        'primary' => 'id_frikgestiontransportista_carrier_rates',
        'fields'  => array(
            'id_carrier_reference' => array('type'=> self::TYPE_INT,   'required'=> true, 'validate'=>'isUnsignedId'),
            'carrier_name'         => array('type'=> self::TYPE_STRING,'required'=> true, 'size'=>64, 'validate'=>'isCleanHtml'),
            'country_iso'          => array('type'=> self::TYPE_STRING,'required'=> true, 'size'=>2,  'validate'=>'isLanguageIsoCode'),
            'weight_min_kg'        => array('type'=> self::TYPE_FLOAT, 'required'=> true),
            'weight_max_kg'        => array('type'=> self::TYPE_FLOAT, 'required'=> true),
            'avg_price_eur'        => array('type'=> self::TYPE_FLOAT, 'required'=> true),
            'active'               => array('type'=> self::TYPE_BOOL,  'required'=> true, 'validate'=>'isBool'),
            'date_upd'             => array('type'=> self::TYPE_DATE),
        ),
    );
}
