<?php

/**
 * Service provider for the xmr-pay VirtueMart settlement task plugin (Joomla 5 namespaced bootstrap).
 */

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use XmrPay\Plugin\Task\XmrpaySettleVm\Extension\XmrpaySettleVm;

return new class implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new XmrpaySettleVm(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('task', 'xmrpaysettlevm')
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
