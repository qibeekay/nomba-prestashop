<?php

class NombaPaymentModuleFrontController extends ModuleFrontController
{
    public $module; // <-- Add this

    public function init()
    {
        parent::init();
        $this->module = Module::getInstanceByName('nomba'); // <-- Add this
    }

    public function postProcess()
    {
        $cart = $this->context->cart;

        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        require_once _PS_MODULE_DIR_ . 'nomba/src/Service/NombaApiClient.php';

        // DEBUG: Check if Account ID is actually saved
        $accountId = \Configuration::get('NOMBA_ACCOUNT_ID');
        if (empty($accountId)) {
            PrestaShopLogger::addLog('NOMBA ERROR: Account ID is empty. Go to Modules → Nomba → Configure', 3);
            $this->errors[] = $this->module->l('Nomba Account ID not configured. Please contact store admin.');
            $this->redirectWithNotifications($this->context->link->getPageLink('order', true, null, ['step' => '3']));
        }

        $orderReference = 'PS_' . $cart->id . '_' . time();

        try {
            $nombaApi = new NombaApiClient();
            $checkoutData = $nombaApi->createCheckoutOrder($cart, $orderReference);

            if (isset($checkoutData['data']['checkoutLink'])) {
                Tools::redirect($checkoutData['data']['checkoutLink']);
            } else {
                throw new Exception('No checkout link returned from Nomba. Response: ' . json_encode($checkoutData));
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog('Nomba Payment Error: ' . $e->getMessage(), 3);
            $this->errors[] = $this->module->l('An error occurred while connecting to Nomba. Please try again.');
            $this->redirectWithNotifications($this->context->link->getPageLink('order', true, null, ['step' => '3']));
        }
    }
}
