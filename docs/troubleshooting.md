# Troubleshooting

## Current wording shows a dash

Open **Official GDPR Compliance** and check two things for the current language:

- the account creation consent switch is enabled;
- the wording field is not empty.

Save it, then return to **Cool Cat Ideas > CCI Consent History**.

## A changed wording does not open after login

1. Make sure **Require acceptance after a wording change** is enabled.
2. Confirm that the customer is logged in.
3. Check that the wording really changed in the language used by that customer.
4. Clear the PrestaShop cache and sign in again.

Changing only another language creates a new version for that language. It does not stop customers browsing in a language whose wording stayed the same.

## The acceptance list is empty

Existing customers are not assigned a made-up historical acceptance during installation. The list starts with new account registrations and renewed acceptances recorded after installation.

If the shop used “GDPR - regulacje ochrony danych RODO” by X13, follow [move consent history from X13](x13-migration.md). The import action is available only when all four source tables are present in the same database. Records already imported into CCI remain in the history when those source tables are absent.

## The X13 import section says that source tables are missing

The importer reads the old X13 tables directly. Check that the upgraded shop database contains:

- `privacy_manager_agreement`;
- `privacy_manager_agreement_lang`;
- `privacy_manager_agreement_shop`;
- `privacy_manager_agreement_history`.

The real table names also include your PrestaShop database prefix. If the old data is stored in another database or customer IDs changed, do not copy random rows by hand. Prepare a separate SQL migration with an explicit customer map.

## The customer cannot continue

The acceptance button stays disabled until the checkbox is selected. The customer can use **Log out** without accepting. If the form still fails, check the PrestaShop logs and confirm that both module tables exist.
