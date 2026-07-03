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

    /** @var string Sub Account ID configuration value */
    private $accountId;

    /** @var bool Active mode (true = production, false = sandbox) */
    private $isLive;

    /** @var string API target base url path */
    private $baseUrl;

    /** @var string|null Cached access token string */
    private $accessToken;

    // Hackathon parent account ID - must be in header
    const PARENT_ACCOUNT_ID = 'f666ef9b-888e-4799-85ce-acb505b28023';
    // Hackathon webhook signing key
    const WEBHOOK_KEY = 'NombaHackathon2026';

    /**
     * NombaApiClient constructor.
     * Loads saved Configuration settings and constructs API endpoints.
     */
    public function __construct()
    {
        $this->clientId = \Configuration::get('NOMBA_CLIENT_ID');
        $this->privateKey = \Configuration::get('NOMBA_PRIVATE_KEY');
        $this->accountId = \Configuration::get('NOMBA_ACCOUNT_ID');
        $this->isLive = (bool) \Configuration::get('NOMBA_LIVE_MODE');
        $this->baseUrl = $this->isLive
            ? 'https://api.nomba.com/v1'
            : 'https://sandbox.nomba.com/v1';
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

        // 🟢 ADD THESE TWO LINES TO BYPASS LOCAL SSL HANDSHAKE FAILS IN SANDBOX:
        // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        // curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'accountId: ' . self::PARENT_ACCOUNT_ID
        ]);

        $response = curl_exec($ch);

        // Catch the explicit cURL error details if it still fails here
        if (curl_errno($ch)) {
            throw new \Exception('Auth cURL Error: ' . curl_error($ch));
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);

        // Extract token whether it is root-level or nested under 'data'
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
        $link = $context->link;
        $currency = new Currency($cart->id_currency);

        $payload = [
            'order' => [
                'orderReference' => $orderReference,
                'callbackUrl' => $link->getModuleLink('nomba', 'webhook', [], true), // Now $link exists
                'redirectUrl' => $link->getModuleLink('nomba', 'validation', ['reference' => $orderReference], true), // Now $link exists
                'customerEmail' => $context->customer->email,
                'amount' => number_format($cart->getOrderTotal(true), 2, '.', ''),
                'currency' => $currency->iso_code,
                'accountId' => $this->accountId,
            ],
        ];

        $signature = hash_hmac('sha512', json_encode($payload['order']), $this->privateKey);

        $ch = curl_init($this->baseUrl . '/checkout/order');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        // curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            // 'Authorization: Bearer ' . $this->clientId,
            'Authorization: Bearer ' . $token,
            'accountId: ' . self::PARENT_ACCOUNT_ID,
            'Signature: ' . $signature
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
     * Cryptographically validates incoming webhooks against private key.
     *
     * @param string $payload Raw request JSON payload content.
     * @param string $signature Signature header value.
     * @return bool True if signature matches, false otherwise.
     */
    public function verifyWebhookSignature($payload, $headers)
    {
        $data = json_decode($payload, true);
        $m = $data['data']['merchant'];
        $t = $data['data']['transaction'];

        $s = implode(':', [
            $data['event_type'],
            $data['requestId'],
            $m['userId'],
            $m['walletId'],
            $t['transactionId'],
            $t['type'],
            $t['time'],
            $t['responseCode'] ?? '',
            $headers['nomba-timestamp']
        ]);

        $computed = base64_encode(hash_hmac('sha256', $s, self::WEBHOOK_KEY, true));
        return hash_equals($computed, $headers['nomba-signature']);
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

        // Enforce strict camelCase syntax matching Nomba requirements
        $payload = [
            'transactionId' => $transactionRef,
            'amount' => number_format((float) $amount, 2, '.', ''),
            'accountNumber' => $accountNumber,
            'bankCode' => $bankCode,
            'accountId' => $this->accountId
        ];

        $jsonPayload = json_encode($payload);

        // Calculate the cryptographic signature across the stringified payload array
        $signature = hash_hmac('sha512', $jsonPayload, $this->privateKey);

        $ch = curl_init($this->baseUrl . '/checkout/refund');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        // curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'accountId: ' . self::PARENT_ACCOUNT_ID,
            'Signature: ' . $signature // This prevents the API from returning a 404
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
}
