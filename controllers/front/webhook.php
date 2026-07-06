<?php
/**
 * Front controller for handling Nomba webhook calls.
 * Processes payment and refund event payloads asynchronously.
 */
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

    /**
     * Polyfill for getallheaders() on Nginx/PHP-FPM
     */
    private function getRequestHeaders()
    {
        if (function_exists('getallheaders')) {
            return array_change_key_case(getallheaders(), CASE_LOWER);
        }
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[strtolower($key)] = $value;
            }
        }
        return $headers;
    }

    public function postProcess()
    {
        $logFile = _PS_MODULE_DIR_ . 'nomba/webhook.log';
        $timestamp = date('Y-m-d H:i:s');
        $payload = file_get_contents('php://input');
        $headers = $this->getRequestHeaders();

        // ===== WEBHOOK LOGGING BLOCK =====
        $log = "[$timestamp] ========== NEW WEBHOOK HIT ==========\n";
        $log .= "Method: " . $_SERVER['REQUEST_METHOD'] . "\n";
        $log .= "IP: " . $_SERVER['REMOTE_ADDR'] . "\n";
        $log .= "User-Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'N/A') . "\n";
        $log .= "Raw Payload: " . $payload . "\n";
        $log .= "GET params: " . json_encode($_GET) . "\n";
        $log .= "Headers: " . json_encode($headers) . "\n";
        $log .= "--------------------------------------------\n";
        file_put_contents($logFile, $log, FILE_APPEND);
        // ===== WEBHOOK LOGGING BLOCK =====

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $reference = Tools::getValue('orderReference') ?? Tools::getValue('reference');
            if ($reference) {
                $validationUrl = $this->context->link->getModuleLink('nomba', 'validation', ['reference' => $reference], true);
                Tools::redirect($validationUrl);
            }
            http_response_code(200);
            die('OK');
        }

        // Decode first to check if it's a ping
        $data = json_decode($payload, true);

        // If payload is empty, {}, or has no event_type = verification ping
        if (empty($payload) || $payload === '{}' || !isset($data['event_type'])) {
            file_put_contents($logFile, "[$timestamp] INFO: Verification ping detected - returning 200\n\n", FILE_APPEND);
            http_response_code(200);
            die('OK');
        }

        // Only real webhooks get here - now we REQUIRE signature
        if (empty($headers['nomba-signature']) || empty($headers['nomba-timestamp'])) {
            file_put_contents($logFile, "[$timestamp] ERROR: Real webhook missing nomba-signature or nomba-timestamp\n\n", FILE_APPEND);
            http_response_code(401);
            die('Missing signature headers');
        }

        require_once _PS_MODULE_DIR_ . 'nomba/src/Service/NombaApiClient.php';
        $nombaApi = new NombaApiClient();

        if (!$nombaApi->verifyWebhookSignature($payload, $headers)) {
            file_put_contents($logFile, "[$timestamp] ERROR: Signature verification failed\n\n", FILE_APPEND);
            http_response_code(401);
            die('Invalid signature');
        }

        file_put_contents($logFile, "[$timestamp] INFO: Signature verified OK\n", FILE_APPEND);

        // ===== END SIGNATURE VALIDATION =====

        if (json_last_error() !== JSON_ERROR_NONE) {
            file_put_contents($logFile, "[$timestamp] ERROR: Invalid JSON - " . json_last_error_msg() . "\n\n", FILE_APPEND);
            http_response_code(400);
            die('Invalid JSON');
        }

        // Verification ping
        if (empty($data) || (!isset($data['event_type']) && !isset($data['event']) && !isset($data['order']))) {
            file_put_contents($logFile, "[$timestamp] INFO: Verification ping\n\n", FILE_APPEND);
            http_response_code(200);
            die('OK');
        }

        $eventType = $data['event_type'] ?? $data['event'] ?? '';
        file_put_contents($logFile, "[$timestamp] INFO: Event Type: $eventType\n", FILE_APPEND);

        // Handle refund webhook
        if ($eventType == 'transaction.refund.successful' || $eventType == 'refund.successful') {
            $orderReference = $data['orderReference']
                ?? $data['order']['orderReference']
                ?? $data['data']['order']['orderReference']
                ?? $data['data']['merchantTxRef']
                ?? null;

            file_put_contents($logFile, "[$timestamp] REFUND: orderReference = $orderReference\n", FILE_APPEND);

            if ($orderReference) {
                $parts = explode('_', $orderReference);
                $cartId = isset($parts[1]) ? (int) $parts[1] : 0;

                if ($cartId) {
                    $orderId = (int) Order::getIdByCartId($cartId);
                    if ($orderId) {
                        $order = new Order($orderId);
                        if (Validate::isLoadedObject($order)) {
                            $order->setCurrentState((int) Configuration::get('PS_OS_REFUND'));
                            Db::getInstance()->update(
                                _DB_PREFIX_ . 'nomba_transaction',
                                [
                                    'status' => 'refunded',
                                    'date_upd' => date('Y-m-d H:i:s')
                                ],
                                'id_cart = ' . (int) $cartId
                            );
                            file_put_contents($logFile, "[$timestamp] REFUND: Order $orderId updated\n\n", FILE_APPEND);
                        }
                    }
                }
            }
            http_response_code(200);
            die('Refund processed');
        }

        // Ignore non-payment events
        if ($eventType && $eventType !== 'payment_success' && $eventType !== 'transaction.successful' && !isset($data['order'])) {
            file_put_contents($logFile, "[$timestamp] INFO: Event ignored: $eventType\n\n", FILE_APPEND);
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

        $accountNumber = $data['data']['accountNumber'] ?? $data['data']['customer']['accountNumber'] ?? $data['order']['accountNumber'] ?? $data['data']['customer']['billerId'] ?? null;

        $bankCode = $data['data']['bankCode'] ?? $data['data']['customer']['bankCode'] ?? $data['order']['bankCode'] ?? $data['data']['customer']['productId'] ?? null;

        file_put_contents($logFile, "[$timestamp] PARSED: orderRef=$orderReference, txnRef=$transactionRef, amount=$amount, acct=$accountNumber, bank=$bankCode\n", FILE_APPEND);

        if (!$orderReference) {
            file_put_contents($logFile, "[$timestamp] ERROR: Missing orderReference\n\n", FILE_APPEND);
            http_response_code(400);
            die('Missing orderReference');
        }

        try {
            $parts = explode('_', $orderReference);
            $cartId = isset($parts[1]) ? (int) $parts[1] : 0;
            if (!$cartId) {
                throw new Exception('Cannot extract cart ID from: ' . $orderReference);
            }

            $cart = new Cart($cartId);
            if (!Validate::isLoadedObject($cart)) {
                throw new Exception('Cart not found: ' . $cartId);
            }

            $cartTotal = (float) $cart->getOrderTotal(true, Cart::BOTH);
            $nombaAmount = (float) $amount;
            $fee = (float) ($data['data']['transaction']['fee'] ?? 0);

            // Allow tolerance for Nomba fees (up to 1 NGN or fee amount + 0.01)
            $maxDifference = max(1.00, $fee + 0.01);

            if (abs($cartTotal - $nombaAmount) > $maxDifference) {
                throw new Exception('Amount mismatch. Cart: ' . $cartTotal . ' Nomba: ' . $nombaAmount . ' Fee: ' . $fee);
            }

            // 1. Check if order exists - create if not
            $orderId = (int) Order::getIdByCartId($cartId);

            if (!$orderId) {
                $customer = new Customer($cart->id_customer);
                $this->module->validateOrder(
                    (int) $cart->id,
                    (int) Configuration::get('PS_OS_PAYMENT'),
                    (float) $cartTotal,
                    $this->module->displayName,
                    'Nomba TXN: ' . $transactionRef . ' | Ref: ' . $orderReference,
                    ['transaction_id' => $transactionRef],
                    null,
                    false,
                    $customer->secure_key
                );
                $orderId = $this->module->currentOrder;
                file_put_contents($logFile, "[$timestamp] INFO: Order $orderId created by webhook\n", FILE_APPEND);
            } else {
                file_put_contents($logFile, "[$timestamp] INFO: Order $orderId already exists for cart $cartId\n", FILE_APPEND);
            }

            // 2. Now check if we already saved to nomba_transaction table
            $txnRecordId = (int) Db::getInstance()->getValue(
                'SELECT id_nomba_transaction FROM `' . _DB_PREFIX_ . 'nomba_transaction` WHERE id_cart = ' . (int) $cartId
            );

            if ($txnRecordId) {
                file_put_contents($logFile, "[$timestamp] INFO: Transaction already logged for cart $cartId. ID: $txnRecordId\n\n", FILE_APPEND);
                http_response_code(200);
                die('Transaction already exists');
            }

            // 3. Save to nomba_transaction table
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

                $insertResult = Db::getInstance()->insert('nomba_transaction', [
                    'id_cart' => (int) $cartId,
                    'id_order' => (int) $orderId,
                    'nomba_order_id' => pSQL($transactionRef),
                    'nomba_order_reference' => pSQL($orderReference),
                    'amount' => (float) $amount,
                    'account_number' => $accountNumber,
                    'bank_code' => $bankCode,
                    'status' => 'completed',
                    'date_add' => date('Y-m-d H:i:s'),
                    'date_upd' => date('Y-m-d H:i:s'),
                ]);

                if (!$insertResult) {
                    $sqlError = Db::getInstance()->getMsgError();
                    file_put_contents($logFile, "[$timestamp] ERROR: DB Insert failed: $sqlError\n\n", FILE_APPEND);
                    throw new Exception('Failed to save transaction: ' . $sqlError);
                }

                file_put_contents($logFile, "[$timestamp] SUCCESS: Transaction logged for Order $orderId, Cart $cartId\n\n", FILE_APPEND);
            }

            http_response_code(200);
            die('OK');

        } catch (Exception $e) {
            PrestaShopLogger::addLog('Nomba Webhook Error: ' . $e->getMessage(), 3);
            file_put_contents($logFile, "[$timestamp] EXCEPTION: " . $e->getMessage() . "\n\n", FILE_APPEND);
            http_response_code(500);
            die('Error: ' . $e->getMessage());
        }
    }
}