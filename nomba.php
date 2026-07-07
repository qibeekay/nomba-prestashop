<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Nomba payment module class.
 * Integrates Nomba Checkout API with PrestaShop.
 *
 * @author Chibuike Mokwe
 * @license AFL-3.0
 */
class Nomba extends PaymentModule
{
    /**
     * Nomba constructor.
     * Initializes module properties.
     */
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

    /**
     * Installs the module, registers hooks, creates database tables, and registers admin tabs.
     *
     * @return bool True if installation is successful, false otherwise.
     */
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

    /**
     * Uninstalls the module, removes admin tabs, and deletes module configuration records.
     *
     * @return bool True if uninstallation is successful, false otherwise.
     */
    public function uninstall()
    {
        return $this->uninstallTab()
            && $this->uninstallRefundTab()
            && Configuration::deleteByName('NOMBA_CLIENT_ID')
            && Configuration::deleteByName('NOMBA_PRIVATE_KEY')
            && Configuration::deleteByName('NOMBA_ACCOUNT_ID')
            && Configuration::deleteByName('NOMBA_LIVE_MODE')
            && Configuration::deleteByName('NOMBA_WEBHOOK_KEY')
            && parent::uninstall();
    }

    /**
     * Dynamically creates the `ps_nomba_transaction` database table.
     * Used for storing payment metadata, transaction IDs, status, and bank coordinates.
     *
     * @return bool True if successful, false otherwise.
     */
    protected function installSql()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'nomba_transaction` (
            `id_nomba_transaction` int(11) NOT NULL AUTO_INCREMENT,
            `id_cart` int(11) NOT NULL,
            `id_order` int(11) DEFAULT NULL,
            `nomba_order_id` varchar(255) NOT NULL,
            `nomba_order_reference` varchar(255) NOT NULL,
            `amount` decimal(20,6) NOT NULL,
            `amount_refunded` decimal(20,6) NOT NULL DEFAULT 0.000000,
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

