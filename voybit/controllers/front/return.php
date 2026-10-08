<?php
/**
 * Sends the customer back to the order confirmation page.
 * The return does not mark the order paid.
 *
 * @license GPL-2.0-or-later
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class VoybitReturnModuleFrontController extends ModuleFrontController
{
    /**
     * @var Voybit
     */
    public $module;

    public function postProcess()
    {
        $cart = $this->context->cart;
        $orderId = Validate::isLoadedObject($cart) ? (int) Order::getIdByCartId((int) $cart->id) : 0;
        $order = new Order($orderId);
        $customer = new Customer((int) $order->id_customer);
        if (!Validate::isLoadedObject($order) || $order->module !== $this->module->name || !Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order');
        }

        Tools::redirect($this->context->link->getPageLink(
            'order-confirmation',
            true,
            null,
            'id_cart=' . (int) $order->id_cart
            . '&id_module=' . (int) $this->module->id
            . '&id_order=' . (int) $order->id
            . '&key=' . $customer->secure_key
        ));
    }
}
