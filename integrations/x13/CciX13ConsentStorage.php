<?php

/** Storage owned by the X13 account-consent integration. */
if (!defined('_PS_VERSION_')) {
    exit;
}

final class CciX13ConsentStorage
{
    public function installSchema(): bool
    {
        $engine = _MYSQL_ENGINE_;
        $queries = [
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'cci_consent_x13_definition` (
                `id_x13_agreement` INT UNSIGNED NOT NULL,
                `id_lang` INT UNSIGNED NOT NULL,
                `required` TINYINT(1) UNSIGNED NOT NULL,
                `guests` TINYINT(1) UNSIGNED NOT NULL,
                `element` INT UNSIGNED NOT NULL,
                `default_on` TINYINT(1) UNSIGNED NOT NULL,
                `active` TINYINT(1) UNSIGNED NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `description` MEDIUMTEXT NOT NULL,
                `url` VARCHAR(1024) NOT NULL,
                `source_date_add` DATETIME NOT NULL,
                `source_date_upd` DATETIME NOT NULL,
                `date_migrated` DATETIME NOT NULL,
                PRIMARY KEY (`id_x13_agreement`, `id_lang`),
                KEY `idx_cci_x13_definition_active` (`active`, `element`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'cci_consent_x13_audit` (
                `id_x13_history` INT UNSIGNED NOT NULL,
                `id_x13_agreement` INT UNSIGNED NOT NULL,
                `source_customer_id` INT UNSIGNED NOT NULL,
                `source_customer_email` VARCHAR(255) DEFAULT NULL,
                `source_guest_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `source_cart_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `source_ip` VARCHAR(45) NOT NULL,
                `source_status` TINYINT(1) UNSIGNED NOT NULL,
                `source_date_add` DATETIME NOT NULL,
                `source_date_upd` DATETIME NOT NULL,
                `mapped_customer_id` INT UNSIGNED DEFAULT NULL,
                `mapping_method` VARCHAR(32) NOT NULL,
                `date_migrated` DATETIME NOT NULL,
                PRIMARY KEY (`id_x13_history`),
                KEY `idx_cci_x13_source_customer` (`source_customer_id`),
                KEY `idx_cci_x13_mapped_customer` (`mapped_customer_id`),
                KEY `idx_cci_x13_date` (`source_date_add`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];

        foreach ($queries as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return true;
    }

    public function uninstallSchema(): bool
    {
        return Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'cci_consent_x13_audit`, `'
            . _DB_PREFIX_ . 'cci_consent_x13_definition`'
        );
    }
}
