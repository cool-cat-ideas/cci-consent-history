# CCI Consent History for PrestaShop GDPR Module

Use this add-on when you need to answer a simple question: which account consent wording did this customer accept, and when?

PrestaShop's Official GDPR Compliance module (`psgdpr`) still owns the consent field and its wording. CCI Consent History watches that wording, saves each version and links every acceptance to the exact text, language, shop and acceptance date.

The module name stays in English in every language. Polish back-office translations are included.

When the wording changes, the add-on creates a new version. A logged-in customer can accept it before continuing or log out. Store employees can search the history by customer name or email and open the exact wording snapshot. Each shop and language keeps its own versions.

The first wording found after installation is the starting version, so existing customers are not blocked immediately. The add-on starts requiring renewed acceptance after a later wording change.

An optional integration can import earlier account consent records from **GDPR - regulacje ochrony danych RODO**, a GDPR module by **X13**, a Polish PrestaShop module vendor. Skip it if your shop did not use that module. Read [import earlier account consents](x13-migration.md) before running it. Developers can follow [write another importer](custom-integrator.md) for a different source.

Start with [installation](installation.md), then read [daily use](daily-use.md). If something does not appear, use [troubleshooting](troubleshooting.md). If you still need help, choose the right route on the [Support](support.md) page.
