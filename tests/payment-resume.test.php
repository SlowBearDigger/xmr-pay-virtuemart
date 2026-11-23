<?php
// Render an existing native invoice without reissuing its quote or changing payment state.
$bootstrap = getenv('XMRPAY_JOOMLA_BOOTSTRAP');
$orderId = (int) getenv('XMRPAY_TEST_ORDER_ID');
if (!$bootstrap || !is_file($bootstrap) || $orderId <= 0) {
    fwrite(STDERR, "Set XMRPAY_JOOMLA_BOOTSTRAP and XMRPAY_TEST_ORDER_ID for a disposable native fixture.\n");
    exit(2);
}
require $bootstrap;
$plugin = vDispatcher::createPlugin('vmpayment', 'xmrpay');
$db = Joomla\CMS\Factory::getContainer()->get(Joomla\Database\DatabaseInterface::class);
$db->setQuery('SELECT * FROM #__virtuemart_payment_plg_xmrpay WHERE virtuemart_order_id=' . $orderId);
$beforePayment = $db->loadAssoc();
$db->setQuery('SELECT * FROM #__virtuemart_orders WHERE virtuemart_order_id=' . $orderId);
$beforeOrder = $db->loadAssoc();
if (!$beforePayment || !$beforeOrder || empty($beforePayment['xmr_subaddress'])) {
    throw new RuntimeException('A native pending invoice with an issued quote is required.');
}
foreach ([1, 2] as $attempt) {
    $html = '';
    $plugin->plgVmOnShowOrderFEPayment($orderId, (int) $beforePayment['virtuemart_paymentmethod_id'], $html);
    if (strpos($html, 'id="xmrpay-pay"') === false
        || strpos($html, htmlspecialchars($beforePayment['xmr_subaddress'])) === false
        || strpos($html, 'tx_amount=' . $beforePayment['xmr_amount']) === false) {
        throw new RuntimeException('Returning to native order details must render the stored payment request.');
    }
}
$html = '';
$plugin->plgVmOnShowOrderFEPayment($orderId, 2147483000, $html);
if (strpos($html, 'id="xmrpay-pay"') !== false) {
    throw new RuntimeException('The payment hook must ignore another payment method.');
}
$db->transactionStart();
try {
    $db->setQuery("UPDATE #__virtuemart_orders SET order_status='X' WHERE virtuemart_order_id=" . $orderId)->execute();
    $html = '';
    $plugin->plgVmOnShowOrderFEPayment($orderId, (int) $beforePayment['virtuemart_paymentmethod_id'], $html);
    if (strpos($html, 'id="xmrpay-pay"') !== false) {
        throw new RuntimeException('Closed native orders must not show payment instructions.');
    }
} finally {
    $db->transactionRollback();
}
$db->setQuery('SELECT * FROM #__virtuemart_orders WHERE virtuemart_order_id=' . $orderId);
if ($db->loadAssoc() !== $beforeOrder) {
    throw new RuntimeException('Payment-page reentry must preserve the complete native order row.');
}
$db->setQuery('SELECT * FROM #__virtuemart_payment_plg_xmrpay WHERE virtuemart_order_id=' . $orderId);
if ($db->loadAssoc() !== $beforePayment) {
    throw new RuntimeException('Payment-page reentry must preserve the complete native payment row.');
}
echo "PASS: native order reentry, repeat rendering, method/status guards and unchanged payment/order rows\n";
