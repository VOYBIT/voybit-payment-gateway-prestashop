<?php
/**
 * Payment rows stored for webhook lookup.
 *
 * @license GPL-2.0-or-later
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class VoybitStorage
{
    /**
     * @return bool
     */
    public static function install()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'voybit_payment` (
            `id_cart` INT UNSIGNED NOT NULL,
            `id_order` INT UNSIGNED NOT NULL,
            `payment_id` CHAR(36) NOT NULL,
            `public_id` VARCHAR(32) NOT NULL,
            `checkout_url` VARCHAR(255) NOT NULL,
            `expires_at` INT UNSIGNED NOT NULL DEFAULT 0,
            `webhook_ids` TEXT NULL,
            PRIMARY KEY (`id_cart`),
            UNIQUE KEY `voybit_payment_id` (`payment_id`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';

        return Db::getInstance()->execute($sql);
    }

    /**
     * @param int $cartId
     *
     * @return array<string, string>|null
     */
    public static function findByCart($cartId)
    {
        $row = Db::getInstance()->getRow(
            'SELECT `id_cart`, `id_order`, `payment_id`, `public_id`, `checkout_url`, `expires_at`, `webhook_ids`
            FROM `' . _DB_PREFIX_ . 'voybit_payment`
            WHERE `id_cart` = ' . (int) $cartId
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @param string $paymentId
     *
     * @return array<string, string>|null
     */
    public static function findByPaymentId($paymentId)
    {
        if (!VoybitAmount::validUuid($paymentId)) {
            return null;
        }
        $row = Db::getInstance()->getRow(
            'SELECT `id_cart`, `id_order`, `payment_id`, `public_id`, `checkout_url`, `expires_at`, `webhook_ids`
            FROM `' . _DB_PREFIX_ . 'voybit_payment`
            WHERE `payment_id` = \'' . pSQL($paymentId) . '\''
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @param int $cartId
     * @param int $orderId
     * @param string $paymentId
     * @param string $publicId
     * @param string $checkoutUrl
     * @param int $expiresAt
     *
     * @return bool
     */
    public static function save($cartId, $orderId, $paymentId, $publicId, $checkoutUrl, $expiresAt)
    {
        $existing = self::findByCart($cartId);
        $data = [
            'id_order' => (int) $orderId,
            'payment_id' => (string) $paymentId,
            'public_id' => (string) $publicId,
            'checkout_url' => (string) $checkoutUrl,
            'expires_at' => (int) $expiresAt,
        ];
        if ($existing) {
            return Db::getInstance()->update('voybit_payment', $data, '`id_cart` = ' . (int) $cartId);
        }
        $data['id_cart'] = (int) $cartId;
        $data['webhook_ids'] = '';

        return Db::getInstance()->insert('voybit_payment', $data);
    }

    /**
     * @param int $cartId
     * @param string $webhookId
     *
     * @return bool True when this delivery was already stored.
     */
    public static function alreadySeen($cartId, $webhookId)
    {
        $row = self::findByCart($cartId);
        if (!$row) {
            return false;
        }
        $ids = json_decode((string) $row['webhook_ids'], true);

        return is_array($ids) && in_array($webhookId, $ids, true);
    }

    /**
     * @param int $cartId
     * @param string $webhookId
     */
    public static function remember($cartId, $webhookId)
    {
        $row = self::findByCart($cartId);
        $ids = $row ? json_decode((string) $row['webhook_ids'], true) : [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids[] = $webhookId;
        if (count($ids) > 30) {
            $ids = array_slice($ids, -30);
        }
        Db::getInstance()->update('voybit_payment', [
            'webhook_ids' => (string) json_encode($ids),
        ], '`id_cart` = ' . (int) $cartId);
    }

    /**
     * @param string $name
     * @param int $waitSeconds
     *
     * @return bool
     */
    public static function lock($name, $waitSeconds = 0)
    {
        if (!preg_match('/^[a-z0-9-]{8,64}$/', $name)) {
            return false;
        }
        $wait = (int) $waitSeconds;
        if ($wait < 0 || $wait > 15) {
            $wait = 0;
        }
        $got = Db::getInstance()->getValue('SELECT GET_LOCK(\'' . pSQL($name) . '\', ' . $wait . ')', false);

        return (string) $got === '1';
    }

    /**
     * @param string $name
     */
    public static function unlock($name)
    {
        if (!preg_match('/^[a-z0-9-]{8,64}$/', $name)) {
            return;
        }
        Db::getInstance()->getValue('SELECT RELEASE_LOCK(\'' . pSQL($name) . '\')', false);
    }
}
