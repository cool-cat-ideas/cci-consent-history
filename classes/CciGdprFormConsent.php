<?php

/**
 * Snapshot and record consent checkboxes rendered by Official GDPR Compliance.
 *
 * @license https://opensource.org/license/afl-3-0-php Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

final class CciGdprFormConsent
{
    public function __construct(
        private readonly CciConsentHistoryRepository $repository,
        private readonly Context $context,
    ) {
    }

    /**
     * @return array{module_id:int,snapshot:string,native_url:string,record_url:string}|null
     */
    public function createSnapshot(int $idModule): ?array
    {
        $source = $this->getSource($idModule);
        if ($source === null) {
            return null;
        }

        $message = (string) GDPRConsent::getConsentMessage($idModule, (int) $this->context->language->id);
        if (trim($message) === '') {
            return null;
        }

        $version = $this->repository->ensureImportedConsentVersion(
            (int) $this->context->shop->id,
            (int) $this->context->language->id,
            $message,
            $source->name,
            date('Y-m-d H:i:s'),
            'gdpr_form_' . $source->name
        );
        if ($version === null) {
            return null;
        }

        if (!$this->context->customer->isLogged()) {
            $visitor = (string) ($this->context->cookie->cci_consent_history_visitor ?? '');
            if (!preg_match('/^[a-f0-9]{32}$/D', $visitor)) {
                $this->context->cookie->cci_consent_history_visitor = bin2hex(random_bytes(16));
            }
        }

        $idVersion = (int) $version['id_cci_consent_version'];
        $nonce = bin2hex(random_bytes(16));

        return [
            'module_id' => $idModule,
            'snapshot' => $idVersion . '.' . $nonce . '.' . $this->signature($idModule, $idVersion, $nonce),
            'native_url' => $this->context->link->getModuleLink('psgdpr', 'FrontAjaxGdpr', [], true),
            'record_url' => $this->context->link->getModuleLink('cci_consent_history', 'record', [], true),
        ];
    }

    /**
     * The standard GDPR event confirms a form submission, not delivery of its email
     * or completion of a newsletter subscription. Preserve that distinction.
     */
    public function record(int $idModule, string $snapshot): bool
    {
        $source = $this->getSource($idModule);
        if ($source === null || !$this->hasValidNativeIdentity()) {
            return false;
        }
        if (!preg_match('/^([1-9][0-9]*)\.([a-f0-9]{32})\.([a-f0-9]{64})$/D', $snapshot, $matches)) {
            return false;
        }

        $idVersion = (int) $matches[1];
        if (!hash_equals($this->signature($idModule, $idVersion, $matches[2]), $matches[3])) {
            return false;
        }

        $version = $this->repository->getGdprFormVersion(
            $idVersion,
            (int) $this->context->shop->id,
            (int) $this->context->language->id,
            $source->name
        );
        if ($version === null) {
            return false;
        }

        $idCustomer = $this->context->customer->isLogged() ? (int) $this->context->customer->id : 0;
        $idGuest = $idCustomer > 0 ? 0 : $this->getGuestId();
        if ($this->repository->hasGdprFormAcceptance($version, $idCustomer, $idGuest, $matches[2])) {
            return true;
        }

        $startedAt = date('Y-m-d H:i:s');
        if (!GDPRLog::addLog($idCustomer, 'consent', $idModule, $idGuest)) {
            return false;
        }

        // Resolve the event saved by the native API, including its native timestamp.
        $log = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'psgdpr_log`'
            . ' WHERE `id_customer` = ' . $idCustomer . ' AND `id_guest` = ' . $idGuest
            . ' AND `id_module` = ' . $idModule . ' AND `request_type` = 1'
            . " AND `date_add` >= '" . pSQL($startedAt) . "'"
            . ' ORDER BY `id_gdpr_log` DESC'
        );

        return is_array($log) && $log !== [] && $this->repository->recordGdprFormAcceptance($version, $log, $matches[2]);
    }

    private function getSource(int $idModule): ?Module
    {
        if ($idModule <= 0 || !Module::isEnabled('psgdpr')) {
            return null;
        }

        // Loading the module also loads its native GDPRConsent and GDPRLog APIs.
        if (!Module::getInstanceByName('psgdpr')) {
            return null;
        }
        $module = Module::getInstanceById($idModule);
        if (
            !$module instanceof Module
            || !Module::isEnabled($module->name)
            || !Hook::isModuleRegisteredOnHook($module, 'registerGDPRConsent', (int) $this->context->shop->id)
            || !GDPRConsent::getConsentActive($idModule)
        ) {
            return null;
        }

        return $module;
    }

    private function hasValidNativeIdentity(): bool
    {
        $idCustomer = (int) Tools::getValue('id_customer');
        if ($this->context->customer->isLogged()) {
            $token = Tools::getValue('customer_token');

            return $idCustomer === (int) $this->context->customer->id
                && is_string($token)
                && hash_equals(sha1($this->context->customer->secure_key), $token);
        }

        $nativeGuest = (int) $this->context->cart->id_guest;
        $token = Tools::getValue('guest_token');
        $expected = sha1('psgdpr' . $nativeGuest . ($_SERVER['REMOTE_ADDR'] ?? '') . date('Y-m-d'));

        $visitor = (string) ($this->context->cookie->cci_consent_history_visitor ?? '');

        return $idCustomer === 0
            && (bool) preg_match('/^[a-f0-9]{32}$/D', $visitor)
            && (int) Tools::getValue('id_guest') === $nativeGuest
            && is_string($token)
            && hash_equals($expected, $token);
    }

    private function getGuestId(): int
    {
        // A browsing visitor can have a native guest cookie before a cart is saved.
        return (int) ($this->context->cart->id_guest ?: $this->context->cookie->id_guest);
    }

    private function signature(int $idModule, int $idVersion, string $nonce): string
    {
        $payload = implode('|', [
            'gdpr_form',
            (int) $this->context->shop->id,
            (int) $this->context->language->id,
            $this->context->customer->isLogged() ? (int) $this->context->customer->id : 0,
            $this->context->customer->isLogged() ? 0 : $this->getGuestId(),
            $this->context->customer->isLogged() ? '' : (string) ($this->context->cookie->cci_consent_history_visitor ?? ''),
            $idModule,
            $idVersion,
            $nonce,
        ]);

        return hash_hmac('sha256', $payload, _COOKIE_KEY_);
    }
}
