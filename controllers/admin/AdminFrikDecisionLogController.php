<?php
class AdminFrikDecisionLogController extends ModuleAdminController
{
    public function __construct()
    {
        $this->table = 'frikgestiontransportista_decision_log';
        $this->identifier = 'id_frikgestiontransportista_decision_log';
        $this->className = 'ObjectModel';
        $this->bootstrap = true;
        $this->lang = false;
        $this->list_id = 'frikgt_logs';


        // ===== Listado + filtros =====
        $this->fields_list = [
            'id_frikgestiontransportista_decision_log' => ['title' => 'ID', 'align' => 'center', 'width' => 40],
            'date_add' => ['title' => 'Fecha', 'type' => 'datetime', 'filter_key' => 'a!date_add'],
            'id_order' => [
                'title' => 'Pedido',
                'filter_key' => 'a!id_order',
                'callback' => 'renderOrderLink', // lo hacemos clicable para enviar a la ficha de pedido
                'width' => 70,
                'align' => 'center',
            ],
            'country_iso' => [
                'title' => 'País',
                'filter_key' => 'a!country_iso',
                'width' => 50,
                'align' => 'center',
            ],
            'weight_kg' => [
                'title' => 'Peso (kg)',
                'width' => 80,
                'align' => 'right',
            ],
            'total_paid_eur' => [
                'title' => 'Total (€)',
                'width' => 90,
                'align' => 'right',
            ],
            'suppliers_summary' => ['title' => 'Proveedores (pedido)'],

            'carrier_before' => ['title' => 'Carrier antes'],
            'carrier_after' => ['title' => 'Carrier después'],

            'price_selected_eur' => [
                'title' => 'Precio elegido (€)',
                'width' => 110,
                'align' => 'right',
            ],

            // 'engine' => [
            //     'title' => 'Motor',
            //     'type' => 'select',
            //     'list' => [
            //         ['id' => 'rules', 'name' => 'rules'],
            //         ['id' => 'gpt', 'name' => 'gpt'],
            //     ],
            //     'filter_key' => 'a!engine',
            //     'width' => 70,
            // ],

            'criteria' => ['title' => 'Criterio'],

            'explanations_json' => [
                'title' => 'Auditoría',
                'callback' => 'renderAuditPreview',
                'search' => false,
                'orderby' => false,
                'width' => 90,
                'align' => 'center',
            ],

            'is_test' => ['title' => 'Test', 'type' => 'bool'],
            // 'applied_by_name' => ['title' => 'Aplicado por'],
            // 'applied_at' => ['title' => 'Aplicado en', 'type' => 'datetime'],
        ];


        // ===== SELECT explícito (evita SELECT , FROM ...) =====
        $this->explicitSelect = true;
        $this->_select =
            'a.`id_frikgestiontransportista_decision_log`, a.`date_add`, a.`id_order`, a.`country_iso`, ' .
            'a.`weight_kg`, a.`total_paid_eur`, ' .
            'a.`carrier_before`, a.`carrier_after`, a.`price_selected_eur`, ' .
            // 'a.`engine`, 
            'a.`criteria`, a.`suppliers_summary`, a.`is_test`, a.`explanations_json`';
        // 'a.`applied_by_name`, a.`applied_at`';


        // ===== Paginación y orden =====
        $this->_orderBy = 'id_frikgestiontransportista_decision_log';
        $this->_orderWay = 'DESC';
        $this->_default_pagination = 50; // tamaño por defecto
        $this->_pagination = array(20, 50, 100, 200, 500); // opciones de usuario

        $this->addRowAction('apply');

        $this->list_no_link = true;
        $this->bulk_actions = [
            'delete' => ['text' => $this->l('Borrar seleccionados'), 'confirm' => $this->l('¿Seguro?')]
        ];


        parent::__construct();


        // Botones de cabecera. En __construct, self::$currentIndex no parece que exista así que montamos la url
        $back_url = 'index.php?controller=' . $this->controller_name . '&token=' . $this->token;
        $this->page_header_toolbar_btn['export'] = [
            'href' => $back_url . '&exportLogs=1&token=' . $this->token,
            'desc' => $this->l('Exportar CSV'),
            'icon' => 'process-icon-export', // añade icono
        ];

        //por ahora no muestro botón para eliminar líneas
        // $this->page_header_toolbar_btn['purge'] = [
        //     'href' => $back_url . '&purgeOld=1&days=90&token=' . $this->token,
        //     'desc' => $this->l('Purgar 90+ días'), // evita el símbolo '>'
        //     'icon' => 'process-icon-eraser',       // icono de borrar/limpiar
        // ];
    }

