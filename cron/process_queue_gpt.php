<?php
// https://lafrikileria.com/modules/frikgestiontransportista/cron/process_queue_gpt.php?token=5O6SGJHL7s2jig11wcKevktH&limit=50&dry_run=1

define('_PS_ROOT_DIR_', realpath(dirname(__FILE__) . '/../../..'));
require_once _PS_ROOT_DIR_ . '/config/config.inc.php';
require_once _PS_ROOT_DIR_ . '/init.php';

require_once dirname(__FILE__) . '/../classes/FrikGptDecisionEngine.php';
require_once _PS_ROOT_DIR_ . '/classes/utils/LoggerFrik.php';

$logger = new LoggerFrik(_PS_MODULE_DIR_ . 'frikgestiontransportista/logs/cron_gpt_' . date('Ymd') . '.txt');
$logger->log('================================================', 'INFO', false);
$logger->log('=== Inicio cron GPT frikgestiontransportista ===', 'INFO', false);

$token = Tools::getValue('token');
$limit = (int) Tools::getValue('limit', 50);
$dry = (int) Tools::getValue('dry_run', 0);

$logger->log("Params: limit=$limit dry_run=$dry token=$token", 'DEBUG', false);

if ($token !== Configuration::get('FRIKGT_CRON_TOKEN')) {
    $logger->log("Bad token", 'ERROR');
    header('HTTP/1.1 403');
    die('Bad token');
}

/** === AQUI BUSCAMOS NUEVOS PEDIDOS === */
$rescue_window_h = (int) Tools::getValue('rescue_window_h', 24);   // ventana lookback, en horas. 24 si no viene en la url
$rescue_limit = (int) Tools::getValue('rescue_limit', 100);    // máximo a encolar por pasada
$inserted = enqueueNewOrders($rescue_window_h, $rescue_limit, $logger);
$logger->log("Pedidos encolados: " . $inserted, 'INFO', $inserted ? true : false);
/** =============================================== */

//DEBUG por si quiero ver los parámetros enviados etc en el txt de log
$debug = false;

// Lock anti-solape
$db = Db::getInstance();

$lockName = pSQL(_DB_NAME_.'_lafrips_frikgt_gpt_lock'); // ej: lafrips_frikgt_gpt_lock
$got = (int)$db->getValue('SELECT GET_LOCK("'.$lockName.'", 1)');
if ($got !== 1) {
    $logger->log('LOCK "'.$lockName.'" detectado, otro proceso GPT en ejecución. Salimos', 'WARNING');
    exit;
}

