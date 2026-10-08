<?php
/**
 * Opens Voybit hosted checkout for the current cart.
 *
 * @license GPL-2.0-or-later
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class VoybitPaymentModuleFrontController extends ModuleFrontController
{
    /**
     * @var Voybit
     */
    public $module;

    public function postProcess()
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            Tools::redirect('index.php?controller=order');
        }

        $cart = $this->context->cart;
        if (!$this->module->isReady()
            || !Validate::isLoadedObject($cart)
            || (int) $cart->id_customer === 0
            || (int) $cart->id_address_delivery === 0
            || (int) $cart->id_address_invoice === 0
        ) {
            Tools::redirect('index.php?controller=order');
        }

        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order');
        }

        $cartId = (int) $cart->id;
        if (!VoybitStorage::lock('voybit-cart-' . $cartId, 8)) {
            $this->fail('lock');
        }

        try {
            $existing = VoybitStorage::findByCart($cartId);
            if ($existing) {
                $stored = VoybitCheckout::canonical($existing['checkout_url']);
                if ($stored !== '' && (int) $existing['expires_at'] > time() + 30) {
                    $this->leave($stored);
                }
            }

            $order = $this->orderForCart($cart, $customer);
            if (!$order) {
                $this->fail('order');
            }

            $currency = new Currency((int) $order->id_currency);
            try {
                $priced = VoybitAmount::from($this->module->formatOrderAmount($order), $currency->iso_code);
            } catch (InvalidArgumentException $error) {
                unset($error);
                $this->fail('amount');
            }

            $idempotencyKey = VoybitRequestKey::forCart($cartId);
            if ($idempotencyKey === '') {
                $this->fail('request');
            }

            // Kept in English so a retry sends the same body as the first request.
            $description = substr('Order ' . $order->reference, 0, 500);
            try {
                $payment = VoybitApi::createCheckoutSession([
                    'fiat_amount' => $priced['fiat_amount'],
                    'fiat_currency' => $priced['fiat_currency'],
                    'description' => $description,
                    'metadata' => [
                        'cms' => 'prestashop',
                        'order_id' => (string) (int) $order->id,
                        'cart_id' => (string) $cartId,
                    ],
                    'payment_window_seconds' => VoybitApi::PAYMENT_WINDOW_SECONDS,
                ], $this->module->apiKey(), $idempotencyKey, $this->module->apiBase());
            } catch (VoybitApiException $error) {
                $this->module->logFailure((int) $order->id, $error->errorCode);
                $this->module->addPrivateNote(
                    $order,
                    'Voybit could not open checkout (' . Voybit::safeCode($error->errorCode) . ').'
                );
                $this->fail($error->errorCode);
            }

            $saved = VoybitStorage::save(
                $cartId,
                (int) $order->id,
                $payment['session_id'],
                $payment['public_id'],
                $payment['checkout_url'],
                $payment['expires_at']
            );
            if (!$saved) {
                $this->fail('storage');
            }
            $this->module->addPrivateNote($order, $this->module->l('Voybit checkout is open.', 'payment'));
            $this->leave($payment['checkout_url']);
        } finally {
            VoybitStorage::unlock('voybit-cart-' . $cartId);
        }
    }

    /**
     * @param Cart $cart
     * @param Customer $customer
     *
     * @return Order|null
     */
    private function orderForCart($cart, $customer)
    {
        $orderId = (int) Order::getIdByCartId((int) $cart->id);
        if ($orderId > 0) {
            $order = new Order($orderId);
            if (!Validate::isLoadedObject($order) || $order->module !== $this->module->name) {
                return null;
            }

            return $order;
        }

        $state = (int) Configuration::get('VOYBIT_OS_AWAITING');
        if ($state < 1) {
            return null;
        }

        $currency = new Currency((int) $cart->id_currency);
        try {
            $this->module->validateOrder(
                (int) $cart->id,
                $state,
                (float) $cart->getOrderTotal(true, Cart::BOTH),
                $this->module->checkoutTitle(),
                null,
                [],
                (int) $currency->id,
                false,
                $customer->secure_key
            );
        } catch (Exception $error) {
            PrestaShopLogger::addLog('Voybit could not create the order.', 3, null, 'Cart', (int) $cart->id, true);

            return null;
        }

        $order = new Order((int) $this->module->currentOrder);
        if (!Validate::isLoadedObject($order)) {
            return null;
        }

        return $order;
    }

    /**
     * @param string $code
     */
    private function fail($code)
    {
        $this->errors[] = $this->module->customerMessage($code);
        $this->redirectWithNotifications($this->context->link->getPageLink('order', true));
    }

    /**
     * @param string $url
     */
    private function leave($url)
    {
        $canonical = VoybitCheckout::canonical($url);
        if ($canonical === '') {
            $this->fail('invalid_checkout');
        }
        Tools::redirect($canonical);
    }
}
