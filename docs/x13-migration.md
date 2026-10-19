# Optional integration: import earlier account consents

This optional integration supports **GDPR - regulacje ochrony danych RODO**, a GDPR module from **X13**, a Polish PrestaShop module vendor. Use it only if the old shop used that product and its tables still exist in the upgraded PrestaShop database. Normal consent history and renewal do not depend on this integration.

The importer moves only:

- account GDPR consent definitions;
- the wording saved for each language;
- accepted and rejected source events in the audit archive;
- accepted events into the customer consent history;
- the original acceptance date, customer ID, guest/cart IDs and IP address when available.

It does not move newsletter, analytics or advertising consents. It also does not move data-access requests, anonymisation requests or data-removal workflows.

## Before you start

1. Make a current database backup.
2. Install and enable **Official GDPR Compliance**.
3. Configure its account creation consent.
4. Install **CCI Consent History**.
5. Confirm that the old X13 consent tables are present in the same database.

The importer matches a historical event to the customer with the same customer ID. It does not guess by name or a similar email address. This is deliberate: a guessed match would make the audit trail unreliable.

## Run the import

1. Open **Cool Cat Ideas > CCI Consent History**.
2. Find **Import earlier consents** and select **GDPR - regulacje ochrony danych RODO (X13)**.
3. Read the source description and any availability notice. If tables or wording are missing, check the source before continuing.
4. Select **Use the source account-consent wording after the import finishes** only if that wording should replace the current account notice.
5. Select **I have a current database backup**.
6. Choose **Start import** and follow the progress. You can pause, reload and resume from the saved batch.
7. After completion, choose **Refresh the acceptance history**.
8. Search for a customer who accepted the old consent and open **Show wording**.

Running the importer again skips events already recorded. Both current `x13_shop:<shop>:history:<id>` references and earlier `x13_history:<id>` references are recognized in their original shop. The importer leaves source tables and earlier CCI history in place. See [Import earlier consents](import-consents.md) for CLI and cron workers.

## When the old shop used another database

Do not use the built-in importer if customer IDs changed or the source tables remain only in a separate dump. Prepare an assisted SQL migration that contains an explicit customer map and a rollback copy. This avoids linking consent history to the wrong person.

CCI Consent History is not affiliated with X13. This importer targets the consent table structure used by the X13 product named **GDPR - regulacje ochrony danych RODO**.

For another source module, see [write a consent-history importer](custom-integrator.md).

## Records imported earlier from a dump

A shop-specific migration may have read the old records from an SQL dump and saved them in CCI Consent History without copying the old module's tables into the shop database. Those imported records remain available even when the optional importer cannot find its source tables.

Check the acceptance history and the imported-source count before planning any further migration. Missing source tables do not mean missing history. Do not rerun an earlier shop migration just to make this integration available; a dump migration may replace existing history. Normal account recording and renewed acceptance do not require the old tables.
