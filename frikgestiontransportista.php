<?php
if (!defined('_PS_VERSION_'))
    exit;


class FrikGestionTransportista extends Module
{
    const CFG_ENABLED = 'FRIKGT_ENABLED';
    const CFG_COUNTRIES = 'FRIKGT_COUNTRIES'; // CSV ISO2
    const CFG_STATES = 'FRIKGT_STATES'; // CSV ids
    const CFG_PREFER_GLS_MARGEN = 'FRIKGT_GLS_MARGEN'; // euros
    const CFG_GB_THRESHOLD = 'FRIKGT_GB_THRESHOLD'; // euros
    const CFG_CRON_TOKEN = 'FRIKGT_CRON_TOKEN';
    const CFG_CARRIER_MAP = 'FRIKGT_CARRIER_MAP'; // json: {"ups":610,"gls":467,"spring-tracked":322,"spring-signatured":323}


    public function __construct()
    {
        $this->name = 'frikgestiontransportista';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.0';
        $this->author = 'Sergio';
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->l('Gestión automática de transportistas en pedidos entrantes');
        $this->description = $this->l('Encola pedidos, elige transportista por precio con preferencia GLS y registra log.');
    }

    public function install()
    {
        if (!parent::install())
            return false;
        $sql = file_get_contents(__DIR__ . '/sql/install.sql');
        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt)
                Db::getInstance()->execute($stmt);
        }
        // Tabs
        $parent = (int) Tab::getIdFromClassName('AdminParentShipping');
        $this->installTab('AdminFrikGestionTransportista', 'Cambio de transportista', $parent);
        $this->installTab('AdminFrikDecisionLog', 'Logs cambio transportista', $parent);
        // Hooks
        // $this->registerHook('actionObjectOrderAddAfter');
        // $this->registerHook('actionOrderStatusPostUpdate');

        // Config
        Configuration::updateValue(self::CFG_ENABLED, 1);
        Configuration::updateValue(self::CFG_COUNTRIES, 'AT,BE,BG,CH,CY,CZ,DE,DK,EE,FI,FR,GB,GR,HR,HU,IE,IT,LI,LT,LU,LV,MC,MT,NL,NO,PL,RO,RS,RU,SE,SI,SK,SM,TR,VA');  //he quitado ES y PT
        Configuration::updateValue(self::CFG_STATES, '2,9,41');
        Configuration::updateValue(self::CFG_PREFER_GLS_MARGEN, '0.50');
        Configuration::updateValue(self::CFG_GB_THRESHOLD, '100.00');
        Configuration::updateValue(self::CFG_CRON_TOKEN, Tools::passwdGen(24));
        $default_map = array(
            'ups' => 610,
            'gls' => 467,
            'spring-tracked' => 322,
            'spring-signatured' => 323
        );
        Configuration::updateValue(self::CFG_CARRIER_MAP, json_encode($default_map));

        return true;
    }


    public function uninstall()
    {
        $this->uninstallTab('AdminFrikGestionTransportista');
        $this->uninstallTab('AdminFrikDecisionLog');
        return parent::uninstall();
    }


    private function installTab($class, $name, $idParent)
    {
        if (Tab::getIdFromClassName($class))
            return true;
        $t = new Tab();
        $t->class_name = $class;
        $t->id_parent = (int) $idParent;
        $t->module = $this->name;
        foreach (Language::getLanguages(false) as $l)
            $t->name[$l['id_lang']] = $name;
        return $t->add();
    }
    private function uninstallTab($class)
    {
        $id = (int) Tab::getIdFromClassName($class);
        if ($id) {
            $t = new Tab($id);
            return $t->delete();
        }
        return true;
    }


    public function getContent()
    {
        Tools::redirectAdmin(AdminController::$currentIndex . '&controller=AdminFrikGestionTransportista&token=' .
            Tools::getAdminTokenLite('AdminFrikGestionTransportista'));
    }


    // SIN USAR
    public function hookActionObjectOrderAddAfter($params)
    {
        if (!Configuration::get(self::CFG_ENABLED))
            return;
        $order = $params['object'];
        if (!$order instanceof Order)
            return;

        if (!$this->shouldEnqueue($order, false))
            return; // ← solo país

        Db::getInstance()->insert('frikgestiontransportista_order_queue', [
            'id_order' => (int) $order->id,
            'status' => 'pending',
            'date_add' => date('Y-m-d H:i:s'),
        ], false, true, Db::INSERT_IGNORE);
    }


    // SIN USAR
    public function hookActionOrderStatusPostUpdate($params)
    {
        if (!Configuration::get(self::CFG_ENABLED))
            return;
        $order = $params['order'];
        if (!$order instanceof Order)
            return;

        if (!$this->shouldEnqueue($order, true))
            return; // ← país + estado

        Db::getInstance()->insert('frikgestiontransportista_order_queue', [
            'id_order' => (int) $order->id,
            'status' => 'pending',
            'date_add' => date('Y-m-d H:i:s'),
        ], false, true, Db::INSERT_IGNORE);
    }


    // SIN USAR
    private function shouldEnqueue(Order $order, $checkState = true)
    {
        // País → desde tabla (o fallback a config)
        $countries = $this->getCountriesFromRates();

        $addr = new Address((int) $order->id_address_delivery);
        $country = new Country((int) $addr->id_country);

        if (!in_array($country->iso_code, $countries)) {
            return false;
        }

        if ($checkState) {
            $states = array_map('intval', array_filter(explode(',', Configuration::get(self::CFG_STATES))));
            if (!in_array((int) $order->current_state, $states)) {
                return false;
            }
        }

        return true;
    }



    //los códigos iso de los países a los que aplicar los cambios de transporte los sacaremos de la tabla, de modo que no corramos el riesgo de que se haya modificado la variable en la table de configuración que los contenía, y estén siempre actualizados. Quizás haya que eliminar ES y PT si no les debe afectar este módulo
    public function getCountriesFromRates()
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
        // Fallback si aún no hay tarifas cargadas: usa configuración antigua si existiera
        if (!$codes) {
            $cfg = trim((string) Configuration::get(self::CFG_COUNTRIES));
            if ($cfg !== '') {
                $codes = array_filter(array_map('trim', explode(',', $cfg)));
                $codes = array_map('Tools::strtoupper', $codes);
            }
        }
        return $codes;
    }

}