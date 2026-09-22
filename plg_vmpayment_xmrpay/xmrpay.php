<?php
// Integrate Monero payments with VirtueMart.


defined('_JEXEC') or die('Restricted access');

if (!class_exists('vmPSPlugin')) {
    require_once JPATH_VM_PLUGINS . DIRECTORY_SEPARATOR . 'vmpsplugin.php';
}

require_once __DIR__ . '/engine/load.php';
require_once __DIR__ . '/VirtueMartOrderStore.php';

use XmrPay\Adapter\Gateway;
use XmrPay\Adapter\Settler;

class plgVmPaymentXmrpay extends vmPSPlugin
{
    function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);

        $this->_loggable  = true;
        $this->_tablepkey = 'id';
        $this->_tableId   = 'id';
        $this->tableFields = array_keys($this->getTableSQLFields());

        $varsToPush = $this->getVarsToPush();
        $this->addVarsToPushCore($varsToPush, 1);
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);

        if (\Joomla\CMS\Factory::getApplication()->isClient('administrator')) {
            $doc  = \Joomla\CMS\Factory::getDocument();
            $base = \Joomla\CMS\Uri\Uri::root(true) . '/plugins/vmpayment/xmrpay/assets/';
            $doc->addStyleSheet($base . 'admin-nodes.css');
            $doc->addScript($base . 'admin-nodes.js');
        }
    }

    protected function createPluginTableObject($tableName, $tableFields, $primaryKey, $tableId, $loggable = false)
    {
        $table = parent::createPluginTableObject($tableName, $tableFields, $primaryKey, $tableId, $loggable);
        // VirtueMart defaults every field to zero and ignores NULL values during binding.
        $table->xmr_txid = null;
        return $table;
    }

    public function getTableSQLFields()
    {
        return array(
            'id'                          => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id'         => 'int(11) UNSIGNED',
            'order_number'                => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name'                => 'varchar(5000)',
            'payment_order_total'         => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency'            => 'char(3)',
            'email_currency'              => 'char(3)',
            'tax_id'                      => 'smallint(1)',

            'xmr_subaddress'              => 'varchar(106)',
            'xmr_amount'                  => 'varchar(32)',
            'xmr_rate'                    => 'varchar(32)',
            'xmr_birthday_height'         => 'int(11) UNSIGNED NOT NULL DEFAULT 0',
            'xmr_scanned_to'              => 'int(11) UNSIGNED NOT NULL DEFAULT 0',
            'xmr_matches'                 => 'mediumtext',
            'xmr_received_pico'           => 'varchar(40)',
            'xmr_txid'                    => 'varchar(64) DEFAULT NULL',
        );
    }

    public function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment xmr-pay Table');
    }

    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        $r = $this->onStoreInstallPluginTable($jplugin_id);

        try {
            $db    = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $table = $db->getPrefix() . 'virtuemart_payment_plg_xmrpay';
            $db->setQuery('UPDATE ' . $db->quoteName($table) . " SET xmr_txid = NULL WHERE xmr_txid = '0'");
            $db->execute();
            $db->setQuery("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = " . $db->quote($table) . " AND index_name = 'uk_xmrpay_txid'");
            if (!(int) $db->loadResult()) {
                $db->setQuery('ALTER TABLE ' . $db->quoteName($table) . ' ADD UNIQUE KEY ' . $db->quoteName('uk_xmrpay_txid') . ' (' . $db->quoteName('xmr_txid') . ')');
                $db->execute();
            }
        } catch (\Throwable $e) {

        }
        return $r;
    }

    function plgVmConfirmedOrder($cart, $order)
    {
        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null;
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        if (!class_exists('VirtueMartModelOrders')) {
            require_once JPATH_VM_ADMINISTRATOR . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'orders.php';
        }
        VmConfig::loadJLang('com_virtuemart', true);

        $this->getPaymentCurrency($method, $order['details']['BT']->payment_currency_id);
        $currencyCode3 = shopFunctions::getCurrencyByID($method->payment_currency, 'currency_code_3');
        $totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total, $method->payment_currency);

        $orderId = (int) $order['details']['BT']->virtuemart_order_id;
        $cfg     = $this->cfgFromMethod($method);

        $sub = '';
        $xmr = '';
        $uri = '';
        $rate = null;
        $birthday = 0;
        try {
            $g   = new Gateway($cfg);
            $sub = $g->subaddressForOrder($orderId);
            $amt = $g->xmrAmount((float) $totalInPaymentCurrency['value'], (string) $currencyCode3, Gateway::rateFetcher($cfg));
            $xmr = $amt['xmr'];
            $rate = $amt['rate'];
            $uri = $g->moneroUri($sub, $xmr, 'Order ' . $order['details']['BT']->order_number);
            $birthday = (int) $g->scanner()->tip_height();
        } catch (\Throwable $e) {

        }

        $dbValues = array(
            'order_number'                => $order['details']['BT']->order_number,
            'virtuemart_order_id'         => $orderId,
            'virtuemart_paymentmethod_id' => (int) $order['details']['BT']->virtuemart_paymentmethod_id,
            'payment_name'                => $this->renderPluginName($method),
            'payment_order_total'         => $totalInPaymentCurrency['value'],
            'payment_currency'            => $currencyCode3,
            'email_currency'              => $this->getEmailCurrency($method),
            'xmr_subaddress'              => $sub,
            'xmr_amount'                  => $xmr,
            'xmr_rate'                    => $rate,
            'xmr_birthday_height'         => $birthday,
            'xmr_scanned_to'              => 0,
            'xmr_matches'                 => '[]',
        );
        $this->storePSPluginInternalData($dbValues);

        $modelOrder = VmModel::getModel('orders');
        $order['order_status']      = $this->getNewStatus($method);
        $order['customer_notified'] = 1;
        $order['comments']          = '';
        $modelOrder->updateStatusForOneOrder($orderId, $order, true);

        $html = $this->renderByLayout('post_payment', array(
            'order_number'                  => $order['details']['BT']->order_number,
            'displayTotalInPaymentCurrency' => $totalInPaymentCurrency['display'],
            'xmr_subaddress'                => $sub,
            'xmr_amount'                    => $xmr,
            'xmr_uri'                       => $uri,
            'order_id'                      => $orderId,
            'min_confirmations'             => (int) $cfg['min_confirmations'],
            'poll_url'                      => 'index.php?option=com_virtuemart&view=vmplg&task=pluginNotification&on=' . $orderId . '&token=' . Gateway::orderToken($orderId, (string) $cfg['view_key']),
            'node_error'                    => ($sub === '' || $xmr === ''),
            'return_url'                    => isset($method->xmr_return_url) ? (string) $method->xmr_return_url : '',
        ));

        $cart->emptyCart();
        vRequest::setVar('html', $html);
        return true;
    }

    function getNewStatus($method)
    {
        $status = isset($method->status_pending) ? $method->status_pending : '';
        return ($status !== '') ? $status : 'U';
    }

    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null;
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        $this->getPaymentCurrency($method);
        $paymentCurrencyId = $method->payment_currency;
    }

    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
    {
        return $this->OnSelectCheck($cart);
    }

    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected, &$htmlIn)
    {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    public function plgVmOnSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices, &$paymentCounter)
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id)
    {
        if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
            return null;
        }
        if (!($row = $this->getDataByOrderId($virtuemart_order_id))) {
            return null;
        }
        $html  = '<table class="adminlist table">' . "\n";
        $html .= $this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('COM_VIRTUEMART_PAYMENT_NAME', $row->payment_name);
        $html .= $this->getHtmlRowBE('Monero address', $row->xmr_subaddress);
        $html .= $this->getHtmlRowBE('Monero amount', $row->xmr_amount . ' XMR');
        if (!empty($row->xmr_txid)) {
            $html .= $this->getHtmlRowBE('Monero txid', $row->xmr_txid);
        }
        $html .= '</table>' . "\n";
        return $html;
    }

    function plgVmOnPaymentNotification(&$html = null)
    {
        $app     = \Joomla\CMS\Factory::getApplication();
        $orderId = (int) $app->input->getInt('on', 0);
        if ($orderId <= 0) {
            return $this->pollResponse($app, false, 'bad-request');
        }
        if (!($row = $this->getDataByOrderId($orderId))) {
            return $this->pollResponse($app, false, 'not-found');
        }

        if (!($method = $this->getVmPluginMethod($row->virtuemart_paymentmethod_id))) {
            return $this->pollResponse($app, false, 'not-found');
        }

        $cfg = $this->cfgFromMethod($method);

        if (empty($cfg['view_key'])) {
            return $this->pollResponse($app, false, 'not-found');
        }

        $token = (string) $app->input->getString('token', '');
        if (!hash_equals(Gateway::orderToken($orderId, (string) $cfg['view_key']), $token)) {
            return $this->pollResponse($app, false, 'not-found');
        }

        if (!empty($row->xmr_txid)) {
            return $this->pollResponse($app, true, 'already-paid');
        }

        $store = new VirtueMartOrderStore(array(
            'table'          => $this->_tablename,
            'payment_id'     => (int) $row->virtuemart_paymentmethod_id,
            'pending_status' => $this->getNewStatus($method),
            'paid_status'    => !empty($method->status_paid) ? $method->status_paid : 'C',
        ));

        $orderRow = $store->loadOne($orderId);
        if ($orderRow === null) {
            return $this->pollResponse($app, false, 'unknown');
        }
        $settler = new Settler(new Gateway($cfg), $store, array('min_confirmations' => (int) $cfg['min_confirmations']));
        $rep     = $settler->settleOrder($orderRow);

        $paid   = !empty($rep['paid']);
        $status = $rep['status'];
        $extra  = array();

        if (!$paid && $status === 'ok') {
            $after = $store->loadOne($orderId);
            $pf    = Gateway::partialFeedback((string) $orderRow['xmr_amount'], $after ? (isset($after['received_pico']) ? $after['received_pico'] : '0') : '0');
            if ($pf !== null) {
                $status = 'partial';
                $extra  = $pf;
            }
        }

        return $this->pollResponse($app, $paid, $status, $extra);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        $r = $this->setOnTablePluginParams($name, $id, $table);
        if ($name === $this->_psType && !empty($table->xmr_address) && !empty($table->xmr_view_key)) {
            try {
                $g = new Gateway($this->cfgFromMethod($table));
                if ($g->cryptoReady()) {
                    $v = $g->verifyKeys();
                    if (empty($v['address_valid'])) {
                        \Joomla\CMS\Factory::getApplication()->enqueueMessage('xmr-pay: the address does not look valid.', 'warning');
                    } elseif (empty($v['key_match'])) {
                        \Joomla\CMS\Factory::getApplication()->enqueueMessage('xmr-pay: the view key does not match the address.', 'warning');
                    } elseif (empty(trim((string) $table->xmr_nodes))) {
                        \Joomla\CMS\Factory::getApplication()->enqueueMessage('xmr-pay: no node is configured, so payments will never be detected. Add at least one Monero node (one per line).', 'warning');
                    } elseif (!\Joomla\CMS\Factory::getApplication()->isClient('administrator')) {

                    } else {
                        try {
                            $probeCfg = $this->cfgFromMethod($table);
                            $results  = $this->probeNodes($probeCfg);
                        } catch (\Throwable $e) {
                            $results = null;
                        }
                        $this->reportNodeProbe($results);
                    }
                }
            } catch (\Throwable $e) {

            }
        }
        return $r;
    }

    function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    function plgVmOnShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    private function cfgFromMethod($method)
    {
        return array(
            'address'           => isset($method->xmr_address) ? $method->xmr_address : '',
            'view_key'          => isset($method->xmr_view_key) ? $method->xmr_view_key : '',
            'nodes'             => isset($method->xmr_nodes) ? $method->xmr_nodes : '',
            'http_timeout'      => max(2, min(60, (int) (isset($method->xmr_http_timeout) ? $method->xmr_http_timeout : 20))),
            'network'           => !empty($method->xmr_network) ? $method->xmr_network : 'mainnet',
            'min_confirmations' => (int) (isset($method->xmr_min_confirmations) ? $method->xmr_min_confirmations : 10),
            'index_offset'      => (int) (isset($method->xmr_index_offset) ? $method->xmr_index_offset : 0),
            'rate_source'       => !empty($method->xmr_rate_source) ? $method->xmr_rate_source : 'coingecko',
            'fixed_rate'        => isset($method->xmr_fixed_rate) ? $method->xmr_fixed_rate : '',
            'rate_url'          => isset($method->xmr_rate_url) ? $method->xmr_rate_url : '',
        );
    }

    private function pollResponse($app, $paid, $status, $extra = array())
    {
        $app->setHeader('Content-Type', 'application/json', true);
        $app->sendHeaders();
        echo json_encode(array_merge(array('paid' => (bool) $paid, 'status' => $status), (array) $extra));
        $app->close();
    }

    private function probeNodes(array $cfg)
    {
        $nodes   = \XmrPay\NodeConfig::normalizeList($cfg['nodes']);
        $public  = \XmrPay\NodeConfig::publicList($nodes);

        $cfg['http_timeout'] = 5;
        $results = array();
        foreach (array_slice($nodes, 0, 6, true) as $index => $node) {
            $one          = $cfg;
            $one['nodes'] = array($node);
            $scanner      = (new Gateway($one))->scanner();
            $tip          = $scanner->tip_height();
            $error        = method_exists($scanner, 'last_node_error') ? $scanner->last_node_error() : array();
            $results[]    = array(
                'number' => $index + 1,
                'url'    => isset($public[$index]['url']) ? $public[$index]['url'] : '',
                'tip'    => $tip,
                'error'  => isset($error['code']) ? $error['code'] : 'unavailable',
            );
        }
        return $results;
    }

    private function reportNodeProbe($results)
    {
        $app = \Joomla\CMS\Factory::getApplication();
        if (!is_array($results)) {
            $app->enqueueMessage('xmr-pay: node settings were saved, but could not be checked. Review the node rows and try again.', 'warning');
            return;
        }
        $failed = array_values(array_filter($results, function ($row) { return $row['tip'] === null; }));
        if (!$failed) {
            $app->enqueueMessage('xmr-pay: checked ' . count($results) . ' Monero node(s). All are connected.', 'message');
            return;
        }
        $details = array_map(function ($row) {
            return 'Node ' . $row['number'] . ' (' . $row['url'] . '): ' . $row['error'];
        }, $failed);
        $working = count($results) - count($failed);
        $suffix  = $working > 0
            ? ' Working nodes remain active. Review or replace the unavailable node(s).'
            : ' Settings were saved, but payment detection will wait until a node reconnects.';
        $app->enqueueMessage('xmr-pay: checked ' . count($results) . ' node(s). ' . implode('; ', $details) . '.' . $suffix, 'warning');
    }
}
