<?php
/**
 * Order total conversion.
 *
 * @license GPL-2.0-or-later
 */

if (!defined('_PS_VERSION_') && !defined('VOYBIT_PRESTASHOP_TEST')) {
    exit;
}

class VoybitAmount
{
    const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF',
        'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    const THREE_DECIMAL = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    const MAX_MINOR = '9000000000000000';

    /**
     * @param string $amount
     * @param string $currency
     *
     * @return array{amount_minor: int, crypto_amount: string, fiat_currency: string}
     */
    public static function from($amount, $currency)
    {
        $currency = strtoupper(trim((string) $currency));
        $amount = trim((string) $amount);
        if (!preg_match('/^[A-Z]{3}$/', $currency) || !preg_match('/^(?:0|[1-9]\d*)(?:\.(\d+))?$/', $amount, $match)) {
            throw new InvalidArgumentException('order total is not a valid amount');
        }

        $exponent = self::exponent($currency);
        if ($exponent > 4) {
            throw new InvalidArgumentException('order total is not a valid amount');
        }

        $parts = explode('.', $amount, 2);
        $whole = $parts[0];
        $fraction = isset($match[1]) ? $match[1] : '';
        if (strlen($fraction) > $exponent && preg_match('/[1-9]/', substr($fraction, $exponent))) {
            throw new InvalidArgumentException('order total has more decimal places than the currency allows');
        }

        $fraction = str_pad(substr($fraction, 0, $exponent), $exponent, '0');
        $minor = ltrim($whole . $fraction, '0');
        if ($minor === '' || strlen($minor) > 16 || (strlen($minor) === 16 && strcmp($minor, self::MAX_MINOR) > 0)) {
            throw new InvalidArgumentException('order total is not a valid amount');
        }

        $value = (int) $minor;
        if ($value < 1 || (string) $value !== $minor) {
            throw new InvalidArgumentException('order total is not a valid amount');
        }

        return [
            'amount_minor' => $value,
            'crypto_amount' => $exponent === 0 ? $whole : $whole . '.' . $fraction,
            'fiat_currency' => $currency,
        ];
    }

    /**
     * @param string $value
     *
     * @return bool
     */
    public static function validUuid($value)
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) $value);
    }

    /**
     * @param string $currency
     *
     * @return int
     */
    private static function exponent($currency)
    {
        if (in_array($currency, self::ZERO_DECIMAL, true)) {
            return 0;
        }
        if (in_array($currency, self::THREE_DECIMAL, true)) {
            return 3;
        }

        return 2;
    }
}
