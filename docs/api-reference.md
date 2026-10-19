# API reference

The first release does not expose a stable public PHP extension API or an importer registry. Normal reporting should read the tables without changing them. A migration integration can use the internal, version-specific repository methods and controlled acceptance writes described in [write a consent-history importer](custom-integrator.md). Pin the module version and test the integration again before upgrading it.

## Acceptance controller

Route: `module/cci_consent_history/accept`

Method: `POST`

Fields:

- `consent_version` (`int`): current immutable version ID;
- `consent_token` (`string`): customer and version-bound HMAC token;
- `accept_consent` (`1`): required checkbox value;
- `return_to` (`string`): same-site relative path used after a successful save.

The controller reloads the current server-side wording before writing. It rejects an old version ID, a missing checkbox, an invalid token and an unauthenticated request.