try {
    $sqlQueue = 'SELECT * FROM ' . _DB_PREFIX_ . 'frikgestiontransportista_order_queue
                 WHERE status="pending"
                 ORDER BY id_frikgestiontransportista_order_queue ASC
                 LIMIT ' . (int) $limit;
    $rows = $db->executeS($sqlQueue);

    if ($rows === false) {
        $logger->log('No encontrados pedidos en estado "pending"', 'WARNING', false);
    } else {
        foreach ($rows as $q) {
            $idq = (int) $q['id_frikgestiontransportista_order_queue'];
            $idOrder = (int) $q['id_order'];

            $db->update(
                'frikgestiontransportista_order_queue',
                array('status' => 'processing', 'date_upd' => date('Y-m-d H:i:s')),
                'id_frikgestiontransportista_order_queue=' . (int) $idq . ' AND status="pending"'
            );
            $affected = method_exists($db, 'Affected_Rows') ? $db->Affected_Rows() : $db->affectedRows();
            if (!$affected) {
                $logger->log('No encontrado en estado "pending" el pedido id_order = ' . $idOrder, 'WARNING');

                continue;
            }

            $logger->log('Pedido id_order = ' . $idOrder . ' actualizado como "processing"', 'WARNING');

            try {
                $order_info = getOrderInfo($idOrder);
                if (!$order_info) {
                    $logger->log('Error obteniendo información del pedido id_order = ' . $idOrder, 'ERROR');

                }

                $options = getOptions($order_info['country'], $order_info['weight_kg']);
                if (!$options) {
                    $logger->log('Error obteniendo opciones de envío para pedido id_order = ' . $idOrder . ' - country_iso: ' . $order_info['country'] . ' - weight_kg = ' . $order_info['weight_kg'], 'ERROR');

                }

                // Vars para el prompt desde config
                $vars = array(
                    'gb_threshold' => (string) (Configuration::get('FRIKGT_GB_THRESHOLD') ?: '100.00'),
                    'margen_gls' => (string) (Configuration::get('FRIKGT_GLS_MARGEN') ?: '0.50')
                );

                if ($debug) {
                    $logger->log(
                        'Parámetros de petición de decisión para id_order = ' . $idOrder . ":\n" .
                        json_encode(
                            [
                                'order_info' => $order_info,
                                'options' => $options,
                                'vars' => $vars
                            ],
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                        ),
                        'DEBUG'
                    );
                }

                $respuesta = FrikGptDecisionEngine::decide($order_info, $options, $vars);

                if (isset($respuesta['error'])) {
                    $logger->log('Error al solicitar decisión para pedido id_order = ' . $idOrder . ' - Error: ' . $respuesta['error'], 'ERROR');

                    if (isset($respuesta['post'])) {
                        $logger->log(
                            'Parámetros POST:\n' .
                            json_encode(
                                [
                                    'post' => $respuesta['post']
                                ],
                                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                            ),
                            'DEBUG'
                        );
                    }

                    if (isset($respuesta['respuesta'])) {
                        $logger->log(
                            'Respuesta:\n' .
                            json_encode(
                                [
                                    'respuesta' => $respuesta['respuesta']
                                ],
                                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                            ),
                            'DEBUG'
                        );
                    }
                }

                $decision = $respuesta['decision']; // null | array               

                if ($decision === null) {
                    // sin opciones válidas
                    $logger->log('Sin opciones válidas para pedido id_order = ' . $idOrder, 'WARNING');

                    logDec($idOrder, $order_info, array(
                        'id_carrier_reference' => (int) $order_info['id_carrier_reference'],
                        'carrier_name' => (string) $order_info['carrier_name'],
                        'price' => 0.0,
                        'criteria' => 'SIN OPCIONES'
                    ), 'gpt', $respuesta, $dry ? 1 : 0);

                    // OJO aquí pasamos estado a done, pero si dry=1 en realidad no hemos hecho el cambio
                    $db->update(
                        'frikgestiontransportista_order_queue',
                        array('status' => 'done', 'date_upd' => date('Y-m-d H:i:s')),
                        'id_frikgestiontransportista_order_queue=' . (int) $idq
                    );
                    continue;
                }

                if ($debug) {
                    $logger->log(
                        'Decisión recibida para id_order = ' . $idOrder . ":\n" .
                        json_encode(
                            [
                                'decision' => $decision
                            ],
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                        ),
                        'DEBUG'
                    );
                }                

                // Resolver id_carrier real
                $afterRef = (int) $decision['id_carrier_reference'];
                $afterName = (string) $decision['carrier_name'];
                $afterId = (int) $db->getValue(
                    'SELECT id_carrier FROM ' . _DB_PREFIX_ . 'carrier WHERE id_reference=' . (int) $afterRef . ' AND deleted=0 ORDER BY id_carrier DESC'
                );
                if (!$afterId) {
                    $logger->log('Error, transportista no encontrado para id_reference=' . $afterRef . ' para el pedido id_order = ' . $idOrder, 'ERROR');
                }

                $error = 0;
                //si el carrier es diferente y si el proceso no es dry, se aplica el cambio, si no es test
                if (!$dry && (int) $order_info['id_carrier'] !== $afterId) {
                    $logger->log('No Test y transportista origen diferente de destino, aplicando cambio de transportista para el pedido id_order = ' . $idOrder, 'INFO');

                    $res = changeCarrier($idOrder, $afterId, $order_info, $decision);

                    if ($res === true) {
                        $logger->log('Pedido ' . $idOrder . ' cambiado de transporte correctamente', 'INFO');
                    } else {
                        $error = 1;
                        $logger->log('Error al cambiar de transporte a pedido id_order = ' . $idOrder . ' - Error: ' . $res['error'], 'ERROR');
                    }
                }

                $status = 'done';
                if ($error) {
                    $status = 'error';
                }

                logDec($idOrder, $order_info, $decision, 'gpt', $respuesta, $dry ? 1 : 0);

                $db->update(
                    'frikgestiontransportista_order_queue',
                    array('status' => $status, 'date_upd' => date('Y-m-d H:i:s')),
                    'id_frikgestiontransportista_order_queue=' . (int) $idq
                );

            } catch (Exception $e) {
                $logger->log('Error pedido ' . $idOrder . ': ' . $e->getMessage(), 'ERROR');
                $db->update(
                    'frikgestiontransportista_order_queue',
                    array('status' => 'error', 'reason' => pSQL($e->getMessage()), 'date_upd' => date('Y-m-d H:i:s')),
                    'id_frikgestiontransportista_order_queue=' . (int) $idq
                );
            }
        }
    }

} finally {
    $rel = $db->getValue('SELECT RELEASE_LOCK("'.$lockName.'")');
    // 1=liberado, 0=no eras el dueño, NULL=no existía
    $logger->log('RELEASE_LOCK  "'.$lockName.'" result: '.var_export($rel,true), 'DEBUG', false);
    $logger->log('=== Fin cron GPT frikgestiontransportista ===', 'INFO', false);
}

