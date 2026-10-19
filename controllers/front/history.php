<?php

/**
 * Read-only consent history for the signed-in customer.
 *
 * @license https://opensource.org/license/afl-3-0-php Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Cci_Consent_HistoryHistoryModuleFrontController extends ModuleFrontController
{
    private const PAGE_SIZE = 20;

    public $auth = true;
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();
        header('Cache-Control: private, no-store, max-age=0');

        $idCustomer = (int) $this->context->customer->id;
        $idShop = (int) $this->context->shop->id;
        $total = $this->module->countCustomerHistory($idCustomer, $idShop);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = max(1, min($pages, (int) Tools::getValue('history_page', 1)));
        $link = $this->context->link;

        $this->context->smarty->assign([
            'consent_history' => $this->module->getCustomerHistory($idCustomer, $idShop, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'consent_history_total' => $total,
            'consent_history_page' => $page,
            'consent_history_pages' => $pages,
            'consent_history_previous' => $page > 1 ? $link->getModuleLink($this->module->name, 'history', ['history_page' => $page - 1], true) : null,
            'consent_history_next' => $page < $pages ? $link->getModuleLink($this->module->name, 'history', ['history_page' => $page + 1], true) : null,
            'consent_history_data_url' => Module::isEnabled('psgdpr') ? $link->getModuleLink('psgdpr', 'gdpr', [], true) : null,
        ]);
        $this->setTemplate('module:cci_consent_history/views/templates/front/history.tpl');
    }

    public function setMedia()
    {
        parent::setMedia();

        $this->registerStylesheet(
            'cci-customer-consent-history',
            'modules/cci_consent_history/views/css/customer-history.css',
            ['media' => 'all', 'priority' => 220, 'version' => (string) filemtime($this->module->getLocalPath() . 'views/css/customer-history.css')]
        );
    }

    public function getTemplateVarPage()
    {
        $page = parent::getTemplateVarPage();
        $page['meta']['title'] = $this->trans('Consent history', [], 'Modules.Cciconsenthistory.Shop');
        $page['meta']['robots'] = 'noindex,follow';

        return $page;
    }

    public function getBreadcrumbLinks()
    {
        $breadcrumb = parent::getBreadcrumbLinks();
        $breadcrumb['links'][] = [
            'title' => $this->trans('Your account', [], 'Shop.Theme.Customeraccount'),
            'url' => $this->context->link->getPageLink('my-account', true),
        ];
        $breadcrumb['links'][] = [
            'title' => $this->trans('Consent history', [], 'Modules.Cciconsenthistory.Shop'),
            'url' => $this->context->link->getModuleLink($this->module->name, 'history', [], true),
        ];

        return $breadcrumb;
    }
}
