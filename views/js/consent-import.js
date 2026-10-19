(() => {
  'use strict';
  const root = document.querySelector('[data-cci-import]');
  if (!root) return;
  const labels = root.querySelector('[data-import-labels]').dataset;
  const form = root.querySelector('[data-import-start]');
  const error = root.querySelector('[data-import-error]');
  const reconnect = root.querySelector('[data-import-reconnect]');
  const actions = root.querySelectorAll('[data-action]');
  const canUpdate = root.dataset.canUpdate === '1';
  const sourceSelect = form.elements.importer;
  const sourceNotice = root.querySelector('[data-import-source-notice]');
  const sourceDescription = root.querySelector('[data-import-source-description]');
  const firstAvailable = Array.from(sourceSelect.options).find(
    (option) => option.dataset.available === '1',
  );
  if (firstAvailable) sourceSelect.value = firstAvailable.value;
  let job = null;
  let timer = null;
  let busy = false;
  let connected = false;
  let stopped = false;

  function buttons() {
    const selected = sourceSelect.selectedOptions[0];
    const available = selected?.dataset.available === '1';
    const locked = busy || !connected || !canUpdate || Boolean(job?.can_cancel);
    sourceSelect.disabled = locked;
    form.querySelectorAll('fieldset, input, button').forEach((el) => {
      el.disabled = locked || !available || (el.type === 'submit' && !form.elements.backup.checked);
    });
    sourceNotice.hidden = available;
    sourceDescription.textContent = selected?.dataset.description || '';
    sourceDescription.hidden = !sourceDescription.textContent;
    sourceNotice.textContent = available
      ? ''
      : selected?.dataset.reason === 'definitions_missing'
        ? labels.definitions
        : selected?.dataset.reason === 'select_one_shop'
          ? labels.shop
          : selected?.dataset.notice || labels.unavailable;
    actions.forEach((el) => {
      el.disabled = busy || !connected || !canUpdate;
    });
    reconnect.disabled = busy;
  }

  function render(value) {
    job = value;
    root.querySelector('[data-import-progress]').hidden = !job;
    form.hidden = Boolean(job?.can_cancel);
    if (!job) return;
    root.querySelector('[data-import-source]').textContent =
      Array.from(form.elements.importer.options).find((option) => option.value === job.importer)
        ?.textContent || job.importer;
    root.querySelector('[data-import-state]').textContent = labels[job.status] || job.status;
    root.querySelector('progress').value = job.percent;
    root.querySelector('[data-import-live]').textContent =
      `${labels.processed}: ${job.counts.processed} / ${job.total} (${job.percent}%)`;
    root.querySelectorAll('[data-count]').forEach((el) => {
      el.textContent = String(job.counts[el.dataset.count]);
    });
    const message = root.querySelector('[data-import-message]');
    message.hidden = job.status !== 'failed';
    message.textContent =
      job.status === 'failed' ? `${labels.failure} ${job.error_code || 'batch_failed'}` : '';
    actions.forEach((el) => {
      el.hidden = !{ pause: job.can_continue, resume: job.can_resume, cancel: job.can_cancel }[
        el.dataset.action
      ];
    });
    root.querySelector('[data-import-history]').hidden = job.status !== 'completed';
  }

  async function request(action, fields = {}) {
    const url = action === 'status' ? root.dataset.statusUrl : root.dataset.workUrl;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 60000);
    try {
      const response = await fetch(url, {
        credentials: 'same-origin',
        cache: 'no-store',
        signal: controller.signal,
        headers: { Accept: 'application/json' },
        ...(action === 'status'
          ? {}
          : {
              method: 'POST',
              body: new URLSearchParams({
                _token: root.dataset.token,
                action,
                job: job?.job_id || '',
                ...fields,
              }),
            }),
      });
      if (response.redirected) throw new Error('csrf_expired');
      if (response.status === 403) {
        const data = await response.json().catch(() => ({}));
        throw new Error(data.error === 'csrf_expired' ? 'csrf_expired' : 'permission');
      }
      const data = await response.json();
      if (!response.ok || data.error) throw new Error(data.error || 'network');
      return data.job;
    } finally {
      clearTimeout(timeout);
    }
  }

  async function run(action, fields = {}) {
    if (busy || stopped) return;
    clearTimeout(timer);
    busy = true;
    error.hidden = true;
    buttons();
    try {
      render(await request(action, fields));
      connected = true;
      reconnect.hidden = true;
    } catch (exception) {
      connected = false;
      const code = exception.message;
      error.textContent =
        code === 'csrf_expired'
          ? labels.csrf
          : code === 'permission'
            ? labels.permission
            : code === 'import_busy'
              ? labels.busy
              : ['source_unavailable', 'definitions_missing', 'select_one_shop'].includes(code)
                ? labels.source
                : code === 'backup_required'
                  ? labels.backup
                  : code === 'network' ||
                      exception instanceof TypeError ||
                      exception.name === 'AbortError' ||
                      exception instanceof SyntaxError
                    ? labels.network
                    : `${labels.failure} ${code}`;
      error.hidden = false;
      reconnect.hidden = false;
    } finally {
      busy = false;
      buttons();
    }
    if (connected && !stopped && canUpdate && job?.can_continue) {
      timer = setTimeout(() => run('step'), 1200);
    } else if (connected && !stopped && job?.can_cancel) {
      timer = setTimeout(() => run('status'), 5000);
    }
  }

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const data = new FormData(form);
    if (sourceSelect.selectedOptions[0]?.dataset.available !== '1' || !data.has('backup')) return;
    run('start', {
      importer: data.get('importer'),
      backup: data.has('backup') ? '1' : '0',
      wording: data.has('wording') ? '1' : '0',
    });
  });
  form.addEventListener('change', buttons);
  actions.forEach((el) => el.addEventListener('click', () => run(el.dataset.action)));
  reconnect.addEventListener('click', () => run('status'));
  window.addEventListener('pagehide', () => {
    stopped = true;
    clearTimeout(timer);
  });
  window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
      stopped = false;
      run('status');
    }
  });
  buttons();
  run('status');
})();
