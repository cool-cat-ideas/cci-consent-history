<?php

declare(strict_types=1);

// Run with PHP PDO MySQL and CCI_IMPORT_TEST_PARAMETERS pointing at a PrestaShop parameters.php.
// All fixtures use a random, isolated prefix. No existing shop table is modified.
if (PHP_SAPI !== 'cli' || !getenv('CCI_IMPORT_TEST_PARAMETERS')) {
    exit("Supply CCI_IMPORT_TEST_PARAMETERS for an isolated-prefix MySQL integration test.\n");
}
$parameters = require getenv('CCI_IMPORT_TEST_PARAMETERS');
$parameters = $parameters['parameters'];
define('_PS_VERSION_', '9.2.0');
define('_DB_NAME_', $parameters['database_name']);
define('_DB_PREFIX_', 'cci_import_test_' . bin2hex(random_bytes(4)) . '_');
define('_MYSQL_ENGINE_', 'InnoDB');
$dsn = 'mysql:host=' . $parameters['database_host'] . ';dbname=' . _DB_NAME_ . ';charset=utf8mb4';
$pdo = new PDO($dsn, $parameters['database_user'], $parameters['database_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function pSQL(string $value, bool $html = false): string
{
    return substr(Db::$pdo->quote($value), 1, -1);
}
function bqSQL(string $value): string
{
    return str_replace('`', '``', $value);
}
final class Shop
{
    public static function getShop(int $id): array|false
    {
        return in_array($id, [1, 2], true) ? ['id_shop' => $id] : false;
    }
}
final class Language
{
    public static function getLanguage(int $id): array|false
    {
        return $id === 1 ? ['id_lang' => 1] : false;
    }
}
final class Module
{
    public static function getModuleIdByName(string $name): int
    {
        return 1;
    }
}
final class Db
{
    public const INSERT_IGNORE = 1;
    public const ON_DUPLICATE_KEY = 2;
    public static PDO $pdo;
    public static int $failAfter = -1;
    public static int $maxRead = 0;

    public static function getInstance(): self
    {
        static $db;

        return $db ??= new self();
    }

    public function execute(string $sql): bool
    {
        return self::$pdo->exec($sql) !== false;
    }

    public function executeS(string $sql, bool $array = true, bool $cache = true): array
    {
        $rows = self::$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if (str_contains($sql, 'SELECT history.*')) {
            self::$maxRead = max(self::$maxRead, count($rows));
        }

        return $rows;
    }

    public function getRow(string $sql, bool $cache = true): array|false
    {
        return self::$pdo->query($sql . ' LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    }

    public function getValue(string $sql, bool $cache = true): mixed
    {
        return self::$pdo->query($sql)->fetchColumn();
    }

    private function values(array $data, bool $null): array
    {
        return array_map(static fn ($value) => is_array($value) && ($value['type'] ?? '') === 'sql' ? $value['value'] : (($value === null || $value === '') && $null ? 'NULL' : "'" . $value . "'"), $data);
    }

    public function insert(string $table, array $data, bool $null = false, bool $cache = true, int $type = 0): bool
    {
        if ($table === 'cci_consent_acceptance' && self::$failAfter >= 0 && --self::$failAfter < 0) {
            throw new RuntimeException('Injected write failure');
        }
        $columns = array_map(static fn ($key) => '`' . $key . '`', array_keys($data));
        $sql = 'INSERT ' . ($type === self::INSERT_IGNORE ? 'IGNORE ' : '') . 'INTO `' . _DB_PREFIX_ . $table . '` (' . implode(',', $columns) . ') VALUES (' . implode(',', $this->values($data, $null)) . ')';
        if ($type === self::ON_DUPLICATE_KEY) {
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(',', array_map(static fn ($column) => $column . '=VALUES(' . $column . ')', $columns));
        }

        return $this->execute($sql);
    }

    public function update(string $table, array $data, string $where, int $limit = 0, bool $null = false): bool
    {
        $sets = [];
        foreach ($this->values($data, $null) as $key => $value) {
            $sets[] = '`' . $key . '`=' . $value;
        }

        return $this->execute('UPDATE `' . _DB_PREFIX_ . $table . '` SET ' . implode(',', $sets) . ' WHERE ' . $where);
    }
}
Db::$pdo = $pdo;
require dirname(__DIR__) . '/classes/CciConsentHistoryRepository.php';
require dirname(__DIR__) . '/vendor/autoload.php';
use Module\CciConsentHistory\Import\BatchResult;
use Module\CciConsentHistory\Import\ImporterRegistry;
use Module\CciConsentHistory\Import\ImportException;
use Module\CciConsentHistory\Import\ImportManager;

function expect(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}
$tables = ['cci_consent_import_job', 'cci_consent_import_run', 'cci_consent_x13_audit', 'cci_consent_x13_definition', 'cci_consent_acceptance', 'cci_consent_version', 'privacy_manager_agreement', 'privacy_manager_agreement_lang', 'privacy_manager_agreement_shop', 'privacy_manager_agreement_history', 'customer', 'psgdpr_log'];
$started = microtime(true);

try {
    $repository = new CciConsentHistoryRepository();
    expect($repository->installSchema(), 'Schema installation');
    expect(!$pdo->query("SHOW TABLES LIKE '" . _DB_PREFIX_ . "cci_consent_x13_definition'")->fetchColumn(), 'Core storage has no integration-specific tables');
    $emptyRegistry = new ImporterRegistry();
    (new Module\CciConsentHistory\Import\BundledImporterLoader())->register(__DIR__ . '/no-bundled-integrations', $emptyRegistry, $repository);
    expect($emptyRegistry->all() === [] && $emptyRegistry->installSchema(), 'No bundled integration is required by the core');
    foreach ([
        'privacy_manager_agreement' => '`id_privacy_manager_agreement` INT PRIMARY KEY, `element` INT, `required` INT, `guests` INT, `default_on` INT, `active` INT, `date_add` DATETIME, `date_upd` DATETIME',
        'privacy_manager_agreement_lang' => '`id_privacy_manager_agreement` INT, `id_lang` INT, `name` VARCHAR(255), `description` TEXT, `url` VARCHAR(1024)',
        'privacy_manager_agreement_shop' => '`id_privacy_manager_agreement` INT, `id_shop` INT',
        'privacy_manager_agreement_history' => '`id_privacy_manager_agreement_history` INT PRIMARY KEY, `id_privacy_manager_agreement` INT, `id_customer` INT, `id_guest` INT, `id_cart` INT, `ip` VARCHAR(45), `status` INT, `date_add` DATETIME, `date_upd` DATETIME',
        'customer' => '`id_customer` INT PRIMARY KEY, `id_lang` INT, `firstname` VARCHAR(255), `lastname` VARCHAR(255), `email` VARCHAR(255)',
        'psgdpr_log' => '`id_gdpr_log` INT PRIMARY KEY AUTO_INCREMENT, `id_customer` INT, `id_guest` INT NULL, `client_name` VARCHAR(255), `id_module` INT, `request_type` INT, `date_add` DATETIME, `date_upd` DATETIME, KEY (`id_customer`)',
    ] as $table => $columns) {
        $pdo->exec('CREATE TABLE `' . _DB_PREFIX_ . $table . '` (' . $columns . ') ENGINE=InnoDB');
    }
    $pdo->exec('INSERT INTO `' . _DB_PREFIX_ . "privacy_manager_agreement` VALUES (1,1,1,0,0,1,'2020-01-01 00:00:00','2020-01-01 00:00:00')");
    $pdo->exec('INSERT INTO `' . _DB_PREFIX_ . "privacy_manager_agreement_lang` VALUES (1,1,'Privacy','<p>Historical account privacy wording.</p>','')");
    $pdo->exec('INSERT INTO `' . _DB_PREFIX_ . 'privacy_manager_agreement_shop` VALUES (1,1),(1,2)');
    $count = (int) (getenv('CCI_IMPORT_TEST_EVENTS') ?: 100000);
    expect($count >= 1000, 'Use at least 1000 events');
    $pdo->beginTransaction();
    for ($start = 1; $start <= $count; $start += 1000) {
        $events = $customers = [];
        for ($id = $start; $id < $start + 1000 && $id <= $count; ++$id) {
            $status = $id % 10 === 0 ? 0 : 1;
            $events[] = "($id,1,$id,0,0,'" . ($id === 7 ? '' : '127.0.0.1') . "',$status,'2020-01-01 12:00:00','2020-01-01 12:00:00')";
            if ($id % 7 !== 0) {
                $customers[] = "($id,1,'Test','Customer','customer$id@example.invalid')";
            }
        }
        $pdo->exec('INSERT INTO `' . _DB_PREFIX_ . 'privacy_manager_agreement_history` VALUES ' . implode(',', $events));
        $pdo->exec('INSERT INTO `' . _DB_PREFIX_ . 'customer` VALUES ' . implode(',', $customers));
    }
    $pdo->commit();
    $registry = new ImporterRegistry();
    (new Module\CciConsentHistory\Import\BundledImporterLoader())->register(dirname(__DIR__) . '/integrations', $registry, $repository);
    expect($registry->installSchema(), 'Integration storage installation');
    expect(isset($registry->acceptanceSources()['x13_import']), 'Integration owns its source label');
    $manager = new ImportManager($registry);
    expect($manager->sources(1, 1)['x13']['available'], 'Source with supported wording is available');
    expect(!$manager->sources(0, 1)['x13']['available'], 'No single-shop context blocks starting');
    $pdo->exec('DELETE FROM `' . _DB_PREFIX_ . 'privacy_manager_agreement_shop` WHERE id_shop=2');
    expect($manager->sources(2, 1)['x13']['reason'] === 'definitions_missing', 'Source availability is scoped to the shop');
    $pdo->exec('INSERT INTO `' . _DB_PREFIX_ . 'privacy_manager_agreement_shop` VALUES (1,2)');

    try {
        new BatchResult([], ['processed' => 1], false);
        throw new RuntimeException('Invalid contract accepted');
    } catch (ImportException) {
    }

    try {
        $manager->start('x13', 1, 1, false, false);
        throw new RuntimeException('Backup not required');
    } catch (ImportException $e) {
        expect($e->reason === 'backup_required', 'Backup error');
    }
    $job = $manager->start('x13', 1, 1, true, false);
    expect($job['total'] === $count, 'Snapshot count');
    expect($manager->start('x13', 1, 1, true, false)['job_id'] === $job['job_id'], 'Double start returns same job');
    $job = $manager->step($job['job_id'], 1); // Prepare snapshots.
    $job = $manager->step($job['job_id'], 1);
    expect($job['counts']['processed'] === 250, 'Bounded first batch');
    $manager->control($job['job_id'], 1, 'pause');
    expect($manager->step($job['job_id'], 1)['counts']['processed'] === 250, 'Paused job does no work');
    $manager->control($job['job_id'], 1, 'resume');
    $observer = new PDO($dsn, $parameters['database_user'], $parameters['database_password']);
    $lock = 'cci-import:' . substr(hash('sha256', _DB_NAME_ . ':' . $job['job_id']), 0, 48);
    $observer->query("SELECT GET_LOCK('$lock',0)");
    expect($manager->step($job['job_id'], 1)['locked'] === true, 'Second connection excludes concurrent worker');
    $observer->query("SELECT RELEASE_LOCK('$lock')");
    Db::$failAfter = 3;
    $failed = $manager->step($job['job_id'], 1);
    expect($failed['status'] === 'failed' && $failed['counts']['processed'] === 250, 'Failed batch keeps checkpoint');
    expect((int) $pdo->query('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cci_consent_x13_audit`')->fetchColumn() === 250, 'Failure rolls back audit rows too');
    Db::$failAfter = -1;
    $job = $manager->control($job['job_id'], 1, 'resume');
    while ($job['can_continue']) {
        $job = $manager->step($job['job_id'], 1);
    }
    expect($job['status'] === 'completed' && $job['percent'] === 100, 'Completion');
    expect($job['counts']['added'] === $count - intdiv($count, 10), 'Affirmative events only');
    expect($job['counts']['rejected'] === intdiv($count, 10), 'Refusals archived');
    expect(Db::$maxRead <= 250, 'History memory bounded by batch');
    $pdo->exec('UPDATE `' . _DB_PREFIX_ . "cci_consent_acceptance` SET source_reference='x13_history:1' WHERE source_reference='x13_shop:1:history:1'");
    $rerun = $manager->start('x13', 1, 1, true, false);
    while ($rerun['can_continue']) {
        $rerun = $manager->step($rerun['job_id'], 1);
    }
    expect($rerun['status'] === 'completed' && $rerun['counts']['added'] === 0 && $rerun['counts']['skipped'] === $job['counts']['added'], 'Rerun recognizes current and legacy references');
    $shop2 = $manager->start('x13', 2, 1, true, false);
    $manager->step($shop2['job_id'], 2);
    $shop2 = $manager->step($shop2['job_id'], 2, 10);
    expect($shop2['counts']['added'] === 9, 'Same source IDs are independent in another shop');

    try {
        $manager->status($shop2['job_id'], 1);
        throw new RuntimeException('Cross-shop read allowed');
    } catch (ImportException $e) {
        expect($e->reason === 'job_not_found', 'Shop scope');
    }
    $manager->control($shop2['job_id'], 2, 'cancel');
    expect($manager->status($shop2['job_id'], 2)['status'] === 'cancelled', 'Cancellation');
    if ($count <= 1000) {
        $changed = $manager->start('x13', 2, 1, true, false);
        $pdo->exec('DELETE FROM `' . _DB_PREFIX_ . 'privacy_manager_agreement_history` WHERE id_privacy_manager_agreement_history = ' . $count);
        while ($changed['can_continue']) {
            $changed = $manager->step($changed['job_id'], 2);
        }
        expect($changed['status'] === 'failed' && $changed['error_code'] === 'source_changed' && $changed['percent'] < 100, 'Deleted source event cannot report completion');
        $manager->control($changed['job_id'], 2, 'cancel');
    }
    $pdo->exec('DELETE FROM `' . _DB_PREFIX_ . 'privacy_manager_agreement_history`');
    $empty = $manager->start('x13', 2, 1, true, false);
    $empty = $manager->step($empty['job_id'], 2);
    expect($empty['status'] === 'completed' && $empty['total'] === 0 && $empty['percent'] === 100, 'Empty source range');
    $pdo->exec('RENAME TABLE `' . _DB_PREFIX_ . 'privacy_manager_agreement_history` TO `' . _DB_PREFIX_ . 'source_history_saved`');
    expect(!$manager->sources(1, 1)['x13']['available'], 'Missing source tables disable only that source');
    $pdo->exec('RENAME TABLE `' . _DB_PREFIX_ . 'source_history_saved` TO `' . _DB_PREFIX_ . 'privacy_manager_agreement_history`');
    $custom = new class implements Module\CciConsentHistory\Import\ImporterInterface {
        public bool $fail = true;

        public function getId(): string
        {
            return 'fixture';
        }

        public function getLabel(): string
        {
            return 'Fixture integration';
        }

        public function transactionalTables(array $context): array
        {
            return [];
        }

        public function plan(array $context): array
        {
            return ['total' => 0, 'source' => [], 'cursor' => []];
        }

        public function processBatch(array $context, array $source, array $cursor, int $limit): BatchResult
        {
            return new BatchResult(['finished' => true], [], true);
        }

        public function finish(array $context, array $source, array $cursor, array $counts): void
        {
            if ($this->fail) {
                throw new ImportException('fixture_finish_failed');
            }
        }
    };
    $registry->register($custom);
    $pdo->exec('RENAME TABLE `' . _DB_PREFIX_ . 'privacy_manager_agreement_history` TO `' . _DB_PREFIX_ . 'source_history_saved`');
    $sources = $manager->sources(1, 1);
    expect(!$sources['x13']['available'] && $sources['fixture']['available'], 'An unavailable source does not disable another contract-v1 adapter');
    $pdo->exec('RENAME TABLE `' . _DB_PREFIX_ . 'source_history_saved` TO `' . _DB_PREFIX_ . 'privacy_manager_agreement_history`');

    $customJob = $manager->start('fixture', 1, 1, true, false);
    expect($manager->start('x13', 1, 1, true, false)['job_id'] === $customJob['job_id'], 'Different sources cannot run in the same shop at once');
    $customJob = $manager->step($customJob['job_id'], 1);
    expect($customJob['status'] === 'failed' && $customJob['percent'] === 0, 'Finalization failure cannot commit completion');
    $custom->fail = false;
    $manager->control($customJob['job_id'], 1, 'resume');
    expect($manager->step($customJob['job_id'], 1)['status'] === 'completed', 'Custom integration resumes through shared contract');
    echo json_encode(['result' => 'passed', 'events' => $count, 'counts' => $job['counts'], 'max_batch_rows' => Db::$maxRead, 'seconds' => round(microtime(true) - $started, 2), 'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2)], JSON_PRETTY_PRINT) . "\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ($tables as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`');
    }
}
