const pending = new WeakSet();
const logTimers = new WeakMap();

function stopLogTimer(viewer) {
  clearInterval(logTimers.get(viewer));
  logTimers.delete(viewer);
}

function showToast(type, text) {
  if (window.jQuery && typeof window.jQuery.noticeAdd === 'function') {
    window.jQuery.noticeAdd({ text, type, stayTime: 5000, inEffect: { left: '0', opacity: 'show' } });
  }
}

function reloadFrame(frame, src) {
  if (!frame || !src) return Promise.resolve();
  return new Promise((resolve) => {
    let settled = false;
    const finish = () => {
      if (settled) return;
      settled = true;
      clearTimeout(timeout);
      frame.removeEventListener('load', finish);
      resolve();
    };
    const timeout = setTimeout(finish, 10000);
    frame.addEventListener('load', finish, { once: true });
    frame.src = src;
  });
}

function updateLogTimer(viewer) {
  stopLogTimer(viewer);
  const enabled = viewer.querySelector('[data-log-enabled]');
  const interval = viewer.querySelector('[data-log-interval]');
  const error = viewer.querySelector('[data-log-error]');
  const valid = interval.value !== '' && interval.validity.valid && Number.isInteger(Number(interval.value));
  error.hidden = valid;
  interval.setAttribute('aria-invalid', String(!valid));
  if (!enabled.checked || !valid) return;
  const timer = setInterval(() => {
    if (!viewer.isConnected) {
      clearInterval(timer);
      return;
    }
    const frame = viewer.querySelector('[data-log-frame]');
    if (frame.getAttribute('src')) frame.src = frame.src;
  }, Number(interval.value) * 1000);
  logTimers.set(viewer, timer);
}

document.addEventListener('change', (event) => {
  if (!(event.target instanceof Element)) return;
  if (event.target.matches('[data-log-enabled], [data-log-interval]')) {
    updateLogTimer(event.target.closest('[data-log-viewer]'));
  }
});

document.addEventListener('input', (event) => {
  if (event.target instanceof Element && event.target.matches('[data-log-interval]')) {
    updateLogTimer(event.target.closest('[data-log-viewer]'));
  }
});

document.addEventListener('click', (event) => {
  if (!(event.target instanceof Element)) return;
  const reset = event.target.closest('[data-log-reset]');
  if (reset) {
    event.preventDefault();
    event.stopPropagation();
    const viewer = reset.closest('[data-log-viewer]');
    const token = reset.closest('form')?.querySelector('[name="SecurityID"]');
    const file = viewer?.dataset.logFile || '';
    const fileLabel = `\`${file}\``;
    const message = (viewer?.dataset.logResetConfirm || '').split('{file}').join(fileLabel);
    if (!viewer || !file || !token?.value || !viewer.dataset.logResetUrl || !window.confirm(message)) return;
    const frame = viewer.querySelector('[data-log-frame]');
    reset.disabled = true;
    const data = new URLSearchParams({ SecurityID: token.value, file });
    fetch(viewer.dataset.logResetUrl, {
      method: 'POST', body: data, credentials: 'same-origin', redirect: 'error',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
    }).then((response) => {
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      showToast('success', viewer.dataset.logResetSuccess || 'Done.');
      return reloadFrame(frame, frame?.getAttribute('src'));
    }).catch((error) => {
      showToast('error', `${viewer.dataset.logResetError || 'Request failed.'} (${error.message})`);
    }).finally(() => {
      reset.disabled = false;
    });
    return;
  }
  const link = event.target.closest('[data-log-file]');
  if (!link) return;
  event.preventDefault();
  event.stopPropagation();
  const logs = link.closest('.developer-tools-logs');
  const viewer = logs.querySelector('[data-log-viewer]');
  const isActive = link.classList.contains('developer-tools-logs__badge--active');
  logs.querySelectorAll('[data-log-file]').forEach((element) => {
    element.classList.remove('developer-tools-logs__badge--active');
    element.setAttribute('aria-pressed', 'false');
  });
  if (isActive) {
    viewer.hidden = true;
    viewer.dataset.logFile = '';
    stopLogTimer(viewer);
    return;
  }
  link.classList.add('developer-tools-logs__badge--active');
  link.setAttribute('aria-pressed', 'true');
  viewer.dataset.logFile = link.dataset.logName || '';
  viewer.hidden = false;
  viewer.querySelector('[data-log-frame]').src = link.href;
});

