<?php
/**
 * Hosted checkout URL checks.
 *
 * @license GPL-2.0-or-later
 */

if (!defined('_PS_VERSION_') && !defined('VOYBIT_PRESTASHOP_TEST')) {
    exit;
}

class VoybitCheckout
{
    /**
     * @param string $url
     *
     * @return string
     */
    public static function canonical($url)
    {
        $url = trim((string) $url);
        if (!preg_match('~\Ahttps://([^/?#]+)/pay/([A-Za-z0-9_-]{22})/?\z~', $url, $match)) {
            return '';
        }
        if (strcasecmp($match[1], 'voybit.com') !== 0) {
            return '';
        }

        return 'https://voybit.com/pay/' . $match[2];
    }
}
