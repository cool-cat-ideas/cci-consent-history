<?php

/**
 * Immutable consent versions and customer acceptance records.
 *
 * @author    Cool Cat Ideas <info@coolcatideas.com>
 * @copyright 2026 Cool Cat Ideas
 * @license   https://opensource.org/license/afl-3-0-php Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

final class CciConsentHistoryRepository
{
    public const PURPOSE_ACCOUNT_PRIVACY = 'account_privacy';
    public const PURPOSE_PROFILE_PRIVACY = 'profile_privacy';

    public function installSchema(): bool
    {
        $engine = _MYSQL_ENGINE_;
        $queries = [
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'cci_consent_version` (
                `id_cci_consent_version` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL,
                `id_lang` INT UNSIGNED NOT NULL,
                `purpose_key` VARCHAR(64) NOT NULL,
                `source_module` VARCHAR(64) NOT NULL,
                `version_number` INT UNSIGNED NOT NULL,
                `content_hash` CHAR(64) NOT NULL,
                `content_html` MEDIUMTEXT NOT NULL,
                `content_text` MEDIUMTEXT NOT NULL,
                `is_baseline` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_cci_consent_version`),
                UNIQUE KEY `uniq_cci_consent_context_hash` (`id_shop`, `id_lang`, `purpose_key`, `content_hash`),
                UNIQUE KEY `uniq_cci_consent_context_version` (`id_shop`, `id_lang`, `purpose_key`, `version_number`),
                KEY `idx_cci_consent_current` (`id_shop`, `id_lang`, `purpose_key`, `version_number`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'cci_consent_acceptance` (
                `id_cci_consent_acceptance` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_cci_consent_version` INT UNSIGNED NOT NULL,
                `id_customer` INT UNSIGNED NOT NULL,
                `id_shop` INT UNSIGNED NOT NULL,
                `id_lang` INT UNSIGNED NOT NULL,
                `purpose_key` VARCHAR(64) NOT NULL,
                `acceptance_source` VARCHAR(32) NOT NULL,
                `source_reference` VARCHAR(96) DEFAULT NULL,
                `source_agreement_id` INT UNSIGNED DEFAULT NULL,
                `source_customer_id` INT UNSIGNED DEFAULT NULL,
                `source_guest_id` INT UNSIGNED DEFAULT NULL,
                `source_cart_id` INT UNSIGNED DEFAULT NULL,
                `source_ip` VARCHAR(45) DEFAULT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_cci_consent_acceptance`),
                UNIQUE KEY `uniq_cci_consent_source_reference` (`acceptance_source`, `source_reference`),
                KEY `idx_cci_consent_customer_version` (`id_cci_consent_version`, `id_customer`),
                KEY `idx_cci_consent_customer` (`id_customer`, `purpose_key`, `date_add`),
                KEY `idx_cci_consent_shop` (`id_shop`, `date_add`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'cci_consent_import_job` (
                `id_job` CHAR(32) NOT NULL,
                `active_key` VARCHAR(80) DEFAULT NULL,
                `importer` VARCHAR(64) NOT NULL,
                `id_shop` INT UNSIGNED NOT NULL,
                `id_employee` INT UNSIGNED DEFAULT NULL,
                `status` VARCHAR(16) NOT NULL,
                `phase` VARCHAR(16) NOT NULL,
                `total` BIGINT UNSIGNED NOT NULL,
                `context_json` LONGTEXT NOT NULL,
                `source_json` LONGTEXT NOT NULL,
                `cursor_json` LONGTEXT NOT NULL,
                `counts_json` LONGTEXT NOT NULL,
                `failed_batches` INT UNSIGNED NOT NULL DEFAULT 0,
                `error_code` VARCHAR(64) DEFAULT NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_job`),
                UNIQUE KEY `uniq_cci_import_active` (`active_key`),
                KEY `idx_cci_import_shop` (`id_shop`, `date_add`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'cci_consent_import_run` (
                `id_cci_consent_import_run` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `importer` VARCHAR(32) NOT NULL,
                `definitions_found` INT UNSIGNED NOT NULL DEFAULT 0,
                `history_found` INT UNSIGNED NOT NULL DEFAULT 0,
                `acceptances_added` INT UNSIGNED NOT NULL DEFAULT 0,
                `acceptances_skipped` INT UNSIGNED NOT NULL DEFAULT 0,
                `customers_matched` INT UNSIGNED NOT NULL DEFAULT 0,
                `customers_unmatched` INT UNSIGNED NOT NULL DEFAULT 0,
                `wording_applied` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_cci_consent_import_run`),
                KEY `idx_cci_consent_importer_date` (`importer`, `date_add`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];

        foreach ($queries as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return $this->upgradeAcceptanceSchema();
    }

    private function upgradeAcceptanceSchema(): bool
    {
        $table = _DB_PREFIX_ . 'cci_consent_acceptance';
        $columns = [
            'source_reference' => 'VARCHAR(96) DEFAULT NULL AFTER `acceptance_source`',
            'source_agreement_id' => 'INT UNSIGNED DEFAULT NULL AFTER `source_reference`',
            'source_customer_id' => 'INT UNSIGNED DEFAULT NULL AFTER `source_agreement_id`',
            'source_guest_id' => 'INT UNSIGNED DEFAULT NULL AFTER `source_customer_id`',
            'source_cart_id' => 'INT UNSIGNED DEFAULT NULL AFTER `source_guest_id`',
            'source_ip' => 'VARCHAR(45) DEFAULT NULL AFTER `source_cart_id`',
        ];

        foreach ($columns as $column => $definition) {
            if (!$this->columnExists($table, $column)
                && !Db::getInstance()->execute(
                    'ALTER TABLE `' . bqSQL($table) . '` ADD `' . bqSQL($column) . '` ' . $definition
                )
            ) {
                return false;
            }
        }

        if ($this->indexExists($table, 'uniq_cci_consent_customer_version')
            && !Db::getInstance()->execute(
                'ALTER TABLE `' . bqSQL($table) . '` DROP INDEX `uniq_cci_consent_customer_version`'
            )
        ) {
            return false;
        }

        if (!$this->indexExists($table, 'idx_cci_consent_customer_version')
            && !Db::getInstance()->execute(
                'ALTER TABLE `' . bqSQL($table)
                . '` ADD KEY `idx_cci_consent_customer_version` (`id_cci_consent_version`, `id_customer`)'
            )
        ) {
            return false;
        }

        if (!$this->indexExists($table, 'uniq_cci_consent_source_reference')
            && !Db::getInstance()->execute(
                'ALTER TABLE `' . bqSQL($table)
                . '` ADD UNIQUE KEY `uniq_cci_consent_source_reference` (`acceptance_source`, `source_reference`)'
            )
        ) {
            return false;
        }

        return true;
    }

    private function columnExists(string $table, string $column): bool
    {
        $rows = Db::getInstance()->executeS(
            'SHOW COLUMNS FROM `' . bqSQL($table) . "` LIKE '" . pSQL($column) . "'"
        );

        return is_array($rows) && $rows !== [];
    }

    private function indexExists(string $table, string $index): bool
    {
        $rows = Db::getInstance()->executeS(
            'SHOW INDEX FROM `' . bqSQL($table) . "` WHERE `Key_name` = '" . pSQL($index) . "'"
        );

        return is_array($rows) && $rows !== [];
    }

    public function uninstallSchema(): bool
    {
        return Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'cci_consent_import_job`, `'
            . _DB_PREFIX_ . 'cci_consent_import_run`, `'
            . _DB_PREFIX_ . 'cci_consent_acceptance`, `'
            . _DB_PREFIX_ . 'cci_consent_version`'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function ensureCurrentAccountConsentVersion(int $idShop, int $idLang, bool $baseline = false): ?array
    {
        return $this->ensureConfiguredConsentVersion(
            $idShop,
            $idLang,
            self::PURPOSE_ACCOUNT_PRIVACY,
            'PSGDPR_CREATION_FORM',
            $baseline
        );
    }

    /**
     * The profile notice can differ from the notice used at account creation.
     * Its versions must never trigger the account-access renewal prompt.
     *
     * @return array<string, mixed>|null
     */
    public function ensureCurrentProfileConsentVersion(int $idShop, int $idLang): ?array
    {
        return $this->ensureConfiguredConsentVersion(
            $idShop,
            $idLang,
            self::PURPOSE_PROFILE_PRIVACY,
            'PSGDPR_CUSTOMER_FORM'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function ensureConfiguredConsentVersion(
        int $idShop,
        int $idLang,
        string $purposeKey,
        string $configurationKey,
        bool $baseline = false,
    ): ?array {
        $snapshot = $this->readConfiguredConsent($idShop, $idLang, $configurationKey);
        if ($snapshot === null) {
            return null;
        }

        $existing = $this->findVersionByHash(
            $idShop,
            $idLang,
            $purposeKey,
            $snapshot['content_hash']
        );
        if ($existing !== null) {
            return $existing;
        }

        $versionNumber = 1 + (int) Db::getInstance()->getValue(
            'SELECT COALESCE(MAX(`version_number`), 0) FROM `' . _DB_PREFIX_ . 'cci_consent_version`'
            . ' WHERE `id_shop` = ' . $idShop
            . ' AND `id_lang` = ' . $idLang
            . " AND `purpose_key` = '" . pSQL($purposeKey) . "'"
        );

        Db::getInstance()->insert(
            'cci_consent_version',
            [
                'id_shop' => $idShop,
                'id_lang' => $idLang,
                'purpose_key' => pSQL($purposeKey),
                'source_module' => 'psgdpr',
                'version_number' => $versionNumber,
                'content_hash' => pSQL($snapshot['content_hash']),
                'content_html' => pSQL($snapshot['content_html'], true),
                'content_text' => pSQL($snapshot['content_text'], true),
                'is_baseline' => ($baseline || $versionNumber === 1) ? 1 : 0,
                'date_add' => date('Y-m-d H:i:s'),
            ],
            false,
            true,
            Db::INSERT_IGNORE
        );

        return $this->findVersionByHash(
            $idShop,
            $idLang,
            $purposeKey,
            $snapshot['content_hash']
        );
    }

    /**
     * @return array{content_html:string,content_text:string,content_hash:string}|null
     */
    private function readConfiguredConsent(int $idShop, int $idLang, string $configurationKey): ?array
    {
        if (!Module::isInstalled('psgdpr') || !Module::isEnabled('psgdpr')) {
            return null;
        }

        $active = (bool) Configuration::get($configurationKey . '_SWITCH', null, null, $idShop);
        $content = (string) Configuration::get($configurationKey, $idLang, null, $idShop);
        $content = $this->normalizeConsentHtml($content);

        if (!$active || $content === '') {
            return null;
        }

        $contentText = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($content), ENT_QUOTES, 'UTF-8')) ?? '');

        return [
            'content_html' => $content,
            'content_text' => $contentText,
            'content_hash' => hash('sha256', $content),
        ];
    }

    private function normalizeConsentHtml(string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = preg_replace('/[\t ]+\n/u', "\n", $content) ?? $content;

        return trim($content);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function ensureImportedConsentVersion(
        int $idShop,
        int $idLang,
        string $contentHtml,
        string $sourceModule,
        string $dateAdd,
        string $purposeKey = self::PURPOSE_ACCOUNT_PRIVACY,
    ): ?array {
        if ($idShop <= 0 || $idLang <= 0 || !preg_match('/^[a-z0-9_]{1,64}$/D', $purposeKey)) {
            return null;
        }

        $contentHtml = $this->normalizeConsentHtml($contentHtml);
        if ($contentHtml === '') {
            return null;
        }

        $contentHash = hash('sha256', $contentHtml);
        $existing = $this->findVersionByHash(
            $idShop,
            $idLang,
            $purposeKey,
            $contentHash
        );
        if ($existing !== null) {
            return $existing;
        }

        $versionNumber = 1 + (int) Db::getInstance()->getValue(
            'SELECT COALESCE(MAX(`version_number`), 0) FROM `' . _DB_PREFIX_ . 'cci_consent_version`'
            . ' WHERE `id_shop` = ' . $idShop
            . ' AND `id_lang` = ' . $idLang
            . " AND `purpose_key` = '" . pSQL($purposeKey) . "'"
        );
        $spacedHtml = preg_replace('/<(?:br\s*\/?|\/p|\/div|\/li)>/i', ' ', $contentHtml) ?? $contentHtml;
        $contentText = trim(
            preg_replace(
                '/\s+/u',
                ' ',
                html_entity_decode(strip_tags($spacedHtml), ENT_QUOTES, 'UTF-8')
            ) ?? ''
        );

        Db::getInstance()->insert(
            'cci_consent_version',
            [
                'id_shop' => $idShop,
                'id_lang' => $idLang,
                'purpose_key' => pSQL($purposeKey),
                'source_module' => pSQL($sourceModule),
                'version_number' => $versionNumber,
                'content_hash' => pSQL($contentHash),
                'content_html' => pSQL($contentHtml, true),
                'content_text' => pSQL($contentText, true),
                'is_baseline' => $versionNumber === 1 ? 1 : 0,
                'date_add' => pSQL($dateAdd !== '' ? $dateAdd : date('Y-m-d H:i:s')),
            ],
            false,
            true,
            Db::INSERT_IGNORE
        );

        return $this->findVersionByHash(
            $idShop,
            $idLang,
            $purposeKey,
            $contentHash
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findVersionByHash(
        int $idShop,
        int $idLang,
        string $purposeKey,
        string $contentHash,
    ): ?array {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'cci_consent_version`'
            . ' WHERE `id_shop` = ' . $idShop
            . ' AND `id_lang` = ' . $idLang
            . " AND `purpose_key` = '" . pSQL($purposeKey) . "'"
            . " AND `content_hash` = '" . pSQL($contentHash) . "'"
        );

        return is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * Resolve a signed profile snapshot within its original shop and language.
     *
     * @return array<string, mixed>|null
     */
    public function getProfileConsentVersion(int $idVersion, int $idShop, int $idLang): ?array
    {
        if ($idVersion <= 0 || $idShop <= 0 || $idLang <= 0) {
            return null;
        }

        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'cci_consent_version`'
            . ' WHERE `id_cci_consent_version` = ' . $idVersion
            . ' AND `id_shop` = ' . $idShop . ' AND `id_lang` = ' . $idLang
            . " AND `purpose_key` = '" . pSQL(self::PURPOSE_PROFILE_PRIVACY) . "'"
            . " AND `source_module` = 'psgdpr'"
        );

        return is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getGdprFormVersion(int $idVersion, int $idShop, int $idLang, string $sourceModule): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'cci_consent_version`'
            . ' WHERE `id_cci_consent_version` = ' . $idVersion
            . ' AND `id_shop` = ' . $idShop . ' AND `id_lang` = ' . $idLang
            . " AND `purpose_key` = '" . pSQL('gdpr_form_' . $sourceModule) . "'"
            . " AND `source_module` = '" . pSQL($sourceModule) . "'"
        );

        return is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * @param array<string, mixed> $version
     */
    public function hasGdprFormAcceptance(array $version, int $idCustomer, int $idGuest, string $nonce): bool
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'cci_consent_acceptance`'
            . " WHERE `acceptance_source` = 'gdpr_form'"
            . " AND `source_reference` = '" . pSQL('psgdpr-form:' . $nonce) . "'"
            . ' AND `id_cci_consent_version` = ' . (int) $version['id_cci_consent_version']
            . ' AND `id_shop` = ' . (int) $version['id_shop']
            . ' AND `id_customer` = ' . $idCustomer . ' AND `source_guest_id` = ' . $idGuest
        );
    }

    /**
     * @param array<string, mixed> $version
     * @param array<string, mixed> $nativeLog
     */
    public function recordGdprFormAcceptance(array $version, array $nativeLog, string $nonce): bool
    {
        return Db::getInstance()->insert(
            'cci_consent_acceptance',
            [
                'id_cci_consent_version' => (int) $version['id_cci_consent_version'],
                'id_customer' => (int) $nativeLog['id_customer'],
                'id_shop' => (int) $version['id_shop'],
                'id_lang' => (int) $version['id_lang'],
                'purpose_key' => pSQL((string) $version['purpose_key']),
                'acceptance_source' => 'gdpr_form',
                'source_reference' => pSQL('psgdpr-form:' . $nonce),
                'source_guest_id' => (int) $nativeLog['id_guest'],
                'date_add' => pSQL((string) $nativeLog['date_add']),
            ],
            false,
            true,
            Db::INSERT_IGNORE
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getCustomerPreferences(int $idCustomer): array
    {
        if ($idCustomer <= 0) {
            return [];
        }

        return Db::getInstance()->getRow(
            'SELECT `newsletter`, `optin` FROM `' . _DB_PREFIX_ . 'customer` WHERE `id_customer` = ' . $idCustomer
        ) ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCustomerFormVersion(int $idVersion, int $idShop, int $idLang, string $purpose, string $module): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'cci_consent_version`'
            . ' WHERE `id_cci_consent_version` = ' . $idVersion
            . ' AND `id_shop` = ' . $idShop . ' AND `id_lang` = ' . $idLang
            . " AND `purpose_key` = '" . pSQL($purpose) . "'"
            . " AND `source_module` = '" . pSQL($module) . "'"
        );

        return is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLatestCustomerConsent(int $idCustomer, int $idShop, string $purpose): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT v.*, a.`acceptance_source`, a.`source_reference`'
            . $this->customerHistoryScope($idCustomer, $idShop)
            . " AND a.`purpose_key` = '" . pSQL($purpose) . "'"
            . ' ORDER BY a.`date_add` DESC, a.`id_cci_consent_acceptance` DESC'
        );

        return is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * @param array<string, mixed> $version
     */
    public function recordAcceptance(
        array $version,
        int $idCustomer,
        string $source,
        ?string $sourceReference = null,
    ): bool {
        if ($idCustomer <= 0 || empty($version['id_cci_consent_version'])) {
            return false;
        }

        if ($sourceReference === null && $this->hasAccepted($version, $idCustomer)) {
            return true;
        }

        $inserted = Db::getInstance()->insert(
            'cci_consent_acceptance',
            [
                'id_cci_consent_version' => (int) $version['id_cci_consent_version'],
                'id_customer' => $idCustomer,
                'id_shop' => (int) $version['id_shop'],
                'id_lang' => (int) $version['id_lang'],
                'purpose_key' => pSQL((string) $version['purpose_key']),
                'acceptance_source' => pSQL($source),
                ...($sourceReference === null ? [] : ['source_reference' => pSQL($sourceReference)]),
                'date_add' => date('Y-m-d H:i:s'),
            ],
            false,
            true,
            Db::INSERT_IGNORE
        );

        if ($inserted) {
            return true;
        }

        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'cci_consent_acceptance`'
            . ' WHERE `id_cci_consent_version` = ' . (int) $version['id_cci_consent_version']
            . ' AND `id_customer` = ' . $idCustomer
        );
    }

    /**
     * @param array<string, mixed> $version
     */
    public function hasAccepted(array $version, int $idCustomer): bool
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'cci_consent_acceptance`'
            . ' WHERE `id_cci_consent_version` = ' . (int) $version['id_cci_consent_version']
            . ' AND `id_customer` = ' . $idCustomer
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCustomerHistory(int $idCustomer, int $idShop, ?int $limit = null, int $offset = 0): array
    {
        if ($idCustomer <= 0 || $idShop <= 0) {
            return [];
        }

        $query = 'SELECT a.`id_cci_consent_acceptance`, a.`date_add` AS `accepted_at`, a.`acceptance_source`, a.`source_reference`,'
            . ' v.`version_number`, v.`content_text`, v.`content_html`, v.`purpose_key`, v.`source_module`, COALESCE(l.`name`, \'\') AS `language_name`'
            . $this->customerHistoryScope($idCustomer, $idShop)
            . ' ORDER BY a.`date_add` DESC, a.`id_cci_consent_acceptance` DESC';

        if ($limit !== null) {
            $query .= ' LIMIT ' . max(0, $offset) . ', ' . max(1, min(100, $limit));
        }

        return Db::getInstance()->executeS($query) ?: [];
    }

    public function countCustomerHistory(int $idCustomer, int $idShop): int
    {
        if ($idCustomer <= 0 || $idShop <= 0) {
            return 0;
        }

        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*)' . $this->customerHistoryScope($idCustomer, $idShop)
        );
    }

    private function customerHistoryScope(int $idCustomer, int $idShop): string
    {
        return ' FROM `' . _DB_PREFIX_ . 'cci_consent_acceptance` a'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'cci_consent_version` v'
            . ' ON v.`id_cci_consent_version` = a.`id_cci_consent_version`'
            . ' AND v.`id_shop` = a.`id_shop` AND v.`id_lang` = a.`id_lang`'
            . ' AND v.`purpose_key` = a.`purpose_key`'
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'lang` l ON l.`id_lang` = a.`id_lang`'
            . ' WHERE a.`id_customer` = ' . $idCustomer . ' AND a.`id_shop` = ' . $idShop;
    }

    /**
     * @return array{versions:int,acceptances:int,customers:int}
     */
    public function getStats(int $idShop): array
    {
        return [
            'versions' => (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cci_consent_version` WHERE `id_shop` = ' . $idShop
            ),
            'acceptances' => (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cci_consent_acceptance` WHERE `id_shop` = ' . $idShop
            ),
            'customers' => (int) Db::getInstance()->getValue(
                'SELECT COUNT(DISTINCT NULLIF(`id_customer`, 0)) FROM `' . _DB_PREFIX_ . 'cci_consent_acceptance`'
                . ' WHERE `id_shop` = ' . $idShop
            ),
        ];
    }
}
