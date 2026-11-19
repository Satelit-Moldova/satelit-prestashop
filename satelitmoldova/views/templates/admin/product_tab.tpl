{*
 * Satelit Moldova: search term field in the product page (Modules tab).
 *}
<div class="panel product-tab satelitmoldova-tab">
  <h3>Satelit Moldova</h3>
  <div class="form-group">
    <label class="control-label" for="satelit_term">{l s='Satelit search term' mod='satelitmoldova'}</label>
    <input type="text" id="satelit_term" name="satelit_term" maxlength="100" class="form-control" value="{$satelit_term|escape:'html':'UTF-8'}" placeholder="{l s='e.g. iPhone 15 128GB' mod='satelitmoldova'}">
    <p class="help-block">{l s='Words that name this product in Moldovan shops, for the market price block. Empty: the product name.' mod='satelitmoldova'}</p>
  </div>
</div>
