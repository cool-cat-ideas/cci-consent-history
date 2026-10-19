{**
 * Customer account navigation, styled by the active shop theme.
 * @license https://opensource.org/license/afl-3-0-php Academic Free License 3.0 (AFL-3.0)
 *}
<a class="col-lg-4 col-md-6 col-sm-6 col-xs-12 cci-consent-history-link account-menu__link{if $cci_consent_history_link_active} account-menu__link--active{/if}" href="{$cci_consent_history_url|escape:'htmlall':'UTF-8'}"{if $cci_consent_history_link_active} aria-current="page"{/if}>
  <span class="link-item">
    <i class="material-icons account-menu__icon" aria-hidden="true">history</i>
    <span>{l s='Consent history' d='Modules.Cciconsenthistory.Shop'}</span>
  </span>
</a>
