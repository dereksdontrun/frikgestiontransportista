<?php
class AdminFrikGestionTransportistaController extends ModuleAdminController
{
    public function __construct()
    {
        $this->table = 'frikgestiontransportista_carrier_rates';
        $this->className = 'FrikCarrierRate';
        $this->bootstrap = true;
        $this->lang = false;
        parent::__construct();


        $this->fields_list = [
            'id_rate' => ['title' => 'ID', 'align' => 'center', 'width' => 30],
            'id_carrier_reference' => ['title' => 'id_reference'],
            'carrier_name' => ['title' => 'Transportista'],
            'country_iso' => ['title' => 'País'],
            'weight_min_kg' => ['title' => 'Peso mín. (kg)'],
            'weight_max_kg' => ['title' => 'Peso máx. (kg)'],
            'avg_price_eur' => ['title' => 'Tarifa media (€)'],
            'active' => ['title' => 'Activo', 'type' => 'bool', 'active' => 'status'],
            'date_upd' => ['title' => 'Actualizado']
        ];


        $this->addRowAction('edit');
        $this->addRowAction('delete');
        $this->bulk_actions = [
            'delete' => ['text' => $this->l('Borrar seleccionados'), 'confirm' => $this->l('¿Seguro?')],
        ];
    }

    public function renderForm()
    {
        $this->fields_form = [
            'legend' => ['title' => $this->l('Tarifa / Banda')],
            'input' => [
                ['type' => 'text', 'name' => 'id_carrier_reference', 'label' => $this->l('id_reference (carrier)'), 'required' => true, 'hint' => 'id estable del carrier'],
                ['type' => 'text', 'name' => 'carrier_name', 'label' => $this->l('Transportista (etiqueta)'), 'required' => true],
                ['type' => 'text', 'name' => 'country_iso', 'label' => $this->l('País (ISO2)'), 'required' => true, 'hint' => 'FR, IT, DE, GB...'],
                ['type' => 'text', 'name' => 'weight_min_kg', 'label' => $this->l('Peso mín. (kg)'), 'required' => true],
                ['type' => 'text', 'name' => 'weight_max_kg', 'label' => $this->l('Peso máx. (kg)'), 'required' => true],
                ['type' => 'text', 'name' => 'avg_price_eur', 'label' => $this->l('Tarifa media (€)'), 'required' => true],
                [
                    'type' => 'switch',
                    'name' => 'active',
                    'label' => $this->l('Activo'),
                    'values' => [
                        ['id' => 'on', 'value' => 1, 'label' => $this->l('Sí')],
                        ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]
                    ]
                ],
            ],
            'submit' => ['title' => $this->l('Guardar')]
        ];
        return parent::renderForm();
    }


    public function renderList()
    {
        $this->toolbar_btn['new'] = [
            'href' => self::$currentIndex . '&add' . $this->table . '&token=' . $this->token,
            'desc' => $this->l('Añadir')
        ];
        $html = parent::renderList();
        $html .= $this->renderConfigPanel();
        $html .= $this->renderCsvPanel();
        return $html;
    }

    protected function renderConfigPanel()
    {
        if (Tools::isSubmit('submitFrikCfg')) {
            Configuration::updateValue(FrikGestionTransportista::CFG_COUNTRIES, Tools::getValue('countries'));
            Configuration::updateValue(FrikGestionTransportista::CFG_STATES, Tools::getValue('states'));
            Configuration::updateValue(FrikGestionTransportista::CFG_PREFER_GLS_DELTA, Tools::getValue('gls_delta'));
            Configuration::updateValue(FrikGestionTransportista::CFG_GB_THRESHOLD, Tools::getValue('gb_threshold'));
            $this->confirmations[] = $this->l('Configuración guardada');
        }
        $countries = Configuration::get(FrikGestionTransportista::CFG_COUNTRIES);
        $states = Configuration::get(FrikGestionTransportista::CFG_STATES);
        $glsDelta = Configuration::get(FrikGestionTransportista::CFG_PREFER_GLS_DELTA);
        $gb = Configuration::get(FrikGestionTransportista::CFG_GB_THRESHOLD);
        $cron = _PS_BASE_URL_ . __PS_BASE_URI__ . 'modules/frikgestiontransportista/cron/process_queue.php?token=' . Configuration::get(FrikGestionTransportista::CFG_CRON_TOKEN);


        $html = '<div class="panel"><h3>' . $this->l('Configuración básica') . '</h3>';
        $html .= '<form method="post">';
        $html .= '<label>Países (CSV ISO2)</label> <input name="countries" value="' . Tools::safeOutput($countries) . '"/><br>';
        $html .= '<label>Estados (CSV)</label> <input name="states" value="' . Tools::safeOutput($states) . '"/><br>';
        $html .= '<label>Preferencia GLS Δ (€)</label> <input name="gls_delta" value="' . Tools::safeOutput($glsDelta) . '"/><br>';
        $html .= '<label>Regla GB (umbral €)</label> <input name="gb_threshold" value="' . Tools::safeOutput($gb) . '"/><br>';
        $html .= '<button class="btn btn-primary" name="submitFrikCfg" value="1">' . $this->l('Guardar') . '</button>';
        $html .= '<hr><p><b>Cron:</b> ' . $cron . '&limit=50&dry_run=0</p>';
        $html .= '</form></div>';
        return $html;
    }


    protected function renderCsvPanel()
    {
        // Export/Import CSV coherente con tu tabla
        $this->context->controller->addJS('');
        $html = '<div class="panel"><h3>' . $this->l('Importar/Exportar CSV') . '</h3>';
        $html .= '<p><a class="btn btn-default" href="' . self::$currentIndex . '&exportRates=1&token=' . $this->token . '">' . $this->l('Exportar CSV') . '</a></p>';
        $html .= '<form method="post" enctype="multipart/form-data">'
            . '<input type="file" name="rates_file" accept=".csv" required /> '
            . '<button class="btn btn-primary" name="submitImportRates" value="1">' . $this->l('Importar') . '</button>'
            . '<p class="help-block">Cabeceras: id_carrier_reference,carrier_name,country_iso,weight_min_kg,weight_max_kg,avg_price_eur,active</p>'
            . '</form></div>';
        return $html;
    }


    public function postProcess()
    {
        parent::postProcess();
        if (Tools::getIsset('exportRates')) {
            $this->processExportCSV();
        }
        if (Tools::getIsset('submitImportRates')) {
            $this->processImportCSV();
        }
    }


    protected function processExportCSV()
    {
        $rows = Db::getInstance()->executeS('SELECT id_carrier_reference,carrier_name,country_iso,weight_min_kg,weight_max_kg,avg_price_eur,active FROM ' . _DB_PREFIX_ . 'frikgestiontransportista_carrier_rates ORDER BY country_iso, carrier_name, weight_min_kg');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=frikgestiontransportista_rates_' . date('Ymd_His') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['id_carrier_reference', 'carrier_name', 'country_iso', 'weight_min_kg', 'weight_max_kg', 'avg_price_eur', 'active']);
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
        fclose($out);
        exit;
    }

    protected function processImportCSV()
    {
        if (empty($_FILES['rates_file']['tmp_name'])) {
            $this->errors[] = $this->l('Archivo no subido');
            return;
        }
        $h = fopen($_FILES['rates_file']['tmp_name'], 'r');
        if (!$h) {
            $this->errors[] = $this->l('No se puede leer el archivo');
            return;
        }
        $header = fgetcsv($h);
        if (!$header) {
            $this->errors[] = $this->l('CSV vacío');
            return;
        }
        $map = array_flip($header);
        $required = ['id_carrier_reference', 'carrier_name', 'country_iso', 'weight_min_kg', 'weight_max_kg', 'avg_price_eur'];
        foreach ($required as $k)
            if (!isset($map[$k])) {
                $this->errors[] = $this->l('Falta columna: ') . $k;
                fclose($h);
                return;
            }


        $count = 0;
        $db = Db::getInstance();
        while (($row = fgetcsv($h)) !== false) {
            $data = [
                'id_carrier_reference' => (int) $row[$map['id_carrier_reference']],
                'carrier_name' => pSQL($row[$map['carrier_name']]),
                'country_iso' => pSQL($row[$map['country_iso']]),
                'weight_min_kg' => (float) $row[$map['weight_min_kg']],
                'weight_max_kg' => (float) $row[$map['weight_max_kg']],
                'avg_price_eur' => (float) $row[$map['avg_price_eur']],
                'active' => isset($map['active']) ? (int) $row[$map['active']] : 1,
            ];
            if ($data['weight_min_kg'] >= $data['weight_max_kg']) {
                continue;
            }
            $exists = $db->getValue('SELECT id_rate FROM ' . _DB_PREFIX_ . 'frikgestiontransportista_carrier_rates WHERE carrier_name="' . $data['carrier_name'] . '" AND country_iso="' . $data['country_iso'] . '" AND weight_min_kg=' . (float) $data['weight_min_kg'] . ' AND weight_max_kg=' . (float) $data['weight_max_kg'] . '');
            if ($exists) {
                $db->update('frikgestiontransportista_carrier_rates', $data, 'id_rate=' . (int) $exists);
            } else {
                $db->insert('frikgestiontransportista_carrier_rates', $data);
            }
            $count++;
        }
        fclose($h);
        $this->confirmations[] = sprintf($this->l('Importadas/actualizadas %d filas.'), $count);
    }
}