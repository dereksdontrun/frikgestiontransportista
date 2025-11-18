<?php
class FrikDecisionEngine
{
    /**
     * Decide mejor opción según reglas:
     * - Exclusión UPS por contenido largo (espada, katana, etc.)
     * - Exclusión Spring-Tracked si total > 100 €
     * - Reglas por país (FR/IT/BE/NL/DE/GB)
     * - Preferencia GLS si ≤ glsMargen
     * - Empates ±0,10 → preferencia GLS
     *
     * @param array $ctx      ['country','total_paid_eur','weight_kg','productos_agrupados','carrier_before'(opt)]
     * @param array $options  lista de opciones (ver keys esperadas arriba)
     * @param float|string $glsMargen       (p.ej. 0.50)
     * @param float|string $gbThreshold    (p.ej. 50.00)
     * @return array|null    ['id_carrier_reference','carrier_name','price','criteria'] | null si sin opciones
     */
    public static function decide(array $ctx, array $options, $glsMargen, $gbThreshold)
    {
        $glsMargen = (float) $glsMargen;
        $gbThreshold = (float) $gbThreshold;

        $opts = array_values($options);
        if (!$opts)
            return null;

        $country = strtoupper((string) $ctx['country']);
        $total = isset($ctx['total_paid_eur']) ? (float) $ctx['total_paid_eur'] : 0.0;
        $prods = isset($ctx['productos_agrupados']) ? (string) $ctx['productos_agrupados'] : '';

        // Normaliza carrier_name para filtros
        foreach ($opts as &$o) {
            $o['_cname'] = self::normCarrier($o['carrier_name']);
            $o['_price'] = (float) $o['avg_price_eur'];
        }
        unset($o);

        // -------- Regla 1: Exclusión por contenido (evitar UPS si props largos) --------
        if (
            self::containsAny($prods, array(
                'espada',
                'paraguas',
                'sable',
                'katana',
                'bastón',
                'lanza',
                'palo',
                'prop largo',
                'props largos',
                'staff',
                'blade'
            ))
        ) {
            $opts = self::filterCarrierBlacklist($opts, array('ups'));
        }
        if (!$opts)
            return null;

        // -------- Regla 2: Exclusión por importe (Spring-Tracked si total > 100) --------
        if ($total > 100) {
            $opts = self::filterCarrierBlacklist($opts, array('spring-tracked'));
        }
        if (!$opts)
            return null;

        // -------- Excepciones por país --------
        switch ($country) {
            case 'GB':
                // Solo válidos Spring-Tracked / spring-signatured
                $opts = self::filterCarrierWhitelist($opts, array('spring-tracked', 'spring-signatured'));
                if (!$opts)
                    return null;
                $target = ($total < $gbThreshold) ? 'spring-tracked' : 'spring-signatured';
                $pick = self::firstByName($opts, $target);
                if ($pick)
                    return self::result($pick, 'Regla GB');
                // Fallback: el más barato entre los dos
                return self::pickWithGLSPreference($opts, $glsMargen, 'Regla GB (fallback)');

            case 'FR':
                if (self::allProductsFromSuppliers($prods, array('redstring', 'amont', 'decor habitat', 'amont (decor habitat)'))) {
                    $pick = self::firstByName($opts, 'gls');
                    if ($pick)
                        return self::result($pick, 'FR: Dropshipping → GLS fijo');
                }
                // Si no todos son de Redstring/Amont
                if ($total < 30) {
                    return self::pickWithGLSPreference($opts, $glsMargen, 'FR: Precio mínimo (<30€)');
                } else {
                    // >= 30, excluir Spring-Tracked
                    $opts2 = self::filterCarrierBlacklist($opts, array('spring-tracked'));
                    if ($opts2)
                        return self::pickWithGLSPreference($opts2, $glsMargen, 'FR: Sin Spring-Tracked (≥30€)');
                    // Fallback si no queda nada tras excluir
                    return self::pickWithGLSPreference($opts, $glsMargen, 'FR: Fallback sin exclusión');
                }

            case 'IT':
                if (self::allProductsFromSuppliers($prods, array('redstring', 'amont', 'decor habitat', 'amont (decor habitat)'))) {
                    $pick = self::firstByName($opts, 'gls');
                    if ($pick)
                        return self::result($pick, 'IT: Dropshipping → GLS fijo');
                }
                // Sin Spring-Tracked ni spring-signatured (aunque sean más baratos)
                $opts2 = self::filterCarrierBlacklist($opts, array('spring-tracked', 'spring-signatured'));
                if ($opts2)
                    return self::pickWithGLSPreference($opts2, $glsMargen, 'IT: Sin Spring');
                // Fallback
                return self::pickWithGLSPreference($opts, $glsMargen, 'IT: Fallback sin exclusión');

            case 'BE':
                if (self::allProductsFromSuppliers($prods, array('redstring', 'amont', 'decor habitat', 'amont (decor habitat)'))) {
                    $pick = self::firstByName($opts, 'gls');
                    if ($pick)
                        return self::result($pick, 'BE: Dropshipping → GLS fijo');
                }
                if ($total < 30) {
                    return self::pickWithGLSPreference($opts, $glsMargen, 'BE: Precio mínimo (<30€)');
                } else {
                    // >= 30 sin Spring (Tracked ni Signed)
                    $opts2 = self::filterCarrierBlacklist($opts, array('spring-tracked', 'spring-signatured'));
                    if ($opts2)
                        return self::pickWithGLSPreference($opts2, $glsMargen, 'BE: Sin Spring (≥30€)');
                    return self::pickWithGLSPreference($opts, $glsMargen, 'BE: Fallback sin exclusión');
                }

            case 'NL':
                if (self::allProductsFromSuppliers($prods, array('redstring', 'amont', 'decor habitat', 'amont (decor habitat)'))) {
                    $pick = self::firstByName($opts, 'gls');
                    if ($pick)
                        return self::result($pick, 'NL: Dropshipping → GLS fijo');
                }
                if ($total < 30) {
                    return self::pickWithGLSPreference($opts, $glsMargen, 'NL: Precio mínimo (<30€)');
                } else {
                    // >= 30 sin Spring
                    $opts2 = self::filterCarrierBlacklist($opts, array('spring-tracked', 'spring-signatured'));
                    if ($opts2)
                        return self::pickWithGLSPreference($opts2, $glsMargen, 'NL: Sin Spring (≥30€)');
                    return self::pickWithGLSPreference($opts, $glsMargen, 'NL: Fallback sin exclusión');
                }

            case 'DE':
                if (self::allProductsFromSuppliers($prods, array('redstring', 'amont', 'decor habitat', 'amont (decor habitat)'))) {
                    $pick = self::firstByName($opts, 'gls');
                    if ($pick)
                        return self::result($pick, 'DE: Dropshipping → GLS fijo');
                }
                if ($total < 100) {
                    return self::pickWithGLSPreference($opts, $glsMargen, 'DE: Precio mínimo (<100€)');
                } else {
                    // ≥ 100 sin Spring
                    $opts2 = self::filterCarrierBlacklist($opts, array('spring-tracked', 'spring-signatured'));
                    if ($opts2)
                        return self::pickWithGLSPreference($opts2, $glsMargen, 'DE: Sin Spring (≥100€)');
                    return self::pickWithGLSPreference($opts, $glsMargen, 'DE: Fallback sin exclusión');
                }
        }

        // -------- Resto de países (reglas generales) --------
        return self::pickWithGLSPreference($opts, $glsMargen, 'Regla general');
    }

