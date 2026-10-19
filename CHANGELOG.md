# Changelog

## 1.0.0

- Require POST for renewed acceptance and prevent caching of personal history and consent-record responses.

- Record native newsletter and partner-offer choices, including withdrawals, and the separate customer-data privacy acknowledgement.
- Provide a versioned registration contract for custom customer-form consent sources.
- Show each event purpose and status in customer history, the back-office list and native personal-data exports.
- Link the settings of installed Official GDPR Compliance and Customer data privacy block modules from the header.

- Record the separate profile privacy notice after a successful customer-profile save.
- Keep purpose-specific history for native GDPR form submissions, including guest records.
- Support native GDPR checkboxes in Classic and Hummingbird without modifying Official GDPR Compliance.
- Preserve the wording shown in each form with signed snapshots and reject forged or mismatched requests.

- Let signed-in customers review their own consent dates, versions and saved wording from My account.
- Include consent history in the native Official GDPR Compliance CSV and PDF exports.

- Explain source availability before import, disable unavailable sources and keep other integrations selectable.
- Link the documentation and product page from the module, and improve import spacing, controls and import-service information.

- Import in checkpointed batches with progress, pause, resume, cancellation and bounded CLI/cron processing.
- Provide importer contract version 1 and a registration hook for other consent sources.
- Preserve earlier X13 source references on reruns, scope new references by shop, and roll back failed batches with their checkpoints.
- Offer help with consent imports and custom source integrations from the back office.

- Save immutable versions of the account privacy wording.
- Record consent at account creation and after a wording update.
- Ask logged-in customers to accept changed wording before continuing.
- Show searchable acceptance history in the Cool Cat Ideas menu.
- Keep separate wording versions for every shop and language.
- Import account GDPR consent wording and historical acceptances from X13 without creating duplicates.
- Use the native PrestaShop Grid for history search, sorting and pagination.
- Support PrestaShop 9.0.0-9.2.0.
- Explain the optional consent migration integration separately from normal history, retain the imported-record count when source tables are absent, and document custom importers.
