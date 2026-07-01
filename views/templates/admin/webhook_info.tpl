<div class="panel">
    <div class="panel-heading">
        <i class="icon-link"></i> {l s='Nomba Webhook Configuration' mod='nomba'}
    </div>
    <div class="panel-body">
        <div class="alert alert-info">
            <p>{l s='Copy this URL and paste it into your Nomba Dashboard → Settings → Webhooks' mod='nomba'}</p>
        </div>
        
        <div class="form-group">
            <label class="control-label col-lg-3">{l s='Webhook URL' mod='nomba'}</label>
            <div class="col-lg-9">
                <div class="input-group">
                    <input type="text" class="form-control" id="nomba_webhook_url" 
                           value="{$webhook_url|escape:'htmlall':'UTF-8'}" readonly>
                    <span class="input-group-btn">
                        <button class="btn btn-default" type="button" onclick="copyWebhookUrl()">
                            <i class="icon-copy"></i> {l s='Copy' mod='nomba'}
                        </button>
                    </span>
                </div>
                <p class="help-block">
                    {l s='Nomba will send payment notifications to this URL. For local testing, use ngrok to expose localhost.' mod='nomba'}
                </p>
            </div>
        </div>
    </div>
</div>

<script>
function copyWebhookUrl() {
    var copyText = document.getElementById("nomba_webhook_url");
    copyText.select();
    document.execCommand("copy");
    $.growl.notice({ title: "", message: "{l s='Copied to clipboard' mod='nomba'}" });
}
</script>