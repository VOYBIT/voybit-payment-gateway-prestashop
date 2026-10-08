<?php
/**
 * Idempotency keys for a PrestaShop cart.
 *
 * @license GPL-2.0-or-later
 */

if (!defined('_PS_VERSION_') && !defined('VOYBIT_PRESTASHOP_TEST')) {
    exit;
}

class VoybitRequestKey
{
    /**
     * @param int $cartId
     *
     * @return string
     */
    public static function forCart($cartId)
    {
        $cartId = (string) $cartId;
        if (!preg_match('/^[1-9][0-9]{0,17}$/', $cartId)) {
            return '';
        }
        $key = 'prestashop:' . $cartId;
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $key)) {
            return '';
        }

        return $key;
    }
}
