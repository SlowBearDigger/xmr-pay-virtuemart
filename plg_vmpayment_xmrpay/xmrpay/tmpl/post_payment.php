<?php

/**
 * Checkout instructions screen, rendered by $this->renderByLayout('post_payment', ...) in
 * plgVmConfirmedOrder. Maps VirtueMart's $viewData onto the shared xmr-pay payment card.
 */

defined('_JEXEC') or die('Restricted access');

$uri       = isset($viewData['xmr_uri']) ? $viewData['xmr_uri'] : '';
$sub       = isset($viewData['xmr_subaddress']) ? $viewData['xmr_subaddress'] : '';
$xmr       = isset($viewData['xmr_amount']) ? $viewData['xmr_amount'] : '';
$fiat      = isset($viewData['displayTotalInPaymentCurrency']) ? $viewData['displayTotalInPaymentCurrency'] : '';
$err       = !empty($viewData['node_error']);
$qrLibUrl  = \Joomla\CMS\Uri\Uri::root(true) . '/plugins/vmpayment/xmrpay/qrcode.min.js';
$pollUrl   = !empty($viewData['poll_url']) ? (\Joomla\CMS\Uri\Uri::root(true) . '/' . $viewData['poll_url']) : '';
$returnUrl = isset($viewData['return_url']) ? (string) $viewData['return_url'] : '';

require __DIR__ . '/../../views/pay_card.php';
