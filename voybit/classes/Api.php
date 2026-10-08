<?php
/**
 * Server-side calls to the Voybit payment API.
 *
 * @license GPL-2.0-or-later
 */

if (!defined('_PS_VERSION_') && !defined('VOYBIT_PRESTASHOP_TEST')) {
    exit;
}

class VoybitApiException extends Exception
{
    /** @var string */
    public $errorCode;

    /**
     * @param string $errorCode
     */
    public function __construct($errorCode)
    {
        $this->errorCode = (string) $errorCode;
        parent::__construct($this->errorCode);
    }
}

class VoybitApi
{
    const DEFAULT_API_BASE = 'https://api.voybit.com/api';
    const PAYMENT_WINDOW_SECONDS = 900;

    /**
     * @param string $apiBase
     * @param string $apiKey
     * @param string $webhookUrl
     * @param string $returnUrl
     *
     * @return string Rotated webhook secret.
     */
    public static function configure($apiBase, $apiKey, $webhookUrl, $returnUrl)
    {
        $base = self::normalizeBase($apiBase);
        if ($base === '' || !self::validHttpsUrl($webhookUrl) || !self::validHttpsUrl($returnUrl)) {
            throw new VoybitApiException('configuration_url');
        }
        $response = self::request(
            $base . '/v1/gateway/integration/configure',
            [
                'webhook_url' => (string) $webhookUrl,
                'return_url' => (string) $returnUrl,
            ],
            $apiKey,
            ''
        );
        $secret = isset($response['webhook_secret']) ? trim((string) $response['webhook_secret']) : '';
        if (!preg_match('/^[A-Za-z0-9._:-]{8,256}$/', $secret)) {
            throw new VoybitApiException('configuration_response');
        }

        return $secret;
    }

    /**
     * @param array<string, mixed> $payload
     * @param string $apiKey
     * @param string $idempotencyKey
     * @param string $apiBase
     *
     * @return array{session_id: string, public_id: string, checkout_url: string, expires_at: int}
     */
    public static function createCheckoutSession(array $payload, $apiKey, $idempotencyKey, $apiBase)
    {
        $base = self::normalizeBase($apiBase);
        if ($base === '') {
            throw new VoybitApiException('api_base');
        }

        return self::accepted(self::request(
            $base . '/v1/gateway/checkout-sessions',
            $payload,
            $apiKey,
            $idempotencyKey
        ));
    }

    /**
     * @param string $apiBase
     *
     * @return string
     */
    public static function normalizeBase($apiBase)
    {
        $parts = parse_url(trim((string) $apiBase));
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || strtolower((string) $parts['scheme']) !== 'https'
            || (string) $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return '';
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : 0;
        if ($port < 0 || $port > 65535) {
            return '';
        }
        $path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';
        if (preg_match('/[\x00-\x20\x7f]/', $path)) {
            return '';
        }

        return 'https://' . strtolower((string) $parts['host']) . ($port ? ':' . $port : '') . $path;
    }

    /**
     * @param string $endpoint
     * @param array<string, mixed> $payload
     * @param string $apiKey
     * @param string $idempotencyKey
     *
     * @return array<string, mixed>
     */
    private static function request($endpoint, array $payload, $apiKey, $idempotencyKey)
    {
        $body = json_encode($payload);
        if (!is_string($body) || $body === '') {
            throw new VoybitApiException('request');
        }

        $lastCode = 'transport';
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $result = self::post($endpoint, $body, $apiKey, $idempotencyKey);
            if ($result['transport']) {
                $lastCode = 'transport';
                if ($attempt === 3) {
                    throw new VoybitApiException('transport');
                }
                usleep((int) min(500 * (2 ** $attempt), 8000) * 1000);
                continue;
            }

            $status = (int) $result['status'];
            $decoded = json_decode((string) $result['body'], true);
            if ($status >= 200 && $status < 300) {
                if (!is_array($decoded)) {
                    throw new VoybitApiException('invalid_response');
                }

                return $decoded;
            }

            $lastCode = is_array($decoded) && isset($decoded['error']['code']) ? (string) $decoded['error']['code'] : 'http_' . $status;
            $retryable = in_array($status, [408, 429, 500, 502, 503, 504], true);
            if (!$retryable || $attempt === 3) {
                throw new VoybitApiException($lastCode);
            }

            $retry = (int) $result['retry_after'];
            if ($retry < 1) {
                $retry = (int) ceil(min(500 * (2 ** $attempt), 8000) / 1000);
            }
            if ($retry > 30) {
                $retry = 30;
            }
            if ($retry < 1) {
                $retry = 1;
            }
            sleep($retry);
        }

        throw new VoybitApiException($lastCode);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{session_id: string, public_id: string, checkout_url: string, expires_at: int}
     */
    private static function accepted(array $data)
    {
        $sessionId = isset($data['session_id']) ? strtolower((string) $data['session_id']) : '';
        $checkoutUrl = VoybitCheckout::canonical(isset($data['checkout_url']) ? (string) $data['checkout_url'] : '');
        $publicId = isset($data['public_id']) ? (string) $data['public_id'] : '';
        $fromUrl = $checkoutUrl !== '' ? substr($checkoutUrl, strlen('https://voybit.com/pay/')) : '';
        if ($publicId === '') {
            $publicId = $fromUrl;
        }
        $idsMatch = $fromUrl !== '' && strlen($fromUrl) === strlen($publicId) && hash_equals($fromUrl, $publicId);
        if (!VoybitAmount::validUuid($sessionId) || !$idsMatch) {
            throw new VoybitApiException('invalid_checkout');
        }

        $expires = isset($data['expires_at']) ? strtotime((string) $data['expires_at']) : false;
        if (!is_int($expires) || $expires <= time()) {
            $expires = time() + self::PAYMENT_WINDOW_SECONDS;
        }

        return [
            'session_id' => $sessionId,
            'public_id' => $publicId,
            'checkout_url' => $checkoutUrl,
            'expires_at' => $expires,
        ];
    }

    /**
     * @param string $endpoint
     * @param string $body
     * @param string $apiKey
     * @param string $idempotencyKey
     *
     * @return array{transport: bool, status: int, body: string, retry_after: int}
     */
    private static function post($endpoint, $body, $apiKey, $idempotencyKey)
    {
        if (!function_exists('curl_init')) {
            return ['transport' => true, 'status' => 0, 'body' => '', 'retry_after' => 0];
        }

        $headers = [
            'X-Voybit-Api-Key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: voybit-prestashop/1.1.0',
        ];
        if ($idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        $handle = curl_init($endpoint);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $raw = curl_exec($handle);
        if ($raw === false) {
            curl_close($handle);

            return ['transport' => true, 'status' => 0, 'body' => '', 'retry_after' => 0];
        }

        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);
        $headers = substr((string) $raw, 0, $headerSize);
        $payload = substr((string) $raw, $headerSize);
        $retryAfter = 0;
        if (preg_match('/^Retry-After:\s*(\d+)/mi', $headers, $match)) {
            $retryAfter = (int) $match[1];
        }

        return [
            'transport' => false,
            'status' => $status,
            'body' => $payload,
            'retry_after' => $retryAfter,
        ];
    }

    /**
     * @param string $url
     *
     * @return bool
     */
    private static function validHttpsUrl($url)
    {
        $parts = parse_url(trim((string) $url));

        return is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && strtolower((string) $parts['scheme']) === 'https'
            && (string) $parts['host'] !== ''
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }
}
