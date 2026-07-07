<?php

/**
 * API client for interacting with the Nomba Checkout API.
 * Manages authorization token retrieval, checkout link creation, and refund processing.
 */
class NombaApiClient
{
    /** @var string Client ID configuration value */
    private $clientId;

    /** @var string Client Secret/Private Key configuration value */
    private $privateKey;

    /** @var string Account ID configuration value */
    private $accountId;

    /** @var bool Active mode (true = production, false = sandbox) */
    private $isLive;

    /** @var string API target base url path */
    private $baseUrl;

    /** @var string|null Cached access token string */
    private $accessToken;

    /** @var string Webhook verification key */
    private $webhookKey;

    /**
     * Parent developer account ID issued by the Nomba platform.
     * Crucial: The `accountId` HTTP header of all API calls (authentication, checkout, refunds) must be this Parent Account ID.
     */
    const PARENT_ACCOUNT_ID = 'd4e77b84-2c39-48da-85d5-fa5edaa3b63c';

    /**
     * Default webhook validation secret key used during the hackathon/sandbox environment.
     */
    const WEBHOOK_KEY = 'NombaHackathon2026';

    /**
     * NombaApiClient constructor.
     * Loads saved Configuration settings and constructs API endpoints.
     */
    public function __construct()
    {
        $this->clientId = \Configuration::get('NOMBA_CLIENT_ID');
        $this->privateKey = \Configuration::get('NOMBA_PRIVATE_KEY');
        $this->accountId = \Configuration::get('NOMBA_ACCOUNT_ID'); // This is your SUB-ACCOUNT ID
        $this->isLive = (bool) \Configuration::get('NOMBA_LIVE_MODE');
        $this->baseUrl = $this->isLive
            ? 'https://api.nomba.com/v1'
            : 'https://sandbox.nomba.com/v1';
        $this->webhookKey = \Configuration::get('NOMBA_WEBHOOK_KEY') ?: self::WEBHOOK_KEY;
    }

    /**
     * Issues and caches a secure authentication Access Token from Nomba API server.
     *
     * @return string Valid access token.
     * @throws \Exception If cURL fails or server returns error status code.
     */
    private function getAccessToken()
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $ch = curl_init($this->baseUrl . '/auth/token/issue');

