<?php
class AdminNombaController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->className = 'Configuration';
        $this->table = 'configuration';
        $this->module = Module::getInstanceByName('nomba');

        parent::__construct();

        $this->fields_options = [
            'nomba' => [
                'title' => $this->module->l('Nomba Checkout Settings'),
                'fields' => [
                    'NOMBA_LIVE_MODE' => [
                        'title' => $this->module->l('Live mode'),
                        'type' => 'bool',
                        'cast' => 'intval',
                        'validation' => 'isBool',
                        'hint' => $this->module->l('Use live mode. Disable for sandbox testing.'),
                    ],
                    'NOMBA_CLIENT_ID' => [
                        'title' => $this->module->l('Client ID'),
                        'type' => 'text',
                        'size' => 64,
                        'required' => true,
                        'validation' => 'isGenericName',
                    ],
                    'NOMBA_ACCOUNT_ID' => [
                        'title' => $this->module->l('Account ID'),
                        'type' => 'text',
                        'size' => 64,
                        'required' => true,
                        'validation' => 'isGenericName',
                    ],
                    'NOMBA_PRIVATE_KEY' => [
                        'title' => $this->module->l('Private Key'),
                        'type' => 'textarea',
                        'rows' => 5,
                        'required' => true,
                        'desc' => $this->module->l('Used to verify webhook signatures from Nomba'),
                    ],
                ],
                'submit' => [
                    'title' => $this->module->l('Save'),
                ],
            ],
        ];
    }

    public function initContent()
    {
        $this->display = 'options';
        $this->content .= $this->displayWebhookInfo();
        parent::initContent();
    }

    protected function displayWebhookInfo()
    {
        $webhookUrl = $this->context->link->getModuleLink('nomba', 'webhook', [], true);
        $redirectUrl = $this->context->link->getModuleLink('nomba', 'validation', [], true);

        $this->context->smarty->assign([
            'webhook_url' => $webhookUrl,
            'redirect_url' => $redirectUrl
        ]);

        $templatePath = $this->module->getLocalPath() . 'views/templates/admin/webhook_info.tpl';

        if (file_exists($templatePath)) {
            return $this->context->smarty->fetch($templatePath);
        } else {
            return '
            <div class="panel">
                <div class="panel-heading">
                    <i class="icon-link"></i> Nomba URL Configuration
                </div>
                <div class="alert alert-info">
                    <strong>1. Webhook URL - For Nomba Dashboard:</strong><br>
                    <code>' . $webhookUrl . '</code><br>
                    <small>Copy this into Nomba Dashboard → Settings → Webhooks</small>
                </div>
                <div class="alert alert-warning">
                    <strong>2. Redirect URL - For Checkout API callbackUrl:</strong><br>
                    <code>' . $redirectUrl . '</code><br>
                    <small>Customer browser redirects here after payment</small>
                </div>
            </div>';
        }
    }
}
