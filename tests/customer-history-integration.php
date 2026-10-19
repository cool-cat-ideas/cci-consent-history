<?php

declare(strict_types=1);

// Run in CLI with CCI_HISTORY_TEST_PARAMETERS pointing at a PrestaShop parameters.php.
// Fixtures use a random prefix and are always removed. Existing shop records are not changed.
if (PHP_SAPI !== 'cli' || !getenv('CCI_HISTORY_TEST_PARAMETERS')) {
    exit("Supply CCI_HISTORY_TEST_PARAMETERS for an isolated-prefix MySQL integration test.\n");
}

$parameters = (require getenv('CCI_HISTORY_TEST_PARAMETERS'))['parameters'];
define('_PS_VERSION_', '9.2.0');
define('_COOKIE_KEY_', 'isolated-history-test-key');
define('_DB_PREFIX_', 'cci_history_test_' . bin2hex(random_bytes(4)) . '_');
$pdo = new PDO(
    'mysql:host=' . $parameters['database_host'] . ';dbname=' . $parameters['database_name'] . ';charset=utf8mb4',
    $parameters['database_user'],
    $parameters['database_password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

final class Db
{
    public const INSERT_IGNORE = 2;
    public static PDO $pdo;

    public static function getInstance(): self
    {
        static $db;

        return $db ??= new self();
    }

    public function executeS(string $sql): array
    {
        return self::$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getValue(string $sql): mixed
    {
        return self::$pdo->query($sql)->fetchColumn();
    }

    public function getRow(string $sql): array|false
    {
        return self::$pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    }

    public function insert(string $table, array $data, bool $nullValues = false, bool $useCache = true, int $type = 0): bool
    {
        $columns = '`' . implode('`, `', array_keys($data)) . '`';
        $values = array_map(static fn ($value) => "'" . $value . "'", array_values($data));

        return self::$pdo->exec(
            'INSERT ' . ($type === self::INSERT_IGNORE ? 'IGNORE ' : '')
            . 'INTO `' . _DB_PREFIX_ . $table . '` (' . $columns . ') VALUES (' . implode(', ', $values) . ')'
        ) !== false;
    }
}

class Module
{
    public object $context;
    public string $name = 'cci_consent_history';
    public int $id = 0;
    public static bool $enabled = true;
    public string $displayName = '';
    public static array $nativeModules = [25 => 'ps_emailsubscription', 26 => 'example_booking'];

    public static function isInstalled(string $name): bool
    {
        return true;
    }

    public static function isEnabled(string $name): bool
    {
        return self::$enabled;
    }

    public static function getInstanceByName(string $name): self
    {
        $module = new self();
        $module->name = $name;
        $module->displayName = $name === 'example_booking' ? 'Appointment form' : $name;

        return $module;
    }

    public static function getInstanceById(int $id): self|false
    {
        if (!isset(self::$nativeModules[$id])) {
            return false;
        }
        $module = new self();
        $module->id = $id;
        $module->name = self::$nativeModules[$id];

        return $module;
    }

    public function getCustomerNameById(int $id): string
    {
        return 'Fixture customer ' . $id;
    }

    public function l(string $message): string
    {
        return $message;
    }

    public function fetch(string $template): string
    {
        return (string) $this->context->smarty->values['cci_profile_consent_snapshot'];
    }

    public function trans(string $source, array $parameters = [], string $domain = ''): string
    {
        return $source;
    }
}

final class Configuration
{
    public static array $values = [
        'PSGDPR_CUSTOMER_FORM_SWITCH' => true,
        'PSGDPR_CUSTOMER_FORM' => 'Profile notice A',
        'PSGDPR_CREATION_FORM_SWITCH' => true,
        'PSGDPR_CREATION_FORM' => 'Registration notice',
    ];

    public static function get(string $key, mixed ...$context): mixed
    {
        return self::$values[$key] ?? false;
    }
}

final class Tools
{
    public static function getValue(string $key): mixed
    {
        return $_POST[$key] ?? false;
    }
}

final class Customer
{
    public bool $logged = true;
    public bool $is_guest = false;
    public bool $passwordCacheStale = false;
    public string $secure_key = 'fixture-customer-secure-key';

    public function __construct(public int $id)
    {
    }

    public function isLogged(): bool
    {
        return $this->id > 0 && !$this->passwordCacheStale;
    }
}

class FrontController
{
    public string $php_self = 'identity';
}

final class Validate
{
    public static function isLoadedObject(object $object): bool
    {
        return $object->id > 0;
    }
}

final class Context
{
    public object $shop;
    public object $language;
    public Customer $customer;
    public object $cart;
    public object $cookie;
    public object $link;
    public object $controller;
}

class ObjectModel
{
    public const TYPE_INT = 1;
    public const TYPE_BOOL = 2;
    public const TYPE_HTML = 3;
    public const TYPE_DATE = 4;
    public const TYPE_STRING = 5;
}

final class Hook
{
    public static bool $registered = true;

    public static function isModuleRegisteredOnHook(Module $module, string $hook, int $shop): bool
    {
        return self::$registered && isset(Module::$nativeModules[$module->id]) && $shop === 1;
    }
}

function pSQL(string $value, bool $html = false): string
{
    return substr(Db::$pdo->quote($value), 1, -1);
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

Db::$pdo = $pdo;
$modulePath = getenv('CCI_HISTORY_TEST_MODULE') ?: dirname(__DIR__);
require $modulePath . '/cci_consent_history.php';
$tables = ['customer', 'psgdpr_log', 'psgdpr_consent_lang', 'psgdpr_consent', 'cci_consent_acceptance', 'cci_consent_version', 'lang'];

try {
    $pdo->exec('CREATE TABLE `' . _DB_PREFIX_ . 'lang` (id_lang INT PRIMARY KEY, name VARCHAR(64))');
    $pdo->exec('CREATE TABLE `' . _DB_PREFIX_ . 'cci_consent_version` (
        id_cci_consent_version INT PRIMARY KEY AUTO_INCREMENT, id_shop INT, id_lang INT,
        purpose_key VARCHAR(64), version_number INT, content_text TEXT,
        source_module VARCHAR(64) DEFAULT "psgdpr", content_hash CHAR(64) DEFAULT "",
        content_html TEXT, is_baseline TINYINT DEFAULT 0, date_add DATETIME
    )');
    $pdo->exec('CREATE TABLE `' . _DB_PREFIX_ . 'cci_consent_acceptance` (
        id_cci_consent_acceptance INT PRIMARY KEY AUTO_INCREMENT, id_cci_consent_version INT,
        id_customer INT, id_shop INT, id_lang INT, purpose_key VARCHAR(64),
        acceptance_source VARCHAR(32), date_add DATETIME, source_reference VARCHAR(96) DEFAULT NULL,
        source_guest_id INT DEFAULT NULL,
        UNIQUE KEY source_reference (acceptance_source, source_reference)
    )');
    $pdo->exec('INSERT INTO `' . _DB_PREFIX_ . "lang` VALUES (1, 'English'), (2, 'Polski')");
    $version = $pdo->prepare('INSERT INTO `' . _DB_PREFIX_ . 'cci_consent_version` (id_cci_consent_version, id_shop, id_lang, purpose_key, version_number, content_text) VALUES (?, ?, ?, ?, ?, ?)');
    $acceptance = $pdo->prepare('INSERT INTO `' . _DB_PREFIX_ . 'cci_consent_acceptance` (id_cci_consent_acceptance, id_cci_consent_version, id_customer, id_shop, id_lang, purpose_key, acceptance_source, date_add) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    for ($index = 1; $index <= 45; ++$index) {
        $language = $index % 2 + 1;
        $version->execute([$index, 1, $language, 'account_privacy', $index, 'Saved wording ' . $index]);
        $acceptance->execute([$index, $index, 7, 1, $language, 'account_privacy', $index === 1 ? 'account_creation' : 'renewal', '2026-10-19 12:00:00']);
    }
    $version->execute([100, 2, 1, 'account_privacy', 1, 'OTHER SHOP']);
    $acceptance->execute([100, 100, 7, 2, 1, 'account_privacy', 'renewal', '2026-10-19 12:00:00']);
    $acceptance->execute([101, 1, 99, 1, 2, 'account_privacy', 'renewal', '2026-10-19 12:00:00']);
    $acceptance->execute([102, 100, 7, 1, 1, 'account_privacy', 'renewal', '2026-10-19 12:00:00']);

    $repository = new CciConsentHistoryRepository();
    check($repository->countCustomerHistory(7, 1) === 45, 'History count must exclude other shops, customers and mismatched versions.');
    $page = $repository->getCustomerHistory(7, 1, 20);
    check(count($page) === 20 && (int) $page[0]['version_number'] === 45, 'History must have bounded, stable pages.');
    check(count($repository->getCustomerHistory(7, 1, 20, 40)) === 5, 'Last page must contain remaining entries.');
    check(count(array_unique(array_column($repository->getCustomerHistory(7, 1), 'language_name'))) === 2, 'Changing interface language must not hide earlier acceptances.');
    check($repository->getCustomerHistory(0, 1) === [] && $repository->getCustomerHistory(7, 0) === [], 'Missing customer or shop must never produce an unscoped query.');
    check($repository->getCustomerHistory(1234, 1) === [], 'An account without acceptances must have an empty history.');

    $module = (new ReflectionClass(Cci_Consent_History::class))->newInstanceWithoutConstructor();
    $module->context = (object) ['shop' => (object) ['id' => 1]];
    $export = json_decode($module->hookActionExportGDPRData(['id' => 7]), true, 512, JSON_THROW_ON_ERROR);
    check(count($export) === 45, 'Native GDPR export must include every page.');
    check(array_keys($export[0]) === ['Recorded on', 'Status', 'Purpose', 'Version', 'Language', 'Source', 'Saved wording'], 'Native CSV/PDF exporter requires flat records.');
    check($export[0]['Saved wording'] === 'Saved wording 45', 'Export must preserve the accepted wording.');
    check(!str_contains(json_encode($export), 'OTHER SHOP'), 'Export must remain scoped to the current shop.');
    check(count(json_decode($module->hookActionExportGDPRData(['id_customer' => 99]), true)) === 1, 'Support the native customer identifier parameter.');
    check($module->hookActionExportGDPRData([]) === '[]', 'An unidentified export request must return no personal data.');
    $profileModule = (new ReflectionClass(Cci_Consent_History::class))->newInstanceWithoutConstructor();
    $profileModule->context = (object) [
        'shop' => (object) ['id' => 1],
        'language' => (object) ['id' => 1],
        'customer' => new Customer(1234),
        'controller' => new FrontController(),
        'smarty' => new class {
            public array $values = [];

            public function assign(string $key, mixed $value): void
            {
                $this->values[$key] = $value;
            }
        },
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $snapshot = $profileModule->hookDisplayCustomerAccountForm();
    check($snapshot !== '', 'The profile form must carry its signed wording snapshot.');
    check($repository->countCustomerHistory(1234, 1) === 0, 'Rendering a profile must not invent an acceptance.');
    $_POST = ['psgdpr' => '1', 'cci_profile_consent_snapshot' => $snapshot];
    $customer = $profileModule->context->customer;

    $_POST['psgdpr'] = '0';
    $profileModule->hookActionCustomerAccountUpdate(['customer' => $customer]);
    check($repository->countCustomerHistory(1234, 1) === 0, 'An unchecked box must not become an acceptance.');
    $_POST['psgdpr'] = '1';
    $_POST['cci_profile_consent_snapshot'] = $snapshot . 'tampered';
    $profileModule->hookActionCustomerAccountUpdate(['customer' => $customer]);
    check($repository->countCustomerHistory(1234, 1) === 0, 'A forged snapshot must not create an acceptance.');
    $_POST['cci_profile_consent_snapshot'] = $snapshot;
    $profileModule->hookActionCustomerAccountUpdate(['customer' => new Customer(4321)]);
    check($repository->countCustomerHistory(4321, 1) === 0, 'The submitted customer must match the signed-in customer.');
    $profileModule->context->shop->id = 2;
    $profileModule->hookActionCustomerAccountUpdate(['customer' => $customer]);
    check($repository->countCustomerHistory(1234, 2) === 0, 'A snapshot from another shop must be rejected.');
    $profileModule->context->shop->id = 1;
    $profileModule->context->language->id = 2;
    $profileModule->hookActionCustomerAccountUpdate(['customer' => $customer]);
    check($repository->countCustomerHistory(1234, 1) === 0, 'A snapshot must retain its original language.');
    $profileModule->context->language->id = 1;
    Configuration::$values['PSGDPR_CUSTOMER_FORM_SWITCH'] = false;
    $profileModule->hookActionCustomerAccountUpdate(['customer' => $customer]);
    check($repository->countCustomerHistory(1234, 1) === 0, 'An inactive profile source must not record an acceptance.');
    Configuration::$values['PSGDPR_CUSTOMER_FORM_SWITCH'] = true;
    Module::$enabled = false;
    $profileModule->hookActionCustomerAccountUpdate(['customer' => $customer]);
    check($repository->countCustomerHistory(1234, 1) === 0, 'A disabled GDPR module must not record an acceptance.');
    Module::$enabled = true;
    $profileModule->context->controller->php_self = 'order';
    $profileModule->hookActionCustomerAccountUpdate(['customer' => $customer]);
    check($repository->countCustomerHistory(1234, 1) === 0, 'A different customer update flow must not create a profile acceptance.');
    $profileModule->context->controller->php_self = 'identity';

    // A wording edit in another request must not change what this customer saw.
    Configuration::$values['PSGDPR_CUSTOMER_FORM'] = 'Profile notice B';
    $customer->passwordCacheStale = true;
    $profileModule->hookActionCustomerAccountUpdate(['customer' => $customer]);
    $history = $profileModule->getCustomerHistory(1234, 1);
    check(count($history) === 1 && $history[0]['content_text'] === 'Profile notice A', 'Record the wording actually rendered, not the latest settings.');
    check($history[0]['source_label'] === 'Profile update', 'The history must distinguish profile saves from renewals.');
    $profileModule->hookActionCustomerAccountUpdate(['customer' => $customer]);
    check($repository->countCustomerHistory(1234, 1) === 1, 'Retrying the same form must not duplicate an acceptance.');
    $_POST['cci_profile_consent_snapshot'] = $profileModule->hookDisplayCustomerAccountForm();
    $profileModule->hookActionCustomerAccountUpdate(['customer' => $customer]);
    $history = $profileModule->getCustomerHistory(1234, 1);
    check(count($history) === 2 && $history[0]['content_text'] === 'Profile notice B', 'A later save must preserve the new notice separately.');
    $accountVersion = $repository->ensureCurrentAccountConsentVersion(1, 1);
    check($accountVersion['purpose_key'] === 'account_privacy', 'Profile versions must not replace account-access renewal versions.');
    check($repository->recordAcceptance($accountVersion, 1234, 'account_creation'), 'Record ordinary account acceptance.');
    check($repository->recordAcceptance($accountVersion, 4321, 'account_creation'), 'Null source references must allow another customer to accept.');
    check($repository->hasAccepted($accountVersion, 4321), 'An ignored insert must not hide a missing account acceptance.');
    $gdprPath = getenv('CCI_HISTORY_TEST_NATIVE_GDPR');
    check(is_string($gdprPath) && is_file($gdprPath . '/classes/GDPRLog.php'), 'Supply CCI_HISTORY_TEST_NATIVE_GDPR to test against native GDPR APIs.');
    require $gdprPath . '/classes/GDPRConsent.php';
    require $gdprPath . '/classes/GDPRLog.php';
    $pdo->exec('CREATE TABLE `' . _DB_PREFIX_ . 'psgdpr_consent` (id_gdpr_consent INT PRIMARY KEY, id_module INT, active TINYINT)');
    $pdo->exec('CREATE TABLE `' . _DB_PREFIX_ . 'psgdpr_consent_lang` (id_gdpr_consent INT, id_lang INT, id_shop INT, message TEXT)');
    $pdo->exec('CREATE TABLE `' . _DB_PREFIX_ . 'psgdpr_log` (
        id_gdpr_log INT PRIMARY KEY AUTO_INCREMENT, id_customer INT, id_guest INT,
        client_name VARCHAR(255), id_module INT, request_type INT, date_add DATETIME, date_upd DATETIME
    )');
    $pdo->exec('INSERT INTO `' . _DB_PREFIX_ . 'psgdpr_consent` VALUES (1,25,1)');
    $pdo->exec('INSERT INTO `' . _DB_PREFIX_ . "psgdpr_consent_lang` VALUES (1,1,1,'Newsletter privacy A')");
    $context = new Context();
    $context->shop = (object) ['id' => 1];
    $context->language = (object) ['id' => 1];
    $context->customer = new Customer(888);
    $context->cart = (object) ['id_guest' => 77];
    $context->cookie = (object) ['id_guest' => 77];
    $context->link = new class {
        public function getModuleLink(string $module, string $controller, array $params, bool $ssl): string
        {
            return 'https://fixture.invalid/module/' . $module . '/' . $controller;
        }
    };
    $recorder = new CciGdprFormConsent($repository, $context);
    $metadata = $recorder->createSnapshot(25);
    check(is_array($metadata), 'An active native source must produce a signed snapshot.');
    check($recorder->createSnapshot(404) === null, 'An unknown module must not become a consent source.');
    Hook::$registered = false;
    check($recorder->createSnapshot(25) === null, 'The source must be registered with native GDPR in this shop.');
    Hook::$registered = true;
    $_POST = ['id_customer' => '888', 'customer_token' => sha1($context->customer->secure_key)];
    check(!$recorder->record(25, $metadata['snapshot'] . 'changed'), 'Reject a forged form snapshot.');
    $_POST['id_customer'] = '999';
    check(!$recorder->record(25, $metadata['snapshot']), 'Reject another customer identifier.');
    $_POST['id_customer'] = '888';
    $_POST['customer_token'] = 'invalid';
    check(!$recorder->record(25, $metadata['snapshot']), 'Reject an invalid native token.');
    unset($_POST['customer_token']);
    check(!$recorder->record(25, $metadata['snapshot']), 'Unlike the native endpoint, require the token to be present.');
    check((int) $pdo->query('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'psgdpr_log`')->fetchColumn() === 0, 'Rejected requests must not write native logs.');
    $_POST['customer_token'] = sha1($context->customer->secure_key);
    $pdo->exec('UPDATE `' . _DB_PREFIX_ . "psgdpr_consent_lang` SET message='Newsletter privacy B'");
    check($recorder->record(25, $metadata['snapshot']), 'Record a valid customer event through the native GDPR API.');
    check($recorder->record(25, $metadata['snapshot']), 'Repeating a native event must be safe.');
    $history = $repository->getCustomerHistory(888, 1);
    check(count($history) === 1 && $history[0]['content_text'] === 'Newsletter privacy A', 'Native API logging must retain the displayed wording and deduplicate its event.');
    check($history[0]['purpose_key'] === 'gdpr_form_ps_emailsubscription', 'Keep a separate purpose for each native source.');
    $metadataB = $recorder->createSnapshot(25);
    check($recorder->record(25, $metadataB['snapshot']), 'A changed notice can share a native timestamp without losing its wording.');
    check($repository->countCustomerHistory(888, 1) === 2, 'Two accepted wording versions must remain distinct.');
    $pdo->exec('UPDATE `' . _DB_PREFIX_ . 'psgdpr_consent` SET active=0');
    check(!$recorder->record(25, $metadataB['snapshot']), 'An inactive native source must not accept a previously issued snapshot.');
    $pdo->exec('UPDATE `' . _DB_PREFIX_ . 'psgdpr_consent` SET active=1');

    // Any module registered with native GDPR uses the same recorder, including modules unknown to CCI.
    $pdo->exec('INSERT INTO `' . _DB_PREFIX_ . 'psgdpr_consent` VALUES (2,26,1)');
    $pdo->exec('INSERT INTO `' . _DB_PREFIX_ . "psgdpr_consent_lang` VALUES (2,1,1,'Booking privacy notice')");
    $context->customer = new Customer(889);
    $_POST = ['id_customer' => '889', 'customer_token' => sha1($context->customer->secure_key)];
    $thirdPartyMetadata = $recorder->createSnapshot(26);
    check(is_array($thirdPartyMetadata) && $recorder->record(26, $thirdPartyMetadata['snapshot']), 'Discover an arbitrary native GDPR source without a module-specific adapter.');
    $genericPresenter = (new ReflectionClass(Cci_Consent_History::class))->newInstanceWithoutConstructor();
    $genericPresenter->context = $context;
    $thirdPartyHistory = $genericPresenter->getCustomerHistory(889, 1);
    check(count($thirdPartyHistory) === 1 && $thirdPartyHistory[0]['purpose_label'] === 'Appointment form', 'Resolve an arbitrary native source label from its module display name.');
    check($thirdPartyHistory[0]['content_text'] === 'Booking privacy notice', 'Read the third-party notice through the native GDPR message API.');

    // Anonymous visitors have a native guest cookie even before their cart is saved.
    $context->customer = new Customer(0);
    $context->cart->id_guest = 0;
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $guestMetadata = $recorder->createSnapshot(25);
    $_POST = ['id_customer' => '0', 'id_guest' => '0', 'guest_token' => sha1('psgdpr0' . $_SERVER['REMOTE_ADDR'] . date('Y-m-d'))];
    check($recorder->record(25, $guestMetadata['snapshot']), 'A guest must be able to submit a native checkbox without an account.');
    check($repository->getCustomerHistory(0, 1) === [], 'Guest events must not expose an unscoped customer history.');
    $guestLog = $pdo->query('SELECT id_customer,source_guest_id FROM `' . _DB_PREFIX_ . "cci_consent_acceptance` WHERE acceptance_source='gdpr_form' AND id_customer=0")->fetch(PDO::FETCH_ASSOC);
    check((int) $guestLog['source_guest_id'] === 77, 'Keep the native guest identity instead of attaching the event to a random account.');
    $context->cookie->id_guest = 88;
    check(!$recorder->record(25, $guestMetadata['snapshot']), 'A snapshot from another guest session must be rejected.');
    $context->cookie->id_guest = 0;
    $metadataWithoutGuestId = $recorder->createSnapshot(25);
    check($recorder->record(25, $metadataWithoutGuestId['snapshot']), 'A visitor without native tracking or a cart can still give consent.');
    $context->cookie->cci_consent_history_visitor = str_repeat('a', 32);
    check(!$recorder->record(25, $metadataWithoutGuestId['snapshot']), 'Bind anonymous snapshots to their encrypted native browser cookie.');
    require $modulePath . '/tests/customer-form-consents.php';
    echo "PASS: isolated history/export, signed profile saves, native GDPR form APIs, customer/guest isolation immutable wording and customer preference transitions.\n";
} finally {
    foreach ($tables as $table) {
        check((bool) preg_match('/^cci_history_test_[a-f0-9]{8}_$/', _DB_PREFIX_), 'Unexpected test prefix.');
        $pdo->exec('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`');
    }
}