// ===== helpers =====
function getOrderInfo($idOrder)
{
    $sql = "SELECT 
        o.id_order,
        o.total_paid AS total_paid_eur,        
        o.id_carrier,
        co.iso_code AS country,
        IFNULL(c.name, CONCAT('carrier#', o.id_carrier)) AS carrier_name,
        c.id_reference AS id_carrier_reference,
        ROUND(SUM(od.product_weight * od.product_quantity), 3) AS weight_kg,    
        GROUP_CONCAT(DISTINCT pl.name ORDER BY pl.name SEPARATOR ' || ') AS productos,    
        GROUP_CONCAT(
            DISTINCT COALESCE(LOWER(TRIM(s.name)), 'sin proveedor')
            ORDER BY LOWER(TRIM(s.name))
            SEPARATOR ' || '
        ) AS proveedores
    FROM lafrips_orders o
    JOIN lafrips_order_detail od 
        ON od.id_order = o.id_order
    LEFT JOIN lafrips_product p
        ON p.id_product = od.product_id
    LEFT JOIN lafrips_product_lang pl 
        ON pl.id_product = od.product_id 
        AND pl.id_lang = 1   
    LEFT JOIN lafrips_supplier s
        ON s.id_supplier = p.id_supplier
    JOIN lafrips_address ad 
        ON ad.id_address = o.id_address_delivery
    JOIN lafrips_country co 
        ON co.id_country = ad.id_country
    LEFT JOIN lafrips_carrier c 
        ON c.id_carrier = o.id_carrier
    WHERE o.id_order = " . (int) $idOrder . "
    GROUP BY o.id_order";

    return Db::getInstance()->getRow($sql);
}

function getOptions($country, $weight)
{
    $sql = 'SELECT id_carrier_reference, carrier_name, country_iso, weight_min_kg, weight_max_kg, avg_price_eur
        FROM ' . _DB_PREFIX_ . 'frikgestiontransportista_carrier_rates
        WHERE active = 1 AND country_iso = "' . pSQL($country) . '"
          AND ' . (float) $weight . ' >= weight_min_kg
          AND ' . (float) $weight . ' <  weight_max_kg';
    return Db::getInstance()->executeS($sql);
}

