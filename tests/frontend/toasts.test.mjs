import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { JSDOM } from 'jsdom';

function page(messages = [{ seconds: 5, removable: true }]) {
  const cards = messages.map(({ seconds, removable, title = 'Notice' }, index) => `
    <section class="dt-toast" data-autohide="${seconds}" tabindex="0">
      <div class="dt-toast__heading">${title ? `<strong>${title} ${index}</strong>` : ''}
        ${removable ? '<button class="dt-toast__close" type="button" hidden>Close</button>' : ''}
      </div><div class="dt-toast__content"><a href="#page">Link</a></div>
    </section>`).join('');
  const dom = new JSDOM(`<button id="page">Page</button>
    <aside id="developer-tools-toasts"><div class="dt-toast-region">${cards}</div></aside>`,
  { runScripts: 'outside-only', pretendToBeVisual: true, url: 'https://example.com/' });
  let now = 0;
  let timerID = 0;
  const timers = new Map();
  dom.window.performance.now = () => now;
  dom.window.setTimeout = (callback, delay) => {
    timers.set(++timerID, { callback, at: now + delay, delay });
    return timerID;
  };
  dom.window.clearTimeout = (id) => timers.delete(id);
  dom.window.eval(readFileSync('client/frontend/dist/toasts.js', 'utf8'));
  dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
  return {
    dom, timers, document: dom.window.document,
    advance(milliseconds) {
      const end = now + milliseconds;
      while (true) {
        const next = [...timers.entries()].sort((a, b) => a[1].at - b[1].at)[0];
        if (!next || next[1].at > end) break;
        now = next[1].at;
        timers.delete(next[0]);
        next[1].callback();
      }
      now = end;
    },
  };
}

test('default and individual durations expire independently; zero never creates a timer', () => {
  const { dom, document, advance, timers } = page([
    { seconds: 5, removable: true }, { seconds: 10, removable: false }, { seconds: 0, removable: false },
  ]);
  assert.equal(timers.size, 2);
  assert.equal(document.querySelectorAll('.dt-toast__close').length, 1);
  advance(4999);
  assert.equal(document.querySelectorAll('.dt-toast').length, 3);
  advance(1);
  assert.equal(document.querySelectorAll('.dt-toast').length, 2);
  advance(5000);
  assert.equal(document.querySelectorAll('.dt-toast').length, 1);
  advance(100000);
  assert.equal(document.querySelectorAll('.dt-toast').length, 1);
  assert.equal(timers.size, 0);
  dom.window.close();
});

test('manual dismissal removes the stack and overlay and restores previous keyboard focus', () => {
  const { dom, document, timers } = page([{ seconds: 5, removable: true, title: '' }]);
  assert.equal(document.querySelector('strong'), null);
  const previous = document.getElementById('page');
  previous.focus();
  const button = document.querySelector('.dt-toast__close');
  assert.equal(button.hidden, false);
  button.focus();
  button.click();
  assert.equal(document.getElementById('developer-tools-toasts'), null);
  assert.equal(document.activeElement, previous);
  assert.equal(timers.size, 0);
  dom.window.close();
});

test('hover and focus pause independently and resume only the remaining duration', () => {
  const { dom, document, advance, timers } = page();
  const toast = document.querySelector('.dt-toast');
  advance(2000);
  toast.dispatchEvent(new dom.window.MouseEvent('mouseenter'));
  toast.querySelector('a').focus();
  advance(20000);
  toast.dispatchEvent(new dom.window.MouseEvent('mouseleave'));
  assert.equal(timers.size, 0);
  document.getElementById('page').focus();
  advance(2999);
  assert.ok(document.querySelector('.dt-toast'));
  advance(1);
  assert.equal(document.querySelector('.dt-toast'), null);
  dom.window.close();
});

test('background tabs pause timers instead of expiring unseen messages', () => {
  const { dom, document, advance } = page();
  advance(1000);
  Object.defineProperty(document, 'hidden', { value: true, configurable: true });
  document.dispatchEvent(new dom.window.Event('visibilitychange'));
  advance(10000);
  assert.ok(document.querySelector('.dt-toast'));
  Object.defineProperty(document, 'hidden', { value: false, configurable: true });
  document.dispatchEvent(new dom.window.Event('visibilitychange'));
  advance(4000);
  assert.equal(document.querySelector('.dt-toast'), null);
  dom.window.close();
});

test('large durations are scheduled in bounded chunks without timeout overflow', () => {
  const { dom, document, advance, timers } = page([{ seconds: 2147483647, removable: false }]);
  assert.equal([...timers.values()][0].delay, 2147483647);
  advance(2147483647);
  assert.ok(document.querySelector('.dt-toast'));
  assert.equal(timers.size, 1);
  assert.equal([...timers.values()][0].delay, 2147483647);
  dom.window.close();
});

test('initialisation is idempotent and reloads do not remember closed messages', () => {
  const { dom, document, timers } = page();
  dom.window.eval(readFileSync('client/frontend/dist/toasts.js', 'utf8'));
  document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
  assert.equal(timers.size, 1);
  document.querySelector('.dt-toast__close').click();
  assert.equal(dom.window.localStorage.length, 0);
  assert.equal(dom.window.sessionStorage.length, 0);
  assert.equal(document.cookie, '');
  const reloaded = page();
  assert.ok(reloaded.document.querySelector('.dt-toast'));
  reloaded.dom.window.close();
  dom.window.close();
});
