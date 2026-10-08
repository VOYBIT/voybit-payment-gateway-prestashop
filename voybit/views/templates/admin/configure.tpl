<div class="panel">
  <div class="panel-heading">
    {l s='Voybit gateway' mod='voybit'}
  </div>
  <p>{l s='Copy both addresses into the Voybit gateway. Both must use HTTPS.' mod='voybit'}</p>
  <div class="form-group">
    <label class="control-label">{l s='Webhook URL' mod='voybit'}</label>
    <input class="form-control" type="text" readonly="readonly" value="{$voybit_webhook_url|escape:'html':'UTF-8'}" />
  </div>
  <div class="form-group">
    <label class="control-label">{l s='Return URL' mod='voybit'}</label>
    <input class="form-control" type="text" readonly="readonly" value="{$voybit_return_url|escape:'html':'UTF-8'}" />
  </div>
  <p>
    {l s='Customers pay on the Voybit page. Enter the API key, webhook secret, and asset ID from the Voybit dashboard. If setup does not match this page, contact Voybit support:' mod='voybit'}
    <a href="https://voybit.com/contact" target="_blank" rel="noopener noreferrer">https://voybit.com/contact</a>
  </p>
</div>
