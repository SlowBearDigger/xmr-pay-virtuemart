# xmr-pay for VirtueMart

A non-custodial Monero payment method for VirtueMart on Joomla 5.4+. The order is placed pending at
checkout and the buyer sees a receiving subaddress + the exact XMR amount + a live poll; the order
settles once the engine confirms a real on-chain payment. View key only — no `monero-wallet-rpc`, no
daemon — funds go straight to the merchant's wallet.

Built on the shared [xmr-pay engine](../xmr-pay-php) + [adapter core](../xmr-pay-adapter-core),
vendored into the plugin. The Monero work lives in the engine; this package is the thin VirtueMart
layer, modelled on VirtueMart's bundled offline plugin (`standard` / bank transfer). It is the same
engine + `OrderStore`/`Settler` contract as the [HikaShop adapter](../xmr-pay-hikashop).

## Install

1. Download `pkg_xmrpay_virtuemart.zip` from the [latest release](../../releases/latest).
2. In the Joomla admin, go to System, Install, Extensions, Upload Package File, and select the zip.
   One package installs both the payment plugin and the settlement scheduler task.
3. Enable both plugins. Joomla installs every third-party plugin disabled by default; this is a
   Joomla-wide behaviour, not specific to this one. Go to System, Manage, Plugins, search `xmr-pay`,
   and enable VM Payment - xmr-pay (Monero) and Task - xmr-pay Monero settlement (VirtueMart).
   Skipping the second one is the most common setup mistake: the payment method will still work at
   checkout, but nothing settles once the buyer closes the tab.
4. In VirtueMart, go to Shop, Payment Methods, New, and pick VM Payment - xmr-pay (Monero) as the
   Payment element. Fill in your wallet's primary address, its private view key (never the spend
   key), and one or more Monero nodes, one per line. A public node is fine to start; run your own for
   real money. Saving now actually tries to reach your node(s) and tells you immediately if it can't.
5. Create the background sweep. Go to System, Scheduled Tasks, New Task, and pick "xmr-pay: settle
   pending Monero orders (VirtueMart)". Save it, then set up Joomla's Web Cron under Global
   Configuration, System tab, Scheduler (or the ad-hoc URL Joomla shows on the task page). VirtueMart
   needs a real web request to run its own code, so the CLI `scheduler:run` console command does not
   work here, but Web Cron does. This sweep is the backstop that settles an order even if the buyer
   paid and closed the browser tab before the on-page poll caught it.

## Packages

```
plg_vmpayment_xmrpay/        # the payment method plugin
  xmrpay.php                 # plgVmPaymentXmrpay extends vmPSPlugin
  xmrpay/tmpl/post_payment.php  # checkout screen: subaddress + amount + monero: link + QR + poll
  VirtueMartOrderStore.php   # implements XmrPay\Adapter\OrderStore over VM's order API
  xmrpay.xml                 # manifest (vmconfig fields; the data table is created by the VM hook)
  qrcode.min.js              # local client-side QR (the address never leaves the browser)
  engine/                    # vendored engine + adapter core + load.php
  language/
plg_task_xmrpaysettle_vm/    # Joomla 5 Scheduler task → Settler::run()
```

## How it maps onto VirtueMart (verified against VirtueMart 4.6.4 source)

| Adapter need | VirtueMart |
|---|---|
| place order pending | `plgVmConfirmedOrder` sets `order_status` to the configured pending status (default `U`), like `standard` |
| receiving address / amount / currency | `$order['details']['BT']->virtuemart_order_id`, `order_total`, `shopFunctions::getCurrencyByID(..., 'currency_code_3')` |
| per-order scan state | the plugin's own table `#__virtuemart_payment_plg_xmrpay` (`getTableSQLFields` + `storePSPluginInternalData`) |
| mark paid | `VmModel::getModel('orders')->updateStatusForOneOrder($id, [...'order_status'=>'C'...], true)` |
| txid dedup | the `xmr_txid` column claimed via `UPDATE ... WHERE xmr_txid IS NULL` (affected-rows guard) |
| checkout "is it paid?" poll | `plgVmOnPaymentNotification` (vmplg `pluginNotification` route) → `Settler::settleOrder` → JSON `{paid,status}` |
| background sweep | a Joomla 5 `task` plugin → `Settler::run()` |

## Status — COMPLETE

- ✅ Payment plugin + task plugin build, install (`extension:install`), and enable on Joomla 5.4.6 + VirtueMart 4.6.4.
- ✅ Vendored engine loads in the Joomla runtime; `Gateway`/`xmrAmount` (curl rate fetch) work.
- ✅ **Full checkout walkthrough** in a browser (product → cart → shipment + Monero payment → Confirm
  Purchase) renders our `post_payment` screen: locked XMR amount (fiat→XMR via the rate), the real
  derived subaddress, a `monero:` deep link, a client-side QR, and the live poll. The order is created
  pending (status `U`), the scan state is stored in `#__virtuemart_payment_plg_xmrpay`, and the poll
  ran (advanced the scan checkpoint) — proving the `plgVmOnPaymentNotification` → `Settler::settleOrder`
  chain.
- ✅ **Task plugin runs via Web Cron** (`last_exit_code = 0`): bootstraps VirtueMart, parses each
  method's pipe `key="value"|` params, builds `Gateway` + `VirtueMartOrderStore`, runs `Settler::run()`.

### Found + fixed during the build

- `plgVmDeclarePluginParamsPaymentVM3` takes **one** arg (`&$data`), not three — a wrong signature
  threw "Too few arguments" on save. All other lifecycle signatures verified against `standard.php`.

## Notes

- The plugin needs a working VirtueMart install: the AIO package (provides
  `script.vmallinone.php` + bundled plugins), then VM's sample-data install (vendor + products +
  shipping). Image/log dirs must be writable by the web user. A shipment method must *match* the order
  (the bundled weight_countries needs a weight range) before any payment method shows — VM checkout is
  progressive.
- `payment_params` are stored in VM's own pipe format: `key="value"|key2="value2"|`.
- Like HikaShop, VirtueMart assumes a **web application** — the settlement task runs under Joomla's
  Web Cron, not the CLI scheduler.
- The plugin reads its config via VM's param machinery (`$method->xmr_address` etc.); the inner data
  table holds only per-order scan state.
