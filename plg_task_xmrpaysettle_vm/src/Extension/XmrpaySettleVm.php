<?php

/**
 * Joomla 5 Scheduler task that settles pending Monero (xmr-pay) VirtueMart orders. Monero has no
 * payment webhook, so on each run we sweep every published xmr-pay VirtueMart payment method, scan
 * its pending orders with the view-key engine, and mark paid the ones a confirmed on-chain payment
 * has covered. The checkout poll settles a single order on demand; this is the scheduled backstop.
 *
 * VirtueMart, like HikaShop, assumes a web application, so this runs under Joomla's Web Cron, not the
 * CLI scheduler.
 */

namespace XmrPay\Plugin\Task\XmrpaySettleVm\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;
use XmrPay\Adapter\Gateway;
use XmrPay\Adapter\Settler;

final class XmrpaySettleVm extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;

    protected $autoloadLanguage = true;

    private const TASKS_MAP = [
        'xmrpay.settle.vm' => [
            'langConstPrefix' => 'PLG_TASK_XMRPAYSETTLEVM_SETTLE',
            'method'          => 'settle',
        ],
    ];

    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList' => 'advertiseRoutines',
            'onExecuteTask'     => 'standardRoutineHandler',
        ];
    }

    private function settle(ExecuteTaskEvent $event): int
    {
        $vmAdmin = JPATH_ADMINISTRATOR . '/components/com_virtuemart';
        $plgDir  = JPATH_PLUGINS . '/vmpayment/xmrpay';

        if (!is_file($vmAdmin . '/helpers/config.php')) {
            $this->logTask('xmrpay settle vm: VirtueMart is not installed', 'warning');
            return Status::KNOCKOUT;
        }
        // bootstrap VirtueMart (web-app context: the scheduler runs under a real SiteApplication)
        require_once $vmAdmin . '/helpers/config.php';
        \VmConfig::loadConfig();
        if (!class_exists('VmModel')) {
            require_once $vmAdmin . '/helpers/vmmodel.php';
        }

        require_once $plgDir . '/engine/load.php';
        require_once $plgDir . '/VirtueMartOrderStore.php';

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $q  = $db->getQuery(true)
            ->select($db->quoteName(['virtuemart_paymentmethod_id', 'payment_params']))
            ->from($db->quoteName('#__virtuemart_paymentmethods'))
            ->where($db->quoteName('payment_element') . ' = ' . $db->quote('xmrpay'))
            ->where($db->quoteName('published') . ' = 1');
        $db->setQuery($q);
        $methods = (array) $db->loadObjectList();

        $methodsRun = 0;
        $checked    = 0;
        $settled    = 0;

        foreach ($methods as $m) {
            $p = $this->parseParams($m->payment_params);
            if (empty($p['xmr_address']) || empty($p['xmr_view_key'])) {
                continue;   // method not configured yet
            }

            $cfg = [
                'address'           => $p['xmr_address'],
                'view_key'          => $p['xmr_view_key'],
                'nodes'             => $p['xmr_nodes'] ?? '',
                'network'           => !empty($p['xmr_network']) ? $p['xmr_network'] : 'mainnet',
                'min_confirmations' => (int) ($p['xmr_min_confirmations'] ?? 10),
                'index_offset'      => (int) ($p['xmr_index_offset'] ?? 0),
            ];

            $store = new \VirtueMartOrderStore([
                'table'          => '#__virtuemart_payment_plg_xmrpay',
                'payment_id'     => (int) $m->virtuemart_paymentmethod_id,
                'pending_status' => !empty($p['status_pending']) ? $p['status_pending'] : 'U',
                'paid_status'    => !empty($p['status_paid']) ? $p['status_paid'] : 'C',
            ]);

            $report = (new Settler(new Gateway($cfg), $store, ['min_confirmations' => $cfg['min_confirmations']]))->run();

            $methodsRun++;
            $checked += (int) $report['checked'];
            $settled += (int) $report['settled'];
            $this->logTask(sprintf(
                'xmrpay vm method %d: checked=%d settled=%d status=%s',
                (int) $m->virtuemart_paymentmethod_id, $report['checked'], $report['settled'], $report['status']
            ));
        }

        $this->logTask(sprintf('xmrpay settle vm done: %d method(s), checked=%d settled=%d', $methodsRun, $checked, $settled));
        return Status::OK;
    }

    /** Parse VirtueMart's pipe-delimited key="value"| payment_params into an assoc array. */
    private function parseParams($raw): array
    {
        $out = [];
        if (preg_match_all('/(\w+)="([^"]*)"/', (string) $raw, $m, PREG_SET_ORDER)) {
            foreach ($m as $pair) {
                $out[$pair[1]] = $pair[2];
            }
        }
        return $out;
    }
}
