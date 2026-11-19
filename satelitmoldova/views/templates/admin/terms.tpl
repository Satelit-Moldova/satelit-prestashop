{*
 * Satelit Moldova: search terms by product (configuration page).
 *}
<div class="panel">
  <h3><i class="icon-search"></i> {l s='Search terms by product' mod='satelitmoldova'}</h3>
  <p>{l s='Words that name a product in Moldovan shops, for the market price block. A product without a term is searched by its name. An empty term removes it.' mod='satelitmoldova'}</p>
  <form method="post" action="{$satelit_action|escape:'html':'UTF-8'}" class="form-inline">
    <input type="number" min="1" name="satelit_term_product" class="form-control" placeholder="{l s='Product ID' mod='satelitmoldova'}" required>
    <input type="text" name="satelit_term" maxlength="100" class="form-control" placeholder="{l s='e.g. iPhone 15 128GB' mod='satelitmoldova'}">
    <button type="submit" name="submitSatelitTerm" value="1" class="btn btn-default">{l s='Save' mod='satelitmoldova'}</button>
  </form>
  {if $satelit_terms}
    <table class="table" style="margin-top:12px">
      <thead><tr><th>{l s='Product ID' mod='satelitmoldova'}</th><th>{l s='Product' mod='satelitmoldova'}</th><th>{l s='Satelit search term' mod='satelitmoldova'}</th></tr></thead>
      <tbody>
        {foreach from=$satelit_terms item=row}
          <tr><td>{$row.id_product|intval}</td><td>{$row.name|escape:'html':'UTF-8'}</td><td>{$row.term|escape:'html':'UTF-8'}</td></tr>
        {/foreach}
      </tbody>
    </table>
  {/if}
</div>
