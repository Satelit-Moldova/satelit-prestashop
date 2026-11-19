{*
 * Satelit Moldova: "Market price" block on the product page.
 * Values from the API are escaped here, never passed through sprintf.
 *}
<div class="satelit-market">
  <p class="satelit-market__head"><strong>{l s='Market price' mod='satelitmoldova'}</strong>
    <span class="satelit-market__sum">{l s='from' mod='satelitmoldova'} {$satelit_item.min|escape:'html':'UTF-8'} · {l s='median' mod='satelitmoldova'} {$satelit_item.median|escape:'html':'UTF-8'}{if $satelit_item.stores > 1} · {l s='in %d shops' sprintf=[$satelit_item.stores|intval] mod='satelitmoldova'}{/if}</span></p>
  <p class="satelit-market__match">{l s='Compared with:' mod='satelitmoldova'} {$satelit_item.name|escape:'html':'UTF-8'}</p>
  <ul class="satelit-market__list">
    {foreach from=$satelit_offers item=offer}
      <li><span>{$offer.store|escape:'html':'UTF-8'}{if $offer.out} <small>({l s='out of stock' mod='satelitmoldova'})</small>{/if}</span><strong>{$offer.price|escape:'html':'UTF-8'}</strong></li>
    {/foreach}
  </ul>
  <p class="satelit-market__src"><a href="https://satelit.md" target="_blank" rel="noopener">{l s='Data: Satelit' mod='satelitmoldova'}</a></p>
</div>
