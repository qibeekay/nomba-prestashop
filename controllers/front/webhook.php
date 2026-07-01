<?php
class NombaWebhookModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $module;

    public function init()
    {
        parent::init();
        $this->module = Module::getInstanceByName('nomba');
        $this->ajax = true;
    }

    public function display()
    {
        exit;
    }

    public function postProcess()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $reference = Tools::getValue('orderReference') ?? Tools::getValue('reference');
            if ($reference) {
                $validationUrl = $this->context->link->getModuleLink('nomba', 'validation', ['reference' => $reference], true);
                Tools::redirect($validationUrl);
            }
            http_response_code(200);
            die('OK');
        }

        $payload = file_get_contents('php://input');

        if (empty($payload)) {
            http_response_code(400);
            die('Empty payload');
        }

        $data = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            die('Invalid JSON');
        }

        // Verification ping
        if (empty($data) || (!isset($data['event_type']) && !isset($data['event']) && !isset($data['order']))) {
            http_response_code(200);
            die('OK');
        }

        $eventType = $data['event_type'] ?? $data['event'] ?? '';

        // Handle refund webhook
        if ($eventType == 'transaction.refund.successful' || $eventType == 'refund.successful') {
            // 1. Pull order reference out using our reliable fallback parsing chain
            $orderReference = $data['orderReference']
                ?? $data['order']['orderReference']
                ?? $data['data']['order']['orderReference']
                ?? $data['data']['merchantTxRef']
                ?? null;

            if ($orderReference) {
                $parts = explode('_', $orderReference);
                $cartId = isset($parts[1]) ? (int)$parts[1] : 0;

                if ($cartId) {
                    $orderId = (int)Order::getIdByCartId($cartId);
                    if ($orderId) {
                        $order = new Order($orderId);
                        if (Validate::isLoadedObject($order)) {
                            // Update PrestaShop Order State to Refunded
                            $order->setCurrentState((int)Configuration::get('PS_OS_REFUND'));

                            // Update your custom transaction database logs using unique id_cart
                            Db::getInstance()->update(
                                'nomba_transaction',
                                [
                                    'status' => 'refunded',
                                    'date_upd' => date('Y-m-d H:i:s')
                                ],
                                'id_cart = ' . (int)$cartId
                            );
                        }
                    }
                }
            }
            http_response_code(200);
            die('Refund processed');
        }

        // Ignore non-payment events
        if ($eventType && $eventType !== 'payment_success' && $eventType !== 'transaction.successful' && !isset($data['order'])) {
            http_response_code(200);
            die('Event ignored');
        }

        $orderReference = $data['orderReference']
            ?? $data['order']['orderReference']
            ?? $data['data']['order']['orderReference']
            ?? $data['data']['merchantTxRef']
            ?? $data['data']['orderReference']
            ?? null;

        $transactionRef = $data['transactionReference']
            ?? $data['data']['transaction']['transactionId']
            ?? $data['data']['transactionReference']
            ?? $data['id']
            ?? $data['order']['orderId']
            ?? $data['data']['order']['orderId']
            ?? $orderReference;

        $amount = $data['amount']
            ?? $data['order']['amount']
            ?? $data['data']['order']['amount']
            ?? $data['data']['transaction']['transactionAmount']
            ?? $data['data']['amount']
            ?? 0;

        $accountNumber = $data['data']['accountNumber'] ?? $data['data']['customer']['accountNumber'] ?? $data['order']['accountNumber'] ?? null;
        $bankCode = $data['data']['bankCode'] ?? $data['data']['customer']['bankCode'] ?? $data['order']['bankCode'] ?? null;

        if (!$orderReference) {
            http_response_code(400);
            die('Missing orderReference');
        }

        try {
            $parts = explode('_', $orderReference);
            $cartId = isset($parts[1]) ? (int)$parts[1] : 0;
            if (!$cartId) {
                throw new Exception('Cannot extract cart ID from: ' . $orderReference);
            }

            $cart = new Cart($cartId);
            if (!Validate::isLoadedObject($cart)) {
                throw new Exception('Cart not found: ' . $cartId);
            }

            if (Order::getIdByCartId($cartId)) {
                http_response_code(200);
                die('Order exists');
            }

            $cartTotal = (float)$cart->getOrderTotal(true, Cart::BOTH);
            if (abs($cartTotal - (float)$amount) > 0.01) {
                throw new Exception('Amount mismatch. Cart: ' . $cartTotal . ' Nomba: ' . $amount);
            }

            $customer = new Customer($cart->id_customer);
            $this->module->validateOrder(
                (int)$cart->id,
                (int)Configuration::get('PS_OS_PAYMENT'),
                (float)$amount,
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

                Db::getInstance()->insert('nomba_transaction', [
                    'id_cart' => (int)$cartId,
                    'id_order' => (int)$orderId,
                    'nomba_order_id' => pSQL($transactionRef),
                    'nomba_order_reference' => pSQL($orderReference),
                    'amount' => (float)$amount,
                    'account_number' => pSQL($accountNumber),
                    'bank_code' => pSQL($bankCode),
                    'status' => 'completed',
                    'date_add' => date('Y-m-d H:i:s'),
                    'date_upd' => date('Y-m-d H:i:s'),
                ]);
            }

            http_response_code(200);
            die('OK');
        } catch (Exception $e) {
            PrestaShopLogger::addLog('Nomba Webhook Error: ' . $e->getMessage(), 3);
            http_response_code(500);
            die('Error: ' . $e->getMessage());
        }
    }
}
