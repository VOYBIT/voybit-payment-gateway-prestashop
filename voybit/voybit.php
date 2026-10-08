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
        $this->version = '1.1.0';
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
            $this->warning = $this->l('Save a valid API key so Voybit can configure this HTTPS shop.');
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
        Configuration::updateValue('VOYBIT_API_BASE', VoybitApi::DEFAULT_API_BASE);

        return true;
    }

    /**
     * Enabling is never blocked by missing credentials or a temporary API failure.
     *
     * @param bool $forceAll
     *
     * @return bool
     */
    public function enable($forceAll = false)
    {
        if (!parent::enable($forceAll)) {
            return false;
        }
        if ($this->apiKey() !== '') {
            $this->configureIntegration(false);
        }

        return true;
    }

    public function uninstall()
    {
        $keys = [
            'VOYBIT_API_KEY',
            'VOYBIT_API_BASE',
            'VOYBIT_WEBHOOK_SECRET',
            'VOYBIT_ASSET_ID',
            'VOYBIT_CONFIG_ERROR',
            'VOYBIT_CHECKOUT_TITLE',
            'VOYBIT_CHECKOUT_TEXT',
        ];
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

        $html .= $this->display(__FILE__, 'admin/configure.tpl');
        $setupError = trim((string) Configuration::get('VOYBIT_CONFIG_ERROR'));
        if ($setupError !== '') {
            $html .= $this->displayError($this->configurationMessage($setupError));
        }
        if (!$this->shopUsesHttps()) {
            $html .= $this->displayWarning($this->l('Voybit needs the shop to use HTTPS before automatic integration setup can complete.'));
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
            && $this->webhookSecret() !== '';
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
    public function apiBase()
    {
        $base = VoybitApi::normalizeBase((string) Configuration::get('VOYBIT_API_BASE'));

        return $base !== '' ? $base : VoybitApi::DEFAULT_API_BASE;
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
        $previousKey = $this->apiKey();
        $previousBase = $this->apiBase();
        $previousSecret = $this->webhookSecret();
        $apiKey = $this->postedString('VOYBIT_API_KEY');
        if ($apiKey !== '') {
            if (!preg_match('/^[A-Za-z0-9._:-]+$/', $apiKey) || strlen($apiKey) > 256) {
                $errors[] = $this->l('The API key contains characters Voybit does not use. Paste the key from the Voybit dashboard.');
            } else {
                Configuration::updateValue('VOYBIT_API_KEY', $apiKey);
            }
        }

        $base = VoybitApi::normalizeBase($this->postedString('VOYBIT_API_BASE'));
        if ($base === '') {
            $errors[] = $this->l('Enter a valid HTTPS Voybit API base URL.');
        } else {
            Configuration::updateValue('VOYBIT_API_BASE', $base);
        }
        Configuration::deleteByName('VOYBIT_ASSET_ID');

        $title = Tools::substr(trim(strip_tags($this->postedString('VOYBIT_CHECKOUT_TITLE'))), 0, 80);
        if ($title === '') {
            $title = 'Voybit';
        }
        Configuration::updateValue('VOYBIT_CHECKOUT_TITLE', $title);

        $text = Tools::substr(trim(strip_tags($this->postedString('VOYBIT_CHECKOUT_TEXT'))), 0, 300);
        Configuration::updateValue('VOYBIT_CHECKOUT_TEXT', $text);

        if ($this->apiKey() === '') {
            $errors[] = $this->l('Enter the gateway-scoped API key from the Voybit dashboard.');
        }

        if ($errors) {
            return $this->renderErrors($errors);
        }

        if (!$this->configureIntegration(false)) {
            $code = (string) Configuration::get('VOYBIT_CONFIG_ERROR');
            if ($previousKey !== '' && $previousSecret !== ''
                && ($this->apiKey() !== $previousKey || $this->apiBase() !== $previousBase)
            ) {
                Configuration::updateValue('VOYBIT_API_KEY', $previousKey);
                Configuration::updateValue('VOYBIT_API_BASE', $previousBase);
                Configuration::updateValue('VOYBIT_WEBHOOK_SECRET', $previousSecret);
                Configuration::deleteByName('VOYBIT_CONFIG_ERROR');
                $errors[] = $this->configurationMessage($code);
                $errors[] = $this->l('The previous working Voybit configuration was kept.');
            } else {
                $errors[] = $this->configurationMessage($code);
            }

            return $this->renderErrors($errors);
        }

        return $this->displayConfirmation($this->l('Settings saved. Voybit configured the webhook and customer return URL automatically.'));
    }

    /**
     * @return string
     */
    private function renderForm()
    {
        $apiSaved = $this->apiKey() !== '';
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
                        'type' => 'text',
                        'label' => $this->l('API base URL (advanced)'),
                        'name' => 'VOYBIT_API_BASE',
                        'desc' => $this->l('Keep the default unless Voybit support gives you another HTTPS API base URL.'),
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
                'VOYBIT_API_BASE' => $this->apiBase(),
            ],
        ];

        return $helper->generateForm([$fields]);
    }

    /**
     * @return bool
     */
    private function hasCredentials()
    {
        return $this->apiKey() !== '' && $this->webhookSecret() !== '';
    }

    /**
     * Configure callbacks and securely keep the rotated secret internal.
     *
     * @param bool $unused Reserved for compatibility with activation calls.
     *
     * @return bool
     */
    public function configureIntegration($unused = false)
    {
        unset($unused);
        if ($this->apiKey() === '') {
            return true;
        }
        if (!$this->shopUsesHttps()) {
            Configuration::updateValue('VOYBIT_CONFIG_ERROR', 'configuration_url');
            return false;
        }
        try {
            $secret = VoybitApi::configure(
                $this->apiBase(),
                $this->apiKey(),
                $this->webhookUrl(),
                $this->returnUrl()
            );
            Configuration::updateValue('VOYBIT_WEBHOOK_SECRET', $secret);
            Configuration::deleteByName('VOYBIT_CONFIG_ERROR');

            return true;
        } catch (VoybitApiException $error) {
            Configuration::updateValue('VOYBIT_CONFIG_ERROR', self::safeCode($error->errorCode));

            return false;
        } catch (Exception $error) {
            Configuration::updateValue('VOYBIT_CONFIG_ERROR', 'transport');

            return false;
        }
    }

    /**
     * @param string $code
     *
     * @return string
     */
    private function configurationMessage($code)
    {
        $safe = self::safeCode($code);
        if ($safe === 'configuration_url') {
            return $this->l('Voybit could not configure this shop because the webhook or return URL is not HTTPS. Enable SSL and save again.');
        }
        if ($safe === 'transport') {
            return $this->l('Voybit could not be reached. Check the API base URL and try saving again.');
        }

        return sprintf(
            $this->l('Voybit could not configure this shop (%s). Check the API key and API base URL, then save again.'),
            $safe
        );
    }

    /**
     * @param string[] $errors
     *
     * @return string
     */
    private function renderErrors(array $errors)
    {
        $html = '';
        foreach ($errors as $error) {
            $html .= $this->displayError($error);
        }

        return $html;
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