    /**
     * Registers the main AdminNomba controller tab under Payment gate settings.
     *
     * @return bool True on success, false on failure.
     */
    public function installTab()
    {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminNomba';
        $tab->name = [];
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'Nomba';
        }
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminParentPayment');
        $tab->module = $this->name;
        return $tab->add();
    }

    /**
     * Registers the AdminNombaRefund controller tab under hidden parent context.
     *
     * @return bool True on success, false on failure.
     */
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

    /**
     * Removes the AdminNomba controller tab.
     *
     * @return bool True on success, false on failure.
     */
    public function uninstallTab()
    {
        $id_tab = (int) Tab::getIdFromClassName('AdminNomba');
        if ($id_tab) {
            $tab = new Tab($id_tab);
            return $tab->delete();
        }
        return true;
    }

    /**
     * Removes the AdminNombaRefund controller tab.
     *
     * @return bool True on success, false on failure.
     */
    public function uninstallRefundTab()
    {
        $id_tab = (int) Tab::getIdFromClassName('AdminNombaRefund');
        if ($id_tab) {
            $tab = new Tab($id_tab);
            return $tab->delete();
        }
        return true;
    }

    /**
     * Back-office module configuration gateway redirect.
     * Automatically redirects the merchant user to the custom AdminNomba controller page.
     *
     * @return void
     */
    public function getContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminNomba'));
    }

    /**
     * Hook to register the Nomba payment option on checkout screen.
     *
     * @param array $params Hook parameters.
     * @return array|void Array containing PaymentOption object, or void if inactive.
     */
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

    /**
     * Hook to display custom order information on checkout completion success page.
     *
     * @param array $params Hook parameters containing 'order' object.
     * @return string Smarty template output content.
     */
    public function hookPaymentReturn($params)
    {
        if (!$this->active || !isset($params['order'])) {
            return;
        }

        $order = $params['order'];

        if ($order->module != $this->name) {
            return;
        }

        $currency = new Currency($order->id_currency);

        // PS 1.7+ safe way to format price
        $formatted_total = $this->context->getCurrentLocale()->formatPrice(
            $order->total_paid,
            $currency->iso_code
        );

        $this->smarty->assign([
            'shop_name' => $this->context->shop->name,
            'total' => $formatted_total,
            'status' => 'ok',
            'reference' => $order->reference,
            'contact_url' => $this->context->link->getPageLink('contact', true)
        ]);

        return $this->fetch('module:nomba/views/templates/hook/payment_return.tpl');
    }

    /**
     * Hook displayAdminOrder.
     * Renders the custom Nomba refund management pane inside the PrestaShop back-office order details screen.
     * This method:
     *  1. Validates if the order was paid using the Nomba payment module.
     *  2. Performs a dynamic database migration check to ensure the `amount_refunded` column exists.
     *  3. Reads and flashes validation/process messages (success or errors) stored in the cookie back to the admin user interface.
     *  4. Fetches transaction data (bank account, bank code, refunded/remaining balances) from `ps_nomba_transaction`.
     *  5. Prepares and renders the admin refund interface template.
     *
     * @param array $params Hook parameters containing:
     *                      - 'id_order' (int): The ID of the order being viewed in the back-office.
     * @return string|void HTML notifications and template content markup, or void if the order is not paid via Nomba.
     */
    public function hookDisplayAdminOrder($params)
    {
        $order = new Order($params['id_order']);
        if ($order->module != $this->name) {
            return;
        }

        // ðŸŸ¢ Dynamic migration check: Ensure amount_refunded column exists in nomba_transaction table
        try {
            $checkColumn = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'nomba_transaction` LIKE "amount_refunded"');
            if (empty($checkColumn)) {
                Db::getInstance()->execute('ALTER TABLE `' . _DB_PREFIX_ . 'nomba_transaction` ADD `amount_refunded` decimal(20,6) NOT NULL DEFAULT 0.000000 AFTER `amount`');
            }
        } catch (Exception $e) {
            // Ignore DB exception
        }

        $htmlNotifications = '';

        // ðŸŸ¢ Read and inject persistent flash alert errors across redirect hooks
        if (isset($this->context->cookie->nomba_refund_error)) {
            $htmlNotifications .= $this->displayError($this->context->cookie->nomba_refund_error);
            unset($this->context->cookie->nomba_refund_error);
        }

        // ðŸŸ¢ Read and inject persistent flash alert confirmations across redirect hooks
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
            'SELECT account_number, bank_code, amount_refunded, status FROM ' . _DB_PREFIX_ . 'nomba_transaction
             WHERE id_order = ' . (int) $order->id
        );

        $amountRefunded = isset($nombaTxn['amount_refunded']) ? (float) $nombaTxn['amount_refunded'] : 0.0;
        $amountRemaining = (float) $order->total_paid - $amountRefunded;
        if ($amountRemaining < 0) {
            $amountRemaining = 0.0;
        }

        $customer = new Customer($order->id_customer);
        $accountName = Validate::isLoadedObject($customer)
            ? $customer->firstname . ' ' . $customer->lastname
            : '';

        $this->context->smarty->assign([
            'transactionRef' => $transactionRef,
            'refundUrl' => $this->context->link->getAdminLink('AdminNombaRefund') . '&id_order=' . $order->id,
            'orderTotal' => $order->total_paid,
            'amountRefunded' => $amountRefunded,
            'amountRemaining' => $amountRemaining,
            'accountNumber' => !empty($nombaTxn['account_number']) && $nombaTxn['account_number'] !== 'N/A' ? $nombaTxn['account_number'] : '',
            'bankCode' => !empty($nombaTxn['bank_code']) && $nombaTxn['bank_code'] !== 'N/A' ? $nombaTxn['bank_code'] : '',
            'accountName' => $accountName,
            'status' => $nombaTxn['status'] ?? '',
            'has_bank_details' => !empty($nombaTxn['account_number']) && $nombaTxn['account_number'] !== 'N/A' && !empty($nombaTxn['bank_code']) && $nombaTxn['bank_code'] !== 'N/A'
        ]);

        return $htmlNotifications . $this->display(__FILE__, 'views/templates/admin/order_refund.tpl');
    }

    /**
     * Hook actionOrderSlipAdd.
     * Automatically triggers when a credit slip (refund slip) is generated for an order in PrestaShop.
     * Intercepts the credit slip event, retrieves order metadata, and issues an automated partial or full refund request via the Nomba API.
     *
     * @param array $params Hook parameters containing:
     *                      - 'order' (Order): PrestaShop Order object instance.
     *                      - 'order_slip' (OrderSlip): Generated credit slip object containing the refund amounts.
     * @return void
     */
    public function hookActionOrderSlipAdd($params)
    {
        $order = $params['order'];

        if (!Validate::isLoadedObject($order) || $order->module != $this->name) {
            return;
        }

        $orderSlip = $params['order_slip'];
        // Total amount being partially or fully refunded via this specific credit slip
        $refundAmount = (float) $orderSlip->amount + (float) $orderSlip->shipping_cost_amount;

        $payments = $order->getOrderPaymentCollection();
        $transactionRef = $payments->count() ? $payments[0]->transaction_id : '';

        if (empty($transactionRef)) {
            PrestaShopLogger::addLog('Nomba Hook Refund: Missing transaction ID for order ' . $order->id, 3);
            return;
        }

        $nombaTxn = Db::getInstance()->getRow(
            'SELECT account_number, bank_code, amount_refunded, amount FROM ' . _DB_PREFIX_ . 'nomba_transaction
             WHERE id_order = ' . (int) $order->id
        );

        try {
            require_once _PS_MODULE_DIR_ . 'nomba/src/Service/NombaApiClient.php';

            $accountNumber = !empty($nombaTxn['account_number']) && $nombaTxn['account_number'] !== 'N/A' ? $nombaTxn['account_number'] : null;
            $bankCode = !empty($nombaTxn['bank_code']) && $nombaTxn['bank_code'] !== 'N/A' ? $nombaTxn['bank_code'] : null;

            $nombaApi = new NombaApiClient();
            $nombaApi->refundTransaction(
                $transactionRef,
                $refundAmount,
                $accountNumber,
                $bankCode
            );

            // Update local DB tracking
            $currentRefunded = isset($nombaTxn['amount_refunded']) ? (float) $nombaTxn['amount_refunded'] : 0.0;
            $newRefunded = $currentRefunded + $refundAmount;
            $totalAmount = isset($nombaTxn['amount']) ? (float) $nombaTxn['amount'] : (float) $order->total_paid;

            $newStatus = ($newRefunded >= $totalAmount) ? 'refunded' : 'partially_refunded';

            Db::getInstance()->update('nomba_transaction', [
                'amount_refunded' => (float) $newRefunded,
                'status' => $newStatus,
                'date_upd' => date('Y-m-d H:i:s')
            ], 'id_order = ' . (int) $order->id);

            PrestaShopLogger::addLog('Nomba Hook Refund: Automated refund of ' . $refundAmount . ' processed via credit slip for Order #' . $order->id, 1);
        } catch (Exception $e) {
            PrestaShopLogger::addLog('Nomba Hook Refund Error: ' . $e->getMessage(), 3);
        }
    }
}
