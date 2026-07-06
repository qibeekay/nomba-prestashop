<?php
/**
 * Front controller for validating and resolving Nomba payments.
 * Handles client browser redirect callback, checks status, and loads confirmation or pending state.
 */
class NombaValidationModuleFrontController extends ModuleFrontController
{
    /** @var Nomba Module instance reference */
    public $module;

    /**
     * Initializes the controller, fetches the module instance, and validates it.
     *
     * @return void
     */
    public function init()
    {
        parent::init();
        $this->module = Module::getInstanceByName('nomba');

        if (!Validate::isLoadedObject($this->module)) {
            die('Nomba module not found');
        }
    }

    /**
     * Resolves redirect validation status.
     * Redirects to order confirmation if webhook has already run successfully,
     * handles explicit failures, or renders the pending state page.
     *
     * @return void
     */
    public function initContent()
    {
        parent::initContent();

        $reference = Tools::getValue('reference');
        $status = Tools::getValue('status');

        if (!$reference) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        // Extract cart ID from reference: PS_10_1783187637
        $parts = explode('_', $reference);
        $cartId = isset($parts[1]) ? (int) $parts[1] : 0;

        if (!$cartId) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        // 1. Check if order exists for THIS cart ID, not session cart
        $orderId = Order::getIdByCartId($cartId);
        if ($orderId) {
            $order = new Order($orderId);
            $customer = new Customer($order->id_customer);
            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . $cartId . '&id_module=' . $this->module->id . '&id_order=' . $orderId . '&key=' . $customer->secure_key);
        }

        // 2. If Nomba said payment failed on redirect, don't wait for webhook
        if ($status && $status !== '00') {
            PrestaShopLogger::addLog('Nomba Redirect: Payment failed. Status: ' . $status, 3);
            $this->errors[] = $this->module->l('Payment was not successful.');
            $this->redirectWithNotifications($this->context->link->getPageLink('order', true, null, ['step' => '3']));
            return;
        }

        // 2.5 API Fallback Check: Query Nomba directly to see if payment was actually successful
        require_once _PS_MODULE_DIR_ . 'nomba/src/Service/NombaApiClient.php';
        $nombaApi = new NombaApiClient();
        $txnData = $nombaApi->getTransactionByOrderReference($reference);

        if ($txnData && isset($txnData['status']) && strtoupper($txnData['status']) === 'SUCCESS') {
            $amount = (float) ($txnData['amount'] ?? 0);
            $transactionRef = $txnData['transactionRef'] ?? $txnData['transactionId'] ?? $reference;
            $accountNumber = $txnData['accountNumber'] ?? $txnData['customer']['accountNumber'] ?? null;
            $bankCode = $txnData['bankCode'] ?? $txnData['customer']['bankCode'] ?? null;
            $nombaFee = (float) ($txnData['fee'] ?? 0);

            try {
                $cart = new Cart($cartId);
                if (Validate::isLoadedObject($cart)) {
                    $cartTotal = (float) $cart->getOrderTotal(true, Cart::BOTH);
                    $nombaAmount = (float) $amount;
                    $fee = (float) ($txnData['fee'] ?? 0);

                    $maxDifference = max(1.00, $fee + 0.01);

                    if (abs($cartTotal - $nombaAmount) <= $maxDifference) {
                        PrestaShopLogger::addLog("Nomba API Fallback: Amount diff. Cart: {$cartTotal} Nomba: {$amount} Fee: {$nombaFee}. Using Nomba amount.", 1);
                    }

                    $customer = new Customer($cart->id_customer);
                    $this->module->validateOrder(
                        (int) $cart->id,
                        (int) Configuration::get('PS_OS_PAYMENT'),
                        $cartTotal,  // <-- Use cart total, not $amount
                        $this->module->displayName,
                        'Nomba TXN: ' . $transactionRef . ' | Ref: ' . $orderReference,
                        ['transaction_id' => $transactionRef],
                        null,
                        false,
                        $customer->secure_key
                    );
                    $orderId = $this->module->currentOrder;

                    if ($orderId) {
                        $order = new Order($orderId);
                        $payments = $order->getOrderPaymentCollection();
                        if (count($payments) > 0) {
                            $payments[0]->transaction_id = $transactionRef;
                            $payments[0]->update();
                        }

                        // Clean "N/A" values
                        $accountNumber = ($accountNumber === 'N/A' || empty($accountNumber)) ? null : pSQL($accountNumber);
                        $bankCode = ($bankCode === 'N/A' || empty($bankCode)) ? null : pSQL($bankCode);

                        // Save to nomba_transaction table if not already exists
                        $txnRecordId = (int) Db::getInstance()->getValue(
                            'SELECT id_nomba_transaction FROM `' . _DB_PREFIX_ . 'nomba_transaction` WHERE id_cart = ' . (int) $cartId
                        );

                        if (!$txnRecordId) {
                            Db::getInstance()->insert('nomba_transaction', [
                                'id_cart' => (int) $cartId,
                                'id_order' => (int) $orderId,
                                'nomba_order_id' => pSQL($transactionRef),
                                'nomba_order_reference' => pSQL($reference),
                                'amount' => $amount,
                                'account_number' => $accountNumber,
                                'bank_code' => $bankCode,
                                'status' => 'completed',
                                'date_add' => date('Y-m-d H:i:s'),
                                'date_upd' => date('Y-m-d H:i:s'),
                            ]);
                        }

                        Tools::redirect('index.php?controller=order-confirmation&id_cart=' . $cartId . '&id_module=' . $this->module->id . '&id_order=' . $orderId . '&key=' . $customer->secure_key);
                    }
                }
            } catch (Exception $e) {
                PrestaShopLogger::addLog('Nomba API fallback Error: ' . $e->getMessage(), 3);
            }
        }

        // 3. Otherwise show pending page - waiting for webhook
        $this->context->smarty->assign([
            'nomba_reference' => $reference,
            'check_url' => $this->context->link->getModuleLink('nomba', 'validation', ['reference' => $reference])
        ]);

        $this->setTemplate('module:nomba/views/templates/front/pending.tpl');
    }
}