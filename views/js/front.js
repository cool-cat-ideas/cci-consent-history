(function () {
  'use strict';

  function initialiseConsentDialog() {
    var overlay = document.querySelector('[data-cci-consent-history-overlay]');
    var dialog = document.querySelector('[data-cci-consent-history-dialog]');
    var form = document.querySelector('[data-cci-consent-history-form]');
    var checkbox = document.querySelector('[data-cci-consent-history-checkbox]');
    var submit = document.querySelector('[data-cci-consent-history-submit]');

    if (!overlay || !dialog || !form || !checkbox || !submit) {
      return;
    }

    document.documentElement.classList.add('cci-consent-history-locked');
    document.body.classList.add('cci-consent-history-locked');

    var updateSubmitState = function () {
      submit.disabled = !checkbox.checked;
    };

    checkbox.addEventListener('change', updateSubmitState);
    updateSubmitState();

    form.addEventListener('submit', function () {
      if (checkbox.checked) {
        submit.disabled = true;
        submit.classList.add('cci-consent-history-submitting');
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        event.preventDefault();
        return;
      }

      if (event.key !== 'Tab') {
        return;
      }

      var focusable = dialog.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled])',
      );
      if (!focusable.length) {
        event.preventDefault();
        dialog.focus();
        return;
      }

      var first = focusable[0];
      var last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });

    window.requestAnimationFrame(function () {
      checkbox.focus();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialiseConsentDialog);
  } else {
    initialiseConsentDialog();
  }
})();
