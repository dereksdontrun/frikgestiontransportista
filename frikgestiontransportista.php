<?php
if (!defined('_PS_VERSION_'))
    exit;


class FrikGestionTransportista extends Module
{
    const CFG_ENABLED = 'FRIKGT_ENABLED';
    const CFG_COUNTRIES = 'FRIKGT_COUNTRIES'; // CSV ISO2
    const CFG_STATES = 'FRIKGT_STATES'; // CSV ids
    const CFG_PREFER_GLS_DELTA = 'FRIKGT_GLS_DELTA'; // euros
    const CFG_GB_THRESHOLD = 'FRIKGT_GB_THRESHOLD'; // euros
    const CFG_CRON_TOKEN = 'FRIKGT_CRON_TOKEN';


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
        $this->registerHook('actionObjectOrderAddAfter');
        // Config
        Configuration::updateValue(self::CFG_ENABLED, 1);
        Configuration::updateValue(self::CFG_COUNTRIES, 'FR,IT,DE,GB');
        Configuration::updateValue(self::CFG_STATES, '2,9,41');
        Configuration::updateValue(self::CFG_PREFER_GLS_DELTA, '0.50');
        Configuration::updateValue(self::CFG_GB_THRESHOLD, '50.00');
        Configuration::updateValue(self::CFG_CRON_TOKEN, Tools::passwdGen(24));
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


    public function hookActionObjectOrderAddAfter($params)
    {
        if (!Configuration::get(self::CFG_ENABLED))
            return;
        $order = $params['object'];
        if (!$order instanceof Order)
            return;
        if (!$this->shouldEnqueue($order))
            return;
        Db::getInstance()->insert('frikgestiontransportista_order_queue', [
            'id_order' => (int) $order->id,
            'status' => pSQL('pending'),
            'date_add' => date('Y-m-d H:i:s')
        ], false, true, Db::INSERT_IGNORE);
    }


    private function shouldEnqueue(Order $order)
    {
        $countries = array_filter(array_map('trim', explode(',', Configuration::get(self::CFG_COUNTRIES))));
        $states = array_map('intval', array_filter(explode(',', Configuration::get(self::CFG_STATES))));
        $addr = new Address((int) $order->id_address_delivery);
        $country = new Country((int) $addr->id_country);
        if (!in_array($country->iso_code, $countries))
            return false;
        if (!in_array((int) $order->current_state, $states))
            return false;
        return true;
    }
}