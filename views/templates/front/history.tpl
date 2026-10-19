{**
 * Consent history for the current customer and shop.
 * @license https://opensource.org/license/afl-3-0-php Academic Free License 3.0 (AFL-3.0)
 *}
{extends file='customer/page.tpl'}

{block name='page_title'}
  {l s='Consent history' d='Modules.Cciconsenthistory.Shop'}
{/block}

{block name='page_content'}
  <div class="cci-customer-history">
    <p>{l s='Review your recorded privacy acceptances and consent choices. Each entry keeps the purpose, status, wording and date saved at the time.' d='Modules.Cciconsenthistory.Shop'}</p>
    {if $consent_history_data_url}
      <p><a class="btn btn-outline-primary" href="{$consent_history_data_url|escape:'htmlall':'UTF-8'}">{l s='Download my personal data' d='Modules.Cciconsenthistory.Shop'}</a></p>
    {/if}

    {if $consent_history}
      <p class="text-muted">{l s='Saved events: %count%' sprintf=['%count%' => $consent_history_total] d='Modules.Cciconsenthistory.Shop'}</p>
      {foreach from=$consent_history item=entry}
        <article class="cci-customer-history-entry">
          <h2 class="h5">{$entry.purpose_label|escape:'html':'UTF-8'}</h2>
          <p><strong>{l s='Status' d='Modules.Cciconsenthistory.Shop'}:</strong> {$entry.status_label|escape:'html':'UTF-8'}</p>
          <dl class="cci-customer-history-meta">
            <div>
              <dt>{l s='Recorded on' d='Modules.Cciconsenthistory.Shop'}</dt>
              <dd><time datetime="{$entry.accepted_at|replace:' ':'T'|escape:'html':'UTF-8'}">{dateFormat date=$entry.accepted_at full=1}</time></dd>
            </div>
            <div>
              <dt>{l s='Version' d='Modules.Cciconsenthistory.Shop'}</dt>
              <dd>{$entry.version_number|intval}</dd>
            </div>
            <div>
              <dt>{l s='Language' d='Modules.Cciconsenthistory.Shop'}</dt>
              <dd>{$entry.language_name|escape:'html':'UTF-8'}</dd>
            </div>
            <div>
              <dt>{l s='Source' d='Modules.Cciconsenthistory.Shop'}</dt>
              <dd>{$entry.source_label|escape:'html':'UTF-8'}</dd>
            </div>
          </dl>
          <details class="cci-customer-history-wording">
            <summary>{l s='Read saved wording' d='Modules.Cciconsenthistory.Shop'}</summary>
            <div>{$entry.content_text|escape:'html':'UTF-8'}</div>
          </details>
        </article>
      {/foreach}
      {if $consent_history_pages > 1}
        <nav class="cci-customer-history-pagination" aria-label="{l s='Consent history pages' d='Modules.Cciconsenthistory.Shop'}">
          {if $consent_history_previous}<a class="btn btn-outline-primary" rel="prev" href="{$consent_history_previous|escape:'htmlall':'UTF-8'}">{l s='Previous' d='Modules.Cciconsenthistory.Shop'}</a>{/if}
          <span>{l s='Page %page% of %pages%' sprintf=['%page%' => $consent_history_page, '%pages%' => $consent_history_pages] d='Modules.Cciconsenthistory.Shop'}</span>
          {if $consent_history_next}<a class="btn btn-outline-primary" rel="next" href="{$consent_history_next|escape:'htmlall':'UTF-8'}">{l s='Next' d='Modules.Cciconsenthistory.Shop'}</a>{/if}
        </nav>
      {/if}
    {else}
      <div class="alert alert-info" role="status">{l s='No consent events have been recorded for your account in this shop.' d='Modules.Cciconsenthistory.Shop'}</div>
    {/if}
  </div>
{/block}
