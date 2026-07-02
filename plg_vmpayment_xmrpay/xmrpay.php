<?php

/**
 * xmr-pay for VirtueMart — a non-custodial Monero payment method.
 *
 * The order is placed pending at checkout; the buyer sees a receiving subaddress + the exact XMR
 * amount + a live poll. A Joomla scheduled task and this plugin's poll endpoint settle the order
 * once the engine confirms a real on-chain payment. View key only — no wallet-rpc, no daemon. The
 * Monero work lives in the vendored engine + adapter core; this file is the thin VirtueMart layer,
 * modelled on the bundled offline plugin (standard / bank transfer).
 */

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
    }

    /** The plugin's own per-order data table: scan state lives here, keyed by virtuemart_order_id. */
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
            // xmr-pay scan state
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
        // defence in depth on the txid dedup: a UNIQUE index makes a double-credit impossible even if
        // the atomic UPDATE...WHERE xmr_txid IS NULL guard is ever weakened. Tolerant — ignore if the
        // index already exists (re-install) or the DB engine rejects it.
        try {
            $db    = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $table = $db->getPrefix() . 'virtuemart_payment_plg_xmrpay';
            $db->setQuery("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = " . $db->quote($table) . " AND index_name = 'uk_xmrpay_txid'");
            if (!(int) $db->loadResult()) {
                $db->setQuery('ALTER TABLE ' . $db->quoteName($table) . ' ADD UNIQUE KEY ' . $db->quoteName('uk_xmrpay_txid') . ' (' . $db->quoteName('xmr_txid') . ')');
                $db->execute();
            }
        } catch (\Throwable $e) {
            // non-fatal: the atomic UPDATE guard still protects against double credit
        }
        return $r;
    }

    /**
     * Order just placed. Lock the XMR amount + receiving subaddress onto a row in our table, record
     * the chain tip as the order's birthday, leave the order in its pending status, and render the
     * instructions screen (QR + amount + live poll). The order is NOT marked paid here.
     */
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
            // node / rate unreachable at checkout: the amount stays empty, so the card shows the
            // "couldn't set the price" state and the settler refuses to credit it (fail-closed). the
            // buyer must reload or contact the merchant; there is no automatic re-lock.
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

    /** Pending status for a freshly placed order: the method's configured value, else 'U'. */
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

    /**
     * The checkout "is it paid yet?" poll, served through VirtueMart's vmplg pluginNotification route
     * (controllers/vmplg.php). Settles this one order on demand, answers JSON {paid,status}.
     */
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
        // same generic answer as a missing row, so a caller can't tell xmrpay orders apart from others.
        if (!($method = $this->getVmPluginMethod($row->virtuemart_paymentmethod_id))) {
            return $this->pollResponse($app, false, 'not-found');
        }

        $cfg = $this->cfgFromMethod($method);

        // an unconfigured method has an empty view key, which makes the HMAC token guessable. refuse
        // to answer rather than expose a forgeable endpoint.
        if (empty($cfg['view_key'])) {
            return $this->pollResponse($app, false, 'not-found');
        }

        // validate the per-order poll token (HMAC keyed on the view key) BEFORE revealing any state.
        // without it a stranger could enumerate order ids and force node scans; respond generically.
        $token = (string) $app->input->getString('token', '');
        if (!hash_equals(Gateway::orderToken($orderId, (string) $cfg['view_key']), $token)) {
            return $this->pollResponse($app, false, 'not-found');
        }

        // already settled: answer paid immediately, no rescan (keeps a re-opened payment page from
        // spinning forever and avoids a pointless node scan on every poll of a done order).
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
        return $this->pollResponse($app, !empty($rep['paid']), $rep['status']);
    }

    /** Validate the address + view key against each other when the merchant saves the method. */
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
                    } else {
                        // the keys check above is purely local (no network) -- this is the only place
                        // that actually confirms the configured node(s) are reachable FROM THIS SERVER.
                        try {
                            $tip = $g->scanner()->tip_height();
                        } catch (\Throwable $e) {
                            $tip = null;
                        }
                        if ($tip === null) {
                            \Joomla\CMS\Factory::getApplication()->enqueueMessage('xmr-pay: could not reach any of the configured Monero nodes from this server. Payments will not be detected until this is fixed -- double-check the Nodes field and that your host allows outbound connections to those addresses/ports.', 'warning');
                        } else {
                            \Joomla\CMS\Factory::getApplication()->enqueueMessage('xmr-pay: connected -- current ' . $table->xmr_network . ' block height ' . $tip . '.', 'message');
                        }
                    }
                }
            } catch (\Throwable $e) {
                // an unexpected local error -- not a network message, since the network check itself
                // is inside the nested try above.
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

    // --- helpers ---------------------------------------------------------------------------------

    private function cfgFromMethod($method)
    {
        return array(
            'address'           => isset($method->xmr_address) ? $method->xmr_address : '',
            'view_key'          => isset($method->xmr_view_key) ? $method->xmr_view_key : '',
            'nodes'             => isset($method->xmr_nodes) ? $method->xmr_nodes : '',
            'network'           => !empty($method->xmr_network) ? $method->xmr_network : 'mainnet',
            'min_confirmations' => (int) (isset($method->xmr_min_confirmations) ? $method->xmr_min_confirmations : 10),
            'index_offset'      => (int) (isset($method->xmr_index_offset) ? $method->xmr_index_offset : 0),
            'rate_source'       => !empty($method->xmr_rate_source) ? $method->xmr_rate_source : 'coingecko',
            'fixed_rate'        => isset($method->xmr_fixed_rate) ? $method->xmr_fixed_rate : '',
            'rate_url'          => isset($method->xmr_rate_url) ? $method->xmr_rate_url : '',
        );
    }

    private function pollResponse($app, $paid, $status)
    {
        $app->setHeader('Content-Type', 'application/json', true);
        $app->sendHeaders();
        echo json_encode(array('paid' => (bool) $paid, 'status' => $status));
        $app->close();
    }
}
