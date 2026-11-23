import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { JSDOM } from 'jsdom';

test('log viewer refresh is opt-in and accepts only integer seconds from 5 to 360', async () => {
  const dom = new JSDOM(`<form><input type="hidden" name="SecurityID" value="token"><div class="developer-tools-logs">
    <a class="developer-tools-logs__badge" href="/log?file=app.log" data-log-file data-log-name="app.log">app.log</a>
    <div data-log-viewer hidden data-log-reset-url="/reset-log" data-log-reset-confirm="Reset {file}?" data-log-reset-success="done" data-log-reset-error="failed">
      <div class="developer-tools-logs__toolbar"><button type="button" data-log-reset>reset</button></div>
      <input type="number" min="5" max="360" step="1" value="30" data-log-interval>
      <input type="checkbox" data-log-enabled>
      <p data-log-error hidden>invalid</p><iframe data-log-frame></iframe>
    </div></div></form>`, { runScripts: 'outside-only', url: 'https://example.test/' });
  let next = 0;
  const timers = new Map();
  const notices = [];
  dom.window.setInterval = (callback, delay) => { timers.set(++next, { callback, delay }); return next; };
  dom.window.clearInterval = (id) => timers.delete(id);
  dom.window.confirm = () => true;
  dom.window.fetch = async () => ({ ok: true, status: 204 });
  dom.window.jQuery = { noticeAdd: (value) => notices.push(value) };
  dom.window.eval(readFileSync('client/admin/dist/bundle.js', 'utf8'));
  const document = dom.window.document;
  const interval = document.querySelector('[data-log-interval]');
  const enabled = document.querySelector('[data-log-enabled]');
  const viewer = document.querySelector('[data-log-viewer]');
  const change = () => interval.dispatchEvent(new dom.window.Event('input', { bubbles: true }));
  document.querySelector('[data-log-file]').click();
  assert.equal(viewer.hidden, false);
  assert.equal(document.querySelector('[data-log-file]').classList.contains('developer-tools-logs__badge--active'), true);
  assert.equal(document.querySelector('iframe').src, 'https://example.test/log?file=app.log');
  document.querySelector('[data-log-file]').click();
  assert.equal(viewer.hidden, true);
  assert.equal(document.querySelector('[data-log-file]').classList.contains('developer-tools-logs__badge--active'), false);
  document.querySelector('[data-log-file]').click();
  assert.equal(viewer.hidden, false);
  assert.equal(timers.size, 0);
  enabled.checked = true;
  enabled.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
  assert.equal([...timers.values()][0].delay, 30000);
  for (const value of ['4', '361', '5.5', '']) {
    interval.value = value;
    change();
    assert.equal(timers.size, 0, value);
    assert.equal(document.querySelector('[data-log-error]').hidden, false);
  }
  for (const value of ['5', '360']) {
    interval.value = value;
    change();
    assert.equal(timers.size, 1);
    assert.equal([...timers.values()][0].delay, Number(value) * 1000);
  }
  const frame = document.querySelector('iframe');
  const oldSrc = frame.src;
  [...timers.values()][0].callback();
  assert.equal(frame.src, oldSrc);
  assert.equal(timers.size, 1);
  enabled.checked = false;
  enabled.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
  assert.equal(timers.size, 0);

  const reset = document.querySelector('[data-log-reset]');
  reset.click();
  assert.equal(reset.disabled, true);
  await new Promise((resolve) => setTimeout(resolve, 0));
  await new Promise((resolve) => setTimeout(resolve, 0));
  document.querySelector('iframe').dispatchEvent(new dom.window.Event('load'));
  await new Promise((resolve) => setTimeout(resolve, 0));
  assert.equal(reset.disabled, false);
  assert.equal(notices[0].type, 'success');
  dom.window.close();
});
