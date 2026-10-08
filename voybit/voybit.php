<?php
/**
 * Voybit payment method for PrestaShop.
 *
 * @license GPL-2.0-or-later
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/Amount.php';
require_once dirname(__FILE__) . '/classes/Checkout.php';
require_once dirname(__FILE__) . '/classes/Signature.php';
require_once dirname(__FILE__) . '/classes/RequestKey.php';
require_once dirname(__FILE__) . '/classes/Api.php';
require_once dirname(__FILE__) . '/classes/Storage.php';

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class Voybit extends PaymentModule
{
    public function __construct()
    {
        $this->name = 'voybit';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'Voybit';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->currencies = false;
        $this->controllers = ['payment', 'webhook', 'return'];
        $this->ps_versions_compliancy = [
            'min' => '1.7.7.0',
            'max' => '9.99.99',
        ];
        $this->is_eu_compatible = 1;

        parent::__construct();

        $this->displayName = $this->l('Voybit');
        $this->description = $this->l('Customers pay on the Voybit page. The store confirms the order when the payment arrives.');
        $this->confirmUninstall = $this->l('Remove Voybit settings from this shop? Orders already placed are kept.');
        if ($this->active && (!$this->hasCredentials() || !(bool) Configuration::get('PS_SSL_ENABLED'))) {
            $this->warning = $this->l('Enter the API key, webhook secret, and asset ID. The shop must use HTTPS.');
        }
    }

    public function install()
    {
        if (!parent::install()
            || !$this->registerHook('paymentOptions')
            || !$this->registerHook('displayPaymentReturn')
            || !VoybitStorage::install()
            || !$this->installOrderState()
        ) {
            return false;
        }

        Configuration::updateValue('VOYBIT_CHECKOUT_TITLE', 'Voybit');
        Configuration::updateValue(
            'VOYBIT_CHECKOUT_TEXT',
            'You pay on the Voybit page. The store confirms the order when the payment arrives.'
        );

        return true;
    }

    public function uninstall()
    {
        $keys = ['VOYBIT_API_KEY', 'VOYBIT_WEBHOOK_SECRET', 'VOYBIT_ASSET_ID', 'VOYBIT_CHECKOUT_TITLE', 'VOYBIT_CHECKOUT_TEXT'];
        foreach ($keys as $key) {
            if (!Configuration::deleteByName($key)) {
                return false;
            }
        }

        return parent::uninstall();
    }

    public function getContent()
    {
        $html = '';
        if (Tools::isSubmit('submitVoybit')) {
            if (Tools::getValue('token') !== Tools::getAdminTokenLite('AdminModules')) {
                return $this->displayError($this->l('The configuration could not be saved.'));
            }
            $html .= $this->saveConfiguration();
        }

        $this->context->smarty->assign([
            'voybit_webhook_url' => $this->webhookUrl(),
            'voybit_return_url' => $this->returnUrl(),
        ]);
        $html .= $this->display(__FILE__, 'admin/configure.tpl');
        if (!$this->shopUsesHttps()) {
            $html .= $this->displayWarning($this->l('Voybit needs the shop to use HTTPS. Enable SSL, then copy the addresses below into the Voybit gateway.'));
        }
        $html .= $this->renderForm();

        return $html;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return PaymentOption[]
     */
    public function hookPaymentOptions($params)
    {
        if (!$this->isReady() || empty($params['cart']) || (float) $params['cart']->getOrderTotal(true, Cart::BOTH) <= 0) {
            return [];
        }

        $this->context->smarty->assign([
            'voybit_description' => $this->checkoutText(),
        ]);

        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($this->checkoutTitle())
            ->setAction($this->context->link->getModuleLink($this->name, 'payment', [], true))
            ->setAdditionalInformation($this->fetch('module:voybit/views/templates/hook/payment_infos.tpl'));
        $logo = Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/logo.png');
        if ($logo) {
            $option->setLogo($logo);
        }

        return [$option];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return string
     */
    public function hookDisplayPaymentReturn($params)
    {
        if (!$this->active || empty($params['order']) || $params['order']->module !== $this->name) {
            return '';
        }

        return $this->fetch('module:voybit/views/templates/hook/payment_return.tpl');
    }

    /**
     * @return bool
     */
    public function isReady()
    {
        return $this->active
            && $this->shopUsesHttps()
            && $this->apiKey() !== ''
            && $this->webhookSecret() !== ''
            && VoybitAmount::validUuid($this->assetId());
    }

    /**
     * @return string
     */
    public function apiKey()
    {
        return trim((string) Configuration::get('VOYBIT_API_KEY'));
    }

    /**
     * @return string
     */
    public function webhookSecret()
    {
        return trim((string) Configuration::get('VOYBIT_WEBHOOK_SECRET'));
    }

    /**
     * @return string
     */
    public function assetId()
    {
        return strtolower(trim((string) Configuration::get('VOYBIT_ASSET_ID')));
    }

    /**
     * @return string
     */
    public function checkoutTitle()
    {
        $title = trim(strip_tags((string) Configuration::get('VOYBIT_CHECKOUT_TITLE')));
        if ($title === '') {
            return $this->l('Voybit');
        }

        return Tools::substr($title, 0, 80);
    }

    /**
     * @return string
     */
    public function checkoutText()
    {
        $text = trim(strip_tags((string) Configuration::get('VOYBIT_CHECKOUT_TEXT')));
        if ($text === '') {
            return $this->l('You pay on the Voybit page. The store confirms the order when the payment arrives.');
        }

        return Tools::substr($text, 0, 300);
    }

    /**
     * @param Order $order
     *
     * @return string
     */
    public function formatOrderAmount($order)
    {
        $currency = new Currency((int) $order->id_currency);
        $precision = 2;
        if (isset($currency->precision)) {
            $precision = (int) $currency->precision;
            if ($precision < 0 || $precision > 6) {
                $precision = 2;
            }
        }
        $rounded = Tools::ps_round((float) $order->total_paid, $precision);

        return number_format($rounded, $precision, '.', '');
    }

    /**
     * @return string
     */
    public function webhookUrl()
    {
        return $this->context->link->getModuleLink($this->name, 'webhook', [], true);
    }

    /**
     * @return string
     */
    public function returnUrl()
    {
        return $this->context->link->getModuleLink($this->name, 'return', [], true);
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function customerMessage($code)
    {
        if ($code === 'crypto_amount_in_use') {
            return $this->l('Another Voybit payment is already open for this amount. Wait a few minutes and try again.');
        }
        if ($code === 'expired') {
            return $this->l('This Voybit payment has expired. Wait a few minutes and try again.');
        }
        if ($code === 'amount') {
            return $this->l('This order total cannot be sent to Voybit.');
        }
        if ($code === 'lock') {
            return $this->l('Voybit checkout is already starting. Wait a moment and try again.');
        }

        return $this->l('Voybit could not open checkout. Try again, or choose another payment method.');
    }

    /**
     * @param int $orderId
     * @param string $code
     */
    public function logFailure($orderId, $code)
    {
        PrestaShopLogger::addLog(
            'Voybit checkout was not created (' . self::safeCode($code) . ').',
            2,
            null,
            'Order',
            (int) $orderId,
            true
        );
    }

    /**
     * @param Order $order
     * @param string $text
     */
    public function addPrivateNote($order, $text)
    {
        $message = new Message();
        $message->message = Tools::substr(strip_tags((string) $text), 0, 800);
        $message->id_order = (int) $order->id;
        $message->id_customer = (int) $order->id_customer;
        $message->private = 1;
        $message->add();
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public static function safeCode($code)
    {
        $safe = strtolower((string) $code);
        $safe = preg_replace('/[^a-z0-9_]/', '', $safe);
        if (!is_string($safe) || $safe === '') {
            return 'unknown';
        }

        return substr($safe, 0, 64);
    }

    /**
     * @return string
     */
    private function saveConfiguration()
    {
        $errors = [];
        $apiKey = $this->postedString('VOYBIT_API_KEY');
        if ($apiKey !== '') {
            if (!preg_match('/^[A-Za-z0-9._:-]+$/', $apiKey) || strlen($apiKey) > 256) {
                $errors[] = $this->l('The API key or webhook secret contains characters Voybit does not use. Paste the value from the Voybit dashboard.');
            } else {
                Configuration::updateValue('VOYBIT_API_KEY', $apiKey);
            }
        }

        $secret = $this->postedString('VOYBIT_WEBHOOK_SECRET');
        if ($secret !== '') {
            if (!preg_match('/^[A-Za-z0-9._:-]+$/', $secret) || strlen($secret) > 256) {
                $errors[] = $this->l('The API key or webhook secret contains characters Voybit does not use. Paste the value from the Voybit dashboard.');
            } else {
                Configuration::updateValue('VOYBIT_WEBHOOK_SECRET', $secret);
            }
        }

        $asset = strtolower($this->postedString('VOYBIT_ASSET_ID'));
        if ($asset === '' || !VoybitAmount::validUuid($asset)) {
            $errors[] = $this->l('Enter the asset ID shown on the Voybit gateway. It looks like a UUID.');
        } else {
            Configuration::updateValue('VOYBIT_ASSET_ID', $asset);
        }

        $title = Tools::substr(trim(strip_tags($this->postedString('VOYBIT_CHECKOUT_TITLE'))), 0, 80);
        if ($title === '') {
            $title = 'Voybit';
        }
        Configuration::updateValue('VOYBIT_CHECKOUT_TITLE', $title);

        $text = Tools::substr(trim(strip_tags($this->postedString('VOYBIT_CHECKOUT_TEXT'))), 0, 300);
        Configuration::updateValue('VOYBIT_CHECKOUT_TEXT', $text);

        if ($this->apiKey() === '' || $this->webhookSecret() === '') {
            $errors[] = $this->l('Enter the API key and webhook secret from the Voybit dashboard. Leave a field blank only after a value is already saved.');
        }

        if ($errors) {
            $html = '';
            foreach ($errors as $error) {
                $html .= $this->displayError($error);
            }

            return $html;
        }

        return $this->displayConfirmation($this->l('Settings saved. An empty secret field keeps the saved value.'));
    }

    /**
     * @return string
     */
    private function renderForm()
    {
        $apiSaved = $this->apiKey() !== '';
        $secretSaved = $this->webhookSecret() !== '';
        $fields = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Voybit'),
                    'icon' => 'icon-credit-card',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('Title'),
                        'name' => 'VOYBIT_CHECKOUT_TITLE',
                        'desc' => $this->l('Name customers see at checkout.'),
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('Description'),
                        'name' => 'VOYBIT_CHECKOUT_TEXT',
                        'desc' => $this->l('Short note customers see under the payment method.'),
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->l('API key'),
                        'name' => 'VOYBIT_API_KEY',
                        'desc' => $apiSaved
                            ? $this->l('A key is saved. Enter a new value to replace it, or leave this blank to keep it.')
                            : $this->l('Secret key from the Voybit dashboard, API keys.'),
                        'autocomplete' => 'new-password',
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->l('Webhook secret'),
                        'name' => 'VOYBIT_WEBHOOK_SECRET',
                        'desc' => $secretSaved
                            ? $this->l('A secret is saved. Enter a new value to replace it, or leave this blank to keep it.')
                            : $this->l('Secret shown once when you create the gateway.'),
                        'autocomplete' => 'new-password',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Asset ID'),
                        'name' => 'VOYBIT_ASSET_ID',
                        'desc' => $this->l('Asset ID from the same Voybit gateway. A USD store should use a stablecoin such as USDT. The order total is the amount of that asset.'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                    'name' => 'submitVoybit',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitVoybit';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => [
                'VOYBIT_CHECKOUT_TITLE' => $this->checkoutTitle(),
                'VOYBIT_CHECKOUT_TEXT' => $this->checkoutText(),
                'VOYBIT_API_KEY' => '',
                'VOYBIT_WEBHOOK_SECRET' => '',
                'VOYBIT_ASSET_ID' => $this->assetId(),
            ],
        ];

        return $helper->generateForm([$fields]);
    }

    /**
     * @return bool
     */
    private function hasCredentials()
    {
        return $this->apiKey() !== '' && $this->webhookSecret() !== '' && VoybitAmount::validUuid($this->assetId());
    }

    /**
     * @return bool
     */
    private function shopUsesHttps()
    {
        return (bool) Configuration::get('PS_SSL_ENABLED')
            && $this->absoluteHttps($this->webhookUrl())
            && $this->absoluteHttps($this->returnUrl());
    }

    /**
     * @param string $url
     *
     * @return bool
     */
    private function absoluteHttps($url)
    {
        if (strpos($url, 'https://') !== 0) {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '';
    }

    /**
     * @return bool
     */
    private function installOrderState()
    {
        $existing = (int) Configuration::get('VOYBIT_OS_AWAITING');
        if ($existing > 0 && Validate::isLoadedObject(new OrderState($existing))) {
            return true;
        }

        $orderState = new OrderState();
        $orderState->send_email = false;
        $orderState->module_name = $this->name;
        $orderState->invoice = false;
        $orderState->color = '#0c0e2c';
        $orderState->unremovable = true;
        $orderState->hidden = false;
        $orderState->logable = false;
        $orderState->delivery = false;
        $orderState->shipped = false;
        $orderState->paid = false;
        $orderState->pdf_invoice = false;
        $orderState->pdf_delivery = false;
        foreach (Language::getLanguages(false) as $language) {
            $orderState->name[(int) $language['id_lang']] = 'Awaiting Voybit payment';
        }
        if (!$orderState->add()) {
            return false;
        }

        return Configuration::updateValue('VOYBIT_OS_AWAITING', (int) $orderState->id);
    }

    /**
     * @param string $key
     *
     * @return string
     */
    private function postedString($key)
    {
        $value = Tools::getValue($key);
        if (!is_string($value)) {
            return '';
        }

        return trim($value);
    }
}
