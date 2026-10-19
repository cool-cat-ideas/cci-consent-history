<?php

/**
 * Records the native customer form's optional choices and privacy acknowledgement.
 *
 * @license https://opensource.org/license/afl-3-0-php Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

final class CciCustomerConsentRecorder
{
    /** @var array<int, array<string, mixed>> */
    private array $beforeUpdate = [];

    public function __construct(
        private readonly CciConsentHistoryRepository $repository,
        private readonly Context $context,
        private readonly CciCustomerConsentSourceRegistry $registry,
    ) {
    }

    public function decorateField(array &$field): void
    {
        $name = (string) ($field['name'] ?? '');
        $source = $this->registry->get($name);
        if (
            !$this->isCustomerForm()
            || ($field['type'] ?? '') !== 'checkbox'
            || $source === null
            || !$this->isSourceEnabled($name)
        ) {
            return;
        }

        $wording = (string) ($field['label'] ?? '');
        if (trim(strip_tags($wording)) === '') {
            return;
        }
        $version = $this->repository->ensureImportedConsentVersion(
            (int) $this->context->shop->id,
            (int) $this->context->language->id,
            $wording,
            $source['module'],
            date('Y-m-d H:i:s'),
            $source['purpose']
        );
        if ($version === null) {
            return;
        }

        $idCustomer = $this->isProfile() ? (int) $this->context->customer->id : 0;
        if ($idCustomer === 0) {
            $visitor = (string) ($this->context->cookie->cci_consent_history_visitor ?? '');
            if (!preg_match('/^[a-f0-9]{32}$/D', $visitor)) {
                $this->context->cookie->cci_consent_history_visitor = bin2hex(random_bytes(16));
            }
        }
        $idVersion = (int) $version['id_cci_consent_version'];
        $nonce = bin2hex(random_bytes(16));
        $signature = $this->signature($name, $idVersion, $idCustomer, $nonce);
        $token = $idVersion . '.' . $idCustomer . '.' . $nonce . '.' . $signature;
        // Hidden inputs inside a native checkbox label remain inside the same form
        // in both Classic and Hummingbird; no template override or JavaScript is needed.
        $field['label'] = $wording . '<input type="hidden" name="cci_customer_consent[' . $name . ']" value="'
            . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    public function captureBeforeUpdate(Customer $customer): void
    {
        if (!$this->isProfile() || (int) $customer->id !== (int) $this->context->customer->id) {
            return;
        }
        $states = [];
        foreach ($this->registry->all() as $field => $source) {
            if ($source['read_state'] !== null) {
                $value = ($source['read_state'])((int) $customer->id, $this->context);
                if (is_bool($value)) {
                    $states[$field] = $value;
                }
            }
        }
        $this->beforeUpdate[(int) $customer->id] = $states;
    }

    public function recordSubmission(Customer $customer, bool $creation = false): void
    {
        if (
            !$this->isCustomerForm()
            || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST'
            || !Validate::isLoadedObject($customer)
            || (int) $customer->id !== (int) $this->context->customer->id
            || ($creation && $this->isProfile())
            || (!$creation && !$this->isProfile())
        ) {
            return;
        }
        $snapshots = Tools::getValue('cci_customer_consent');
        if (!is_array($snapshots)) {
            return;
        }
        $idCustomer = (int) $customer->id;
        $before = $creation ? [] : ($this->beforeUpdate[$idCustomer] ?? []);
        foreach ($this->registry->all() as $field => $source) {
            if (!$this->isSourceEnabled($field) || !is_string($snapshots[$field] ?? null)) {
                continue;
            }
            $token = $snapshots[$field];
            if (!preg_match('/^([1-9][0-9]*)\.([0-9]+)\.([a-f0-9]{32})\.([a-f0-9]{64})$/D', $token, $matches)) {
                continue;
            }
            $idVersion = (int) $matches[1];
            $boundCustomer = $creation ? 0 : $idCustomer;
            if (
                (int) $matches[2] !== $boundCustomer
                || !hash_equals($this->signature($field, $idVersion, $boundCustomer, $matches[3]), $matches[4])
            ) {
                continue;
            }
            $version = $this->repository->getCustomerFormVersion(
                $idVersion,
                (int) $this->context->shop->id,
                (int) $this->context->language->id,
                $source['purpose'],
                $source['module']
            );
            if ($version === null) {
                continue;
            }
            $checked = in_array(Tools::getValue($field), [1, '1'], true);
            $reference = ($creation ? 'registration:' : 'profile:') . $matches[3];
            if ($source['kind'] === 'acknowledgement') {
                if ($checked) {
                    $this->repository->recordAcceptance($version, $idCustomer, 'customer_field_accepted', $reference);
                }
                continue;
            }
            if ($source['read_state'] !== null) {
                $saved = ($source['read_state'])($idCustomer, $this->context);
                if (!is_bool($saved) || $saved !== $checked) {
                    continue;
                }
            }
            $last = $this->repository->getLatestCustomerConsent($idCustomer, (int) $this->context->shop->id, $source['purpose']);
            $prefix = 'customer_choice';
            if ($checked) {
                if (
                    $last !== null
                    && $last['acceptance_source'] === $prefix . '_granted'
                    && (int) $last['id_cci_consent_version'] === $idVersion
                ) {
                    continue;
                }
                $this->repository->recordAcceptance($version, $idCustomer, $prefix . '_granted', $reference);
            } elseif (
                (bool) ($before[$field] ?? false)
                || ($last !== null && $last['acceptance_source'] === $prefix . '_granted')
            ) {
                if ($last !== null && $last['acceptance_source'] === $prefix . '_withdrawn') {
                    continue;
                }
                // A withdrawal refers to the previous granted wording when it is known.
                $withdrawnVersion = $last !== null && $last['acceptance_source'] === $prefix . '_granted' ? $last : $version;
                $this->repository->recordAcceptance($withdrawnVersion, $idCustomer, $prefix . '_withdrawn', $reference);
            }
        }
    }

    private function isCustomerForm(): bool
    {
        return $this->context->controller instanceof FrontController
            && in_array($this->context->controller->php_self, ['identity', 'registration', 'authentication', 'order'], true);
    }

    private function isProfile(): bool
    {
        return $this->context->controller instanceof FrontController
            && $this->context->controller->php_self === 'identity'
            && (int) $this->context->customer->id > 0
            && (bool) $this->context->customer->logged
            && !(bool) $this->context->customer->is_guest;
    }

    private function isSourceEnabled(string $field): bool
    {
        $module = $this->registry->get($field)['module'];

        return $module === 'prestashop' || Module::isEnabled($module);
    }

    private function signature(string $field, int $version, int $customer, string $nonce): string
    {
        $payload = implode('|', [
            'customer_form', $field, $version, $customer,
            $this->registry->get($field)['purpose'],
            $this->registry->get($field)['module'],
            $this->registry->get($field)['kind'],
            (int) $this->context->shop->id, (int) $this->context->language->id,
            $customer > 0 ? '' : (string) ($this->context->cookie->cci_consent_history_visitor ?? ''),
            $nonce,
        ]);

        return hash_hmac('sha256', $payload, _COOKIE_KEY_);
    }
}