function detailCell(row) {
  let detail = row.nextElementSibling;
  if (!detail || !detail.classList.contains('monitoring-detail-row')) {
    detail = document.createElement('tr');
    detail.className = 'monitoring-detail-row';
    const cell = document.createElement('td');
    cell.colSpan = row.cells.length;
    cell.setAttribute('aria-live', 'polite');
    detail.append(cell);
    row.after(detail);
  }
  detail.hidden = false;
  return detail.firstElementChild;
}

async function loadDetails(button) {
  const row = button.closest('tr');
  const grid = button.closest('.developer-tools-monitoring');
  const open = button.getAttribute('aria-expanded') === 'true';
  if (open) {
    if (row.nextElementSibling?.classList.contains('monitoring-detail-row')) {
      row.nextElementSibling.hidden = true;
    }
    button.setAttribute('aria-expanded', 'false');
    return;
  }
  const cell = detailCell(row);
  button.setAttribute('aria-expanded', 'true');
  cell.textContent = grid.dataset.loading;
  try {
    const response = await fetch(button.dataset.monitoringExpand, { credentials: 'same-origin', redirect: 'error' });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    cell.innerHTML = await response.text();
  } catch (error) {
    cell.textContent = `${grid.dataset.failure} (${error.message})`;
  }
}

async function checkWebsite(button) {
  if (pending.has(button)) return;
  const row = button.closest('tr');
  const grid = button.closest('.developer-tools-monitoring');
  const cell = detailCell(row);
  const expand = row.querySelector('[data-monitoring-expand]');
  const token = button.closest('form')?.querySelector('[name="SecurityID"]');
  pending.add(button);
  button.disabled = true;
  expand.setAttribute('aria-expanded', 'true');
  cell.textContent = grid.dataset.loading;
  try {
    if (!token?.value) throw new Error(grid.dataset.csrfError);
    const data = new URLSearchParams({ SecurityID: token.value });
    const response = await fetch(button.dataset.monitoringCheck, {
      method: 'POST', body: data, credentials: 'same-origin', redirect: 'error',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const result = await response.json();
    cell.innerHTML = result.html;
    const actionRequired = row.querySelector('.col-ActionRequired');
    if (actionRequired) actionRequired.innerHTML = result.action_required_html;
    const values = { LastStatus: result.status_label, LastCheckedAt: result.checked_at, LastChangeAt: result.change_at };
    Object.entries(values).forEach(([name, value]) => {
      const column = row.querySelector(`.col-${name}`);
      if (column) column.textContent = value || '';
    });
  } catch (error) {
    cell.textContent = `${grid.dataset.failure} (${error.message})`;
  } finally {
    pending.delete(button);
    button.disabled = false;
  }
}

document.addEventListener('click', (event) => {
  if (!(event.target instanceof Element)) return;
  const grid = event.target.closest('.developer-tools-monitoring');
  if (!grid) return;
  const check = event.target.closest('[data-monitoring-check]');
  const expand = event.target.closest('[data-monitoring-expand]');
  if (check || expand) {
    event.preventDefault();
    event.stopPropagation();
    if (check) checkWebsite(check);
    else loadDetails(expand);
    return;
  }
  if (event.target.closest('a, button, input, select, textarea, .monitoring-detail-row')) return;
  const row = event.target.closest('tbody tr');
  const control = row?.querySelector('[data-monitoring-expand]');
  if (control) {
    event.preventDefault();
    event.stopPropagation();
    loadDetails(control);
  }
});
