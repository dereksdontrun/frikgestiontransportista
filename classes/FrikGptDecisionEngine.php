<?php
if (!defined('_PS_VERSION_'))
    exit;

require_once dirname(__FILE__) . '/FrikGptClient.php';

class FrikGptDecisionEngine
{
    /**
     * @param array $order_info (id_order,country,weight_kg,total_paid_eur,carrier_name,id_carrier_reference,...)
     * @param array $options (id_carrier_reference,carrier_name,country_iso,weight_min_kg,weight_max_kg,avg_price_eur)
     * @param array $varsPrompt (gb_threshold, margen_gls, etc.)
     * @return array ['decision'=>null|array,'audit'=>array] o ['error'=>...]
     */
    public static function decide(array $order_info, array $options, array $varsPrompt = array())
    {
        // Normaliza etiquetas Spring
        foreach ($options as &$o) {
            if (!isset($o['carrier_name']))
                continue;
            $c = Tools::strtolower($o['carrier_name']);
            if ($c === 'spring signed')
                $o['carrier_name'] = 'spring-signatured';
            if ($c === 'spring-signed')
                $o['carrier_name'] = 'spring-signatured';
            if ($c === 'spring tracked')
                $o['carrier_name'] = 'spring-tracked';
        }
        unset($o);

        // Construye el contexto de usuario como JSON para el prompt (robusto y fácil de parsear)
        $pedido = array(
            'id_order' => (int) $order_info['id_order'],
            'destination_country_iso' => (string) $order_info['country'],
            'products_weight_kg' => (float) $order_info['weight_kg'],            
            'total_paid_eur' => (float) $order_info['total_paid_eur'],
            'carrier_name' => (string) $order_info['carrier_name'],
            'productos' => $order_info['productos'],
            'proveedores' => $order_info['proveedores']
        );

        $user_context = json_encode(array(
            'pedido' => $pedido,
            'opciones' => array_values($options),
        ));

        // Llama al prompt (grupo/nombre configurables)
        $resp = FrikGptClient::callWithPrompt(
            'transportistas',
            'asignar_transporte_internacional',
            $user_context,
            $varsPrompt
        );

        if (isset($resp['error']))
            return $resp;

        // Esperamos: ['decision'=>null|{id_carrier_reference,carrier_name,price,criteria}, 'audit'=>{...}]
        if (!array_key_exists('decision', $resp) || !array_key_exists('audit', $resp)) {
            return array('error' => 'Respuesta GPT sin campos esperados');
        }

        $decision = $resp['decision'];
        $audit = $resp['audit'];

        if (is_array($decision)) {
            $decision = array(
                'id_carrier_reference' => isset($decision['id_carrier_reference']) ? (int) $decision['id_carrier_reference'] : 0,
                'carrier_name' => isset($decision['carrier_name']) ? (string) $decision['carrier_name'] : '',
                'price' => isset($decision['price']) ? (float) $decision['price'] : 0.0,
                'criteria' => isset($decision['criteria']) ? (string) $decision['criteria'] : '',
            );
        } else {
            // null -> SIN OPCIONES
            $decision = null;
        }

        return array('decision' => $decision, 'audit' => $audit);
    }
}
