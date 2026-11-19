{*
 * Satelit Moldova: rates panel of the configuration page.
 *}
<div class="panel">
  <h3><i class="icon-money"></i> {l s='BNM exchange rates' mod='satelitmoldova'}</h3>
  <p>{l s='Sets the conversion rate of every shop currency BNM publishes (EUR, USD, RON, RUB, UAH...) from the official MDL rates, whatever your default currency is.' mod='satelitmoldova'}
    {l s='Default currency:' mod='satelitmoldova'} <strong>{$satelit_default|escape:'html':'UTF-8'}</strong>.
    {if !$satelit_has_mdl}<br><span class="text-warning">{l s='Add the Moldovan leu (MDL) under International > Localization > Currencies to show market prices in your currencies.' mod='satelitmoldova'}</span>{/if}</p>
  <p class="text-muted">Sursa: Banca Națională a Moldovei, bnm.md, CC BY 4.0</p>
  <p><strong>{l s='Last update' mod='satelitmoldova'}:</strong> <span id="satelit-last">{if $satelit_last}{$satelit_last|escape:'html':'UTF-8'}{else}{l s='Not yet' mod='satelitmoldova'}{/if}</span></p>
  <p><strong>{l s='Cron URL' mod='satelitmoldova'}:</strong></p>
  <input type="text" readonly class="form-control" value="{$satelit_cron|escape:'html':'UTF-8'}" onclick="this.select()">
  <p class="help-block">{l s='Call it every hour; it asks the API only when the last rates are older than the TTL. Turn off the live exchange rates of PrestaShop so they do not overwrite these.' mod='satelitmoldova'}</p>
</div>
