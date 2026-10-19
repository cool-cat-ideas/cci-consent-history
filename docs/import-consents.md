# Import earlier account consents

You can use CCI Consent History normally without importing anything. Use this option only when moving earlier account-consent records from another module.

## Start in the back office

1. Save a current database backup. Check that you can restore it.
2. Open **Cool Cat Ideas > CCI Consent History** and select one shop if multistore is enabled.
3. Read the optional integration preview. For X13, the old source tables must be in the same database and customer IDs must still refer to the same people.
4. In **Import earlier consents**, select the source integration.
5. Select **Use the source account-consent wording after the import finishes** only if this wording should replace the account notice in Official GDPR Compliance. It is off by default.
6. Confirm the backup and choose **Start import**.
7. Watch the progress and counters. Each browser request saves up to 250 history records. **Added** counts acceptances; refusals are archived separately. **Added without a matched customer** flags records that need review. It does not mean the module guessed a customer.
8. When the state is **Completed**, choose **Refresh the acceptance history** and check a few customers, original dates and wording snapshots.

The import keeps existing history and source tables. Running it again skips source events already imported. Keep the source unchanged until the job completes. The built-in X13 adapter covers account-consent records, not newsletter, analytics, advertising, deletion or anonymisation workflows.

## Pause, return or recover

Choose **Pause** before leaving. After the current batch is saved, the job stops. Reopening or refreshing the page shows the same saved progress. Choose **Resume from saved progress** to continue a paused job. An active job continues when this page is open.

Closing the page stops browser processing after the current request. It does not create a background server process. Use a CLI or cron worker below when the import must continue without the browser.

If the connection drops, choose **Reconnect and check saved progress**. The server may already have saved the last request. Check its state before doing anything else. If your login expired, reload the page and sign in again.

If a batch fails, earlier batches remain saved and the failed batch is rolled back. Check the displayed error code, source tables and database access. Then choose **Resume from saved progress**. Missing or invalid wording dates must be corrected in the source or integration, not replaced with the import date. A changed source range can require ending the job and planning a new import.

**End this import** stops future batches. It keeps records already saved. It does not restore a backup or remove imported history. A new import can be started later and will skip the same source references.

## Run without an open browser

Ask the person managing your server to run the following from the PrestaShop directory. Replace shop and language IDs with your shop's actual values. Save a backup first.

```sh
php bin/console cci:consent-history:import --source=x13 --shop=1 --language=1 --backup-confirmed --max-batches=100
```

Add `--apply-wording` only when the source wording should become the current account notice after completion. The command prints the job ID and status. It saves up to 100 batches of 250 records, then exits. Reuse the printed job ID to continue:

```sh
php bin/console cci:consent-history:import --shop=1 --job=YOUR_JOB_ID --max-batches=100
```

Check without importing:

```sh
php bin/console cci:consent-history:import --shop=1 --job=YOUR_JOB_ID --status
```

To retry a paused or failed job after fixing its cause, add `--resume`. You can reduce batch size with `--batch-size=100` (allowed range 1-500). Use the shop's normal PHP CLI executable and environment. These commands do not need a web session.

For cron, run the continuation command with the same job ID and a bounded `--max-batches` value. Choose a schedule suited to the server. Concurrent runs do not process the same job's batch twice. A completed or ended job does no more work. A failed job requires an explicit resume; cron does not silently ignore the error. Remove the cron entry after checking completion.

## Need another source?

Cool Cat Ideas can help move consent history, match customers or write an integration for the module used in your shop. [Tell us which module and PrestaShop version you use](https://coolcatideas.com/contact/). A developer can also follow the [integration guide](custom-integrator.md).

## If the source is unavailable

An unavailable integration stays in the source list with an explanation. Its confirmation boxes and Start import button are disabled. Choose another available integration, or check the required source tables and wording for the selected shop, then reload the page. Missing X13 tables do not stop normal consent recording or an integration for another source. Documentation and the product page are linked at the top of the module.
