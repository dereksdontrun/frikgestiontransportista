<?php

require_once _PS_MODULE_DIR_ . 'frikgestiontransportista/classes/FrikCarrierRate.php';

class AdminFrikGestionTransportistaController extends ModuleAdminController
{
    public function __construct()
    {
        $this->table = 'frikgestiontransportista_carrier_rates';
        $this->identifier = 'id_frikgestiontransportista_carrier_rates';
        $this->className = 'FrikCarrierRate';
        $this->bootstrap = true;
        $this->lang = false;
        parent::__construct();

        $this->_default_pagination = 50;
        $this->_pagination = array(20, 50, 100, 200, 500, 999999);
        $this->_orderBy = 'id_frikgestiontransportista_carrier_rates';
        $this->_orderWay = 'ASC';

        $this->fields_list = [
            'id_frikgestiontransportista_carrier_rates' => ['title' => 'ID', 'align' => 'center', 'width' => 30],
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
            // Ya no tocamos CFG_COUNTRIES (se autodetecta sacndo los valores de la tabla).
            Configuration::updateValue(FrikGestionTransportista::CFG_STATES, Tools::getValue('states'));
            Configuration::updateValue(FrikGestionTransportista::CFG_PREFER_GLS_MARGEN, Tools::getValue('gls_margen'));
            Configuration::updateValue(FrikGestionTransportista::CFG_GB_THRESHOLD, Tools::getValue('gb_threshold'));
            //las correspondencias transportista->id_carrier_reference las obtenemos del json guardado en configuration, el cual hay que decodificar
            $raw_map = Tools::getValue('carrier_map_json');
            if ($raw_map !== null) {
                $decoded = json_decode($raw_map, true);
                if (is_array($decoded)) {
                    Configuration::updateValue(FrikGestionTransportista::CFG_CARRIER_MAP, json_encode($decoded));
                } else {
                    $this->errors[] = $this->l('El JSON de mapeo de carriers no es válido.');
                }
            }
            $this->confirmations[] = $this->l('Configuración guardada');
        }

        $states = Configuration::get(FrikGestionTransportista::CFG_STATES);
        $glsMargen = Configuration::get(FrikGestionTransportista::CFG_PREFER_GLS_MARGEN);
        $gb = Configuration::get(FrikGestionTransportista::CFG_GB_THRESHOLD);
        $carrierMap = Configuration::get(FrikGestionTransportista::CFG_CARRIER_MAP);

        $cron_gpt = (method_exists('Tools', 'getShopDomainSsl') ? Tools::getShopDomainSsl(true) : _PS_BASE_URL_)
            . __PS_BASE_URI__ . 'modules/frikgestiontransportista/cron/process_queue_gpt.php?token='
            . Configuration::get(FrikGestionTransportista::CFG_CRON_TOKEN);

        // $cron_reglas = (method_exists('Tools', 'getShopDomainSsl') ? Tools::getShopDomainSsl(true) : _PS_BASE_URL_)
        //     . __PS_BASE_URI__ . 'modules/frikgestiontransportista/cron/process_queue.php?token='
        //     . Configuration::get(FrikGestionTransportista::CFG_CRON_TOKEN);

        // Países detectados desde la tabla
        $detected = $this->module->getCountriesFromRates();
        $badges = '';
        foreach ($detected as $iso) {
            $badges .= '<span class="badge">' . $iso . '</span> ';
        }
        if ($badges === '') {
            $badges = '<em>' . $this->l('Sin países detectados aún (rellena la tabla de tarifas)') . '</em>';
        }

        $html = '<div class="panel"><h3>' . $this->l('Configuración básica') . '</h3>';
        $html .= '<form method="post">';
        $html .= '<label>Estados (CSV)</label> <input name="states" value="' . Tools::safeOutput($states) . '"/><br>';
        $html .= '<label>Preferencia GLS Δ (€)</label> <input name="gls_margen" value="' . Tools::safeOutput($glsMargen) . '"/><br>';
        $html .= '<label>Regla GB (umbral €)</label> <input name="gb_threshold" value="' . Tools::safeOutput($gb) . '"/><br>';
        $html .= '<p class="help-block">' . $this->l('Países usados: detectados automáticamente de la tabla de tarifas (active=1)') . '</p>';
        $html .= '<p>' . $badges . '</p>';
        $html .= '<label>Mapeo nombre transporte→id_reference (JSON)</label><br>';
        $html .= '<textarea name="carrier_map_json" style="width:30%;height:60px">' . Tools::safeOutput($carrierMap) . '</textarea>';
        $html .= '<button class="btn btn-success" name="submitFrikCfg" value="1">' . $this->l('Guardar') . '</button>';
        $html .= '<hr><p><b>Cron GPT:</b> ' . $cron_gpt . '&limit=50&dry_run=0</p>';
        // $html .= '<hr><p><b>Cron Reglas:</b> ' . $cron_reglas . '&limit=50&dry_run=0</p>';
        $html .= '</form></div>';

        return $html;
    }



