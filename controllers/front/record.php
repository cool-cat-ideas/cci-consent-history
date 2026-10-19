<?php

/**
 * Native GDPR consent logging with a signed historical wording snapshot.
 *
 * @license https://opensource.org/license/afl-3-0-php Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Cci_Consent_HistoryRecordModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $ssl = true;

    public function postProcess(): void
    {
        header('Cache-Control: no-store');
        $snapshot = Tools::getValue('cci_gdpr_snapshot');
        $valid = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST'
            && Tools::getValue('action') === 'AddLog'
            && is_string($snapshot);

        try {
            $recorded = $valid && $this->module->recordGdprFormConsent((int) Tools::getValue('id_module'), $snapshot);
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog('CCI Consent History could not record a GDPR form acceptance.', 3);
            http_response_code(500);
            $this->ajaxRender(json_encode(['recorded' => false]));
            exit;
        }

        http_response_code($recorded ? 200 : 403);
        header('Content-Type: application/json; charset=utf-8');
        $this->ajaxRender(json_encode(['recorded' => $recorded]));
        exit;
    }
}
