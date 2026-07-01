<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

class Nomba extends PaymentModule
{
    public function __construct()
    {
        $this->name = 'nomba';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'Chibuike Mokwe';
        $this->controllers = ['payment', 'validation', 'webhook'];
        $this->ps_versions_compliancy = ['min' => '8.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = 'Nomba Checkout';
        $this->description = 'Accept payments via Nomba Checkout API';
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn')
            && $this->registerHook('displayAdminOrder')
            && $this->registerHook('actionOrderSlipAdd')
            && $this->installSql()
            && $this->installTab()
            && $this->installRefundTab()
            && Configuration::updateValue('NOMBA_LIVE_MODE', 0);
    }

    public function uninstall()
    {
        return $this->uninstallTab()
            && $this->uninstallRefundTab()
            && Configuration::deleteByName('NOMBA_CLIENT_ID')
            && Configuration::deleteByName('NOMBA_PRIVATE_KEY')
            && Configuration::deleteByName('NOMBA_ACCOUNT_ID')
            && Configuration::deleteByName('NOMBA_LIVE_MODE')
            && parent::uninstall();
    }

    protected function installSql()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'nomba_transaction` (
            `id_nomba_transaction` int(11) NOT NULL AUTO_INCREMENT,
            `id_cart` int(11) NOT NULL,
            `id_order` int(11) DEFAULT NULL,
            `nomba_order_id` varchar(255) NOT NULL,
            `nomba_order_reference` varchar(255) NOT NULL,
            `amount` decimal(20,6) NOT NULL,
            `account_number` varchar(20) DEFAULT NULL,
            `bank_code` varchar(10) DEFAULT NULL,
            `status` varchar(50) NOT NULL,
            `date_add` datetime NOT NULL,
            `date_upd` datetime NOT NULL,
            PRIMARY KEY (`id_nomba_transaction`),
            KEY `id_cart` (`id_cart`),
            KEY `id_order` (`id_order`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        return Db::getInstance()->execute($sql);
    }

    public function installTab()
    {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminNomba';
        $tab->name = [];
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'Nomba';
        }
        $tab->id_parent = (int)Tab::getIdFromClassName('AdminParentPayment');
        $tab->module = $this->name;
        return $tab->add();
    }

    public function installRefundTab()
    {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminNombaRefund';
        $tab->name = [];
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'Nomba Refund';
        }
        $tab->id_parent = -1;
        $tab->module = $this->name;
        return $tab->add();
    }

    public function uninstallTab()
    {
        $id_tab = (int)Tab::getIdFromClassName('AdminNomba');
        if ($id_tab) {
            $tab = new Tab($id_tab);
            return $tab->delete();
        }
        return true;
    }

    public function uninstallRefundTab()
    {
        $id_tab = (int)Tab::getIdFromClassName('AdminNombaRefund');
        if ($id_tab) {
            $tab = new Tab($id_tab);
            return $tab->delete();
        }
        return true;
    }

    public function getContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminNomba'));
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return;
        }

        $payment_option = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $payment_option->setCallToActionText('Pay with Nomba')
            ->setAction($this->context->link->getModuleLink($this->name, 'payment', [], true))
            ->setAdditionalInformation('Card, Bank Transfer, USSD');

        return [$payment_option];
    }

    public function hookPaymentReturn($params)
    {
        if (!$this->active) {
            return;
        }

        $order = $params['order'];

        if ($order->module != $this->name) {
            return;
        }

        $this->smarty->assign([
            'shop_name' => $this->context->shop->name,
            'total' => Tools::displayPrice($order->total_paid, $this->context->currency),
            'status' => 'ok',
            'reference' => $order->reference,
            'contact_url' => $this->context->link->getPageLink('contact', true)
        ]);

        return $this->display(__FILE__, 'views/templates/hook/payment_return.tpl');
    }

    public function hookDisplayAdminOrder($params)
    {
        $order = new Order($params['id_order']);
        if ($order->module != $this->name) {
            return;
        }

        $htmlNotifications = '';

        // 🟢 Read and inject persistent flash alert errors across redirect hooks
        if (isset($this->context->cookie->nomba_refund_error)) {
            $htmlNotifications .= $this->displayError($this->context->cookie->nomba_refund_error);
            unset($this->context->cookie->nomba_refund_error);
        }

        // 🟢 Read and inject persistent flash alert confirmations across redirect hooks
        if (isset($this->context->cookie->nomba_refund_success)) {
            $htmlNotifications .= $this->displayConfirmation($this->context->cookie->nomba_refund_success);
            unset($this->context->cookie->nomba_refund_success);
        }

        $payments = $order->getOrderPaymentCollection();
        $transactionRef = $payments->count() ? $payments[0]->transaction_id : '';

        if (empty($transactionRef)) {
            return $htmlNotifications;
        }

        $nombaTxn = Db::getInstance()->getRow(
            'SELECT account_number, bank_code FROM ' . _DB_PREFIX_ . 'nomba_transaction
             WHERE id_order = ' . (int)$order->id
        );

        $this->context->smarty->assign([
            'transactionRef' => $transactionRef,
            'refundUrl' => $this->context->link->getAdminLink('AdminNombaRefund') . '&id_order=' . $order->id,
            'orderTotal' => $order->total_paid,
            'has_bank_details' => !empty($nombaTxn['account_number']) && !empty($nombaTxn['bank_code'])
        ]);

        return $htmlNotifications . $this->display(__FILE__, 'views/templates/admin/order_refund.tpl');
    }

    /**
     * Automatically triggers when a credit slip (refund) is generated for an order
     */
    public function hookActionOrderSlipAdd($params)
    {
        $order = $params['order'];

        if (!Validate::isLoadedObject($order) || $order->module != $this->name) {
            return;
        }

        $orderSlip = $params['order_slip'];
        // Total amount being partially or fully refunded via this specific credit slip
        $refundAmount = (float)$orderSlip->amount + (float)$orderSlip->shipping_cost_amount;

        $payments = $order->getOrderPaymentCollection();
        $transactionRef = $payments->count() ? $payments[0]->transaction_id : '';

        $nombaTxn = Db::getInstance()->getRow(
            'SELECT account_number, bank_code FROM ' . _DB_PREFIX_ . 'nomba_transaction
             WHERE id_order = ' . (int)$order->id
        );

        if (empty($transactionRef) || empty($nombaTxn['account_number']) || empty($nombaTxn['bank_code'])) {
            PrestaShopLogger::addLog('Nomba Hook Refund: Missing bank details or transaction ID for order ' . $order->id, 3);
            return;
        }

        try {
            require_once _PS_MODULE_DIR_ . 'nomba/src/Service/NombaApiClient.php';

            $nombaApi = new NombaApiClient();
            $nombaApi->refundTransaction(
                $transactionRef,
                $refundAmount,
                $nombaTxn['account_number'],
                $nombaTxn['bank_code']
            );

            PrestaShopLogger::addLog('Nomba Hook Refund: Automated partial/full refund processed via credit slip for Order #' . $order->id, 1);
        } catch (Exception $e) {
            PrestaShopLogger::addLog('Nomba Hook Refund Error: ' . $e->getMessage(), 3);
        }
    }
}
