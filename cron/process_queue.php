<?php

// require_once(dirname(__FILE__).'/../../../config/config.inc.php');
// require_once(dirname(__FILE__).'/../../../init.php');

//para sacar la localización de los archivos de otra forma
define('_PS_ROOT_DIR_', realpath(dirname(__FILE__) . '/../../..')); // modules/..../cron → raíz PS
require_once _PS_ROOT_DIR_ . '/config/config.inc.php';
require_once _PS_ROOT_DIR_ . '/init.php';

require_once(_PS_ROOT_DIR_ . '/classes/utils/LoggerFrik.php');
// require_once(dirname(__FILE__) . '/../classes/FrikDecisionEngine.php');

// Ruta de log
$logDir = _PS_MODULE_DIR_ . 'frikgestiontransportista/logs';
$logFile = $logDir . '/frikgt_' . date('Ymd') . '.log';
$logger = new LoggerFrik($logFile, false); // segundo parámetro true si quieres aviso email
$logger->log('== Inicio cron frikgestiontransportista ==', 'INFO', false);

//Contexto tienda (por si multitienda)
// if (method_exists('Shop','setContext') && Shop::isFeatureActive()) {
//     Shop::setContext(Shop::CONTEXT_ALL);
// }


$token = Tools::getValue('token');
$limit = (int) Tools::getValue('limit', 50);
$dry = (int) Tools::getValue('dry_run', 0);

$logger->log("Params: limit=$limit dry_run=$dry", 'DEBUG');

if ($token !== Configuration::get('FRIKGT_CRON_TOKEN')) {
    $logger->log("Bad token", 'ERROR');

    header('HTTP/1.1 403');
    die('Bad token');
}

//Lock anti-solape
$lock = _PS_CACHE_DIR_ . 'frikgt_process_queue.lock';
if (file_exists($lock) && (time() - filemtime($lock)) < 300) {
    $logger->log('Abortado: lock activo (otro proceso en curso)', 'WARNING');
    exit("Locked\n");
}
@touch($lock);


