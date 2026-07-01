{if $status == 'ok'}
<div class="alert alert-success">
  <p><strong>{l s='Your order on %s is complete.' sprintf=[$shop_name] mod='nomba'}</strong></p>
  <p>{l s='Amount: %s' sprintf=[$total] mod='nomba'}</p>
  <p>{l s='Order reference: %s' sprintf=[$reference] mod='nomba'}</p>
  <p>{l s='An email has been sent with this information.' mod='nomba'}</p>
  <p>{l s='If you have questions, please contact us.' mod='nomba'} <a href="{$contact_url|escape:'html':'UTF-8'}">{l s='customer service' mod='nomba'}</a></p>
</div>
{/if}