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
    const ENDPOINT = 'https://api.voybit.com/api/v1/gateway/payments';

    /**
     * @param array<string, mixed> $payload
     * @param string $apiKey
     * @param string $idempotencyKey
     *
     * @return array{id: string, public_id: string, checkout_url: string, expires_at: int}
     */
    public static function createPayment(array $payload, $apiKey, $idempotencyKey)
    {
        $body = json_encode($payload);
        if (!is_string($body) || $body === '') {
            throw new VoybitApiException('request');
        }

        $lastCode = 'transport';
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $result = self::post($body, $apiKey, $idempotencyKey);
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

                return self::accepted($decoded);
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
     * @return array{id: string, public_id: string, checkout_url: string, expires_at: int}
     */
    private static function accepted(array $data)
    {
        $paymentId = isset($data['id']) ? strtolower((string) $data['id']) : '';
        $checkoutUrl = VoybitCheckout::canonical(isset($data['checkout_url']) ? (string) $data['checkout_url'] : '');
        $publicId = isset($data['public_id']) ? (string) $data['public_id'] : '';
        $fromUrl = $checkoutUrl !== '' ? substr($checkoutUrl, strlen('https://voybit.com/pay/')) : '';
        $idsMatch = $fromUrl !== '' && strlen($fromUrl) === strlen($publicId) && hash_equals($fromUrl, $publicId);
        if (!VoybitAmount::validUuid($paymentId) || !$idsMatch) {
            throw new VoybitApiException('invalid_checkout');
        }

        $expires = isset($data['expires_at']) ? strtotime((string) $data['expires_at']) : false;
        if (!is_int($expires) || $expires <= time()) {
            throw new VoybitApiException('expired');
        }

        return [
            'id' => $paymentId,
            'public_id' => $publicId,
            'checkout_url' => $checkoutUrl,
            'expires_at' => $expires,
        ];
    }

    /**
     * @param string $body
     * @param string $apiKey
     * @param string $idempotencyKey
     *
     * @return array{transport: bool, status: int, body: string, retry_after: int}
     */
    private static function post($body, $apiKey, $idempotencyKey)
    {
        if (!function_exists('curl_init')) {
            return ['transport' => true, 'status' => 0, 'body' => '', 'retry_after' => 0];
        }

        $handle = curl_init(self::ENDPOINT);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'X-Voybit-Api-Key: ' . $apiKey,
                'Idempotency-Key: ' . $idempotencyKey,
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: voybit-prestashop/1.0.0',
            ],
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
}
