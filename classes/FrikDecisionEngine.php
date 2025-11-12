<?php
class FrikDecisionEngine
{
    public static function decide(array $ctx, array $options, $glsDelta, $gbThreshold)
    {
        $opts = array_values($options);
        if (!$opts)
            return null;


        // GB: sólo Spring-Tracked/Signed según umbral
        if (strtoupper($ctx['country']) === 'GB') {
            $opts = array_values(array_filter($opts, function ($o) {
                $c = Tools::strtolower($o['carrier_name']);
                return in_array($c, ['spring-tracked', 'spring-signed']);
            }));
            if (!$opts)
                return null;
            $target = ($ctx['total_paid_eur'] < (float) $gbThreshold) ? 'spring-tracked' : 'spring-signed';
            foreach ($opts as $o)
                if (Tools::strtolower($o['carrier_name']) === $target)
                    return self::result($o, 'Regla GB');
        }


        // Precio mínimo
        $min = min(array_map(function ($o) {
            return (float) $o['avg_price_eur']; }, $opts));


        // Preferencia GLS si Δ ≤ glsDelta
        foreach ($opts as $o) {
            if (Tools::strtolower($o['carrier_name']) === 'gls' && ((float) $o['avg_price_eur'] - $min) <= (float) $glsDelta) {
                return self::result($o, 'GLS preferido (Δ ≤ ' . number_format($glsDelta, 2, ',', '.') . ' €)');
            }
        }


        // Por defecto, mínimo precio
        foreach ($opts as $o)
            if ((float) $o['avg_price_eur'] == $min)
                return self::result($o, 'Precio mínimo');
        return self::result($opts[0], 'Precio mínimo');
    }


    private static function result($opt, $criteria)
    {
        return [
            'id_carrier_reference' => (int) $opt['id_carrier_reference'],
            'carrier_name' => $opt['carrier_name'],
            'price' => (float) $opt['avg_price_eur'],
            'criteria' => $criteria,
        ];
    }
}