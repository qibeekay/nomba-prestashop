<?php

class NombaValidationModuleFrontController extends ModuleFrontController
{
    /** @var Nomba */
    public $module;

    public function init()
    {
        parent::init();
        $this->module = Module::getInstanceByName('nomba');

        if (!Validate::isLoadedObject($this->module)) {
            die('Nomba module not found');
        }
    }

    public function initContent()
    {
        parent::initContent();

        $reference = Tools::getValue('reference');
        $status = Tools::getValue('status'); // Nomba sends '00' on redirect
        $cart = $this->context->cart;

        if (!$reference || $cart->id_customer == 0) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        // 1. If order already exists, webhook worked - go to confirmation
        $orderId = Order::getIdByCartId((int)$cart->id);
        if ($orderId) {
            $order = new Order($orderId);
            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . $cart->id . '&id_module=' . $this->module->id . '&id_order=' . $orderId . '&key=' . $order->secure_key);
        }

        // 2. If Nomba said payment failed on redirect, don't wait for webhook
        if ($status && $status !== '00') {
            PrestaShopLogger::addLog('Nomba Redirect: Payment failed. Status: ' . $status, 3);
            $this->errors[] = $this->module->l('Payment was not successful.');
            $this->redirectWithNotifications($this->context->link->getPageLink('order', true, null, ['step' => '3']));
            return;
        }

        // 3. Otherwise show pending page - waiting for webhook
        $this->context->smarty->assign([
            'nomba_reference' => $reference,
            'check_url' => $this->context->link->getModuleLink('nomba', 'validation', ['reference' => $reference])
        ]);

        $this->setTemplate('module:nomba/views/templates/front/pending.tpl');
    }
}