    protected function renderCsvPanel()
    {
        $backups = $this->getBackupTables();

        $carrierMap = Configuration::get(FrikGestionTransportista::CFG_CARRIER_MAP);

        $html = '<div class="panel"><h3>' . $this->l('Importar/Exportar CSV') . '</h3>';
        $html .= '<p><a class="btn btn-info" href="' . self::$currentIndex . '&exportRates=1&token=' . $this->token . '">' . $this->l('Exportar CSV') . '</a></p><hr>';
        $html .= '<form method="post" enctype="multipart/form-data">'
            . '<input type="file" name="rates_file" accept=".csv" required /> '
            . '<p></p>'
            . '<button class="btn btn-success" name="submitImportRates" value="1">' . $this->l('Importar (con copia previa)') . '</button>'
            . '<p class="help-block">Cabeceras válidas: id_carrier_reference, carrier_name, country_iso, weight_min_kg, weight_max_kg, avg_price_eur, active</p>'
            . '<p class="help-block">Separador recomendado: <b>;</b> (Excel). La importación autodetecta ; , tab o | y quita BOM.</p>'
            . '<p class="help-block">Acepta coma decimal (16,34 → 16.34)</p>'
            . '<p class="help-block">Si falta id_carrier_reference, aplicará: ' . Tools::safeOutput($carrierMap) . ' </p>'
            . '<p class="help-block">Si falta active, aplicará 1 o "activo" </p>'
            . '</form>';

        // Backups
        $html .= '<hr><h4>' . $this->l('Copias de seguridad disponibles') . '</h4>';
        if (!$backups) {
            $html .= '<p>' . $this->l('No hay copias de seguridad.') . '</p>';
        } else {
            $html .= '<table class="table"><thead><tr>'
                . '<th>' . $this->l('Tabla') . '</th>'
                . '<th>' . $this->l('Fecha') . '</th>'
                . '<th>' . $this->l('Acción') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($backups as $t) {
                // extrae timestamp del nombre
                $stamp = substr($t, -15); // YYYYMMDD_HHMMSS
                $fecha = DateTime::createFromFormat('Ymd_His', $stamp);
                $label = $fecha ? $fecha->format('Y-m-d H:i:s') : $t;
                $html .= '<tr>'
                    . '<td>' . pSQL($t) . '</td>'
                    . '<td>' . Tools::safeOutput($label) . '</td>'
                    . '<td>'
                    . '<a class="btn btn-warning" href="' . self::$currentIndex . '&restoreRates=1&table=' . urlencode($t) . '&token=' . $this->token . '" '
                    . 'onclick="return confirm(\'' . $this->l('¿Restaurar esta copia sobre la tabla principal? Se perderán los cambios actuales.') . '\')">' . $this->l('Restaurar') . '</a>'
                    . '</td></tr>';
            }
            $html .= '</tbody></table>';
            $html .= '<p class="help-block">' . $this->l('Se conservarán automáticamente solo las 2 copias más recientes.') . '</p>';
        }

        $html .= '</div>';
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
        if (Tools::getIsset('restoreRates')) {
            $table = Tools::getValue('table');
            $this->restoreBackup($table);
        }
    }


