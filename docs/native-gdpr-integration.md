# Native Official GDPR Compliance integration

CCI Consent History extends `psgdpr`. It does not change PrestaShop or `psgdpr` files and does not add columns to native tables. Versions and acceptance snapshots use the module's own tables.

## Supported sources

| Source | Saved wording | When an acceptance is recorded |
| --- | --- | --- |
| Account creation | `PSGDPR_CREATION_FORM` | Native `actionCustomerAccountAdd` |
| Account renewal | Current account-creation notice | Successful acceptance through the renewal controller |
| Customer profile | `PSGDPR_CUSTOMER_FORM` | Native `actionCustomerAccountUpdate` after a successful authenticated identity-form save |
| Native newsletter and partner choices | Rendered checkbox label and conditions | Successful customer registration/profile save; choice grants and withdrawals |
| Customer data privacy | Rendered `customer_privacy` label | Successful customer registration/profile save with the checkbox checked |
| Registered module form | `GDPRConsent::getConsentMessage()` | A checked native GDPR checkbox is submitted through its form handler |

Account privacy and profile privacy have different purpose keys. A changed profile or module-form notice does not trigger the mandatory account-access renewal prompt.

A generic form's purpose key is `gdpr_form_<module_name>`. The module must be enabled in the current shop, registered on `registerGDPRConsent` there, and enabled in `GDPRConsent`. Its form must render `displayGDPRConsent` with `id_module`.

## Hooks registered by CCI Consent History

- `actionCustomerAccountAdd`: records the current registration notice for the created customer.
- `displayCustomerAccountForm`: adds a signed hidden profile snapshot on the authenticated `identity` page.
- `additionalHtmlAttributesFormFields`: attaches signed metadata to registered customer checkbox labels without overriding a theme.
- `actionObjectCustomerUpdateBefore`: reads persisted choices before the customer update.
- `actionCustomerAccountUpdate`: records profile privacy and registered customer fields only after the native profile save succeeds.
- `displayGDPRConsent`: adds hidden snapshot metadata alongside the native checkbox. It leaves the checkbox to `psgdpr` and the theme.
- `actionFrontControllerSetMedia`: loads the small native-form adapter and existing account-renewal assets.
- `displayCustomerAccount`: links to the current customer's history.
- `actionExportGDPRData`: returns flat purpose, status, date, version, language, source and wording fields for the native CSV/PDF exporter.

Open the module in the back office once after replacing an older installation's files. This registers missing hooks without resetting the module or deleting history.

## Profile save

The hidden `cci_profile_consent_snapshot` has the form `version_id.nonce.signature`. Its HMAC binds the original wording version to the customer, shop, language, purpose and nonce. A successful profile save records only a checked `psgdpr` field with a matching signature. A repeated submission of the same form is idempotent.

The native checkbox starts unchecked when the identity form is opened again. This does not remove the earlier acceptance. Newsletter and partner-offer fields persist their choices separately in the native customer record.

## Module-form request

The adapter retains each theme's existing submission mechanism. In Classic it augments the native GDPR jQuery request for the form being submitted. In Hummingbird it adds the snapshot to that checkbox's `data-ps-data` configuration. Both use the CCI `record` front controller, which delegates the native log entry to `GDPRLog::addLog()` and stores the historical wording.

The POST request uses the native `action=AddLog`, `id_module`, `id_customer`, `customer_token`, `id_guest` and `guest_token` fields, plus `cci_gdpr_snapshot`. Tokens are required. The authenticated customer must match the request. The snapshot HMAC also binds the wording version, source module, shop, language and browser identity. Guest snapshots use a random value inside PrestaShop's encrypted browser cookie, including when no native guest/cart record exists. They do not require an account or create one.

The endpoint returns JSON with `recorded: true` on HTTP 200. Invalid or mismatched requests return HTTP 403. Persistence errors return HTTP 500. It does not accept arbitrary wording from the browser. The source must remain enabled when the request is processed. Reusing the same signed form snapshot is idempotent.

