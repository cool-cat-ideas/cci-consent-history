<?php

/**
 * Imports account GDPR consent definitions and acceptance history from GDPR - regulacje ochrony danych RODO by X13.
 *
 * @author    Cool Cat Ideas <info@coolcatideas.com>
 * @copyright 2026 Cool Cat Ideas
 * @license   https://opensource.org/license/afl-3-0-php Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use Module\CciConsentHistory\Import\BatchResult;
use Module\CciConsentHistory\Import\ImportAvailabilityInterface;
use Module\CciConsentHistory\Import\ImporterInterface;
use Module\CciConsentHistory\Import\ImporterRegistry;
use Module\CciConsentHistory\Import\ImporterSourceLabelsInterface;
use Module\CciConsentHistory\Import\ImporterStorageInterface;
use Module\CciConsentHistory\Import\ImportException;
use Module\CciConsentHistory\Import\ImportManager;

final class CciX13ConsentImporter implements ImporterInterface, ImportAvailabilityInterface, ImporterStorageInterface, ImporterSourceLabelsInterface
{
    private const SOURCE_TABLES = [
        'privacy_manager_agreement',
        'privacy_manager_agreement_lang',
        'privacy_manager_agreement_shop',
        'privacy_manager_agreement_history',
    ];

    public function __construct(
        private readonly CciConsentHistoryRepository $repository,
    ) {
    }

    public function installSchema(): bool
    {
        return (new CciX13ConsentStorage())->installSchema();
    }

    public function uninstallSchema(): bool
    {
        return (new CciX13ConsentStorage())->uninstallSchema();
    }

    public function getAcceptanceSources(): array
    {
        return ['x13_import' => 'Imported from X13 Privacy Manager'];
    }

    public function isAvailable(): bool
    {
        foreach (self::SOURCE_TABLES as $table) {
            if (!$this->tableExists(_DB_PREFIX_ . $table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{available:bool,definitions:int,history:int,accepted:int,already_imported:int}
     */
    public function getPreview(int $idShop): array
    {
        $alreadyImported = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cci_consent_acceptance`'
            . ' WHERE `id_shop` = ' . $idShop . " AND `acceptance_source` = 'x13_import'"
        );

        if (!$this->isAvailable()) {
            return [
                'available' => false,
                'definitions' => 0,
                'history' => 0,
                'accepted' => 0,
                'already_imported' => $alreadyImported,
            ];
        }

        $shopCondition = ' AND EXISTS (
            SELECT 1 FROM `' . _DB_PREFIX_ . 'privacy_manager_agreement_shop` source_shop
            WHERE source_shop.`id_privacy_manager_agreement` = agreement.`id_privacy_manager_agreement`
              AND source_shop.`id_shop` = ' . $idShop . '
        )';

        return [
            'available' => true,
            'definitions' => (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'privacy_manager_agreement` agreement'
                . ' WHERE agreement.`element` = 1' . $shopCondition
            ),
            'history' => (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'privacy_manager_agreement_history` history'
                . ' INNER JOIN `' . _DB_PREFIX_ . 'privacy_manager_agreement` agreement'
                . ' ON agreement.`id_privacy_manager_agreement` = history.`id_privacy_manager_agreement`'
                . ' WHERE agreement.`element` = 1' . $shopCondition
            ),
            'accepted' => (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'privacy_manager_agreement_history` history'
                . ' INNER JOIN `' . _DB_PREFIX_ . 'privacy_manager_agreement` agreement'
                . ' ON agreement.`id_privacy_manager_agreement` = history.`id_privacy_manager_agreement`'
                . ' WHERE agreement.`element` = 1 AND history.`status` = 1' . $shopCondition
            ),
            'already_imported' => $alreadyImported,
        ];
    }

    public function getId(): string
    {
        return 'x13';
    }

    public function getLabel(): string
    {
        return 'GDPR - regulacje ochrony danych RODO (X13)';
    }

    public function availability(array $context): array
    {
        if (!$this->isAvailable()) {
            return [
                'available' => false,
                'reason' => 'source_unavailable',
                'description' => $this->getDescription(),
                'notice' => 'The X13 source tables are missing. Import from this source is unavailable. Existing consent history remains available.',
            ];
        }
        if (!$this->readDefinitions((int) $context['shop_id'])) {
            return ['available' => false, 'reason' => 'definitions_missing', 'description' => $this->getDescription()];
        }

        return ['available' => true, 'reason' => null, 'description' => $this->getDescription()];
    }

    private function getDescription(): string
    {
        return 'Import account-consent wording and acceptance records. The old source tables must be in this database, with the original customer IDs.';
    }

    public function transactionalTables(array $context): array
    {
        $tables = ['cci_consent_version', 'cci_consent_acceptance', 'cci_consent_x13_definition', 'cci_consent_x13_audit'];
        if ($this->tableExists(_DB_PREFIX_ . 'psgdpr_log')) {
            $tables[] = 'psgdpr_log';
        }
        if ($context['apply_wording']) {
            $tables = array_merge($tables, ['configuration', 'configuration_lang']);
        }

        return array_map(static fn ($table) => _DB_PREFIX_ . $table, $tables);
    }

    public function plan(array $context): array
    {
        if (!$this->isAvailable()) {
            throw new ImportException('source_unavailable');
        }
        $definitions = $this->readDefinitions($context['shop_id']);
        if (!$definitions) {
            throw new ImportException('definitions_missing');
        }
        $agreements = array_values(array_unique(array_map(static fn ($row) => (int) $row['id_privacy_manager_agreement'], $definitions)));
        $where = '`id_privacy_manager_agreement` IN (' . implode(',', $agreements) . ')';
        $maximum = (int) Db::getInstance()->getValue('SELECT COALESCE(MAX(`id_privacy_manager_agreement_history`), 0) FROM `' . _DB_PREFIX_ . 'privacy_manager_agreement_history` WHERE ' . $where, false);
        $total = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'privacy_manager_agreement_history` WHERE ' . $where . ' AND `id_privacy_manager_agreement_history` <= ' . $maximum, false);

        return ['total' => $total, 'cursor' => ['prepared' => false, 'last_id' => 0],
            'source' => ['maximum' => $maximum, 'agreements' => $agreements, 'definitions' => $definitions]];
    }

    public function processBatch(array $context, array $source, array $cursor, int $limit): BatchResult
    {
        if (!$this->isAvailable()) {
            throw new ImportException('source_unavailable');
        }
        if (!$cursor['prepared']) {
            $cursor['versions'] = $this->archiveDefinitionsAndCreateVersions($source['definitions'], $context['shop_id']);
            $cursor['prepared'] = true;

            return new BatchResult($cursor, [], $source['maximum'] === 0);
        }
        $result = $this->importHistory($context['shop_id'], $context['language_id'], $cursor['versions'], $source, $cursor['last_id'], $limit);
        $cursor['last_id'] = $result['last_id'];
        unset($result['last_id']);

        // A short batch means the frozen source range has been exhausted, including gaps in IDs.
        return new BatchResult($cursor, $result, $result['processed'] < $limit);
    }

    public function finish(array $context, array $source, array $cursor, array $counts): void
    {
        if ($context['apply_wording']) {
            $this->applyActiveWording($source['definitions'], $context['shop_id']);
        }
    }

    /** Compatibility for existing command-line callers; every step has its own checkpoint. */
    public function import(int $idShop, int $defaultLangId, bool $applyWording): array
    {
        if (!$this->repository->installSchema() || !$this->installSchema()) {
            throw new ImportException('storage_write_failed');
        }
        $registry = new ImporterRegistry();
        $registry->register($this);
        $manager = new ImportManager($registry);
        $job = $manager->start('x13', $idShop, $defaultLangId, true, $applyWording);
        while ($job['can_continue']) {
            $job = $manager->step($job['job_id'], $idShop);
            if (!empty($job['locked'])) {
                throw new ImportException('import_busy');
            }
        }
        if ($job['status'] !== 'completed') {
            throw new ImportException($job['error_code'] ?? 'import_paused');
        }

        return ['definitions' => count($this->readDefinitions($idShop)), 'history' => $job['counts']['processed'],
            'added' => $job['counts']['added'], 'skipped' => $job['counts']['skipped'],
            'matched' => $job['counts']['added'] - $job['counts']['unmatched'],
            'unmatched' => $job['counts']['unmatched'], 'wording_applied' => $applyWording];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readDefinitions(int $idShop): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT agreement.*, agreement_lang.`id_lang`, agreement_lang.`name`,'
            . ' agreement_lang.`description`, agreement_lang.`url`'
            . ' FROM `' . _DB_PREFIX_ . 'privacy_manager_agreement` agreement'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'privacy_manager_agreement_lang` agreement_lang'
            . ' ON agreement_lang.`id_privacy_manager_agreement` = agreement.`id_privacy_manager_agreement`'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'privacy_manager_agreement_shop` agreement_shop'
            . ' ON agreement_shop.`id_privacy_manager_agreement` = agreement.`id_privacy_manager_agreement`'
            . ' AND agreement_shop.`id_shop` = ' . $idShop
            . ' WHERE agreement.`element` = 1'
            . ' ORDER BY agreement.`id_privacy_manager_agreement`, agreement_lang.`id_lang`'
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<int, array<string, mixed>> $definitions
     *
     * @return array<string, array<string, mixed>>
     */
    private function archiveDefinitionsAndCreateVersions(array $definitions, int $idShop): array
    {
        $versionMap = [];

        foreach ($definitions as $definition) {
            $this->validateDate((string) $definition['date_add']);
            $this->validateDate((string) $definition['date_upd']);
            $agreementId = (int) $definition['id_privacy_manager_agreement'];
            $idLang = (int) $definition['id_lang'];
            if (!Language::getLanguage($idLang)) {
                continue;
            }

            $written = Db::getInstance()->insert(
                'cci_consent_x13_definition',
                [
                    'id_x13_agreement' => $agreementId,
                    'id_lang' => $idLang,
                    'required' => (int) $definition['required'],
                    'guests' => (int) $definition['guests'],
                    'element' => (int) $definition['element'],
                    'default_on' => (int) $definition['default_on'],
                    'active' => (int) $definition['active'],
                    'name' => pSQL((string) $definition['name']),
                    'description' => pSQL((string) $definition['description'], true),
                    'url' => pSQL((string) $definition['url']),
                    'source_date_add' => pSQL((string) $definition['date_add']),
                    'source_date_upd' => pSQL((string) $definition['date_upd']),
                    'date_migrated' => date('Y-m-d H:i:s'),
                ],
                false,
                true,
                Db::ON_DUPLICATE_KEY
            );
            if (!$written) {
                throw new ImportException('storage_write_failed');
            }

            $version = $this->repository->ensureImportedConsentVersion(
                $idShop,
                $idLang,
                (string) $definition['description'],
                'x13privacymanager',
                (string) $definition['date_add']
            );
            if ($version === null) {
                throw new ImportException('wording_missing');
            }
            $versionMap[$agreementId . ':' . $idLang] = $version;
        }

        return $versionMap;
    }

    /**
     * @param array<string, array<string, mixed>> $versionMap
     *
     * @return array{history:int,added:int,skipped:int,matched:int,unmatched:int}
     */
    private function importHistory(int $idShop, int $defaultLangId, array $versionMap, array $source, int $lastId, int $limit): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT history.*, customer.`id_customer` AS `mapped_customer_id`, customer.`id_lang`,'
            . ' customer.`firstname`, customer.`lastname`, customer.`email`'
            . ' FROM `' . _DB_PREFIX_ . 'privacy_manager_agreement_history` history'
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'customer` customer'
            . ' ON customer.`id_customer` = history.`id_customer`'
            . ' WHERE history.`id_privacy_manager_agreement_history` > ' . $lastId
            . ' AND history.`id_privacy_manager_agreement_history` <= ' . (int) $source['maximum']
            . ' AND history.`id_privacy_manager_agreement` IN (' . implode(',', array_map('intval', $source['agreements'])) . ')'
            . ' ORDER BY history.`id_privacy_manager_agreement_history` LIMIT ' . $limit,
            true, false
        );
        if (!is_array($rows)) {
            throw new ImportException('source_read_failed');
        }
        $counts = array_fill_keys(BatchResult::COUNTERS, 0);
        $nativeModule = $this->tableExists(_DB_PREFIX_ . 'psgdpr_log') ? (int) Module::getModuleIdByName('psgdpr') : 0;

        foreach ($rows as $row) {
            $this->validateDate((string) $row['date_add']);
            $this->validateDate((string) $row['date_upd']);
            ++$counts['processed'];
            $historyId = (int) $row['id_privacy_manager_agreement_history'];
            $lastId = $historyId;
            $agreementId = (int) $row['id_privacy_manager_agreement'];
            $sourceCustomerId = (int) $row['id_customer'];
            $mappedCustomerId = (int) ($row['mapped_customer_id'] ?? 0);
            $idLang = (int) ($row['id_lang'] ?? 0);
            if ($idLang <= 0 || !isset($versionMap[$agreementId . ':' . $idLang])) {
                $idLang = $this->findVersionLanguage($agreementId, $defaultLangId, $versionMap);
            }
            $version = $versionMap[$agreementId . ':' . $idLang] ?? null;

            $written = Db::getInstance()->insert(
                'cci_consent_x13_audit',
                [
                    'id_x13_history' => $historyId,
                    'id_x13_agreement' => $agreementId,
                    'source_customer_id' => $sourceCustomerId,
                    'source_customer_email' => $mappedCustomerId > 0 ? pSQL((string) $row['email']) : ['type' => 'sql', 'value' => 'NULL'],
                    'source_guest_id' => (int) $row['id_guest'],
                    'source_cart_id' => (int) $row['id_cart'],
                    'source_ip' => pSQL(substr((string) $row['ip'], 0, 45)),
                    'source_status' => (int) $row['status'],
                    'source_date_add' => pSQL((string) $row['date_add']),
                    'source_date_upd' => pSQL((string) $row['date_upd']),
                    'mapped_customer_id' => $mappedCustomerId > 0 ? $mappedCustomerId : ['type' => 'sql', 'value' => 'NULL'],
                    'mapping_method' => $mappedCustomerId > 0 ? 'same_customer_id' : 'not_found',
                    'date_migrated' => date('Y-m-d H:i:s'),
                ],
                false,
                true,
                Db::ON_DUPLICATE_KEY
            );
            if (!$written) {
                throw new ImportException('storage_write_failed');
            }

            if ((int) $row['status'] !== 1) {
                ++$counts['rejected'];
                continue;
            }
            if ($version === null) {
                throw new ImportException('wording_missing');
            }

            $sourceReference = 'x13_shop:' . $idShop . ':history:' . $historyId;
            $exists = (bool) Db::getInstance()->getValue(
                'SELECT 1 FROM `' . _DB_PREFIX_ . 'cci_consent_acceptance`'
                . " WHERE `acceptance_source` = 'x13_import'"
                . ' AND `id_shop` = ' . $idShop
                . " AND `source_reference` IN ('" . pSQL($sourceReference) . "', 'x13_history:" . $historyId . "')", false
            );
            if ($exists) {
                ++$counts['skipped'];
                continue;
            }

            $inserted = Db::getInstance()->insert('cci_consent_acceptance', [
                'id_cci_consent_version' => (int) $version['id_cci_consent_version'],
                'id_customer' => $mappedCustomerId,
                'id_shop' => $idShop,
                'id_lang' => $idLang,
                'purpose_key' => pSQL(CciConsentHistoryRepository::PURPOSE_ACCOUNT_PRIVACY),
                'acceptance_source' => 'x13_import',
                'source_reference' => pSQL($sourceReference),
                'source_agreement_id' => $agreementId,
                'source_customer_id' => $sourceCustomerId,
                'source_guest_id' => (int) $row['id_guest'] ?: null,
                'source_cart_id' => (int) $row['id_cart'] ?: null,
                'source_ip' => pSQL(substr((string) $row['ip'], 0, 45)),
                'date_add' => pSQL((string) $row['date_add']),
            ], true);
            if (!$inserted) {
                throw new RuntimeException('An X13 consent acceptance could not be saved.');
            }

            ++$counts['added'];
            if ($mappedCustomerId > 0) {
                $this->insertPsgdprLog($row, $mappedCustomerId, $nativeModule);
            } else {
                ++$counts['unmatched'];
            }
        }

        return $counts + ['last_id' => $lastId];
    }

    /**
     * @param array<int, array<string, mixed>> $definitions
     */
    private function applyActiveWording(array $definitions, int $idShop): void
    {
        $values = [];
        foreach (Language::getLanguages(false) as $language) {
            $idLang = (int) $language['id_lang'];
            $values[$idLang] = (string) Configuration::get(
                'PSGDPR_CREATION_FORM',
                $idLang,
                null,
                $idShop
            );
        }

        foreach ($definitions as $definition) {
            if ((int) $definition['active'] !== 1 || (int) $definition['required'] !== 1) {
                continue;
            }

            $idLang = (int) $definition['id_lang'];
            if (array_key_exists($idLang, $values)) {
                $values[$idLang] = (string) $definition['description'];
            }
        }

        if (!array_filter($values, static fn (string $value): bool => trim($value) !== '')) {
            throw new RuntimeException('The active X13 account consent has no wording to apply.');
        }

        if (!Configuration::updateValue('PSGDPR_CREATION_FORM', $values, true, null, $idShop)
            || !Configuration::updateValue('PSGDPR_CREATION_FORM_SWITCH', 1, false, null, $idShop)
        ) {
            throw new RuntimeException('The X13 wording could not be applied to Official GDPR Compliance.');
        }
    }

    /**
     * @param array<string, array<string, mixed>> $versionMap
     */
    private function findVersionLanguage(int $agreementId, int $preferredLangId, array $versionMap): int
    {
        if (isset($versionMap[$agreementId . ':' . $preferredLangId])) {
            return $preferredLangId;
        }

        $prefix = $agreementId . ':';
        foreach (array_keys($versionMap) as $key) {
            if (str_starts_with($key, $prefix)) {
                return (int) substr($key, strlen($prefix));
            }
        }

        return $preferredLangId;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insertPsgdprLog(array $row, int $idCustomer, int $idModule): void
    {
        if ($idModule <= 0) {
            return;
        }

        $dateAdd = (string) $row['date_add'];
        $exists = (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'psgdpr_log`'
            . ' WHERE `id_customer` = ' . $idCustomer
            . ' AND `id_module` = ' . $idModule
            . ' AND `request_type` = 1'
            . " AND `date_add` = '" . pSQL($dateAdd) . "'", false
        );
        if ($exists) {
            return;
        }

        $clientName = trim((string) $row['firstname'] . ' ' . (string) $row['lastname']);
        if ($clientName === '') {
            $clientName = (string) $row['email'];
        }

        $written = Db::getInstance()->insert('psgdpr_log', [
            'id_customer' => $idCustomer,
            'id_guest' => (int) $row['id_guest'] ?: null,
            'client_name' => pSQL($clientName),
            'id_module' => $idModule,
            'request_type' => 1,
            'date_add' => pSQL($dateAdd),
            'date_upd' => pSQL((string) $row['date_upd']),
        ], true);
        if (!$written) {
            throw new ImportException('storage_write_failed');
        }
    }

    private function validateDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date);
        if (!$parsed || $parsed->format('Y-m-d H:i:s') !== $date || $date < '1000-01-01 00:00:00') {
            throw new ImportException('invalid_source_date');
        }
    }

    private function tableExists(string $table): bool
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM information_schema.tables'
            . " WHERE table_schema = DATABASE() AND table_name = '" . pSQL($table) . "'"
        );
    }
}
