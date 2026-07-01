<div class="panel" id="nomba-refund-panel">
  <div class="panel-heading">
    <i class="icon-undo"></i> {l s='Nomba Refund' mod='nomba'}
  </div>
  <div class="panel-body">
    <div class="alert alert-info">
      <p><strong>{l s='Transaction Reference:' mod='nomba'}</strong> {$transactionRef|escape:'html':'UTF-8'}</p>
      <p><strong>{l s='Order Total:' mod='nomba'}</strong> {displayPrice price=$orderTotal}</p>
    </div>

    {if $has_bank_details}
      <a href="{$refundUrl|escape:'html':'UTF-8'}" class="btn btn-danger" onclick="return confirm('{l s='Process full refund via Nomba? This cannot be undone.' mod='nomba'}');">
        <i class="icon-undo"></i> {l s='Process Refund via Nomba API' mod='nomba'}
      </a>
      <p class="help-block" style="margin-top:10px;">
        {l s='Refund will be sent directly to customer bank account' mod='nomba'}
      </p>
    {else}
      <div class="alert alert-warning">
        <p><strong>{l s='Manual Refund Required' mod='nomba'}</strong></p>
        <p>{l s='Bank details not captured. Refund manually in Nomba Dashboard using Transaction ID above.' mod='nomba'}</p>
        <a href="https://dashboard.nomba.com" target="_blank" class="btn btn-default">
          <i class="icon-external-link"></i> {l s='Open Nomba Dashboard' mod='nomba'}
        </a>
      </div>
    {/if}
  </div>
</div>