    /* ===================== Helpers ===================== */

    //por si aparece spring-signed
    private static function normCarrier($name)
    {
        $n = Tools::strtolower(trim($name));
        // Acepta alias antiguos y normaliza a 'spring-signatured'
        if ($n === 'spring-signed')
            $n = 'spring-signatured';
        return $n;
    }


    private static function containsAny($haystack, array $needles)
    {
        $h = Tools::strtolower((string) $haystack);
        foreach ($needles as $n) {
            if ($n !== '' && strpos($h, Tools::strtolower($n)) !== false)
                return true;
        }
        return false;
    }

    /**
     * Comprueba si TODOS los productos son de alguno de los proveedores dados.
     * Asume formato "Nombre 1 (Proveedor) || Nombre 2 (Proveedor)..."
     */
    private static function allProductsFromSuppliers($productos, array $suppliers)
    {
        $suppliers = array_map('Tools::strtolower', $suppliers);
        $parts = array_map('trim', explode('||', (string) $productos));
        $okCount = 0;
        $total = 0;

        foreach ($parts as $p) {
            if ($p === '')
                continue;
            $total++;
            // extrae texto dentro de paréntesis final: (Proveedor)
            $prov = '';
            $lp = strrpos($p, '(');
            $rp = strrpos($p, ')');
            if ($lp !== false && $rp !== false && $rp > $lp) {
                $prov = Tools::strtolower(trim(substr($p, $lp + 1, $rp - $lp - 1)));
            } else {
                // si no hay paréntesis, intenta última palabra
                $chunks = preg_split('/\s+/', Tools::strtolower($p));
                $prov = end($chunks);
            }
            // normaliza algunos alias
            if ($prov === 'decor' || $prov === 'habitat') {
                $prov = 'decor habitat';
            }

            $match = false;
            foreach ($suppliers as $s) {
                if ($s !== '' && strpos($prov, $s) !== false) {
                    $match = true;
                    break;
                }
            }
            if ($match)
                $okCount++;
        }
        return ($total > 0 && $okCount === $total);
    }

