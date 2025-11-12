<?php
define('_PS_ROOT_DIR_', dirname(dirname(dirname(__FILE__))));
require_once _PS_ROOT_DIR_ . '/config/config.inc.php';
require_once _PS_ROOT_DIR_ . '/init.php';


$token = Tools::getValue('token');
if ($token !== Configuration::get(FrikGestionTransportista::CFG_CRON_TOKEN)) {
    header('HTTP/1.1 403');
    die('Bad token');
}
$limit = (int) Tools::getValue('limit', 50);
$dry = (int) Tools::getValue('dry_run', 0);


$db = Db::getInstance();
$rows = $db->executeS('SELECT * FROM ' . _DB_PREFIX_ . 'frikgestiontransportista_order_queue WHERE status="pending" ORDER BY id_queue ASC LIMIT ' . (int) $limit);


foreach ($rows as $q) {
    $idq = (int) $q['id_queue'];
    $idOrder = (int) $q['id_order'];
    $db->update('frikgestiontransportista_order_queue', ['status' => 'processing', 'date_upd' => date('Y-m-d H:i:s')], 'id_queue=' . (int) $idq . ' AND status="pending"');
    if (!$db->Affected_Rows())
        continue;


    try {
        $ctx = getCtx($idOrder);
        if (!$ctx)
            throw new Exception('ctx vacío');
        $opts = getOpts($ctx['country'], $ctx['weight_kg']);
        if (!$opts)
            throw new Exception('sin opciones');
        $dec = FrikDecisionEngine::decide(
            $ctx,
            $opts,
            (float) Configuration::get(FrikGestionTransportista::CFG_PREFER_GLS_DELTA),
            (float) Configuration::get(FrikGestionTransportista::CFG_GB_THRESHOLD)
        );
        if (!$dec)
            throw new Exception('sin decisión');


        // Resolver id_carrier por id_reference
        $afterRef = (int) $dec['id_carrier_reference'];
        $afterName = $dec['carrier_name'];
        $afterId = (int) $db->getValue('SELECT id_carrier FROM ' . _DB_PREFIX_ . 'carrier WHERE id_reference=' . (int) $afterRef . ' AND deleted=0 ORDER BY id_carrier DESC');
        if (!$afterId)
            throw new Exception('carrier no encontrado para id_reference=' . $afterRef);


        if (!$dry && (int) $ctx['id_carrier'] !== $afterId) {
            changeCarrier($idOrder, $afterId);
        }
        logDec($idOrder, $ctx, $dec, $afterRef, $afterName);
        $db->update('frikgestiontransportista_order_queue', ['status' => 'done', 'date_upd' => date('Y-m-d H:i:s')], 'id_queue=' . (int) $idq);
    } catch (Exception $e) {
        $db->update('frikgestiontransportista_order_queue', ['status' => 'error', 'reason' => pSQL($e->getMessage()), 'date_upd' => date('Y-m-d H:i:s')], 'id_queue=' . (int) $idq);
    }
}

function getCtx($idOrder)
{
    $sql = 'SELECT o.id_order, o.total_paid AS total_paid_eur, o.id_carrier,
co.iso_code AS country, IFNULL(c.name, CONCAT("carrier#",o.id_carrier)) AS carrier_name,
c.id_reference AS id_carrier_reference,
ROUND(SUM(od.product_weight*od.product_quantity),3) AS weight_kg
FROM ' . _DB_PREFIX_ . 'orders o
JOIN ' . _DB_PREFIX_ . 'order_detail od ON od.id_order=o.id_order
JOIN ' . _DB_PREFIX_ . 'address ad ON ad.id_address=o.id_address_delivery
JOIN ' . _DB_PREFIX_ . 'country co ON co.id_country=ad.id_country
LEFT JOIN ' . _DB_PREFIX_ . 'carrier c ON c.id_carrier=o.id_carrier
WHERE o.id_order=' . (int) $idOrder . ' GROUP BY o.id_order';
    return Db::getInstance()->getRow($sql);
}


function getOpts($country, $weight)
{
    $sql = 'SELECT id_carrier_reference, carrier_name, country_iso, weight_min_kg, weight_max_kg, avg_price_eur
FROM ' . _DB_PREFIX_ . 'frikgestiontransportista_carrier_rates
WHERE active=1 AND country_iso="' . pSQL($country) . '" AND ' . (float) $weight . ' >= weight_min_kg AND ' . (float) $weight . ' < weight_max_kg';
    return Db::getInstance()->executeS($sql);
}


function changeCarrier($idOrder, $idCarrier)
{
    $order = new Order((int) $idOrder);
    if (!Validate::isLoadedObject($order))
        throw new Exception('order not found');
    $db = Db::getInstance();
    $db->execute('BEGIN');
    try {
        $db->update('orders', ['id_carrier' => (int) $idCarrier], 'id_order=' . (int) $idOrder);
        $oc = new OrderCarrier();
        $oc->id_order = $idOrder;
        $oc->id_carrier = $idCarrier;
        $oc->id_customer = (int) $order->id_customer;
        $oc->weight = (float) $order->getTotalWeight();
        $oc->shipping_cost_tax_excl = 0;
        $oc->shipping_cost_tax_incl = 0;
        $oc->add();
        $db->execute('COMMIT');
    } catch (Exception $e) {
        $db->execute('ROLLBACK');
        throw $e;
    }
}

function logDec($idOrder, $ctx, $dec, $afterRef, $afterName)
{
    Db::getInstance()->insert('frikgestiontransportista_decision_log', [
        'id_order' => (int) $idOrder,
        'country_iso' => pSQL($ctx['country']),
        'weight_kg' => (float) $ctx['weight_kg'],
        'total_paid_eur' => (float) $ctx['total_paid_eur'],
        'id_carrier_reference_before' => (int) $ctx['id_carrier_reference'],
        'carrier_before' => pSQL($ctx['carrier_name']),
        'id_carrier_reference_after' => (int) $afterRef,
        'carrier_after' => pSQL($afterName),
        'price_selected_eur' => (float) $dec['price'],
        'engine' => pSQL('rules'),
        'criteria' => pSQL($dec['criteria']),
        'explanations_json' => null,
        'email_sent' => 0,
        'date_add' => date('Y-m-d H:i:s')
    ]);
}