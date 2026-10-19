# Write a consent-history integration

CCI Consent History 1.0.0 provides import contract **version 1**. A separate integration module registers an adapter. The same adapter works in the PrestaShop back office and in the CLI worker. It does not need React or its own progress storage.

## 1. Establish the source and mapping

Identify affirmative account-consent events, historical wording and language, original dates and timezone, and immutable event IDs. Keep marketing, newsletter and deletion requests outside account-consent acceptances. Do not invent earlier wording from today's notice. Explain any source limitation to the shop owner.

Map source shops, languages and customers explicitly. The built-in X13 adapter assumes an in-place upgrade with preserved customer IDs. That assumption is not suitable for unrelated databases. An unmatched X13 event remains archived and its acceptance has customer ID zero; it is never attached to a guessed customer. Other adapters should document their own handling of unmatched evidence.

Take a database backup and retain an access-controlled mapping report. Keep source data unchanged until the import finishes. A job freezes the source range, not the entire source database. New events above that range belong to a later import.

## 2. Register your adapter

Keep the integration in its own PrestaShop module so updating CCI Consent History cannot overwrite it. Depend on `cci_consent_history`, load your module's autoloader, and register the hook during installation:

```php
$this->registerHook('actionCciConsentHistoryRegisterImporters');
```

Implement the hook in that module:

```php
public function hookActionCciConsentHistoryRegisterImporters(array $params): void
{
    $params['registry']->register(new MyConsentImporter());
}
```

`MyConsentImporter` must implement `Module\CciConsentHistory\Import\ImporterInterface`. Its ID is a stable lowercase identifier of up to 64 characters, for example `vendor_account_privacy`. IDs must be unique. The registry rejects duplicates. Never accept class names, SQL or table names from browser input.

### Bundled integration example

The module discovers trusted local `integrations/*/register.php` files. Each entry returns a factory with this signature:

```php
use Module\CciConsentHistory\Import\ImporterInterface;

return static function (CciConsentHistoryRepository $repository): ImporterInterface {
    return new MyConsentImporter($repository);
};
```

Load your integration classes in that entry file. The X13 directory is a working example; the main module has no X13 property, class import or source-specific branch. A bundled adapter may implement `ImporterStorageInterface` to prepare and remove only its own tables during the module's storage lifecycle. These schema methods run outside batch transactions. Keep table names stable so replacing files preserves earlier imports. External PrestaShop integration modules should normally manage their own schema in their own install and uninstall methods.

An optional `ImporterSourceLabelsInterface::getAcceptanceSources()` returns the acceptance-source IDs and labels used by the back-office filter. Labels remain available even when the old source tables are missing. Adapters without this interface still work; their events use the general imported-acceptance label.

`availability()` may also return plain-text `description` and `notice` values. The selected source supplies these values to the import panel; the template escapes them and JavaScript uses `textContent`. `notice` explains an unavailable source. When omitted, the panel uses its standard explanation for the reason code. Do not return SQL errors or customer data in these fields.

## 3. Implement contract version 1

| Method | Responsibility |
| --- | --- |
| `getId(): string` | Stable integration ID, used by jobs and CLI. |
| `getLabel(): string` | Short source name shown in the back office. |
| `plan(array $context): array` | Read-only source snapshot. Return `total` as a non-negative integer and JSON-serializable `source` and `cursor` arrays. |
| `transactionalTables(array $context): array` | All destination table names, including the PrestaShop prefix. Every destination must use InnoDB. The manager checks this before starting. |
| `processBatch(array $context, array $source, array $cursor, int $limit): BatchResult` | Process no more than `$limit` source history events and return the next cursor and counters. |
| `finish(array $context, array $source, array $cursor, array $counts): void` | Final, repeatable writes, such as applying current wording if requested. Runs in the final batch transaction. |

Context contains `shop_id`, `language_id` and `apply_wording`. Do not replace those with the current browser customer or an implicit shop. `source` holds a frozen maximum event ID and small definition snapshots. `cursor` holds progress, such as the last event ID and prepared wording-version IDs. Avoid keeping an array of every customer or event in either field.

Use a stable unique key for pagination, for example `WHERE event_id > :last AND event_id <= :maximum ORDER BY event_id LIMIT :limit`. Do not use OFFSET. Count the same eligible source range in `plan()` that the batches will read. Read fresh database values rather than cached query results.

```php
use Module\CciConsentHistory\Import\BatchResult;

// Example after reading and persisting a bounded batch of source events:
return new BatchResult(
    cursor: ['last_id' => $lastProcessedId],
    counts: [
        'processed' => count($events),
        'added' => $added,
        'skipped' => $alreadyPresent,
        'rejected' => $refusalsArchived,
        'unmatched' => $addedWithoutCustomer,
        'errors' => $explicitlyArchivedErrors,
    ],
    done: $rangeExhausted,
    phase: 'history',
);
```

The counters above are increments for this batch, not lifetime totals. Missing counters default to zero. Values must be non-negative integers. `processed` must equal `added + skipped + rejected + errors`. `unmatched` is an additional diagnostic count and must not exceed `processed`. In the X13 adapter it counts newly added acceptances without a matching customer, not unique customers. `rejected` counts non-affirmative events preserved in the audit table, never an accepted account consent.

