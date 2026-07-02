<?php

/**
 * The VirtueMart side of the storage contract the engine's Settler drives. Per-order scan state (the
 * receiving subaddress, the locked XMR amount, the birthday height, the scan checkpoint, the
 * accumulated matches and the settled txid) lives in the payment plugin's own table
 * #__virtuemart_payment_plg_xmrpay, one row per order. Marking paid goes through VirtueMart's order
 * model so its stock / invoice / notification logic runs. Txid dedup uses the xmr_txid column as the
 * guard — VirtueMart has no native dedup.
 */

defined('_JEXEC') or die('Restricted access');

require_once __DIR__ . '/engine/load.php';

use XmrPay\Adapter\OrderStore;

class VirtueMartOrderStore implements OrderStore
{
    private $table;
    private $paymentId;
    private $pendingStatus;
    private $paidStatus;

    public function __construct(array $opts = array())
    {
        $this->table         = isset($opts['table']) ? $opts['table'] : '#__virtuemart_payment_plg_xmrpay';
        $this->paymentId     = isset($opts['payment_id']) ? (int) $opts['payment_id'] : 0;
        $this->pendingStatus = isset($opts['pending_status']) ? $opts['pending_status'] : 'U';
        $this->paidStatus    = isset($opts['paid_status']) ? $opts['paid_status'] : 'C';
    }

    public function loadPending(): iterable
    {
        $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $q  = $db->getQuery(true)
            ->select('p.' . $db->quoteName('virtuemart_order_id'))
            ->from($db->quoteName($this->table, 'p'))
            ->innerJoin($db->quoteName('#__virtuemart_orders', 'o')
                . ' ON o.' . $db->quoteName('virtuemart_order_id') . ' = p.' . $db->quoteName('virtuemart_order_id'))
            ->where('o.' . $db->quoteName('order_status') . ' = ' . $db->quote($this->pendingStatus))
            ->where('p.' . $db->quoteName('xmr_txid') . ' IS NULL');
        if ($this->paymentId > 0) {
            $q->where('p.' . $db->quoteName('virtuemart_paymentmethod_id') . ' = ' . (int) $this->paymentId);
        }
        $db->setQuery($q);
        $ids = (array) $db->loadColumn();

        $out = array();
        foreach ($ids as $id) {
            $row = $this->loadOne((int) $id);
            if ($row !== null) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** The contract row for one order, or null. Reused by the checkout poll. */
    public function loadOne(int $orderId)
    {
        $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $q  = $db->getQuery(true)
            ->select($db->quoteName(array('xmr_birthday_height', 'xmr_scanned_to', 'xmr_matches', 'xmr_amount', 'xmr_received_pico')))
            ->from($db->quoteName($this->table))
            ->where($db->quoteName('virtuemart_order_id') . ' = ' . (int) $orderId)
            ->order($db->quoteName('id') . ' DESC');
        $db->setQuery($q, 0, 1);
        $r = $db->loadObject();
        if (!$r) {
            return null;
        }

        $matches = array();
        if (!empty($r->xmr_matches)) {
            $decoded = json_decode($r->xmr_matches, true);
            $matches = is_array($decoded) ? $decoded : array();
        }

        return array(
            'id'              => $orderId,
            'birthday_height' => (int) $r->xmr_birthday_height,
            'scanned_to'      => (int) $r->xmr_scanned_to,
            'matches'         => $matches,
            'xmr_amount'      => (string) ($r->xmr_amount !== null ? $r->xmr_amount : '0'),
            'status'          => 'pending',
            // extra (not part of the OrderStore contract; the Settler ignores it): funds seen so far,
            // so the checkout poll can report partial-payment progress to the buyer.
            'received_pico'   => (string) (isset($r->xmr_received_pico) && $r->xmr_received_pico !== null ? $r->xmr_received_pico : '0'),
        );
    }

    public function saveProgress(int $orderId, array $patch): void
    {
        $db  = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $set = array();
        if (array_key_exists('birthday_height', $patch)) $set[] = $db->quoteName('xmr_birthday_height') . ' = ' . (int) $patch['birthday_height'];
        if (array_key_exists('scanned_to', $patch))      $set[] = $db->quoteName('xmr_scanned_to') . ' = ' . (int) $patch['scanned_to'];
        if (array_key_exists('matches', $patch))         $set[] = $db->quoteName('xmr_matches') . ' = ' . $db->quote(json_encode($patch['matches']));
        if (array_key_exists('received_pico', $patch))   $set[] = $db->quoteName('xmr_received_pico') . ' = ' . $db->quote((string) $patch['received_pico']);
        if (!$set) {
            return;
        }
        $q = 'UPDATE ' . $db->quoteName($this->table) . ' SET ' . implode(', ', $set)
            . ' WHERE ' . $db->quoteName('virtuemart_order_id') . ' = ' . (int) $orderId
            . ' ORDER BY ' . $db->quoteName('id') . ' DESC LIMIT 1';
        $db->setQuery($q);
        $db->execute();
    }

    public function isSettled(string $txid): bool
    {
        try {
            $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName($this->table) . ' WHERE ' . $db->quoteName('xmr_txid') . ' = ' . $db->quote($txid));
            return (bool) $db->loadResult();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function markPaid(int $orderId, string $txid, array $verdict): void
    {
        $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);

        // claim the txid on this order's row: the WHERE xmr_txid IS NULL means only one of two
        // overlapping runs flips it; the loser's affected-rows is 0 and bails before crediting.
        $db->setQuery(
            'UPDATE ' . $db->quoteName($this->table)
            . ' SET ' . $db->quoteName('xmr_txid') . ' = ' . $db->quote($txid)
            . ' WHERE ' . $db->quoteName('virtuemart_order_id') . ' = ' . (int) $orderId
            . ' AND ' . $db->quoteName('xmr_txid') . ' IS NULL'
            . ' ORDER BY ' . $db->quoteName('id') . ' DESC LIMIT 1'
        );
        $db->execute();
        if ((int) $db->getAffectedRows() === 0) {
            return;   // another run already settled this order
        }

        // release the txid claim if we can't actually promote the order — otherwise the row is
        // excluded from loadPending (xmr_txid set) and isSettled returns true, so a paid order would
        // be stuck forever. releasing lets the next run retry.
        $release = function () use ($db, $orderId) {
            try {
                $db->setQuery('UPDATE ' . $db->quoteName($this->table) . ' SET ' . $db->quoteName('xmr_txid') . ' = NULL WHERE ' . $db->quoteName('virtuemart_order_id') . ' = ' . (int) $orderId . ' ORDER BY ' . $db->quoteName('id') . ' DESC LIMIT 1');
                $db->execute();
            } catch (\Throwable $e) {
            }
        };

        if (!class_exists('VmModel')) {
            $release();
            return;   // VirtueMart not bootstrapped (should not happen from the plugin / task)
        }
        try {
            $modelOrder = VmModel::getModel('orders');
            $modelOrder->updateStatusForOneOrder($orderId, array(
                'virtuemart_order_id' => $orderId,
                'order_status'        => $this->paidStatus,
                'customer_notified'   => 1,
                'comments'            => 'XMR payment confirmed. txid: ' . $txid,
            ), true);
        } catch (\Throwable $e) {
            $release();
            throw $e;
        }
    }
}