    public function displayApplyLink($token = null, $id, $name = null)
    {
        $row = Db::getInstance()->getRow('
            SELECT is_test, applied_at
            FROM ' . _DB_PREFIX_ . 'frikgestiontransportista_decision_log
            WHERE id_frikgestiontransportista_decision_log=' . (int) $id
        );
        if (!$row) {
            return '';
        }

        // Solo mostrar si es dry-run y aún no aplicado
        if ((int) $row['is_test'] !== 1 || !empty($row['applied_at'])) {
            return '';
        }

        $href = self::$currentIndex
            . '&' . $this->identifier . '=' . (int) $id
            . '&applyChange=1'
            . '&token=' . $this->token;

        return '<a class="btn btn-warning" href="' . $href . '">'
            . $this->l('Aplicar cambio')
            . '</a>';
    }

    public function renderOrderLink($value, $row)
    {
        // Asegura entero y crea URL segura con token
        $idOrder = (int) $value;
        $url = $this->context->link->getAdminLink('AdminOrders')
            . '&id_order=' . $idOrder
            . '&vieworder=1';

        return '<a class="btn btn-default btn-sm"'
            . ' href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'
            . ' title="' . $this->l('Abrir pedido') . '"'
            . ' target="_blank" rel="noopener noreferrer">'
            . '    <i class="icon-search"></i> ' . $idOrder
            . '</a>';
    }

    //para mostral un modal con el contenido de audit
    public function renderAuditPreview($value, $row)
    {
        $id = (int) $row['id_frikgestiontransportista_decision_log'];
        $raw = (string) $value;

        // Pretty-print si es JSON válido (mantén tildes y / sin escapar)
        $pretty = $raw;
        $arr = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $pretty = json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // Escapar para HTML pero respetando formato en <pre>
        $safe = htmlspecialchars($pretty, ENT_QUOTES, 'UTF-8');

        $modalId = 'auditModal_' . $id;

        return '
            <button type="button" class="btn btn-info btn-xs" data-toggle="modal" data-target="#' . $modalId . '">
                ' . $this->l('Ver') . '
            </button>

            <div class="modal fade" id="' . $modalId . '" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog" style="width:900px;max-width:95%;">
                <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="' . $this->l('Cerrar') . '">
                    <span aria-hidden="true">&times;</span>
                    </button>
                    <h4 class="modal-title">' . $this->l('Auditoría / explanations_json') . ' — #' . $id . '</h4>
                </div>
                <div class="modal-body" style="text-align:left; max-height:70vh; overflow:auto;">
                    <pre style="
                        font-family: Menlo, Monaco, Consolas, \'Courier New\', monospace;
                        font-size: 13px;
                        line-height: 1.5;
                        white-space: pre;        /* respeta indentación exacta */
                        background:#f7f7f7;
                        color:#333;
                        padding:12px 14px;
                        border:1px solid #ddd;
                        border-radius:4px;
                        margin:0;">
                    ' . $safe . '
                    </pre>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">' . $this->l('Cerrar') . '</button>
                </div>
                </div>
            </div>
            </div>';
    }

    //para que los botones del header para exportar el csv y para purgar la tabla funcionen, no se los recoge con postProcess() porque son GET y no POST, así que se recogen en initContent() que si  recoge los botones GET
    public function initContent()
    {
        if (Tools::isSubmit('exportLogs')) {
            $this->processExportCSV(); // ¡debe hacer exit/die al final!
            return;
        }

        if (Tools::isSubmit('purgeOld')) {
            $days = (int) Tools::getValue('days', 90);
            $this->processPurge($days);
            Tools::redirectAdmin(self::$currentIndex . '&conf=4&token=' . $this->token);            
            return;
        }

        parent::initContent();
    }

    public function postProcess()
    {
        parent::postProcess();

        if (Tools::getIsset('applyChange'))
            $this->processApplyChange((int) Tools::getValue($this->identifier));
    }

