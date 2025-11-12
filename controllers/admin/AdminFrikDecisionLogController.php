<?php
class AdminFrikDecisionLogController extends ModuleAdminController
{
    public function __construct()
    {
        $this->table = 'frikgestiontransportista_decision_log';
        $this->className = 'ObjectModel'; // sólo listado
        $this->bootstrap = true;
        $this->lang = false;
        parent::__construct();


        // Campos visibles
        $this->fields_list = [
            'id_log' => ['title' => 'ID', 'align' => 'center', 'width' => 40],
            'date_add' => ['title' => 'Fecha', 'type' => 'datetime'],
            'id_order' => ['title' => 'Pedido'],
            'country_iso' => ['title' => 'País'],
            'weight_kg' => ['title' => 'Peso (kg)'],
            'total_paid_eur' => ['title' => 'Total (€)'],
            'id_carrier_reference_before' => ['title' => 'Ref. antes'],
            'carrier_before' => ['title' => 'Carrier antes'],
            'id_carrier_reference_after' => ['title' => 'Ref. después'],
            'carrier_after' => ['title' => 'Carrier después'],
            'price_selected_eur' => ['title' => 'Precio elegido (€)'],
            'engine' => ['title' => 'Motor'],
            'criteria' => ['title' => 'Criterio'],
            'email_sent' => ['title' => 'Email', 'type' => 'bool']
        ];


        // Filtros
        $this->_filter = '';
        $this->_select = '';
        $this->_orderBy = 'id_log';
        $this->_orderWay = 'DESC';


        $this->list_no_link = true;
        $this->bulk_actions = [
            'delete' => ['text' => $this->l('Borrar seleccionados'), 'confirm' => $this->l('¿Seguro?')]
        ];


        // Botones de cabecera
        $this->page_header_toolbar_btn['export'] = [
            'href' => self::$currentIndex . '&exportLogs=1&token=' . $this->token,
            'desc' => $this->l('Exportar CSV')
        ];
        $this->page_header_toolbar_btn['purge'] = [
            'href' => self::$currentIndex . '&purgeOld=1&days=90&token=' . $this->token,
            'desc' => $this->l('Purgar >90 días')
        ];
    }

    public function postProcess()
    {
        parent::postProcess();
        if (Tools::getIsset('exportLogs'))
            $this->processExportCSV();
        if (Tools::getIsset('purgeOld'))
            $this->processPurge((int) Tools::getValue('days', 90));
    }


    protected function processExportCSV()
    {
        $rows = Db::getInstance()->executeS('SELECT * FROM ' . _DB_PREFIX_ . 'frikgestiontransportista_decision_log ORDER BY id_log DESC');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=frikgestiontransportista_logs_' . date('Ymd_His') . '.csv');
        $out = fopen('php://output', 'w');
        if (!empty($rows))
            fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $r)
            fputcsv($out, $r);
        fclose($out);
        exit;
    }


    protected function processPurge($days)
    {
        $days = max(1, (int) $days);
        Db::getInstance()->execute('DELETE FROM ' . _DB_PREFIX_ . 'frikgestiontransportista_decision_log WHERE date_add < DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)');
        $this->confirmations[] = sprintf($this->l('Purgados logs con más de %d días.'), $days);
    }
}