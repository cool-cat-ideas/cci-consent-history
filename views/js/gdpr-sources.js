/**
 * Attach signed wording snapshots to submissions from native GDPR checkboxes.
 * @license https://opensource.org/license/afl-3-0-php Academic Free License 3.0 (AFL-3.0)
 */
(() => {
  let submittedForm = null;

  const bindHummingbirdSources = () => {
    document.querySelectorAll('input[data-cci-gdpr-module]').forEach((metadata) => {
      const scope = metadata.closest('form') || metadata.parentElement;
      if (!scope) return;
      scope.querySelectorAll('[data-ps-ref="gdpr-consent"]').forEach((element) => {
        try {
          const data = JSON.parse(element.dataset.psData || '{}');
          if (String(data.id_module) !== metadata.dataset.cciGdprModule) return;
          const native = new URL(metadata.dataset.cciGdprNative, window.location.href);
          const target = new URL(metadata.dataset.cciGdprRecord, window.location.href);
          const current = new URL(data.front_controller, window.location.href);
          if (native.origin !== window.location.origin || target.origin !== window.location.origin)
            return;
          if (current.href !== native.href && current.href !== target.href) return;
          data.front_controller = target.href;
          data.cci_gdpr_snapshot = metadata.value;
          element.dataset.psData = JSON.stringify(data);
        } catch {
          // Leave unsupported theme markup under the native module's control.
        }
      });
    });
  };

  document.addEventListener(
    'submit',
    (event) => {
      bindHummingbirdSources();
      submittedForm = event.target instanceof HTMLFormElement ? event.target : null;
      window.setTimeout(() => {
        submittedForm = null;
      }, 0);
    },
    true,
  );

  document.addEventListener('click', bindHummingbirdSources, true);

  const install = () => {
    bindHummingbirdSources();
    const jquery = window.jQuery;
    if (!jquery || window.cciGdprSnapshotInstalled) return;
    window.cciGdprSnapshotInstalled = true;

    jquery.ajaxPrefilter((options) => {
      if (!submittedForm || typeof options.data !== 'string') return;
      if ((options.type || 'GET').toUpperCase() !== 'POST') return;

      const data = new URLSearchParams(options.data);
      if (data.get('action') !== 'AddLog') return;
      const moduleId = data.get('id_module');
      if (!moduleId || !/^\d+$/.test(moduleId)) return;

      const metadata = submittedForm.querySelector(`input[data-cci-gdpr-module="${moduleId}"]`);
      const checkbox = submittedForm.querySelector(
        `.gdpr_module_${moduleId} input[name="psgdpr_consent_checkbox"]`,
      );
      if (!metadata || !checkbox?.checked) return;

      const requested = new URL(options.url, window.location.href);
      const native = new URL(metadata.dataset.cciGdprNative, window.location.href);
      const target = new URL(metadata.dataset.cciGdprRecord, window.location.href);
      if (requested.origin !== window.location.origin) return;
      if (native.origin !== requested.origin || target.origin !== requested.origin) return;
      if (requested.pathname !== native.pathname) return;
      if (
        native.pathname.endsWith('/index.php') &&
        ['fc', 'module', 'controller'].some(
          (key) => requested.searchParams.get(key) !== native.searchParams.get(key),
        )
      )
        return;

      data.set('cci_gdpr_snapshot', metadata.value);
      options.data = data.toString();
      options.url = target.href;
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', install, { once: true });
  } else {
    install();
  }
})();
