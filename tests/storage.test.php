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
$db->setQuery('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='.$db->quote($table)." AND index_name='uk_xmrpay_txid'");
if (!(int)$db->loadResult()) throw new RuntimeException('Install the plugin table and unique index before this test');
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
    echo "PASS: consecutive unpaid rows and stored txid readback\n";
} finally { $db->transactionRollback(); }
