{**
 * Metadata for the native GDPR checkbox in the same form.
 * @license https://opensource.org/license/afl-3-0-php Academic Free License 3.0 (AFL-3.0)
 *}
<input type="hidden" name="cci_gdpr_snapshot_{$cci_gdpr_snapshot.module_id|intval}"
  value="{$cci_gdpr_snapshot.snapshot|escape:'html':'UTF-8'}"
  data-cci-gdpr-module="{$cci_gdpr_snapshot.module_id|intval}"
  data-cci-gdpr-native="{$cci_gdpr_snapshot.native_url|escape:'html':'UTF-8'}"
  data-cci-gdpr-record="{$cci_gdpr_snapshot.record_url|escape:'html':'UTF-8'}">
