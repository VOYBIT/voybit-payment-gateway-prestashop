<?php
/**
 * Signed webhook that marks the order paid.
 *
 * @license GPL-2.0-or-later
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class VoybitWebhookModuleFrontController extends ModuleFrontController
{
    /**
     * @var Voybit
     */
    public $module;

    public function postProcess()
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw)) {
            $raw = '';
        }
        if (strlen($raw) > 65536) {
            $this->respond(400);
        }

        $webhookId = $this->headerValue('Voybit-Webhook-Id');
        $error = VoybitSignature::error(
            $this->module->webhookSecret(),
            $webhookId,
            $this->headerValue('Voybit-Webhook-Timestamp'),
            $this->headerValue('Voybit-Webhook-Signature'),
            $raw
        );
        if ($error !== '') {
            $this->respond(401);
        }

        $event = json_decode($raw, true);
        if (!preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $webhookId) || !is_array($event)) {
            $this->respond(400);
        }

        $paymentId = isset($event['payment_id']) ? strtolower((string) $event['payment_id']) : '';
        if (!VoybitAmount::validUuid($paymentId)) {
            $this->respond(400);
        }
        $sessionId = isset($event['checkout_session_id'])
            ? strtolower((string) $event['checkout_session_id'])
            : (isset($event['session_id']) ? strtolower((string) $event['session_id']) : '');

        $status = isset($event['status']) ? (string) $event['status'] : '';
        $fulfil = $status === 'paid' || $status === 'overpaid';
        $lock = 'voybit-pay-' . str_replace('-', '', $paymentId);
        if (!VoybitStorage::lock($lock, 8)) {
            $this->respond(503);
        }

        try {
            $row = VoybitAmount::validUuid($sessionId)
                ? VoybitStorage::findBySessionId($sessionId)
                : null;
            if (!$row) {
                $row = VoybitStorage::findByPaymentId($paymentId);
            }
            if (!$row && $fulfil) {
                $this->respond(503);
            }
            if ($row && (string) $row['payment_id'] === ''
                && !VoybitStorage::attachPayment((int) $row['id_cart'], $paymentId)
            ) {
                $this->respond(503);
            }
            if ($row && VoybitStorage::alreadySeen((int) $row['id_cart'], $webhookId)) {
                $this->respond(204);
            }
            if ($row && $fulfil && !$this->fulfil($row, $event)) {
                $this->respond(503);
            }
            if ($row) {
                VoybitStorage::remember((int) $row['id_cart'], $webhookId);
            }
            $this->respond(204);
        } finally {
            VoybitStorage::unlock($lock);
        }
    }

    /**
     * @param array<string, string> $row
     * @param array<string, mixed> $event
     *
     * @return bool
     */
    private function fulfil(array $row, array $event)
    {
        $publicId = isset($event['checkout_public_id'])
            ? (string) $event['checkout_public_id']
            : (isset($event['public_id']) ? (string) $event['public_id'] : '');
        $stored = (string) $row['public_id'];
        $orders = Db::getInstance()->executeS(
            'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_cart` = ' . (int) $row['id_cart']
        );
        if (!is_array($orders) || !$orders) {
            return false;
        }

        if ($publicId === '' || strlen($publicId) !== strlen($stored) || !hash_equals($stored, $publicId)) {
            $order = new Order((int) $orders[0]['id_order']);
            if (Validate::isLoadedObject($order)) {
                $this->module->addPrivateNote($order, $this->module->l('Voybit webhook did not match this payment.', 'webhook'));
            }

            return true;
        }

        $paidState = (int) Configuration::get('PS_OS_PAYMENT');
        $canceledState = (int) Configuration::get('PS_OS_CANCELED');
        $refundState = (int) Configuration::get('PS_OS_REFUND');
        foreach ($orders as $item) {
            $order = new Order((int) $item['id_order']);
            if (!Validate::isLoadedObject($order)) {
                return false;
            }
            if ($order->module !== $this->module->name) {
                $this->module->addPrivateNote($order, $this->module->l('Voybit webhook did not match this order payment method.', 'webhook'));
                continue;
            }
            $current = (int) $order->current_state;
            if ($current === $canceledState) {
                $this->module->addPrivateNote($order, $this->module->l('Voybit reported a confirmed payment after this order was canceled. Review it before shipping.', 'webhook'));
                continue;
            }
            if ($current === $paidState || $current === $refundState || $order->hasBeenPaid()) {
                continue;
            }

            try {
                $history = new OrderHistory();
                $history->id_order = (int) $order->id;
                $history->changeIdOrderState($paidState, $order);
                $history->addWithemail(true);
            } catch (Exception $error) {
                PrestaShopLogger::addLog('Voybit could not mark the order paid.', 3, null, 'Order', (int) $order->id, true);

                return false;
            }
            $this->module->addPrivateNote($order, $this->module->l('Voybit confirmed this payment.', 'webhook'));
        }

        return true;
    }

    /**
     * @param string $name
     *
     * @return string
     */
    private function headerValue($name)
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
            return trim($_SERVER[$key]);
        }
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $header => $value) {
                    if (strcasecmp((string) $header, $name) === 0 && is_string($value)) {
                        return trim($value);
                    }
                }
            }
        }

        return '';
    }

    /**
     * @param int $status
     */
    private function respond($status)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code((int) $status);
        header('Content-Type: text/plain; charset=UTF-8');
        exit;
    }
}