A preparation batch may process zero history events, but must advance its cursor. A non-final batch with an unchanged cursor is rejected. Valid phases are `preparing`, `history` and `finishing`. Set `done` only after exhausting the frozen range, including an empty range. The manager rejects completion unless processed equals the planned total; changed or deleted source rows must not produce a false 100% result.

### Optional availability check

An adapter can also implement `ImportAvailabilityInterface::availability(array $context): array`. Return `available` as a boolean and `reason` as `null`, `source_unavailable` or `definitions_missing`. Check the selected shop and language, required source tables and supported account-consent definitions. Keep this check inexpensive and read-only: do not count all events or write a checkpoint. The back office keeps unavailable sources visible, explains the reason and disables starting that source. Other integrations remain selectable. Existing contract-v1 adapters continue to work; their read-only `plan()` is used as the availability check. The manager checks again when starting the job.

Use the source product's official name in `getLabel()`. Do not invent a translated product name. For example, the built-in adapter uses `GDPR - regulacje ochrony danych RODO (X13)` in every locale.

## 4. Let the manager own transactions

Every batch's destination writes and checkpoint commit together. Do not begin or commit transactions, run DDL, change table engines, send email, or call external write APIs inside adapter methods. The database cannot roll those external actions back. Prepare your own schema before starting a job. Do not change PrestaShop core table definitions.

Throw `ImportException('your_safe_error_code')` on an error that should stop the job. A failed batch is rolled back, keeps its previous cursor and becomes resumable. Other exceptions become the public code `batch_failed`; SQL and personal source data are not returned to the browser. Do not catch and ignore failed writes. If an adapter deliberately archives an invalid event and counts it as `errors`, document that policy; do not silently count it as accepted.

The manager uses database named locks to prevent two workers from processing the same job at once. Browser and CLI processing can coexist. Only one active import is allowed per shop, including paused or failed jobs. Starting another source in that shop returns the existing job; end or complete it before selecting another source. Pause and end wait for the current batch's lock; an ended job retains committed batches. Ending is not a database rollback. Restoring a backup remains an explicit shop-maintenance operation.

## 5. Preserve evidence and make reruns safe

Save wording with `CciConsentHistoryRepository::ensureImportedConsentVersion($shop, $language, $historicalHtml, $sourceModule, $originalWordingDate)` and check the returned row. Reject missing or invalid source dates. The repository's live `recordAcceptance()` uses the current time and is unsuitable for historical events.

For each affirmative event with verified wording, insert an acceptance with its original date, destination shop/language/customer, `purpose_key = account_privacy`, version ID, source identifiers and source metadata. Use a source-specific `acceptance_source` (maximum 32 characters) and immutable `source_reference` (maximum 96 characters) containing the source installation, shop and event ID. The destination unique key prevents duplicate source references. Check that an existing reference belongs to the expected context; count it as skipped, never overwrite it.

The built-in X13 implementation is in `integrations/x13/CciX13ConsentImporter.php`. Its `register.php` entry registers it through the same importer contract as an external integration. Source-specific storage is in `integrations/x13/CciX13ConsentStorage.php`. New references include the shop, and earlier `x13_history:<id>` references remain recognized in their original shop. An adapter for another source needs its own mapping and wording checks; copying only the table names is not sufficient.

## 6. Read normalized job status

The manager's `start()`, `status()`, `step()` and `control()` return the same versioned public structure:

```json
{
  "schema_version": 1,
  "job_id": "32-character-hex-id",
  "importer": "vendor_account_privacy",
  "status": "running",
  "phase": "history",
  "total": 10000,
  "counts": {"processed": 250, "added": 240, "skipped": 5, "rejected": 5, "unmatched": 2, "errors": 0},
  "percent": 2,
  "can_continue": true,
  "can_resume": false,
  "can_cancel": true,
  "failed_batches": 0,
  "error_code": null,
  "updated_at": "2026-10-19 12:00:00"
}
```

Possible states: `queued`, `running`, `paused`, `failed`, `completed`, `cancelled`. Continue only when `can_continue` is true. A held worker lock adds `locked: true`; wait before checking again. Resume a paused or failed job explicitly. Only `completed` reports 100%. `failed_batches` counts rolled-back attempts; `counts.errors` counts deliberately handled source events and means something different.

The back-office routes require a logged-in employee and native module permissions. Writes require update permission and the `cci_consent_import` CSRF token. Status requires read permission. Job reads and writes are scoped to the selected shop. These routes are not a public import API. A separate CLI command needs operating-system access to the shop.

## 7. Verify the integration

Test realistic source volume, repeat imports, empty ranges, missing wording, invalid dates, unmatched customers, different shops, pause/resume and concurrent workers. Interrupt a write in the middle of a batch and confirm that both evidence and cursor roll back. Delete a source row after planning and confirm that the job stops instead of announcing completion. Refresh the browser and confirm it reads the saved job rather than creating another one.

The repository includes `tests/import-integration.php`. With PDO MySQL available, set `CCI_IMPORT_TEST_PARAMETERS` to a PrestaShop `app/config/parameters.php` and run it in a disposable local environment. It creates a random fixture prefix, runs the real repository, manager and X13 adapter against MySQL, then removes only its own fixture tables. It never modifies shop data. The default source contains 100,000 fictional events.

For shop-owner instructions and bounded cron workers, see [Import earlier consents](import-consents.md). Cool Cat Ideas also offers assisted imports and custom source integrations through [the contact form](https://coolcatideas.com/contact/).
