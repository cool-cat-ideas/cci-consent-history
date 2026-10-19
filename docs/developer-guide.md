# Developer guide

## Choose the smallest supported mechanism

- Change the visible account wording in `psgdpr`; do not edit this module.
- Use a theme override only when the renewal dialog needs storefront-specific presentation.
- Read the module tables when an internal audit export needs consent snapshots.
- Extend the module only when another consent purpose needs its own version and acceptance workflow.

## Data flow

1. The module reads `PSGDPR_CREATION_FORM_SWITCH` and the translated `PSGDPR_CREATION_FORM` value for the current shop and language.
2. A normalized SHA-256 hash identifies the wording.
3. New wording creates an immutable row in `cci_consent_version`.
4. Account creation and renewed acceptance create rows in `cci_consent_acceptance`.
5. A logged-in customer is blocked only when the current non-baseline version has no matching acceptance.

Normal registration and renewal records resolve customer details when displaying the history. The optional migration integration also keeps source audit fields, including source customer IDs and any available source email or IP. Review the importer’s archive fields when deciding what historical data to copy.

## Optional migration integrations

The included X13 integration is one supported source, not a dependency of consent history. For a different source, follow [write a consent-history importer](custom-integrator.md).
