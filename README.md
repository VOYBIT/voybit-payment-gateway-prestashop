# Voybit payment gateway for PrestaShop

## Get an API key

1. Create an account at [dashboard.voybit.com](https://dashboard.voybit.com).
2. Open **Gateways**, create a payment gateway, enable it, and choose every asset customers may use.
3. Open **API keys**, choose **Create secret key**, and bind it to that gateway. Copy the full `vb_live_…` value once.

The API key stays in the PrestaShop back office and is not sent to the browser. The module configures the webhook and customer return URL automatically and stores the rotated signing secret.

## Install

PrestaShop 1.7.7 or newer, including 8 and 9. PHP 7.2 or newer. The shop must use HTTPS. Not published on PrestaShop Addons.

1. Download [voybit.zip](https://github.com/VOYBIT/voybit-payment-gateway-prestashop/releases/download/v1.1.0/voybit.zip). The archive contains `voybit/voybit.php`. You can also copy the `voybit` folder from this repository into the shop's `modules` directory.
2. In the back office, open **Modules → Module Manager → Upload a module** and choose the zip. Install and enable **Voybit**.
3. Open **Configure** on the Voybit module.
4. Paste the API key and save. No asset ID or webhook secret is required; the module registers its webhook and return URL automatically.

Both addresses have to be HTTPS. Enable SSL under **Shop Parameters → General** before enabling Voybit at checkout.

The order total is sent in the shop currency. Voybit shows only assets enabled on that gateway; the customer chooses one and confirms the live crypto quote before the address and QR are created.

Placing the order opens Voybit checkout. The order stays in **Awaiting Voybit payment** until a signed webhook says `paid` or `overpaid`. A repeated delivery is ignored. The return page does not mark the order paid.

## Support

If the module does not install, or a payment does not complete as described here, contact Voybit support at [voybit.com/contact](https://voybit.com/contact).
