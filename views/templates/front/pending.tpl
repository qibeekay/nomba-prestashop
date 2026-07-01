{extends file='page.tpl'}

{block name='page_title'}
  {l s='Payment Processing' mod='nomba'}
{/block}

{block name='page_content'}
  <div class="card">
    <div class="card-block">
      <div class="text-xs-center">
        <h3><i class="material-icons" style="font-size:48px;">hourglass_empty</i></h3>
        <h3>{l s='Confirming your payment...' mod='nomba'}</h3>
        <p>{l s='Please wait while we verify your payment with Nomba.' mod='nomba'}</p>
        <p><strong>{l s='Reference:' mod='nomba'} {$nomba_reference}</strong></p>
        <p class="text-muted">{l s='This page will refresh automatically. Do not close your browser.' mod='nomba'}</p>
        
        <div class="loader" style="margin: 20px auto; border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite;"></div>
      </div>
    </div>
  </div>

  <style>
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
  </style>

  <script>
    // Auto-refresh every 5 seconds to check if webhook created the order
    setTimeout(function(){ 
      window.location.href = "{$check_url}"; 
    }, 5000);
  </script>
{/block}