    protected function processExportCSV()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT id_carrier_reference,carrier_name,country_iso,weight_min_kg,weight_max_kg,avg_price_eur,active
         FROM ' . _DB_PREFIX_ . 'frikgestiontransportista_carrier_rates
         ORDER BY country_iso, carrier_name, weight_min_kg'
        );

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=frikgestiontransportista_rates_' . date('Ymd_His') . '.csv');

        $out = fopen('php://output', 'w');
        // BOM UTF-8 (para Excel Windows)
        fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        // Sugerencia de separador para Excel
        fwrite($out, "sep=;\n");

        // Cabeceras
        fputcsv($out, ['id_carrier_reference', 'carrier_name', 'country_iso', 'weight_min_kg', 'weight_max_kg', 'avg_price_eur', 'active'], ';');

        foreach ($rows as $r) {
            fputcsv($out, $r, ';');
        }
        fclose($out);
        exit;
    }


    /**
     * Importa el CSV de tarifas con:
     * - Backup previo de la tabla y retención de N copias
     * - Detección de BOM y separador (sep=; / ; / , / tab / |)
     * - Normalización de números (coma → punto)
     * - Campo 'active' por defecto a 1 si no viene
     * - Resolución de id_carrier_reference si no viene (mapa en Configuration o por nombre en ps_carrier)
     * - Carga limpia (TRUNCATE + INSERT)
     */
    protected function processImportCSV()
    {
        // 0) Validaciones básicas de fichero
        if (empty($_FILES['rates_file']['tmp_name'])) {
            $this->errors[] = $this->l('Archivo no subido');
            return;
        }
        $path = $_FILES['rates_file']['tmp_name'];
        $h = fopen($path, 'r');
        if (!$h) {
            $this->errors[] = $this->l('No se puede leer el archivo');
            return;
        }

        // 1) Leer primera línea para detectar BOM y/o "sep="
        $first = fgets($h);
        if ($first === false) {
            fclose($h);
            $this->errors[] = $this->l('CSV vacío');
            return;
        }
        // Quita BOM si existe
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);

        // 2) Detectar separador
        $delimiter = ','; // fallback
        $headerLine = '';
        if (stripos($first, 'sep=') === 0) {
            // Excel export "sep=;"
            $delimiter = trim(substr($first, 4), " \t\r\n");
            $headerLine = fgets($h);
            if ($headerLine === false) {
                fclose($h);
                $this->errors[] = $this->l('CSV sin cabecera');
                return;
            }
            $headerLine = preg_replace('/^\xEF\xBB\xBF/', '', $headerLine);
        } else {
            $headerLine = $first;
            // Heurística: cuenta separadores en la cabecera
            $counts = array(
                ';' => substr_count($headerLine, ';'),
                ',' => substr_count($headerLine, ','),
                "\t" => substr_count($headerLine, "\t"),
                '|' => substr_count($headerLine, '|'),
            );
            arsort($counts);
            $best = key($counts);
            $delimiter = ($best && $counts[$best] > 0) ? $best : ',';
        }

        // 3) Parsear cabecera → mapa de columnas (en minúsculas y trim)
        $header = str_getcsv(trim($headerLine), $delimiter);
        $header = array_map(function ($c) {
            $c = preg_replace('/^\xEF\xBB\xBF/', '', $c);
            return Tools::strtolower(trim($c));
        }, $header);
        $map = array_flip($header);

        // 4) Columnas requeridas (sin id_carrier_reference; si falta la resolveremos)
        $required = array('carrier_name', 'country_iso', 'weight_min_kg', 'weight_max_kg', 'avg_price_eur');
        foreach ($required as $k) {
            if (!isset($map[$k])) {
                fclose($h);
                $this->errors[] = $this->l('Falta columna: ') . $k;
                return;
            }
        }
        $hasRef = isset($map['id_carrier_reference']);

        // 5) Preparar normalizadores y configuración auxiliar
        $toFloat = function ($v) {
            // quita espacios (incl. nbsp) y cambia coma decimal por punto
            $v = str_replace(array(' ', "\xC2\xA0"), '', (string) $v);
            $v = str_replace(',', '.', $v);
            return (float) $v;
        };
        $db = Db::getInstance();

        // Mapa opcional en Configuration: {"ups":610,"gls":467,"spring-tracked":322,"spring-signatured":323}
        $refMapJson = Configuration::get('FRIKGT_CARRIER_MAP');
        $nameToRef = json_decode($refMapJson, true);
        if (!is_array($nameToRef)) {
            $nameToRef = [];
        }
        // Normaliza claves a minúsculas
        $nameToRef = array_change_key_case($nameToRef, CASE_LOWER);

        // Helper lectura segura de campo por nombre
        $get = function ($name, $row) use ($map) {
            return (isset($map[$name]) && isset($row[$map[$name]])) ? $row[$map[$name]] : null;
        };

        // === BACKUP + RETENCIÓN + TRUNCATE ===
        $bak = $this->createBackupCopy();
        if (!$bak) {
            fclose($h);
            $this->errors[] = $this->l('No se pudo crear la copia de seguridad previa. Importación abortada.');
            return;
        }
        // Mantener solo 2 copias (ajusta si quieres)
        $this->enforceBackupRetention(2);

        // Limpieza de la tabla principal para una importación 100% coherente
        $main = _DB_PREFIX_ . 'frikgestiontransportista_carrier_rates';
        $db->execute('TRUNCATE TABLE `' . pSQL($main) . '`');

        // 6) Iterar filas y construir INSERTs
        $inserted = 0;
        $skipped = 0;

        while (($row = fgetcsv($h, 0, $delimiter)) !== false) {
            // Saltar líneas vacías
            if (count($row) === 1 && trim($row[0]) === '') {
                continue;
            }

            $carrierName = (string) $get('carrier_name', $row);
            $countryIso = Tools::strtoupper((string) $get('country_iso', $row));
            $wmin = $toFloat($get('weight_min_kg', $row));
            $wmax = $toFloat($get('weight_max_kg', $row));
            $price = $toFloat($get('avg_price_eur', $row));
            // active por defecto = 1 si no viene
            $active = $get('active', $row);
            $active = ($active === null || $active === '') ? 1 : (int) $active;

            // Validaciones mínimas
            if ($carrierName === '' || $countryIso === '' || $wmin >= $wmax) {
                $skipped++;
                continue;
            }

            // Resolver id_carrier_reference:
            // 1) Si viene en CSV y es numérico, úsalo
            // 2) Si no, intenta por mapa de Configuration (clave en minúsculas)
            // 3) Si no, último fallback: buscar por nombre en ps_carrier (no borrado)
            $idRef = 0;
            if ($hasRef) {
                $idRef = (int) $get('id_carrier_reference', $row);
            }
            if (!$idRef) {
                $key = Tools::strtolower(trim($carrierName));
                if (isset($nameToRef[$key]) && (int) $nameToRef[$key] > 0) {
                    $idRef = (int) $nameToRef[$key];
                }
            }
            // if (!$idRef) {
            //     $idRef = (int) $db->getValue('
            //     SELECT id_reference
            //     FROM ' . _DB_PREFIX_ . 'carrier
            //     WHERE name = "' . pSQL($carrierName) . '" AND deleted = 0
            //     ORDER BY id_carrier DESC
            // ');
            // }
            if (!$idRef) {
                // No podemos insertar sin referencia fiable
                $skipped++;
                continue;
            }

            $data = array(
                'id_carrier_reference' => (int) $idRef,
                'carrier_name' => pSQL($carrierName),
                'country_iso' => pSQL($countryIso),
                'weight_min_kg' => (float) $wmin,
                'weight_max_kg' => (float) $wmax,
                'avg_price_eur' => (float) $price,
                'active' => (int) $active,
            );

            // Insert directo (tabla vacía tras TRUNCATE)
            if ($db->insert('frikgestiontransportista_carrier_rates', $data)) {
                $inserted++;
            } else {
                $skipped++;
            }
        }
        fclose($h);

        // 7) Mensajes finales
        $msg = sprintf($this->l('Importación finalizada. Insertadas %d filas. Saltadas %d.'), $inserted, $skipped);
        if ($bak) {
            $msg .= ' ' . $this->l('Se creó copia previa: ') . pSQL($bak);
        }
        $this->confirmations[] = $msg;
    }


    /** Devuelve array de tablas backup existentes, ordenadas desc por fecha (más nueva primero) */
    protected function getBackupTables()
    {
        $like = pSQL($this->getBackupPrefix() . '%');
        $rows = Db::getInstance()->executeS('SHOW TABLES LIKE "' . $like . '"');
        $out = [];
        foreach ($rows as $r) {
            $out[] = reset($r); // primer valor de la fila
        }
        // Ordena por timestamp final (YYYYMMDD_HHMMSS)
        usort($out, function ($a, $b) {
            return strcmp(substr($b, -15), substr($a, -15));
        });
        return $out;
    }

    /** Elimina backups dejando solo $keep más recientes */
    protected function enforceBackupRetention($keep = 2)
    {
        $all = $this->getBackupTables();
        if (count($all) <= $keep)
            return;
        $toDrop = array_slice($all, $keep);
        foreach ($toDrop as $t) {
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . pSQL($t) . '`');
        }
    }

    // Usa un prefijo corto para evitar pasar de 64 chars
    protected function getBackupPrefix()
    {
        // quedará: lafrips_frikgt_rates_bak_YYYYMMDD_HHMMSS  (≈ 41 chars)
        return _DB_PREFIX_ . 'frikgt_rates_bak_';
    }

    /** Comprueba si existe la tabla $table en el schema actual */
    protected function tableExists($table)
    {
        $sql = 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "' . pSQL($table) . '"';
        return (bool) Db::getInstance()->getValue($sql);
    }

    /** Crea backup de la tabla principal y devuelve el nombre (o false si falla) */
    protected function createBackupCopy()
    {
        $db = Db::getInstance();
        $main = _DB_PREFIX_ . 'frikgestiontransportista_carrier_rates';

        // 1) Comprueba que la principal exista de verdad
        if (!$this->tableExists($main)) {
            $this->errors[] = $this->l('La tabla principal no existe (nombre esperado: ') . pSQL($main) . ').';
            return false;
        }

        // 2) Genera nombre corto y seguro (<=64 chars)
        $bak = $this->getBackupPrefix() . date('Ymd_His'); // ~41 chars → sobrado

        // 3) Crea estructura y copia datos
        if (!$db->execute('CREATE TABLE `' . pSQL($bak) . '` LIKE `' . pSQL($main) . '`')) {
            $this->errors[] = $this->l('No se pudo crear la tabla backup con LIKE.');
            return false;
        }
        if (!$db->execute('INSERT INTO `' . pSQL($bak) . '` SELECT * FROM `' . pSQL($main) . '`')) {
            $this->errors[] = $this->l('No se pudieron copiar los datos a la tabla backup.');
            // Limpieza por si creó la tabla vacía
            $db->execute('DROP TABLE IF EXISTS `' . pSQL($bak) . '`');
            return false;
        }

        return $bak;
    }

    protected function getTmpTableName()
    {
        // lafrips_frikgt_rates_tmp_251114130625  (≈ 8 + 19 + 12 = 39 chars)
        return _DB_PREFIX_ . 'frikgt_rates_tmp_' . date('ymdHis');
    }

    /** Restaura una tabla backup sobre la principal */
    protected function restoreBackup($backupTable)
    {
        $prefix = $this->getBackupPrefix(); // lafrips_frikgt_rates_bak_
        if (strpos($backupTable, $prefix) !== 0) {
            $this->errors[] = $this->l('Tabla de backup no válida.');
            return false;
        }
        if (!$this->tableExists($backupTable)) {
            $this->errors[] = $this->l('La tabla de backup indicada no existe.');
            return false;
        }

        $db = Db::getInstance();
        $main = _DB_PREFIX_ . 'frikgestiontransportista_carrier_rates';

        if (!$this->tableExists($main)) {
            $this->errors[] = $this->l('La tabla principal no existe y no se puede restaurar sobre ella.');
            return false;
        }

        // === nombre corto y siempre <=64 ===
        $tmp = $this->getTmpTableName();

        // por si acaso (colisiones absurdas)
        if ($this->tableExists($tmp)) {
            // añade un sufijo aleatorio muy corto
            $tmp = _DB_PREFIX_ . 'frikgt_rates_tmp_' . date('ymdHis') . '_' . substr(uniqid('', true), -4);
            // 8 + 19 + 1 + 4 ≈ 32 + 12(fecha) = < 64 seguro
        }

        // Crear tmp con misma estructura que la principal y cargar datos de la backup
        $ok = $db->execute('CREATE TABLE `' . pSQL($tmp) . '` LIKE `' . pSQL($main) . '`')
            && $db->execute('INSERT INTO `' . pSQL($tmp) . '` SELECT * FROM `' . pSQL($backupTable) . '`');

        if (!$ok) {
            $db->execute('DROP TABLE IF EXISTS `' . pSQL($tmp) . '`');
            $this->errors[] = $this->l('No se pudo restaurar la copia (creación/carga de temporal).');
            return false;
        }

        // Reemplazar contenido de la principal
        $db->execute('TRUNCATE TABLE `' . pSQL($main) . '`');
        $ok = $db->execute('INSERT INTO `' . pSQL($main) . '` SELECT * FROM `' . pSQL($tmp) . '`');

        // Limpieza de la temporal
        $db->execute('DROP TABLE IF EXISTS `' . pSQL($tmp) . '`');

        if ($ok) {
            $this->confirmations[] = $this->l('Restauración completada desde: ') . pSQL($backupTable);
            return true;
        }

        $this->errors[] = $this->l('Fallo al insertar datos restaurados en la tabla principal.');
        return false;
    }

}