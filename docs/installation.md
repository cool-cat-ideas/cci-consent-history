# Installation

Download the module ZIP from the [Cool Cat Ideas product page](https://coolcatideas.com/products/cci-consent-history/).

## Before you install

1. Open **Modules > Module Manager**.
2. Check that **Official GDPR Compliance** is installed and enabled.
3. Open its settings.
4. Enable the account creation consent.
5. Enter the account privacy wording for every language used by the shop.

## Install the module

1. In **Modules > Module Manager**, choose **Upload a module**.
2. Select `cci_consent_history-1.0.0.zip`.
3. After installation, open **Cool Cat Ideas > CCI Consent History**.
4. Check that **Current wording** shows `v1`.
5. Leave **Require acceptance after a wording change** enabled and save.

The first wording is a starting point. Customers who already had accounts before installation can continue without being stopped. New accounts are recorded from this point forward.

The optional import section is separate from normal consent history. Use it only when migrating from “GDPR - regulacje ochrony danych RODO” by Polish module vendor X13. Read [import earlier account consents](x13-migration.md) and make a database backup first. Records imported earlier remain available without the old source tables.