function changeCarrier($idOrder, $newIdCarrier, $order_info, $decision)
{
    $order = new Order((int) $idOrder);
    if (!Validate::isLoadedObject($order)) {
        return ['error' => 'Pedido no encontrado'];
    }

    $db = Db::getInstance();
    $db->execute('START TRANSACTION');

    try {
        // 1) Cambiar en la tabla orders
        $db->update('orders', ['id_carrier' => (int) $newIdCarrier], 'id_order=' . (int) $idOrder);

        // 2) Localizar la fila "actual" de order_carrier (la más reciente)
        $idOrderCarrier = (int) $db->getValue('
            SELECT id_order_carrier
            FROM ' . _DB_PREFIX_ . 'order_carrier
            WHERE id_order=' . (int) $idOrder . '
            ORDER BY id_order_carrier DESC
        ');

        if ($idOrderCarrier) {
            // 3A) Actualizar la fila existente
            $oc = new OrderCarrier($idOrderCarrier);
            $oc->id_carrier = (int) $newIdCarrier;
            // actualiza peso/costes si corresponde
            $oc->weight = (float) $order->getTotalWeight();
            // Mantén los shipping_cost_* si no recalculas
            if ($oc->update() === false) {
                throw new Exception('Error haciendo update a order_carrier');
            }
        } else {
            // 3B) Si no existía fila, la creamos (caso raro)
            $oc = new OrderCarrier();
            $oc->id_order = (int) $idOrder;
            $oc->id_carrier = (int) $newIdCarrier;
            $oc->weight = (float) $order->getTotalWeight();
            $oc->shipping_cost_tax_excl = 0;
            $oc->shipping_cost_tax_incl = 0;
            if (!$oc->add()) {
                throw new Exception('Error insertando order_carrier');
            }
        }

        $db->execute('COMMIT');

        $m = new Message();
        $m->id_order = $idOrder;
        $m->private = 1;
        $m->id_employee = 44;
        $m->message = sprintf(
            "Cambio de transportista aplicado automáticamente.\nAntes: %s | Después: %s.\nCriterio: %s\n" . date("d-m-Y H:i:s"),
            pSQL($order_info['carrier_name']),
            pSQL($decision['carrier_name']),
            pSQL($decision['criteria'])
        );

        if (!$m->add()) {
            throw new Exception('Error añadiendo mensaje a pedido');
        }

        return true;

    } catch (Exception $e) {
        $db->execute('ROLLBACK');
        return ['error' => 'Error cambiando transportista: ' . $e];
    }
}

function logDec($idOrder, $order_info, $decision, $engine, $audit, $isTest = 0)
{
    $suppliers = getSuppliersSummary($idOrder);

    Db::getInstance()->insert('frikgestiontransportista_decision_log', array(
        'id_order' => (int) $idOrder,
        'country_iso' => pSQL($order_info['country']),
        'weight_kg' => (float) $order_info['weight_kg'],
        'total_paid_eur' => (float) $order_info['total_paid_eur'],
        'id_carrier_reference_before' => (int) $order_info['id_carrier_reference'],
        'carrier_before' => pSQL($order_info['carrier_name']),
        'id_carrier_reference_after' => (int) $decision['id_carrier_reference'],
        'carrier_after' => pSQL($decision['carrier_name']),
        'price_selected_eur' => (float) $decision['price'],
        'engine' => pSQL($engine),
        'criteria' => pSQL($decision['criteria']),
        'suppliers_summary' => pSQL($suppliers),
        'explanations_json' => pSQL(json_encode($audit)),
        'email_sent' => 0,
        'is_test' => (int) $isTest,
        'date_add' => date('Y-m-d H:i:s')
    ));
}

/**
 * Lee los ISO2 desde la tabla de tarifas (distinct) y, si está vacía, cae al CFG_COUNTRIES.
 * Devuelve array de ISO2 en mayúsculas.
 */
function getCountriesFromRates()
{
    $rows = Db::getInstance()->executeS(
        'SELECT DISTINCT country_iso
         FROM ' . _DB_PREFIX_ . 'frikgestiontransportista_carrier_rates
         WHERE active=1
         ORDER BY country_iso'
    );
    $codes = array();
    foreach ($rows as $r) {
        $codes[] = Tools::strtoupper($r['country_iso']);
    }
    if (!$codes) {
        $cfg = trim((string) Configuration::get('FRIKGT_COUNTRIES'));
        if ($cfg !== '') {
            $codes = array_map(
                'Tools::strtoupper',
                array_filter(array_map('trim', explode(',', $cfg)))
            );
        }
    }
    return $codes;
}

/**
 * Encola pedidos “nuevos” (no presentes en la cola) que:
 *  - Estén dentro de la ventana temporal
 *  - Tengan estado en el CFG_STATES
 *  - Tengan país en la lista detectada de getCountriesFromRates()
 *  - (Opcional) Aquí puedes filtrar solo externos por módulo/reference si lo deseas
 *
 * @return int número de filas insertadas (aprox.; MariaDB no siempre devuelve afectadas con IGNORE)
 */
/*
SELECT  o.id_order, "pending", NOW()
   FROM lafrips_orders o
   JOIN lafrips_address ad ON ad.id_address = o.id_address_delivery
   JOIN lafrips_country co ON co.id_country = ad.id_country
   LEFT JOIN lafrips_frikgestiontransportista_order_queue q ON q.id_order = o.id_order
   WHERE q.id_order IS NULL
	 AND o.date_add >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
	 AND o.current_state IN (2,9,41)
	 AND co.iso_code IN ('AT','BE','BG','CH','CY','CZ','DE','DK','EE','FI','FR','GB','GR','HR','HU','IE','IT','LI','LT','LU','LV','MC','MT','NL','NO','PL','RO','RS','RU','SE','SI','SK','SM','TR','VA')
	 AND co.iso_code NOT IN ("ES", "ESC", "PT", "ESM", "AD")       
   ORDER BY o.date_add DESC
   LIMIT 100
*/
function enqueueNewOrders($windowHours, $maxRows, $logger = null)
{
    $db = Db::getInstance();

    // Estados desde config
    $states_csv = trim((string) Configuration::get('FRIKGT_STATES'));
    $states = array_map('intval', array_filter(explode(',', $states_csv)));
    if (!$states) {
        $states = array(2, 9, 41);
    } // fallback sensato

    // Países desde tarifas (o CFG)
    $countries = getCountriesFromRates();
    if (!$countries) {
        if ($logger)
            $logger->log('Sin países en lafrips_frikgestiontransportista_carrier_rates ni en lafrips_configuration, rescate omitido', 'ERROR');
        return 0;
    }

    // Construye IN(...)
    $states_in = implode(',', array_map('intval', $states));
    $countries_in = array();
    foreach ($countries as $c) {
        $countries_in[] = '"' . pSQL($c) . '"';
    }
    $countries_in_sql = implode(',', $countries_in);

    // Filtro opcional para “sólo externos” (dejar vacío si queremos todos)
    // $extern_where = ' AND o.module IN ("amazon","webservice") '; ???
    // $extern_where = ' AND o.reference LIKE "*****" ';
    $extern_where = '';

    // INSERT IGNORE idempotente
    $sql =
        'INSERT IGNORE INTO ' . _DB_PREFIX_ . 'frikgestiontransportista_order_queue (id_order, status, date_add)
       SELECT o.id_order, "pending", NOW()
       FROM ' . _DB_PREFIX_ . 'orders o
       JOIN ' . _DB_PREFIX_ . 'address ad ON ad.id_address = o.id_address_delivery
       JOIN ' . _DB_PREFIX_ . 'country co ON co.id_country = ad.id_country
       LEFT JOIN ' . _DB_PREFIX_ . 'frikgestiontransportista_order_queue q ON q.id_order = o.id_order
       WHERE q.id_order IS NULL
         AND o.date_add >= DATE_SUB(NOW(), INTERVAL ' . (int) $windowHours . ' HOUR)
         AND o.current_state IN (' . $states_in . ')
         AND co.iso_code IN (' . $countries_in_sql . ')
         AND co.iso_code NOT IN ("ES", "ESC", "PT", "ESM", "AD")
         ' . $extern_where . '
       ORDER BY o.date_add DESC
       LIMIT ' . (int) $maxRows;

    $ok = $db->execute($sql);
    // Nota: con INSERT IGNORE, affectedRows() puede no reflejar todo; devolvemos lo que sepamos
    $inserted = method_exists($db, 'Affected_Rows') ? (int) $db->Affected_Rows() : (int) $db->affectedRows();

    if ($logger) {
        // $logger->log('enqueueNewOrders() SQL: ' . $sql, 'DEBUG', false);
        $logger->log('enqueueNewOrders() ok=' . ($ok ? '1' : '0') . ' inserted=' . $inserted, 'DEBUG', $inserted ? true : false);
    }
    return $inserted;
}

//devuelve los proveedores de los productos en el pedido
function getSuppliersSummary($idOrder)
{
    $sql = 'SELECT GROUP_CONCAT(DISTINCT IFNULL(s.name, "Sin proveedor") ORDER BY s.name SEPARATOR ", ") AS suppliers
            FROM ' . _DB_PREFIX_ . 'order_detail od
            LEFT JOIN ' . _DB_PREFIX_ . 'product p ON p.id_product = od.product_id
            LEFT JOIN ' . _DB_PREFIX_ . 'supplier s ON s.id_supplier = p.id_supplier
            WHERE od.id_order = ' . (int) $idOrder;
    return (string) Db::getInstance()->getValue($sql);
}