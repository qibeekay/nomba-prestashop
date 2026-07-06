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

    // Nomba Hackathon / Production constants
    // const PARENT_ACCOUNT_ID = 'f666ef9b-888e-4799-85ce-acb505b28023';
    const PARENT_ACCOUNT_ID = 'd4e77b84-2c39-48da-85d5-fa5edaa3b63c';
    const WEBHOOK_KEY = 'NombaHackathon2027';

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
     * Cryptographically validates incoming webhooks against the production private key.
     *
     * @param string $payload Raw request JSON payload content.
     * @param array $headers Parsed request headers.
     * @return bool True if signature matches, false otherwise.
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
     * @param float $amount Amount to be refunded
     * @param string $accountNumber Customer bank account number
     * @param string $bankCode Customer bank sorting code
     * @return array Decoded API response array
     * @throws \Exception On cURL errors or non-200 server responses
     */
    public function refundTransaction($transactionRef, $amount, $accountNumber, $bankCode)
    {
        $token = $this->getAccessToken();

        $payload = [
            'transactionId' => $transactionRef,
            'amount' => number_format((float) $amount, 2, '.', ''),
            'accountNumber' => $accountNumber,
            'bankCode' => $bankCode,
            'accountId' => $this->accountId
        ];

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
}
