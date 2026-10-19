<?php

/**
 * Canonical back-office controller for CCI Consent History.
 *
 * @license https://opensource.org/license/afl-3-0-php Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Adapter\SymfonyContainer;

class AdminCciConsentHistoryController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        $this->meta_title = $this->module->displayName;
    }

    public function initContent()
    {
        $this->module->prepareModernAdminPage();
        $router = SymfonyContainer::getInstance()->get('router');
        Tools::redirectAdmin($router->generate('cci_consent_history_index'));
    }
}
