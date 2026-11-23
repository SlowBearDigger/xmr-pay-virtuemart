<?php
// Exercise VirtueMart's real table binding in a disposable Joomla database.
$bootstrap = getenv('XMRPAY_JOOMLA_BOOTSTRAP');
if (!$bootstrap || !is_file($bootstrap)) { fwrite(STDERR, "Set XMRPAY_JOOMLA_BOOTSTRAP to a disposable Joomla bootstrap.\n"); exit(2); }
require $bootstrap;
require_once JPATH_ADMINISTRATOR . '/components/com_virtuemart/helpers/config.php';
VmConfig::loadConfig();
$plugin = vDispatcher::createPlugin('vmpayment', 'xmrpay');
$db = Joomla\CMS\Factory::getContainer()->get(Joomla\Database\DatabaseInterface::class);
$table = $db->getPrefix() . 'virtuemart_payment_plg_xmrpay';
$db->setQuery("SELECT extension_id FROM #__extensions WHERE folder='vmpayment' AND element='xmrpay'");
$pluginId = (int) $db->loadResult();
if ($pluginId <= 0) throw new RuntimeException('Install the native payment extension before this test');
vDispatcher::directTrigger('vmpayment', 'xmrpay', 'OnStoreInstallPluginTable', [$pluginId]);
$db->setQuery('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='.$db->quote($table)." AND index_name='uk_xmrpay_txid' AND non_unique=0 AND column_name='xmr_txid'");
if ((int)$db->loadResult() !== 1) throw new RuntimeException('The native installation hook must create the transaction claim unique index');
vDispatcher::directTrigger('vmpayment', 'xmrpay', 'OnStoreInstallPluginTable', [$pluginId]);
$db->transactionStart();
try {
    $db->setQuery('UPDATE '.$db->quoteName($table)." SET xmr_txid=NULL WHERE xmr_txid='0'")->execute();
    foreach ([2147483001, 2147483002] as $orderId) {
        $values = ['virtuemart_order_id'=>$orderId, 'order_number'=>'storage-test-'.$orderId, 'xmr_amount'=>'0.002', 'xmr_matches'=>'[]'];
        $plugin->storePluginInternalData($values);
        $db->setQuery('SELECT xmr_txid FROM '.$db->quoteName($table).' WHERE virtuemart_order_id='.$orderId);
        $rows=$db->loadAssocList();
        if (count($rows)!==1 || $rows[0]['xmr_txid']!==null) throw new RuntimeException('New unpaid orders must store NULL txids, not VirtueMart zero defaults');
    }
    $txid=str_repeat('a',64);
    $db->setQuery('UPDATE '.$db->quoteName($table).' SET xmr_txid='.$db->quote($txid).' WHERE virtuemart_order_id=2147483002')->execute();
    $row=(new ReflectionMethod($plugin,'getDataByOrderId'))->invoke($plugin,2147483002);
    if (!$row || $row->xmr_txid!==$txid) throw new RuntimeException('Stored txids must remain readable through VirtueMart');
    $duplicateRejected = false;
    try {
        $db->setQuery('UPDATE '.$db->quoteName($table).' SET xmr_txid='.$db->quote($txid).' WHERE virtuemart_order_id=2147483001')->execute();
    } catch (Throwable $error) {
        if (strpos($error->getMessage(), 'Duplicate entry') === false) throw $error;
        $duplicateRejected = true;
    }
    if (!$duplicateRejected) throw new RuntimeException('A stored transaction must not bind to a second order');
    $db->setQuery('SELECT xmr_txid FROM '.$db->quoteName($table).' WHERE virtuemart_order_id=2147483001');
    if ($db->loadResult() !== null) throw new RuntimeException('A rejected duplicate claim must preserve the unpaid order');
    echo "PASS: native installation, repeated upgrade, unpaid rows, transaction readback and duplicate rejection\n";
} finally { $db->transactionRollback(); }
