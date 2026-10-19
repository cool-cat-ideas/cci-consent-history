{**
 * Mandatory renewed account privacy acknowledgement.
 *
 * @license https://opensource.org/license/afl-3-0-php Academic Free License 3.0 (AFL-3.0)
 *}
<div class="cci-consent-history-overlay" data-cci-consent-history-overlay>
  <section
    class="cci-consent-history-dialog"
    role="dialog"
    aria-modal="true"
    aria-labelledby="cci-consent-history-title"
    aria-describedby="cci-consent-history-description"
    tabindex="-1"
    data-cci-consent-history-dialog
  >
    <span class="cci-consent-history-dialog-icon material-icons" aria-hidden="true">privacy_tip</span>
    <p id="cci-consent-history-title" class="cci-consent-history-dialog-title">
      {l s='Please review the updated privacy information' d='Modules.Cciconsenthistory.Shop'}
    </p>
    <p id="cci-consent-history-description" class="cci-consent-history-dialog-intro">
      {l s='The account privacy wording has changed since your previous visit. Read the new version before continuing.' d='Modules.Cciconsenthistory.Shop'}
    </p>

    <div class="cci-consent-history-wording">
      {$cci_consent_history_version.content_html nofilter}
    </div>

    {if $cci_consent_history_error}
      <div class="alert alert-danger" role="alert">
        {$cci_consent_history_error|escape:'htmlall':'UTF-8'}
      </div>
    {/if}

    <form method="post" action="{$cci_consent_history_action|escape:'htmlall':'UTF-8'}" data-cci-consent-history-form>
      <input type="hidden" name="consent_version" value="{$cci_consent_history_version.id_cci_consent_version|intval}">
      <input type="hidden" name="consent_token" value="{$cci_consent_history_token|escape:'htmlall':'UTF-8'}">
      <input type="hidden" name="return_to" value="{$cci_consent_history_return_to|escape:'htmlall':'UTF-8'}">

      <label class="cci-consent-history-choice">
        <input type="checkbox" name="accept_consent" value="1" required data-cci-consent-history-checkbox>
        <span>{l s='I have read and accept the updated account privacy information.' d='Modules.Cciconsenthistory.Shop'}</span>
      </label>

      <div class="cci-consent-history-actions">
        <a class="cci-consent-history-logout" href="{$cci_consent_history_logout_url|escape:'htmlall':'UTF-8'}">
          {l s='Log out' d='Modules.Cciconsenthistory.Shop'}
        </a>
        <button class="btn btn-primary cci-consent-history-accept" type="submit" disabled data-cci-consent-history-submit>
          {l s='Accept and continue' d='Modules.Cciconsenthistory.Shop'}
        </button>
      </div>
    </form>
  </section>
</div>
