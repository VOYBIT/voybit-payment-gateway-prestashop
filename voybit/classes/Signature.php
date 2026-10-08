<?php
/**
 * Webhook signature verification.
 *
 * @license GPL-2.0-or-later
 */

if (!defined('_PS_VERSION_') && !defined('VOYBIT_PRESTASHOP_TEST')) {
    exit;
}

class VoybitSignature
{
    const TOLERANCE = 300;

    /**
     * @param string $secret
     * @param string $id
     * @param string $timestamp
     * @param string $signature
     * @param string $rawBody
     * @param int|null $now
     *
     * @return string
     */
    public static function error($secret, $id, $timestamp, $signature, $rawBody, $now = null)
    {
        $hex = strpos((string) $signature, 'v1=') === 0 ? substr((string) $signature, 3) : '';
        if ((string) $secret === '' || (string) $id === '' || !ctype_digit((string) $timestamp) || !preg_match('/^[0-9a-f]{64}$/i', $hex)) {
            return 'invalid';
        }

        $supplied = hex2bin($hex);
        $current = $now === null ? time() : (int) $now;
        if (!is_string($supplied) || strlen($supplied) !== 32) {
            return 'invalid';
        }
        if (abs($current - (int) $timestamp) > self::TOLERANCE) {
            return 'expired';
        }

        $expected = hash_hmac('sha256', $id . '.' . $timestamp . '.' . $rawBody, $secret, true);
        if (!is_string($expected) || strlen($expected) !== 32 || !hash_equals($expected, $supplied)) {
            return 'mismatch';
        }

        return '';
    }
}
