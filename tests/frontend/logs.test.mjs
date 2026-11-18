import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { JSDOM } from 'jsdom';

test('log viewer refresh is opt-in and accepts only integer seconds from 5 to 360', () => {
  const dom = new JSDOM(`<div class="developer-tools-logs">
    <a href="/log?file=app.log" data-log-file>app.log</a>
    <div data-log-viewer hidden>
      <input type="number" min="5" max="360" step="1" value="30" data-log-interval>
      <input type="checkbox" data-log-enabled>
      <p data-log-error hidden>invalid</p><iframe data-log-frame></iframe>
    </div></div>`, { runScripts: 'outside-only', url: 'https://example.test/' });
  let next = 0;
  const timers = new Map();
  dom.window.setInterval = (callback, delay) => { timers.set(++next, { callback, delay }); return next; };
  dom.window.clearInterval = (id) => timers.delete(id);
  dom.window.eval(readFileSync('client/admin/dist/bundle.js', 'utf8'));
  const document = dom.window.document;
  const interval = document.querySelector('[data-log-interval]');
  const enabled = document.querySelector('[data-log-enabled]');
  const viewer = document.querySelector('[data-log-viewer]');
  const change = () => interval.dispatchEvent(new dom.window.Event('input', { bubbles: true }));
  document.querySelector('[data-log-file]').click();
  assert.equal(viewer.hidden, false);
  assert.equal(document.querySelector('iframe').src, 'https://example.test/log?file=app.log');
  assert.equal(timers.size, 0);
  enabled.click();
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
  enabled.click();
  assert.equal(timers.size, 0);
  dom.window.close();
});
