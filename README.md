# Voybit payment gateway for PrestaShop

## Get an API key

1. Create an account at [dashboard.voybit.com](https://dashboard.voybit.com).
2. Open **Gateways** and create a payment gateway. Keep it enabled. Copy the asset ID you will charge, and store the webhook secret (`whsec_…`) shown once at creation.
3. Open **API keys**, choose **Create secret key**, and bind it to that gateway. Copy the full `vb_live_…` value once.

The API key and webhook secret stay in the PrestaShop back office. They are not sent to the browser.

## Install

PrestaShop 1.7.7 or newer, including 8 and 9. PHP 7.2 or newer. The shop must use HTTPS. Not published on PrestaShop Addons.

1. Download [voybit.zip](https://github.com/VOYBIT/voybit-payment-gateway-prestashop/releases/download/v1.0.0/voybit.zip). The archive contains `voybit/voybit.php`. You can also copy the `voybit` folder from this repository into the shop's `modules` directory.
2. In the back office, open **Modules → Module Manager → Upload a module** and choose the zip. Install and enable **Voybit**.
3. Open **Configure** on the Voybit module.
4. Paste the API key, webhook secret, and asset ID. Leave a secret blank on a later save to keep the saved value.
5. Copy the webhook URL and the return URL shown there into the same gateway in the Voybit dashboard.

Both addresses have to be HTTPS. Enable SSL under **Shop Parameters → General** before enabling Voybit at checkout.

The order total is the amount the customer pays, in the shop currency. A 25.00 order asks for 25.00 of the selected asset. Use a stablecoin that matches the shop currency, such as USDT for a USD store.

Placing the order opens Voybit checkout. The order stays in **Awaiting Voybit payment** until a signed webhook says `paid` or `overpaid`. A repeated delivery is ignored. The return page does not mark the order paid.

## Support

If the module does not install, or a payment does not complete as described here, contact Voybit support at [voybit.com/contact](https://voybit.com/contact).
