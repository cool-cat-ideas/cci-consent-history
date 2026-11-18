<?php

/**
 * CCI Consent History for PrestaShop.
 *
 * @author    Cool Cat Ideas <info@coolcatideas.com>
 * @copyright 2026 Cool Cat Ideas
 * @license   https://opensource.org/license/afl-3-0-php Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/CciConsentHistoryRepository.php';
require_once __DIR__ . '/classes/CciGdprFormConsent.php';
require_once __DIR__ . '/classes/CciCustomerConsentSourceRegistry.php';
require_once __DIR__ . '/classes/CciCustomerConsentRecorder.php';
require_once __DIR__ . '/vendor/autoload.php';

require_once __DIR__ . '/classes/Admin/CciConsentHistoryAdminPresentation.php';

class Cci_Consent_History extends Module
{
    private const ADMIN_PARENT_TAB_CLASS = 'AdminCciParent';
    private const ADMIN_TAB_CLASS = 'AdminCciConsentHistory';
    private const CONFIG_REQUIRE_RENEWAL = 'CCI_CONSENT_HISTORY_REQUIRE_RENEWAL';
    private const CONFIG_INSTALLED_AT = 'CCI_CONSENT_HISTORY_INSTALLED_AT';
    /** @var CciConsentHistoryRepository|null */
    private $repository;

    private ?Module\CciConsentHistory\Import\ImportManager $importManager = null;

    /** @var array<string, mixed>|false|null */
    private $pendingConsent;

    private ?CciCustomerConsentRecorder $customerConsentRecorder = null;

    private ?CciConsentHistoryAdminPresentation $adminPresentation = null;

    public function __construct()
    {
        $this->name = 'cci_consent_history';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Cool Cat Ideas';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = [
            'min' => '9.0.0',
            'max' => '9.2.999',
        ];

        parent::__construct();

        $this->displayName = $this->trans(
            'CCI Consent History for PrestaShop GDPR Module',
            [],
            'Modules.CciConsentHistory.Admin'
        );
        $this->description = $this->trans(
            'Store the exact account-consent wording, language, version and acceptance date. Ask for renewed acceptance after the wording changes.',
            [],
            'Modules.CciConsentHistory.Admin'
        );
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        $installed = $this->getRepository()->installSchema()
            && $this->getImportManager()->registry()->installSchema()
            && Configuration::updateValue(self::CONFIG_REQUIRE_RENEWAL, 1)
            && Configuration::updateValue(self::CONFIG_INSTALLED_AT, date('Y-m-d H:i:s'))
            && $this->registerHook('actionCustomerAccountAdd')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('displayFooter')
            && $this->ensureCustomerHistoryHooks()
            && $this->ensureAdminTab()
            && $this->createBaselineVersions();

        if (!$installed) {
            parent::uninstall();
        }

        return $installed;
    }

    public function uninstall()
    {
        return $this->getImportManager()->registry()->uninstallSchema()
            && $this->uninstallAdminTab()
            && Configuration::deleteByName(self::CONFIG_REQUIRE_RENEWAL)
            && Configuration::deleteByName(self::CONFIG_INSTALLED_AT)
            && $this->getRepository()->uninstallSchema()
            && parent::uninstall();
    }

    private function getRepository(): CciConsentHistoryRepository
    {
        if (!$this->repository instanceof CciConsentHistoryRepository) {
            $this->repository = new CciConsentHistoryRepository();
        }

        return $this->repository;
    }

    private function createBaselineVersions(): bool
    {
        if (!Module::isInstalled('psgdpr') || !Module::isEnabled('psgdpr')) {
            return true;
        }

        $shopIds = Shop::getShops(true, null, true);
        $languages = Language::getLanguages(true);

        foreach ($shopIds as $shopId) {
            foreach ($languages as $language) {
                $this->getRepository()->ensureCurrentAccountConsentVersion(
                    (int) $shopId,
                    (int) $language['id_lang'],
                    true
                );
            }
        }

        return true;
    }

    public function getImportManager(): Module\CciConsentHistory\Import\ImportManager
    {
        if ($this->importManager === null) {
            $registry = new Module\CciConsentHistory\Import\ImporterRegistry();
            (new Module\CciConsentHistory\Import\BundledImporterLoader())->register(
                __DIR__ . '/integrations',
                $registry,
                $this->getRepository()
            );
            Hook::exec('actionCciConsentHistoryRegisterImporters', ['registry' => $registry]);
            $this->importManager = new Module\CciConsentHistory\Import\ImportManager($registry);
        }

        return $this->importManager;
    }

    public function ensureCustomerHistoryHooks(): bool
    {
        $hooks = [
            'displayCustomerAccount',
            'actionExportGDPRData',
            'displayCustomerAccountForm',
            'actionCustomerAccountUpdate',
            'displayGDPRConsent',
            'additionalHtmlAttributesFormFields',
            'actionObjectCustomerUpdateBefore',
        ];
        foreach ($hooks as $hook) {
            if (!$this->isRegisteredInHook($hook) && !$this->registerHook($hook)) {
                return false;
            }
        }

        return true;
    }

    public function hookDisplayCustomerAccount(): string
    {
        if (!$this->context->customer->isLogged()) {
            return '';
        }

        $controller = $this->context->controller;
        $this->context->smarty->assign([
            'cci_consent_history_url' => $this->context->link->getModuleLink($this->name, 'history', [], true),
            'cci_consent_history_link_active' => $controller instanceof ModuleFrontController
                && $controller->module->name === $this->name,
        ]);

        return $this->fetch('module:' . $this->name . '/views/templates/hook/customer_account.tpl');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCustomerHistory(int $idCustomer, int $idShop, ?int $limit = null, int $offset = 0): array
    {
        $rows = $this->getRepository()->getCustomerHistory($idCustomer, $idShop, $limit, $offset);
        foreach ($rows as &$row) {
            $source = match ($row['acceptance_source']) {
                'account_creation' => 'Account creation',
                'renewal' => 'Renewed acceptance',
                'profile_update' => 'Profile update',
                'gdpr_form' => 'GDPR form submission',
                default => 'Imported acceptance',
            };
            $row['source_label'] = $this->trans($source, [], 'Modules.Cciconsenthistory.Shop');
            $row['purpose_label'] = $this->getConsentPurposeLabel($row);
            $row['status_label'] = $this->trans(
                str_ends_with($row['acceptance_source'], '_withdrawn') ? 'Withdrawn'
                    : (str_ends_with($row['acceptance_source'], '_granted') ? 'Granted' : 'Accepted'),
                [], 'Modules.Cciconsenthistory.Shop'
            );
            if (in_array($row['acceptance_source'], ['customer_choice_granted', 'customer_choice_withdrawn', 'customer_field_accepted'], true)) {
                $row['source_label'] = $this->trans(
                    str_starts_with((string) ($row['source_reference'] ?? ''), 'registration:') ? 'Account creation' : 'Profile update',
                    [], 'Modules.Cciconsenthistory.Shop'
                );
            }
        }
        unset($row);

        return $rows;
    }

    public function getConsentPurposeLabel(array $row): string
    {
        $label = match ($row['purpose_key']) {
            CciConsentHistoryRepository::PURPOSE_ACCOUNT_PRIVACY => 'Account privacy information',
            CciConsentHistoryRepository::PURPOSE_PROFILE_PRIVACY => 'Profile privacy information',
            'newsletter_subscription' => 'Newsletter subscription',
            'partner_offers' => 'Offers from partners',
            'customer_data_privacy' => 'Customer data privacy',
            default => null,
        };
        if ($label !== null) {
            return $this->trans($label, [], 'Modules.Cciconsenthistory.Shop');
        }

        if (str_starts_with($row['purpose_key'], 'gdpr_form_')) {
            $module = Module::getInstanceByName((string) $row['source_module']);

            return $module instanceof Module && !empty($module->displayName) ? $module->displayName : (string) $row['source_module'];
        }

        $heading = preg_split('/<br\s*\/?\s*>|<\/p>/i', (string) ($row['content_html'] ?? $row['content_text']))[0] ?? '';
        $heading = trim(html_entity_decode(strip_tags($heading), ENT_QUOTES, 'UTF-8'));

        return mb_strlen($heading) > 100 ? mb_substr($heading, 0, 97) . '...' : $heading;
    }

    public function countCustomerHistory(int $idCustomer, int $idShop): int
    {
        return $this->getRepository()->countCustomerHistory($idCustomer, $idShop);
    }

    public function hookActionExportGDPRData(array $params): string
    {
        $idCustomer = (int) ($params['id'] ?? $params['id_customer'] ?? 0);
        if ($idCustomer <= 0) {
            return '[]';
        }

        $rows = $this->getCustomerHistory($idCustomer, (int) $this->context->shop->id);
        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                $this->trans('Recorded on', [], 'Modules.Cciconsenthistory.Shop') => $row['accepted_at'],
                $this->trans('Status', [], 'Modules.Cciconsenthistory.Shop') => $row['status_label'],
                $this->trans('Purpose', [], 'Modules.Cciconsenthistory.Shop') => $row['purpose_label'],
                $this->trans('Version', [], 'Modules.Cciconsenthistory.Shop') => (string) $row['version_number'],
                $this->trans('Language', [], 'Modules.Cciconsenthistory.Shop') => $row['language_name'],
                $this->trans('Source', [], 'Modules.Cciconsenthistory.Shop') => $row['source_label'],
                $this->trans('Saved wording', [], 'Modules.Cciconsenthistory.Shop') => $row['content_text'],
            ];
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]';
    }

    public function hookActionCustomerAccountAdd(array $params): void
    {
        $customer = $params['newCustomer'] ?? null;
        if (!$customer instanceof Customer || !Validate::isLoadedObject($customer)) {
            return;
        }

        if (is_array(Tools::getValue('cci_customer_consent'))) {
            $this->getCustomerConsentRecorder()->recordSubmission($customer, true);
        }

        $version = $this->getRepository()->ensureCurrentAccountConsentVersion(
            (int) $this->context->shop->id,
            (int) $this->context->language->id
        );
        if ($version === null) {
            return;
        }

        $this->getRepository()->recordAcceptance($version, (int) $customer->id, 'account_creation');
    }

    private function getCustomerConsentRecorder(): CciCustomerConsentRecorder
    {
        if ($this->customerConsentRecorder !== null) {
            return $this->customerConsentRecorder;
        }

        $registry = new CciCustomerConsentSourceRegistry();
        $readPreference = function (string $field): callable {
            return function (int $idCustomer) use ($field): ?bool {
                $preferences = $this->getRepository()->getCustomerPreferences($idCustomer);

                return array_key_exists($field, $preferences) ? (bool) $preferences[$field] : null;
            };
        };
        $registry->register(
            'newsletter',
            'newsletter_subscription',
            'ps_emailsubscription',
            'choice',
            $readPreference('newsletter')
        );
        $registry->register('optin', 'partner_offers', 'prestashop', 'choice', $readPreference('optin'));
        $registry->register('customer_privacy', 'customer_data_privacy', 'ps_dataprivacy', 'acknowledgement');
        Hook::exec('actionCciConsentHistoryRegisterCustomerSources', ['registry' => $registry, 'context' => $this->context]);

        return $this->customerConsentRecorder = new CciCustomerConsentRecorder($this->getRepository(), $this->context, $registry);
    }

    public function hookAdditionalHtmlAttributesFormFields(array $params): void
    {
        if (isset($params['formFieldArray']) && is_array($params['formFieldArray'])) {
            $this->getCustomerConsentRecorder()->decorateField($params['formFieldArray']);
        }
    }

    public function hookActionObjectCustomerUpdateBefore(array $params): void
    {
        $customer = $params['object'] ?? null;
        if ($customer instanceof Customer && is_array(Tools::getValue('cci_customer_consent'))) {
            $this->getCustomerConsentRecorder()->captureBeforeUpdate($customer);
        }
    }

    public function hookDisplayGDPRConsent(array $params): string
    {
        $snapshot = (new CciGdprFormConsent($this->getRepository(), $this->context))
            ->createSnapshot((int) ($params['id_module'] ?? 0));
        if ($snapshot === null) {
            return '';
        }

        $this->context->smarty->assign('cci_gdpr_snapshot', $snapshot);

        return $this->fetch('module:' . $this->name . '/views/templates/hook/gdpr_snapshot.tpl');
    }

    public function recordGdprFormConsent(int $idModule, string $snapshot): bool
    {
        return (new CciGdprFormConsent($this->getRepository(), $this->context))->record($idModule, $snapshot);
    }

    public function hookDisplayCustomerAccountForm(): string
    {
        if (!$this->isCustomerProfileRequest()) {
            return '';
        }

        $version = $this->getRepository()->ensureCurrentProfileConsentVersion(
            (int) $this->context->shop->id,
            (int) $this->context->language->id
        );
        if ($version === null) {
            return '';
        }

        $idVersion = (int) $version['id_cci_consent_version'];
        $nonce = bin2hex(random_bytes(16));
        $signature = $this->createProfileSnapshotSignature($idVersion, $nonce);
        $this->context->smarty->assign('cci_profile_consent_snapshot', $idVersion . '.' . $nonce . '.' . $signature);

        return $this->fetch('module:' . $this->name . '/views/templates/hook/profile_snapshot.tpl');
    }

    public function hookActionCustomerAccountUpdate(array $params): void
    {
        $customer = $params['customer'] ?? null;
        if ($customer instanceof Customer && is_array(Tools::getValue('cci_customer_consent'))) {
            $this->getCustomerConsentRecorder()->recordSubmission($customer);
        }
        if (
            !$this->isCustomerProfileRequest()
            || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST'
            || !$customer instanceof Customer
            || !Validate::isLoadedObject($customer)
            || (int) $customer->id !== (int) $this->context->customer->id
            || !in_array(Tools::getValue('psgdpr'), [1, '1'], true)
            || !Module::isEnabled('psgdpr')
            || !(bool) Configuration::get('PSGDPR_CUSTOMER_FORM_SWITCH', null, null, (int) $this->context->shop->id)
        ) {
            return;
        }

        $snapshot = Tools::getValue('cci_profile_consent_snapshot');
        if (!is_string($snapshot) || !preg_match('/^([1-9][0-9]*)\.([a-f0-9]{32})\.([a-f0-9]{64})$/D', $snapshot, $matches)) {
            return;
        }

        $idVersion = (int) $matches[1];
        $nonce = $matches[2];
        if (!hash_equals($this->createProfileSnapshotSignature($idVersion, $nonce), $matches[3])) {
            return;
        }

        $version = $this->getRepository()->getProfileConsentVersion(
            $idVersion,
            (int) $this->context->shop->id,
            (int) $this->context->language->id
        );
        if ($version !== null) {
            $this->getRepository()->recordAcceptance($version, (int) $customer->id, 'profile_update', $nonce);
        }
    }

    private function isCustomerProfileRequest(): bool
    {
        // IdentityController authenticates the session before processing the form.
        // CustomerPersister may rehash the password; isLogged() can then see a stale
        // password-check cache until the redirect starts a fresh request.
        return $this->context->controller instanceof FrontController
            && $this->context->controller->php_self === 'identity'
            && $this->context->customer instanceof Customer
            && (int) $this->context->customer->id > 0
            && (bool) $this->context->customer->logged
            && !(bool) $this->context->customer->is_guest;
    }

    private function createProfileSnapshotSignature(int $idVersion, string $nonce): string
    {
        $payload = implode('|', [
            'profile_update',
            (int) $this->context->customer->id,
            (int) $this->context->shop->id,
            (int) $this->context->language->id,
            $idVersion,
            $nonce,
        ]);

        return hash_hmac('sha256', $payload, _COOKIE_KEY_);
    }

    public function hookActionFrontControllerSetMedia(): void
    {
        if (Module::isEnabled('psgdpr')) {
            $this->context->controller->registerJavascript(
                'module-cci-consent-history-gdpr-sources',
                'modules/' . $this->name . '/views/js/gdpr-sources.js',
                ['position' => 'bottom', 'priority' => 220, 'version' => $this->getAssetVersion('views/js/gdpr-sources.js')]
            );
        }
        if (!$this->context->customer->isLogged()) {
            return;
        }

        $this->context->controller->registerStylesheet(
            'module-cci-consent-history-front',
            'modules/' . $this->name . '/views/css/front.css',
            ['media' => 'all', 'priority' => 220, 'version' => $this->getAssetVersion('views/css/front.css')]
        );
        if ($this->getPendingConsent() === null) {
            return;
        }

        $this->context->controller->registerJavascript(
            'module-cci-consent-history-front',
            'modules/' . $this->name . '/views/js/front.js',
            ['position' => 'bottom', 'priority' => 220, 'version' => $this->getAssetVersion('views/js/front.js')]
        );
    }

    public function hookDisplayFooter(): string
    {
        $version = $this->getPendingConsent();
        if ($version === null) {
            return '';
        }

        $idCustomer = (int) $this->context->customer->id;
        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $error = (string) $this->context->cookie->__get('cci_consent_history_error');
        $this->context->cookie->__unset('cci_consent_history_error');

        $this->context->smarty->assign([
            'cci_consent_history_version' => $version,
            'cci_consent_history_action' => $this->context->link->getModuleLink($this->name, 'accept', [], true),
            'cci_consent_history_token' => $this->createAcceptanceToken(
                $idCustomer,
                (int) $version['id_cci_consent_version']
            ),
            'cci_consent_history_return_to' => $this->normalizeReturnPath($requestUri),
            'cci_consent_history_logout_url' => $this->context->link->getPageLink('index', true, null, 'mylogout='),
            'cci_consent_history_error' => $error,
        ]);

        return $this->fetch('module:' . $this->name . '/views/templates/hook/renewal_modal.tpl');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getPendingConsent(): ?array
    {
        if ($this->pendingConsent !== null) {
            return $this->pendingConsent === false ? null : $this->pendingConsent;
        }

        $this->pendingConsent = false;
        if (
            !(bool) Configuration::get(self::CONFIG_REQUIRE_RENEWAL)
            || !$this->context->customer instanceof Customer
            || !$this->context->customer->isLogged()
        ) {
            return null;
        }

        $version = $this->getRepository()->ensureCurrentAccountConsentVersion(
            (int) $this->context->shop->id,
            (int) $this->context->language->id
        );
        if ($version === null || $this->getRepository()->hasAccepted($version, (int) $this->context->customer->id)) {
            return null;
        }

        if ((bool) $version['is_baseline'] && $this->customerPredatesModule($this->context->customer)) {
            return null;
        }

        $this->pendingConsent = $version;

        return $version;
    }

    private function customerPredatesModule(Customer $customer): bool
    {
        $installedAt = (string) Configuration::get(self::CONFIG_INSTALLED_AT);

        return $installedAt !== ''
            && $customer->date_add !== null
            && strtotime((string) $customer->date_add) < strtotime($installedAt);
    }

    public function createAcceptanceToken(int $idCustomer, int $idVersion): string
    {
        return hash_hmac('sha256', $idCustomer . '|' . $idVersion . '|' . $this->name, _COOKIE_KEY_);
    }

    public function isValidAcceptanceToken(int $idCustomer, int $idVersion, string $token): bool
    {
        return $token !== '' && hash_equals($this->createAcceptanceToken($idCustomer, $idVersion), $token);
    }

    public function acceptCurrentConsent(int $idCustomer, int $submittedVersionId): bool
    {
        $currentVersion = $this->getRepository()->ensureCurrentAccountConsentVersion(
            (int) $this->context->shop->id,
            (int) $this->context->language->id
        );
        if (
            $currentVersion === null
            || (int) $currentVersion['id_cci_consent_version'] !== $submittedVersionId
        ) {
            return false;
        }

        return $this->getRepository()->recordAcceptance($currentVersion, $idCustomer, 'renewal');
    }

    public function getSafeReturnUrl(string $returnTo): string
    {
        $path = $this->normalizeReturnPath($returnTo);
        if ($path === '/') {
            return $this->context->link->getPageLink('my-account', true);
        }

        return Tools::getShopDomainSsl(true) . $path;
    }

    private function normalizeReturnPath(string $returnTo): string
    {
        $returnTo = str_replace(["\r", "\n"], '', trim($returnTo));
        if ($returnTo === '' || $returnTo[0] !== '/' || str_starts_with($returnTo, '//')) {
            return '/';
        }

        $parts = parse_url($returnTo);
        if ($parts === false || isset($parts['host']) || isset($parts['scheme'])) {
            return '/';
        }

        return $returnTo;
    }

    public function getContent(): string
    {
        Tools::redirectAdmin($this->getModernAdminUrl());

        return '';
    }

    public function prepareModernAdminPage(): void
    {
        $this->ensureAdminTab();
        if (!$this->ensureCustomerHistoryHooks()) {
            throw new RuntimeException('Customer consent history hooks could not be registered.');
        }
        if (!$this->getRepository()->installSchema() || !$this->getImportManager()->registry()->installSchema()) {
            throw new RuntimeException('Consent history storage could not be prepared.');
        }
    }

    public function renderDashboardForModernAdmin(): string
    {
        $currentVersion = $this->getRepository()->ensureCurrentAccountConsentVersion(
            (int) $this->context->shop->id,
            (int) $this->context->language->id
        );
        $output = '';

        if (!Module::isInstalled('psgdpr') || !Module::isEnabled('psgdpr')) {
            $output .= $this->displayWarning(
                $this->trans(
                    'Official GDPR Compliance must be installed and enabled before consent history can be recorded.',
                    [],
                    'Modules.CciConsentHistory.Admin'
                )
            );
        } elseif ($currentVersion === null) {
            $output .= $this->displayWarning(
                $this->trans(
                    'Enable the account creation consent and enter its wording in Official GDPR Compliance.',
                    [],
                    'Modules.CciConsentHistory.Admin'
                )
            );
        }

        return $output
            . $this->getAdminPresentation()->renderHeroSection()
            . $this->getAdminPresentation()->renderStats($currentVersion);
    }

    public function isRenewalRequired(): bool
    {
        return (bool) Configuration::get(self::CONFIG_REQUIRE_RENEWAL);
    }

    public function saveRenewalSetting(bool $enabled): string
    {
        Configuration::updateValue(self::CONFIG_REQUIRE_RENEWAL, (int) $enabled);

        return $this->trans('Settings have been saved.', [], 'Modules.CciConsentHistory.Admin');
    }

    public function renderBrandPanelForModernAdmin(): string
    {
        return $this->getAdminPresentation()->renderBrandPanel();
    }

    public function getAdminCssVersion(): string
    {
        return $this->getAssetVersion('views/css/admin.css') . '-'
            . $this->getAssetVersion('views/js/consent-import.js');
    }

    /**
     * @param array<string, mixed>|null $currentVersion
     */
    private function registerAdminAssets(): void
    {
        $controller = $this->context->controller;
        $version = $this->getAssetVersion('views/css/admin.css');

        if (method_exists($controller, 'registerStylesheet')) {
            $controller->registerStylesheet(
                'cci-consent-history-admin',
                'modules/' . $this->name . '/views/css/admin.css?v=' . rawurlencode($version),
                ['media' => 'all', 'priority' => 230]
            );

            return;
        }

        if (method_exists($controller, 'addCSS')) {
            $controller->addCSS($this->_path . 'views/css/admin.css?v=' . rawurlencode($version), 'all');
        }
    }

    private function getModernAdminUrl(): string
    {
        try {
            $router = PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance()->get('router');

            return $router->generate('cci_consent_history_index');
        } catch (Throwable $exception) {
            return $this->context->link->getAdminLink(self::ADMIN_TAB_CLASS);
        }
    }

    private function getAssetVersion(string $relativePath): string
    {
        $path = $this->getLocalPath() . ltrim($relativePath, '/');

        return (string) (@filemtime($path) ?: $this->version);
    }

    private function transCore(string $id): string
    {
        $domain = in_array($id, ['Enabled', 'Disabled'], true) ? 'Admin.Global' : 'Admin.Actions';

        return $this->getTranslator()->trans($id, [], $domain);
    }

    private function escapeHtml($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public function ensureAdminTab(): bool
    {
        $parentId = $this->ensureCciParentTab();
        if ($parentId <= 0) {
            return false;
        }

        $tabId = (int) Tab::getIdFromClassName(self::ADMIN_TAB_CLASS);
        $tab = $tabId > 0 ? new Tab($tabId) : new Tab();
        $tab->active = 1;
        $tab->class_name = self::ADMIN_TAB_CLASS;
        $tab->id_parent = $parentId;
        $tab->icon = 'verified_user';
        $tab->module = $this->name;

        foreach (Language::getLanguages(false) as $language) {
            $tab->name[(int) $language['id_lang']] = 'CCI Consent History';
        }

        $saved = $tabId > 0 ? (bool) $tab->update() : (bool) $tab->add();

        return $saved && $this->normalizeCciMenuPositions($parentId);
    }

    private function ensureCciParentTab(): int
    {
        $improveTabId = (int) Tab::getIdFromClassName('IMPROVE');
        if ($improveTabId <= 0) {
            return 0;
        }

        $tabId = (int) Tab::getIdFromClassName(self::ADMIN_PARENT_TAB_CLASS);
        $tab = $tabId > 0 ? new Tab($tabId) : new Tab();
        $tab->active = 1;
        $tab->class_name = self::ADMIN_PARENT_TAB_CLASS;
        $tab->id_parent = $improveTabId;
        $tab->icon = 'pets';
        $tab->module = '';
        foreach (Language::getLanguages(false) as $language) {
            $tab->name[(int) $language['id_lang']] = 'Cool Cat Ideas';
        }

        if (!($tabId > 0 ? (bool) $tab->update() : (bool) $tab->add())) {
            return 0;
        }

        return (int) $tab->id;
    }

    private function normalizeCciMenuPositions(int $parentId): bool
    {
        $tabs = Db::getInstance()->executeS(
            'SELECT `id_tab`, `class_name`, `position` FROM `' . _DB_PREFIX_ . 'tab` WHERE `id_parent` = ' . $parentId
        );
        if (!is_array($tabs)) {
            return false;
        }

        $priorities = [
            'AdminCciBlog' => 10,
            'AdminCciWithdrawal' => 20,
            self::ADMIN_TAB_CLASS => 25,
            'AdminCciNiceMenu' => 30,
            'AdminCciCategoryGrid' => 40,
            'AdminCciCourierCashOnDelivery' => 50,
        ];
        usort($tabs, static function (array $left, array $right) use ($priorities): int {
            $leftPriority = $priorities[$left['class_name']] ?? 1000 + (int) $left['position'];
            $rightPriority = $priorities[$right['class_name']] ?? 1000 + (int) $right['position'];

            return $leftPriority <=> $rightPriority ?: (int) $left['id_tab'] <=> (int) $right['id_tab'];
        });

        foreach ($tabs as $index => $tabData) {
            if (!Db::getInstance()->update('tab', ['position' => $index + 1], 'id_tab = ' . (int) $tabData['id_tab'])) {
                return false;
            }
        }

        return true;
    }

    private function uninstallAdminTab(): bool
    {
        $tabId = (int) Tab::getIdFromClassName(self::ADMIN_TAB_CLASS);
        if ($tabId > 0 && !(bool) (new Tab($tabId))->delete()) {
            return false;
        }

        $parentId = (int) Tab::getIdFromClassName(self::ADMIN_PARENT_TAB_CLASS);
        if ($parentId <= 0) {
            return true;
        }

        $remainingChildren = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tab` WHERE `id_parent` = ' . $parentId
        );

        return $remainingChildren > 0 || (bool) (new Tab($parentId))->delete();
    }

    private function getAdminPresentation(): CciConsentHistoryAdminPresentation
    {
        return $this->adminPresentation ??= new CciConsentHistoryAdminPresentation($this, $this->context, $this->getRepository());
    }
}
