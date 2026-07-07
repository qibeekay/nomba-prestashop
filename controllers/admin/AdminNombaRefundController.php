<?php
require_once _PS_MODULE_DIR_ . 'nomba/src/Service/NombaApiClient.php';

/**
 * Hidden back-office admin controller for automated and manual refund operations.
 * Supports two refund strategies:
 *   1. Native checkout refund (card reversal via /v1/checkout/refund)
 *   2. Bank transfer refund (direct transfer via /v2/transfers/bank/{subAccountId})
 */
class AdminNombaRefundController extends ModuleAdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->bootstrap = true;
        $this->module = Module::getInstanceByName('nomba');
    }

    /**
     * Handles back-office form submissions for manual refunds.
     * Evaluates refund method (Native vs Bank Transfer) and process amount (Full vs Partial).
     *
     * Refund Strategies executed:
     *  1. Native checkout refund (card/bank reversal): Triggers `NombaApiClient::refundTransaction`.
     *     Initiated when original transaction bank/card details are handled natively by Nomba.
     *  2. Bank transfer refund (direct payout fallback): Triggers `NombaApiClient::transferRefund`.
     *     Used when card/native reversals are not possible or fail. Directly pays out to the account details
     *     provided in the admin refund form panel (account number, bank code, and account name).
     *
     * On success:
     *  - Increments local database tracking `amount_refunded` in `ps_nomba_transaction`.
     *  - Updates order status to `PS_OS_REFUND` (Refunded) if fully paid back.
     *  - Logs success messages, adds an internal private note to the order, and redirects back to the order page.
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

        if (!Tools::isSubmit('submitNombaRefund')) {
            Tools::redirectAdmin($redirectToOrderView);
            return;
        }

        $payments = $order->getOrderPaymentCollection();
        $transactionRef = $payments->count() ? $payments[0]->transaction_id : '';

        if (empty($transactionRef)) {
            $this->context->cookie->nomba_refund_error = $this->module->l('Transaction ID not found for this order.');
            Tools::redirectAdmin($redirectToOrderView);
            return;
        }

        $nombaTxn = Db::getInstance()->getRow(
            'SELECT account_number, bank_code, amount_refunded, status, amount FROM ' . _DB_PREFIX_ . 'nomba_transaction
             WHERE id_order = ' . (int) $id_order
        );

        $amountRefunded = isset($nombaTxn['amount_refunded']) ? (float) $nombaTxn['amount_refunded'] : 0.0;
        $orderTotal = (float) $order->total_paid;
        $amountRemaining = $orderTotal - $amountRefunded;

        if ($amountRemaining <= 0.01 || (isset($nombaTxn['status']) && $nombaTxn['status'] === 'refunded')) {
            $this->context->cookie->nomba_refund_error = $this->module->l('This payment has already been fully refunded.');
            Tools::redirectAdmin($redirectToOrderView);
            return;
        }

        // Read form inputs
        $refundType = Tools::getValue('refund_type');
        $refundMethod = Tools::getValue('refund_method'); // 'native' or 'transfer'
        $refundAmountInput = (float) Tools::getValue('refund_amount');
        $accountNumberInput = trim(Tools::getValue('account_number'));
        $bankCodeInput = trim(Tools::getValue('bank_code'));
        $accountNameInput = trim(Tools::getValue('account_name'));

        // Determine refund amount
        if ($refundType === 'partial') {
            if ($refundAmountInput <= 0 || $refundAmountInput > $amountRemaining) {
                $this->context->cookie->nomba_refund_error = sprintf(
                    $this->module->l('Invalid refund amount. Must be between 0.01 and %s'),
                    Tools::displayPrice($amountRemaining)
                );
                Tools::redirectAdmin($redirectToOrderView);
                return;
            }
            $refundAmount = $refundAmountInput;
        } else {
            $refundAmount = $amountRemaining;
        }

        $nombaApi = new NombaApiClient();
        $successMethod = null;
        $apiMessage = '';

        try {
            if ($refundMethod === 'transfer') {
                // ─── BANK TRANSFER REFUND ───
                if (empty($accountNumberInput) || empty($bankCodeInput) || empty($accountNameInput)) {
                    throw new Exception($this->module->l('Bank transfer refund requires account number, bank code, and account name.'));
                }

                $merchantTxRef = 'REFUND-' . $order->reference . '-' . time();

                $result = $nombaApi->transferRefund(
                    $refundAmount,
                    $accountNumberInput,
                    $accountNameInput,
                    $bankCodeInput,
                    $merchantTxRef,
                    $this->module->displayName,
                    'Refund for order ' . $order->reference
                );

                $successMethod = 'transfer';
                $apiMessage = $result['data']['message'] ?? $result['description'] ?? 'Bank transfer initiated';

            } else {
                // ─── NATIVE CHECKOUT REFUND ───
                $result = $nombaApi->refundTransaction(
                    $transactionRef,
                    $refundType === 'partial' ? $refundAmount : null,
                    null,
                    null
                );

                $successMethod = 'native';
                $apiMessage = $result['data']['message'] ?? $result['description'] ?? 'Refund processed';
            }

            // Calculate new totals
            $newRefunded = $amountRefunded + $refundAmount;
            $isFullRefund = ($newRefunded >= $orderTotal - 0.01);
            $newStatus = $isFullRefund ? 'refunded' : 'partially_refunded';

            PrestaShopLogger::addLog(
                sprintf(
                    'Nomba Refund Successful (%s): Order #%d | Amount: %.2f | Status: %s | Msg: %s',
                    $successMethod,
                    $id_order,
                    $refundAmount,
                    $newStatus,
                    $apiMessage
                ),
                1,
                null,
                'Order',
                $id_order
            );

            // Internal order message
            $msg = new Message();
            $msg->message = sprintf(
                'Nomba %s refund (%s): %s | Amount: %.2f | Method: %s',
                $successMethod,
                $refundType,
                $apiMessage,
                $refundAmount,
                $successMethod === 'transfer'
                ? ('Bank ' . $bankCodeInput . ' / ' . $accountNumberInput . ' (' . $accountNameInput . ')')
                : 'Original payment method'
            );
            $msg->id_order = $id_order;
            $msg->private = 1;
            $msg->add();

            if ($isFullRefund) {
                $order->setCurrentState((int) Configuration::get('PS_OS_REFUND'));
            }

            $updateData = [
                'amount_refunded' => (float) $newRefunded,
                'status' => $newStatus,
                'date_upd' => date('Y-m-d H:i:s')
            ];
            if ($successMethod === 'transfer') {
                $updateData['account_number'] = pSQL($accountNumberInput);
                $updateData['bank_code'] = pSQL($bankCodeInput);
            }
            Db::getInstance()->update('nomba_transaction', $updateData, 'id_order = ' . (int) $id_order);

            $this->context->cookie->nomba_refund_success = sprintf(
                $this->module->l('Refund of %s processed via %s: %s'),
                Tools::displayPrice($refundAmount),
                $successMethod === 'native' ? 'Card Reversal' : 'Bank Transfer',
                $apiMessage
            );

        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'Nomba Refund API Failure: ' . $e->getMessage() . ' | Order ID: ' . $id_order,
                3,
                null,
                'Order',
                $id_order
            );
            $this->context->cookie->nomba_refund_error = $this->module->l('Nomba Refund Failed: ') . $e->getMessage();
        }

        Tools::redirectAdmin($redirectToOrderView);
    }
}