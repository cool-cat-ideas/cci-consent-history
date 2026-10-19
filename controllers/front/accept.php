<?php

/**
 * Records a renewed account privacy consent.
 *
 * @license https://opensource.org/license/afl-3-0-php Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Cci_Consent_HistoryAcceptModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            header('Allow: POST');
            http_response_code(405);
            exit;
        }

        if (!$this->context->customer->isLogged()) {
            Tools::redirect($this->context->link->getPageLink('authentication', true));
        }

        $idCustomer = (int) $this->context->customer->id;
        $idVersion = (int) Tools::getValue('consent_version');
        $token = (string) Tools::getValue('consent_token');
        $accepted = (bool) Tools::getValue('accept_consent');

        if (
            !$accepted
            || !$this->module->isValidAcceptanceToken($idCustomer, $idVersion, $token)
            || !$this->module->acceptCurrentConsent($idCustomer, $idVersion)
        ) {
            $this->context->cookie->__set(
                'cci_consent_history_error',
                $this->trans(
                    'We could not save your acceptance. Check the box and try again.',
                    [],
                    'Modules.Cciconsenthistory.Shop'
                )
            );
        }

        Tools::redirect($this->module->getSafeReturnUrl((string) Tools::getValue('return_to')));
    }
}