This records the native privacy-acceptance event. It does not assert that a contact message was delivered, a stock alert was scheduled or a newsletter subscription was confirmed. Source modules keep responsibility for those workflows.

## Historical and guest data

Each version preserves its original wording. A settings change between rendering and submission does not replace the text accepted in that form. Old native logs have no historical wording, so they are not silently backfilled with today's text.

Anonymous records stay guest records. They are not automatically linked to the next account that logs in on that browser. Customer history queries and exports require a positive customer ID and the current shop; guest ID zero never acts as a wildcard.

Modules with custom checkboxes, cookies and checkout terms need explicit event integrations. Registering a module on `registerGDPRConsent` alone is not enough. Earlier-record import adapters use the separate [import contract](custom-integrator.md).

## Customer-form source contract, version 1

The native GDPR module registry supplies all standard `displayGDPRConsent` form sources dynamically. Do not add a separate adapter for each GDPR-enabled module. A custom checkbox in the native customer form is different: it must declare whether it is a persisted choice or a per-submission acknowledgement. A login preference or an unrelated checkbox must not become a consent event by guessing its name.

CCI Consent History supplies definitions for native `newsletter`, `optin` and `customer_privacy` fields. Other modules register the hook `actionCciConsentHistoryRegisterCustomerSources` and add their fields to the same registry:

```php
public function hookActionCciConsentHistoryRegisterCustomerSources(array $params): void
{
    $registry = $params['registry'] ?? null;
    if (
        !$registry instanceof CciCustomerConsentSourceRegistry
        || $registry::CONTRACT_VERSION !== 1
    ) {
        return;
    }

    $registry->register(
        'sms_permission',
        'example_sms_marketing',
        $this->name,
        'choice',
        function (int $idCustomer, Context $context): ?bool {
            return $this->readSavedSmsChoice($idCustomer, (int) $context->shop->id);
        }
    );
}
```

`register(field, purpose, module, kind, readState)` accepts:

- `field`: exact native `FormField` name. Its type must be `checkbox` and its submitted checked value must be `1`.
- `purpose`: a stable source-specific key, at most 64 lowercase letters, digits or underscores. Prefix custom keys with your module name to avoid collisions.
- `module`: the technical name of the source module. It must remain enabled in the current shop. `prestashop` identifies built-in customer fields.
- `kind`: `choice` for a grant that can later be withdrawn, or `acknowledgement` for a confirmation recorded on each successful save.
- `readState`: optional callable receiving the saved customer ID and current `Context`. Return the actual persisted `bool`; return `null` when it cannot be verified. If supplied, a non-boolean value or a mismatch with the submitted choice prevents recording. Save the source state before the CCI successful-save hook reads it.

Without a state reader, a choice records an explicit checked submission after the native customer form succeeds. An unchecked submission records a withdrawal only when a previous grant is known to CCI. Add a reader when the source has its own persistence or confirmation rules. A source that uses double opt-in should record confirmed subscription events in its own workflow; the form checkbox alone does not prove email confirmation.

The exact rendered label, including explanatory text, is saved as a version. The registry does not supply replacement wording. Names for custom customer purposes come from the saved field label. Names for standard GDPR module forms come from the installed module's `displayName`. The history engine has no fixed list of third-party module names.

The hidden `cci_customer_consent[field]` snapshot binds the version, field, purpose, module, kind, shop, language, customer or anonymous registration browser, and a nonce. Only a successful native form save consumes it. Another account, field or source cannot reuse it. Retrying the same signed submission does not duplicate its event.

Event sources are `customer_choice_granted`, `customer_choice_withdrawn` and `customer_field_accepted`. The source reference distinguishes `profile:` and `registration:`. Unchanged saved marketing choices are not repeated. A withdrawal refers to the previous grant's wording when it is known. Existing native flags without historical evidence are never assigned a fabricated earlier date.