//Ejecución
try {
    $db = Db::getInstance();

    // Carga cola
    $sqlQueue = 'SELECT * FROM ' . _DB_PREFIX_ . 'frikgestiontransportista_order_queue
                 WHERE status="pending"
                 ORDER BY id_frikgestiontransportista_order_queue ASC
                 LIMIT ' . (int) $limit;
    $rows = $db->executeS($sqlQueue);
    $n = is_array($rows) ? count($rows) : 0;
    $logger->log("Pedidos pending en cola: $n", 'INFO');

    if (!$rows) {
        $logger->log('Sin pedidos que procesar', 'INFO');
        exit; // terminar limpio (el shutdown volcará)
    }

    foreach ($rows as $q) {
        $idq = (int) $q['id_frikgestiontransportista_order_queue'];
        $idOrder = (int) $q['id_order'];

        $logger->log("---- Procesando id_queue=$idq id_order=$idOrder ----", 'INFO');

        // Marcar processing
        $db->update(
            'frikgestiontransportista_order_queue',
            ['status' => 'processing', 'date_upd' => date('Y-m-d H:i:s')],
            'id_frikgestiontransportista_order_queue=' . (int) $idq . ' AND status="pending"'
        );
        $affected = method_exists($db, 'Affected_Rows') ? $db->Affected_Rows() : $db->affectedRows();
        if (!$affected) {
            $logger->log("Salto: otro proceso lo tomó (queue=$idq)", 'WARNING');
            continue;
        }

        try {
            // Contexto pedido
            $ctx = getCtx($idOrder);
            $logger->log('CTX: ' . json_encode($ctx), 'DEBUG');

            if (!$ctx) {
                throw new Exception('ctx vacío');
            }

            // Opciones tarifas
            $opts = getOpts($ctx['country'], $ctx['weight_kg']);
            $logger->log('OPTS: ' . json_encode($opts), 'DEBUG');

            if (!$opts) {
                throw new Exception('sin opciones');
            }

            // Motor reglas
            require_once dirname(__FILE__) . '/../classes/FrikDecisionEngine.php';
            $dec = FrikDecisionEngine::decide(
                $ctx,
                $opts,
                (float) Configuration::get('FRIKGT_GLS_MARGEN'),
                (float) Configuration::get('FRIKGT_GB_THRESHOLD')
            );
            $logger->log('DEC: ' . json_encode($dec), 'DEBUG');

            if (!$dec) {
                throw new Exception('sin decisión');
            }

            // Resolver id_carrier (actual y nuevo)
            $afterRef = (int) $dec['id_carrier_reference'];
            $afterName = $dec['carrier_name'];

            $afterId = (int) $db->getValue('SELECT id_carrier FROM ' . _DB_PREFIX_ . 'carrier
                                            WHERE id_reference=' . (int) $afterRef . ' AND deleted=0
                                            ORDER BY id_carrier DESC');
            $logger->log("Carrier destino: ref=$afterRef name=$afterName id=$afterId", 'DEBUG');

            if (!$afterId) {
                throw new Exception('carrier no encontrado para id_reference=' . $afterRef);
            }

            // Cambio (si no dry-run y si distinto del actual)
            if (!$dry && (int) $ctx['id_carrier'] !== $afterId) {
                $logger->log("changeCarrier(): {$ctx['id_carrier']} → $afterId", 'INFO');
                changeCarrier($idOrder, $afterId, $logger);
            } else {
                $logger->log('Sin cambio (dry-run o carrier igual)', 'INFO');
            }

            // Log de decisión
            logDec($idOrder, $ctx, $dec, $afterRef, $afterName, $logger);

            // Marcar done
            $db->update(
                'frikgestiontransportista_order_queue',
                ['status' => 'done', 'date_upd' => date('Y-m-d H:i:s')],
                'id_frikgestiontransportista_order_queue=' . (int) $idq
            );
            $logger->log("Queue $idq → done", 'INFO');

        } catch (Exception $e) {
            $logger->log("ERROR pedido $idOrder: " . $e->getMessage(), 'ERROR');
            $db->update(
                'frikgestiontransportista_order_queue',
                ['status' => 'error', 'reason' => pSQL($e->getMessage()), 'date_upd' => date('Y-m-d H:i:s')],
                'id_frikgestiontransportista_order_queue=' . (int) $idq
            );
        }
    }

} catch (Exception $e) {
    $logger->log('ERROR global: ' . $e->getMessage(), 'ERROR');
} finally {
    @unlink($lock);
    $logger->log('== Fin cron frikgestiontransportista ==', 'INFO');
}


/* ================= helpers ================= */

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
        WHERE active=1 AND country_iso="' . pSQL($country) . '"
          AND ' . (float) $weight . ' >= weight_min_kg AND ' . (float) $weight . ' < weight_max_kg
        ORDER BY avg_price_eur ASC';

    return Db::getInstance()->executeS($sql);
}

function changeCarrier($idOrder, $idCarrier, LoggerFrik $logger = null)
{
    $order = new Order((int) $idOrder);
    if (!Validate::isLoadedObject($order)) {
        throw new Exception('order not found');
    }

    $db = Db::getInstance();
    $db->execute('START TRANSACTION');
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
        if ($logger)
            $logger->log('changeCarrier() ROLLBACK: ' . $e->getMessage(), 'ERROR');
        $db->execute('ROLLBACK');
        throw $e;
    }
}

function logDec($idOrder, $ctx, $dec, $afterRef, $afterName, LoggerFrik $logger = null)
{
    $ok = Db::getInstance()->insert('frikgestiontransportista_decision_log', [
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
    if ($logger)
        $logger->log('logDec(): insert ' . ($ok ? 'OK' : 'FAIL'), $ok ? 'DEBUG' : 'ERROR');
}