    protected function processApplyChange($idLog)
    {
        $log = Db::getInstance()->getRow('
            SELECT * FROM ' . _DB_PREFIX_ . 'frikgestiontransportista_decision_log
            WHERE ' . $this->identifier . '=' . (int) $idLog
        );
        if (!$log) {
            $this->errors[] = $this->l('Log no encontrado.');
            return;
        }
        if ((int) $log['is_test'] !== 1) {
            $this->errors[] = $this->l('Este registro no es de prueba o ya fue aplicado.');
            return;
        }

        $idOrder = (int) $log['id_order'];
        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order)) {
            $this->errors[] = $this->l('Pedido no válido.');
            return;
        }

        $afterRef = (int) $log['id_carrier_reference_after'];
        $afterId = (int) Db::getInstance()->getValue('
            SELECT id_carrier
            FROM ' . _DB_PREFIX_ . 'carrier
            WHERE id_reference=' . (int) $afterRef . ' AND deleted=0
            ORDER BY id_carrier DESC
        ');
        if (!$afterId) {
            $this->errors[] = $this->l('No se encontró carrier activo para la referencia indicada.');
            return;
        }

        $employee = Context::getContext()->employee;
        $empId = $employee ? (int) $employee->id : null;
        $empName = $employee ? $employee->firstname . ' ' . $employee->lastname : $this->l('Desconocido');

        $db = Db::getInstance();
        $db->execute('START TRANSACTION');
        try {
            if ((int) $order->id_carrier !== $afterId) {

                // 1) Cambiar en la tabla orders
                $db->update('orders', ['id_carrier' => (int) $afterId], 'id_order=' . (int) $idOrder);

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
                    $oc->id_carrier = (int) $afterId;
                    // actualiza peso/costes si corresponde
                    $oc->weight = (float) $order->getTotalWeight();
                    // Mantén los shipping_cost_* si no recalculas
                    if ($oc->update() === false) {
                        throw new Exception('failed to update order_carrier');
                    }
                } else {
                    // 3B) Si no existía fila, la creamos (caso raro)
                    $oc = new OrderCarrier();
                    $oc->id_order = (int) $idOrder;
                    $oc->id_carrier = (int) $afterId;
                    $oc->weight = (float) $order->getTotalWeight();
                    $oc->shipping_cost_tax_excl = 0;
                    $oc->shipping_cost_tax_incl = 0;
                    if (!$oc->add()) {
                        throw new Exception('failed to insert order_carrier');
                    }
                }

            }

            // Marca como aplicado + traza
            $db->update('frikgestiontransportista_decision_log', [
                'is_test' => 0,
                'applied_by_id_employee' => $empId,
                'applied_by_name' => pSQL($empName),
                'applied_at' => date('Y-m-d H:i:s'),
                'criteria' => pSQL($log['criteria'] . ' | Aplicado desde Logs'),
            ], $this->identifier . '=' . (int) $idLog);

            $db->execute('COMMIT');

            // Nota privada en el pedido (tra-za visible para el equipo)
            if (class_exists('Message')) {
                $m = new Message();
                $m->id_order = $idOrder;
                $m->private = 1;
                $m->id_employee = (int) $empId;
                $m->message = sprintf(
                    "Cambio de transportista aplicado desde Logs por %s.\nAntes: %s | Después: %s.\nCriterio: %s\n" . date("d-m-Y H:i:s"),
                    $empName,
                    $log['carrier_before'],
                    $log['carrier_after'],
                    $log['criteria']
                );
                if (!$m->add()) {
                    $this->errors[] = $this->l('No se pudo guardar la nota interna del pedido.');
                }
            }

            $this->confirmations[] = $this->l('Cambio de transportista aplicado correctamente.');
        } catch (Exception $e) {
            $db->execute('ROLLBACK');
            $this->errors[] = $this->l('Error aplicando el cambio: ') . $e->getMessage();
        }
    }



    protected function processExportCSV()
    {
        // Exporta respetando filtros simples (id_order, country_iso, engine) y rango de fechas si vienen en request
        $where = 'WHERE 1';
        $id_order = (int) Tools::getValue('frikgt_logsFilter_a!id_order');
        $country = trim(Tools::getValue('frikgt_logsFilter_a!country_iso'));
        // $engine = trim(Tools::getValue('frikgt_logsFilter_a!engine'));
        $date_from = Tools::getValue('frikgt_logsFilter_a!date_add[0]');
        $date_to = Tools::getValue('frikgt_logsFilter_a!date_add[1]');
        if ($id_order)
            $where .= ' AND a.id_order=' . (int) $id_order;
        if ($country !== '')
            $where .= " AND a.country_iso='" . pSQL($country) . "'";
        // if ($engine !== '')
        //     $where .= " AND a.engine='" . pSQL($engine) . "'";
        if ($date_from)
            $where .= " AND a.date_add >= '" . pSQL($date_from) . " 00:00:00'";
        if ($date_to)
            $where .= " AND a.date_add <= '" . pSQL($date_to) . " 23:59:59'";


        $rows = Db::getInstance()->executeS(
            'SELECT a.* FROM ' . _DB_PREFIX_ . 'frikgestiontransportista_decision_log a '
            . $where . ' ORDER BY a.id_frikgestiontransportista_decision_log DESC'
        );
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=frikgestiontransportista_logs_' . date('Ymd_His') . '.csv');

        $out = fopen('php://output', 'w');
        // BOM + separador sugerido
        fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fwrite($out, "sep=;\n");

        if (!empty($rows))
            fputcsv($out, array_keys($rows[0]), ';');
        foreach ($rows as $r)
            fputcsv($out, $r, ';');
        fclose($out);
        exit;
    }


    protected function processPurge($days)
    {        
        // $days = max(1, (int) $days);
        // Db::getInstance()->execute(
        //     'DELETE FROM ' . _DB_PREFIX_ . 'frikgestiontransportista_decision_log '
        //     . 'WHERE date_add < DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)'
        // );
        // $this->confirmations[] = sprintf($this->l('Purgados logs con más de %d días.'), $days);
    }
}