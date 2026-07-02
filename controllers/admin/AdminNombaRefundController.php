<?php
require_once _PS_MODULE_DIR_ . 'nomba/src/Service/NombaApiClient.php';

/**
 * Hidden back-office admin controller for automated and manual refund operations.
 * Interacts with Nomba APIs and manages order status transitions and internal audit logging.
 */
class AdminNombaRefundController extends ModuleAdminController
{
    /**
     * AdminNombaRefundController constructor.
     * Sets up controller environment context parameters.
     */
    public function __construct()
    {
        parent::__construct();
        $this->bootstrap = true;
        $this->module = Module::getInstanceByName('nomba');
    }

    /**
     * Resolves refund triggers.
     * Evaluates order status, executes validation checks against bank details,
     * enforces API idempotency guards, and contacts Nomba client endpoints to refund.
     *
     * @return void
     */
    public function initContent()
    {
        parent::initContent();

        $id_order = (int) Tools::getValue('id_order');
        $order = new Order($id_order);
        $redirectToOrderView = $this->context->link->getAdminLink('AdminOrders') . '&id_order=' . $id_order . '&vieworder';

        if (!Validate::isLoadedObject($order)) {
            $this->context->cookie->nomba_refund_error = $this->module->l('Order not found.');
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminOrders'));
            return;
        }

        $payments = $order->getOrderPaymentCollection();
        $transactionRef = $payments->count() ? $payments[0]->transaction_id : '';

        // Fetch transaction details from local database
        $nombaTxn = Db::getInstance()->getRow(
            'SELECT account_number, bank_code, status FROM ' . _DB_PREFIX_ . 'nomba_transaction
             WHERE id_order = ' . (int) $id_order
        );

        if (empty($transactionRef) || empty($nombaTxn['account_number']) || empty($nombaTxn['bank_code'])) {
            $errorMsg = $this->module->l('Missing bank details. Cannot automate refund for TXN: ') . $transactionRef;

            // Log the systemic validation failure
            PrestaShopLogger::addLog('Nomba Refund Blocked: ' . $errorMsg . ' | Order ID: ' . $id_order, 3, null, 'Order', $id_order);

            $this->context->cookie->nomba_refund_error = $errorMsg;
            Tools::redirectAdmin($redirectToOrderView);
            return;
        }

        // 🟢 IDEMPOTENCY GUARD: Catch duplicate requests before hitting the API
        if (isset($nombaTxn['status']) && $nombaTxn['status'] === 'refunded') {
            $warnMsg = $this->module->l('This payment has already been refunded via the Nomba API. Action aborted.');

            // Log the duplicate attempt for verification audit trails
            PrestaShopLogger::addLog('Nomba Idempotency Guard: Blocked duplicate refund attempt for Order #' . $id_order, 2, null, 'Order', $id_order);

            $this->context->cookie->nomba_refund_error = $warnMsg;
            Tools::redirectAdmin($redirectToOrderView);
            return;
        }

        try {
            $nombaApi = new NombaApiClient();
            $result = $nombaApi->refundTransaction(
                $transactionRef,
                $order->total_paid,
                $nombaTxn['account_number'],
                $nombaTxn['bank_code']
            );

            $message = $result['data']['message']
                ?? $result['message']
                ?? $result['description']
                ?? 'Refund processed successfully';

            // Log successful verification trail
            PrestaShopLogger::addLog('Nomba Refund Successful: Order #' . $id_order . ' | API Msg: ' . $message, 1, null, 'Order', $id_order);

            // Add an internal hidden store message thread to the order details page
            $msg = new Message();
            $msg->message = 'Nomba refund processed: ' . $message;
            $msg->id_order = $id_order;
            $msg->private = 1;
            $msg->add();

            // Transition Order state natively
            $order->setCurrentState((int) Configuration::get('PS_OS_REFUND'));

            // Finalize local table idempotency flag
            Db::getInstance()->update('nomba_transaction', [
                'status' => 'refunded',
                'date_upd' => date('Y-m-d H:i:s')
            ], 'id_order = ' . (int) $id_order);

            $this->context->cookie->nomba_refund_success = $this->module->l('Refund processed successfully: ') . $message;
        } catch (Exception $e) {
            $caughtError = $e->getMessage();

            // Log failure parameters with Severity Level 3 (Error)
            PrestaShopLogger::addLog('Nomba Refund API Failure: ' . $caughtError . ' | Order ID: ' . $id_order, 3, null, 'Order', $id_order);

            $this->context->cookie->nomba_refund_error = $this->module->l('Nomba Refund Failed: ') . $caughtError;
        }

        Tools::redirectAdmin($redirectToOrderView);
    }
}