    private static function filterCarrierWhitelist(array $opts, array $namesLower)
    {
        $namesLower = array_map(array('self', 'normCarrier'), $namesLower);
        $out = array();
        foreach ($opts as $o)
            if (in_array($o['_cname'], $namesLower))
                $out[] = $o;
        return $out;
    }

    private static function filterCarrierBlacklist(array $opts, array $namesLower)
    {
        $namesLower = array_map(array('self', 'normCarrier'), $namesLower);
        $out = array();
        foreach ($opts as $o)
            if (!in_array($o['_cname'], $namesLower))
                $out[] = $o;
        return $out;
    }

    private static function firstByName(array $opts, $nameLower)
    {
        $nameLower = self::normCarrier($nameLower);
        foreach ($opts as $o)
            if ($o['_cname'] === $nameLower)
                return $o;
        return null;
    }

    private static function pickWithGLSPreference(array $opts, $glsMargen, $label)
    {
        if (!$opts)
            return null;
        // precio mínimo
        $min = null;
        foreach ($opts as $o) {
            if ($min === null || $o['_price'] < $min)
                $min = $o['_price'];
        }

        // preferencia GLS si Δ ≤ glsMargen
        foreach ($opts as $o) {
            if ($o['_cname'] === 'gls' && ($o['_price'] - $min) <= (float) $glsMargen) {
                return self::result($o, 'GLS preferido (Δ ≤ ' . number_format($glsMargen, 2, ',', '.') . ' €) — ' . $label);
            }
        }

        // candidatos al mínimo (para empate ±0,10 con preferencia GLS)
        $epsilon = 0.10;
        $cands = array();
        foreach ($opts as $o) {
            if (abs($o['_price'] - $min) <= $epsilon)
                $cands[] = $o;
        }
        // si entre los empatados está GLS, elige GLS
        foreach ($cands as $o) {
            if ($o['_cname'] === 'gls')
                return self::result($o, 'Empate ±0,10 → GLS — ' . $label);
        }
        // si no, elige el más barato (primero con precio == min)
        foreach ($opts as $o) {
            if ($o['_price'] == $min)
                return self::result($o, 'Precio mínimo — ' . $label);
        }
        // último recurso
        return self::result($opts[0], 'Precio mínimo — ' . $label);
    }

    private static function result($opt, $criteria)
    {
        return array(
            'id_carrier_reference' => isset($opt['id_carrier_reference']) ? (int) $opt['id_carrier_reference'] : 0,
            'carrier_name' => $opt['carrier_name'],
            'price' => (float) $opt['avg_price_eur'],
            'criteria' => $criteria,
        );
    }
}