        $authPayload = [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->privateKey
        ];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($authPayload));

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'accountId: ' . self::PARENT_ACCOUNT_ID // Golden rule: header is ALWAYS parent
        ]);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new \Exception('Auth cURL Error: ' . curl_error($ch));
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);

        $token = $result['access_token']
            ?? $result['data']['access_token']
            ?? $result['accessToken']
            ?? $result['data']['accessToken']
            ?? null;

        if ($httpCode === 200 && $token !== null) {
            $this->accessToken = $token;
            return $this->accessToken;
        }

        PrestaShopLogger::addLog('Nomba Auth Error Response: ' . $response, 3);
        throw new \Exception('Nomba Authentication Failed (' . $httpCode . '): ' . ($result['description'] ?? 'Unexpected response format'));
    }

    /**
     * Calls Nomba order creation endpoint to obtain a redirect checkout hosted link.
     *
     * @param Cart $cart PrestaShop cart object instance.
     * @param string $orderReference Unique order reference sequence.
     * @return array Decoded response body.
     * @throws \Exception On cURL errors or non-20x status replies.
     */
    public function createCheckoutOrder($cart, $orderReference)
    {
        $token = $this->getAccessToken();
        $context = \Context::getContext();
        $link = $context->link;  // <-- use $context, not $this->context
        $baseUrl = $link->getBaseLink(null, true);
        $currency = new Currency($cart->id_currency);

        $payload = [
            'order' => [
                'orderReference' => $orderReference,
                'callbackUrl' => $baseUrl . 'index.php?fc=module&module=nomba&controller=webhook',
                'redirectUrl' => $baseUrl . 'index.php?fc=module&module=nomba&controller=validation&reference=' . $orderReference,
                'customerEmail' => $context->customer->email,
                'amount' => number_format($cart->getOrderTotal(true), 2, '.', ''),
                'currency' => $currency->iso_code,
                'accountId' => $this->accountId,
            ],
        ];


        $ch = curl_init($this->baseUrl . '/checkout/order');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'accountId: ' . self::PARENT_ACCOUNT_ID,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            throw new \Exception('cURL Error: ' . curl_error($ch));
        }

        curl_close($ch);
        $result = json_decode($response, true);

        if ($httpCode !== 200 && $httpCode !== 201) {
            PrestaShopLogger::addLog('Nomba HTTP Code: ' . $httpCode, 3);
            PrestaShopLogger::addLog('Nomba Response: ' . $response, 3);
            throw new \Exception('Nomba API Error: ' . ($result['message'] ?? $response));
        }

        return $result;
    }

    /**
     * Cryptographically validates incoming webhooks against the configured webhook secret key.
     * Computes the HMAC-SHA256 signature using the hashing payload layout specified by Nomba:
     * `{event_type}:{requestId}:{userId}:{walletId}:{transactionId}:{type}:{time}:{responseCode}:{timestamp}`
     *
     * It iteratively checks the custom configured key, default constant key, and fallback hardcoded key
     * using a constant-time comparison to prevent timing attacks.
     *
     * @param string $payload Raw request JSON payload content.
     * @param array $headers Parsed request headers (specifically `nomba-signature` or `nomba-sig-value`, and `nomba-timestamp`).
     * @return bool True if signature matches any of the valid keys, false otherwise.
     */
    public function verifyWebhookSignature($payload, $headers)
    {
        $signature = $headers['nomba-signature'] ?? $headers['nomba-sig-value'] ?? '';
        $timestamp = $headers['nomba-timestamp'] ?? '';

        if (empty($signature) || empty($timestamp)) {
            return false;
        }

        $data = json_decode($payload, true);
        $merchant = $data['data']['merchant'] ?? [];
        $transaction = $data['data']['transaction'] ?? [];

        $eventType = $data['event_type'] ?? '';
        $requestId = $data['requestId'] ?? '';
        $userId = $merchant['userId'] ?? '';
        $walletId = $merchant['walletId'] ?? '';
        $transactionId = $transaction['transactionId'] ?? '';
        $transactionType = $transaction['type'] ?? '';
        $transactionTime = $transaction['time'] ?? '';
        $responseCode = $transaction['responseCode'] ?? '';

        if ($responseCode === "null") {
            $responseCode = '';
        }

        $hashingPayload = sprintf(
            "%s:%s:%s:%s:%s:%s:%s:%s:%s",
            $eventType,
            $requestId,
            $userId,
            $walletId,
            $transactionId,
            $transactionType,
            $transactionTime,
            $responseCode,
            $timestamp
        );

        // Try both keys
        $keys = [
            'config' => \Configuration::get('NOMBA_WEBHOOK_KEY'),
            'constant' => self::WEBHOOK_KEY,
            'hardcoded' => 'NombaHackathon2027'
        ];

        foreach ($keys as $name => $key) {
            if (empty($key))
                continue;
            $computed = base64_encode(hash_hmac('sha256', $hashingPayload, $key, true));
            $match = hash_equals($computed, $signature);
            PrestaShopLogger::addLog("Nomba Key [$name] (len " . strlen($key) . "): $computed | " . ($match ? 'MATCH' : 'NO'), 1);
            if ($match) {
                return true;
            }
        }

        return false;
    }

    /**
     * Process a checkout refund via the Nomba API
     *
     * @param string $transactionRef Unique transaction identifier to refund
     * @param float|null $amount Amount to be refunded (null for full refund)
     * @param string|null $accountNumber Customer bank account number
     * @param string|null $bankCode Customer bank sorting code
     * @return array Decoded API response array
     * @throws \Exception On cURL errors or non-200 server responses
     */
    public function refundTransaction($transactionRef, $amount = null, $accountNumber = null, $bankCode = null)
    {
        $token = $this->getAccessToken();

        $payload = [
            'transactionId' => $transactionRef,
            // 'accountId' => $this->accountId
        ];

        if ($amount !== null && (float) $amount > 0) {
            $payload['amount'] = round((float) $amount, 2);
        }

        if (!empty($accountNumber) && $accountNumber !== 'N/A') {
            $payload['accountNumber'] = $accountNumber;
        }

        if (!empty($bankCode) && $bankCode !== 'N/A') {
            $payload['bankCode'] = $bankCode;
        }

        $jsonPayload = json_encode($payload);
        // $signature = hash_hmac('sha512', $jsonPayload, $this->privateKey);

        $ch = curl_init($this->baseUrl . '/checkout/refund');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'accountId: ' . self::PARENT_ACCOUNT_ID,
            // 'Signature: ' . $signature
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            throw new \Exception('cURL Error: ' . curl_error($ch));
        }

        curl_close($ch);
        $result = json_decode($response, true);

        if ($httpCode !== 200 && $httpCode !== 201) {
            PrestaShopLogger::addLog('Nomba Refund HTTP Error Code: ' . $httpCode, 3);
            PrestaShopLogger::addLog('Nomba Refund Error Response Body: ' . $response, 3);
            throw new \Exception('Nomba Refund Error: ' . ($result['description'] ?? $response));
        }

        $nombaCode = $result['code'] ?? null;
        $nombaSuccess = $result['data']['success'] ?? null;
        $nombaMessage = $result['data']['message'] ?? $result['description'] ?? $response;

        if ($nombaCode !== '00') {
            PrestaShopLogger::addLog(
                'Nomba Refund Business Error. Code: ' . $nombaCode . ' | Message: ' . $nombaMessage . ' | Response: ' . $response,
                3
            );
            throw new \Exception('Nomba Refund Failed: ' . $nombaMessage);
        }

        if ($nombaSuccess !== true && $nombaSuccess !== null) {
            PrestaShopLogger::addLog(
                'Nomba Refund API reported failure. success=' . var_export($nombaSuccess, true) . ' | Message: ' . $nombaMessage . ' | Response: ' . $response,
                3
            );
            throw new \Exception('Nomba Refund Failed: ' . $nombaMessage);
        }

        return $result;
    }

    /**
     * Checks the status of a checkout order by its order reference.
     *
     * @param string $orderReference
     * @return array|null The transaction data array if successful and found, or null.
     */
    public function getTransactionByOrderReference($orderReference)
    {
        try {
            $token = $this->getAccessToken();
            $url = $this->baseUrl . '/transactions/accounts/single?orderReference=' . urlencode($orderReference);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $token,
                'accountId: ' . self::PARENT_ACCOUNT_ID,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (curl_errno($ch)) {
                PrestaShopLogger::addLog('Nomba API (getTransactionByOrderReference) cURL Error: ' . curl_error($ch), 3);
                return null;
            }

            curl_close($ch);
            $result = json_decode($response, true);

            if ($httpCode === 200 && isset($result['data'])) {
                return $result['data'];
            }

            PrestaShopLogger::addLog('Nomba API (getTransactionByOrderReference) Error. HTTP Code: ' . $httpCode . ' Response: ' . $response, 3);
            return null;
        } catch (\Exception $e) {
            PrestaShopLogger::addLog('Nomba API (getTransactionByOrderReference) Exception: ' . $e->getMessage(), 3);
            return null;
        }
    }

    /**
     * Perform a bank transfer refund by transferring funds from sub-account to customer bank account.
     * This serves as a fallback when native checkout refund fails or is not supported.
     *
     * @param float $amount Amount to transfer
     * @param string $accountNumber Recipient bank account number
     * @param string $accountName Recipient account name
     * @param string $bankCode Recipient bank code
     * @param string $merchantTxRef Unique reference for idempotency (e.g., REFUND-ORDER-123-1699123456)
     * @param string $senderName Sender name shown on recipient's statement
     * @param string|null $narration Optional narration/description
     * @return array Decoded API response
     * @throws \Exception On API errors
     */
    public function transferRefund($amount, $accountNumber, $accountName, $bankCode, $merchantTxRef, $senderName, $narration = null)
    {
        $token = $this->getAccessToken();

        $payload = [
            'amount' => round((float) $amount, 2),
            'accountNumber' => $accountNumber,
            'accountName' => $accountName,
            'bankCode' => $bankCode,
            'merchantTxRef' => $merchantTxRef,
            'senderName' => $senderName,
        ];

        if (!empty($narration)) {
            $payload['narration'] = $narration;
        }

        $jsonPayload = json_encode($payload);

        // Log request for debugging
        PrestaShopLogger::addLog(
            'Nomba Transfer Refund Request: subAccount=' . $this->accountId . ' | Payload=' . $jsonPayload,
            1
        );

        $url = $this->baseUrl . '/transfers/bank/' . $this->accountId;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'accountId: ' . self::PARENT_ACCOUNT_ID,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            throw new \Exception('Transfer cURL Error: ' . curl_error($ch));
        }

        curl_close($ch);
        $result = json_decode($response, true);

        PrestaShopLogger::addLog(
            'Nomba Transfer Refund Response: HTTP=' . $httpCode . ' | Body=' . $response,
            1
        );

        if ($httpCode !== 200 && $httpCode !== 201) {
            PrestaShopLogger::addLog('Nomba Transfer HTTP Error: ' . $httpCode . ' | ' . $response, 3);
            throw new \Exception('Nomba Transfer Error: ' . ($result['description'] ?? $response));
        }

        $nombaCode = $result['code'] ?? null;
        $nombaStatus = $result['status'] ?? null;
        $nombaDescription = $result['description'] ?? '';

        $isFailedStatus = ($nombaStatus === 'false' || $nombaStatus === false || $nombaStatus === 0);

        if ($nombaCode !== '00' || $isFailedStatus) {
            $errorMsg = !empty($nombaDescription) ? $nombaDescription : 'Transfer failed (code: ' . $nombaCode . ')';
            PrestaShopLogger::addLog('Nomba Transfer Business Error: ' . $errorMsg, 3);
            throw new \Exception('Nomba Transfer Failed: ' . $errorMsg);
        }

        return $result;
    }
}
