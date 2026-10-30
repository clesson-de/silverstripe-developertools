import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { existsSync } from 'node:fs';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

function fixture(css) {
  const groups = ['top', 'center', 'bottom'].flatMap((vertical) =>
    ['left', 'center', 'right'].map((horizontal) => {
      const cards = Array.from({ length: 5 }, (_, index) => `
        <section class="dt-toast dt-toast--info" data-autohide="0" tabindex="0">
          <div class="dt-toast__heading">${index % 2 ? '<strong>Custom title</strong>' : ''}
            <button type="button" class="dt-toast__close" aria-label="Close message" hidden>&times;</button>
          </div><div class="dt-toast__content">
            <p>${vertical} ${horizontal} ${index}</p>
            ${index === 0 ? `<pre><code>${'LongUnbrokenContent'.repeat(80)}</code></pre>
              <table><tbody><tr>${'<td>Wide table column</td>'.repeat(12)}</tr></tbody></table>` : ''}
          </div>
        </section>`).join('');
      return `<div class="dt-toast-region" data-horizontal="${horizontal}" data-vertical="${vertical}">${cards}</div>`;
    })).join('');
  return `<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1">
    <style>body { margin: 0; min-height: 2000px; } ${css}</style></head>
    <body><main><button id="page-button">Underlying page</button></main>
    <aside id="developer-tools-toasts" class="dt-toasts" aria-label="Website notices">${groups}</aside></body></html>`;
}

function connect(url) {
  return new Promise((resolve, reject) => {
    const socket = new WebSocket(url);
    const pending = new Map();
    let id = 0;
    socket.addEventListener('error', reject, { once: true });
    socket.addEventListener('message', ({ data }) => {
      const message = JSON.parse(data);
      if (!pending.has(message.id)) return;
      const { resolve, reject } = pending.get(message.id);
      pending.delete(message.id);
      if (message.error) reject(new Error(JSON.stringify(message.error)));
      else resolve(message.result);
    });
    socket.addEventListener('open', () => resolve({
      socket,
      send(method, params = {}, sessionId) {
        return new Promise((resolve, reject) => {
          pending.set(++id, { resolve, reject });
          socket.send(JSON.stringify({ id, method, params, sessionId }));
        });
      },
    }), { once: true });
  });
}

function inspectLayout() {
  const overlay = document.getElementById('developer-tools-toasts');
  const bounds = (element) => {
    const rect = element.getBoundingClientRect();
    return { left: rect.left, top: rect.top, right: rect.right, bottom: rect.bottom };
  };
  const visibleBounds = (element) => {
    const rect = bounds(element);
    for (let parent = element.parentElement; parent && parent !== document.body; parent = parent.parentElement) {
      if (getComputedStyle(parent).display === 'contents') continue;
      const clip = bounds(parent);
      rect.left = Math.max(rect.left, clip.left);
      rect.top = Math.max(rect.top, clip.top);
      rect.right = Math.min(rect.right, clip.right);
      rect.bottom = Math.min(rect.bottom, clip.bottom);
    }
    return rect;
  };
  const rects = [...overlay.querySelectorAll('.dt-toast')].map(visibleBounds)
    .filter((rect) => rect.right > rect.left && rect.bottom > rect.top);
  const overlaps = [];
  for (let i = 0; i < rects.length; i++) {
    for (let j = i + 1; j < rects.length; j++) {
      const a = rects[i];
      const b = rects[j];
      if (Math.min(a.right, b.right) - Math.max(a.left, b.left) > 0.5
          && Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top) > 0.5) overlaps.push([i, j]);
    }
  }
  let gapClickable = true;
  if (getComputedStyle(overlay).display === 'grid') {
    const [first, second] = [...overlay.querySelectorAll('.dt-toast-region')].map(bounds);
    gapClickable = !document.elementFromPoint((first.right + second.left) / 2, first.top + 8)
      ?.closest('#developer-tools-toasts');
  }
  return {
    mode: getComputedStyle(overlay).display,
    visible: rects.length,
    overlaps,
    outsideViewport: rects.filter((rect) => rect.left < 0 || rect.top < 0 || rect.right > innerWidth || rect.bottom > innerHeight),
    pageOverflow: document.documentElement.scrollWidth > innerWidth,
    pageClickable: !document.elementFromPoint(2, 2)?.closest('#developer-tools-toasts'),
    gapClickable,
    closeButtonVisible: !overlay.querySelector('.dt-toast__close').hidden,
    total: overlay.querySelectorAll('.dt-toast').length,
  };
}

test('real browser: all positions, overflow, responsive fallback and resize stay non-overlapping', { timeout: 60000 }, async (t) => {
  const chrome = process.env.CHROME_BIN || (process.platform === 'darwin'
    ? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
    : '/usr/bin/chromium');
  assert.ok(existsSync(chrome), 'Set CHROME_BIN to an installed Chrome/Chromium executable.');
  const profile = await mkdtemp(join(tmpdir(), 'developertools-toast-browser-'));
  const browser = spawn(chrome, [
    '--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--disable-background-networking', '--disable-component-update', '--remote-debugging-port=0',
    `--user-data-dir=${profile}`, 'about:blank',
  ], { stdio: ['ignore', 'ignore', 'pipe'] });
  let connection;
  try {
    const endpoint = await new Promise((resolve, reject) => {
      let output = '';
      browser.on('error', reject);
      browser.once('exit', (code) => reject(new Error(`Chrome exited before connecting: ${code}\n${output}`)));
      browser.stderr.on('data', (data) => {
        output += data.toString();
        const match = output.match(/DevTools listening on (ws:\/\/[^\s]+)/);
        if (match) resolve(match[1]);
      });
    });
    connection = await connect(endpoint);
    const { targetId } = await connection.send('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await connection.send('Target.attachToTarget', { targetId, flatten: true });
    const send = (method, params) => connection.send(method, params, sessionId);
    const css = await readFile('client/frontend/dist/toasts.css', 'utf8');
    const js = await readFile('client/frontend/dist/toasts.js', 'utf8');
    const { frameTree } = await send('Page.getFrameTree');
    await send('Page.setDocumentContent', { frameId: frameTree.frame.id, html: fixture(css) });
    await send('Runtime.evaluate', { expression: js });
    for (const [width, height, mode] of [[1440, 1000, 'grid'], [390, 844, 'flex'], [1440, 480, 'flex'], [1440, 1000, 'grid']]) {
      await t.test(`${width}x${height}: ${mode}`, async () => {
        await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
        await send('Runtime.evaluate', { expression: 'new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))', awaitPromise: true });
        const { result } = await send('Runtime.evaluate', { expression: `(${inspectLayout})()`, returnByValue: true });
        const layout = result.value;
        assert.equal(layout.mode, mode);
        assert.equal(layout.total, 45);
        assert.ok(layout.visible >= (mode === 'grid' ? 9 : 1));
        assert.deepEqual(layout.overlaps, []);
        assert.deepEqual(layout.outsideViewport, []);
        assert.equal(layout.pageOverflow, false);
        assert.equal(layout.pageClickable, true);
        assert.equal(layout.gapClickable, true);
        assert.equal(layout.closeButtonVisible, true);
        await send('Runtime.evaluate', { expression: `document.querySelectorAll('.dt-toast-region, #developer-tools-toasts').forEach(element => { element.scrollTop = element.scrollHeight; })` });
        const scrolled = await send('Runtime.evaluate', { expression: `(${inspectLayout})()`, returnByValue: true });
        assert.deepEqual(scrolled.result.value.overlaps, []);
        assert.ok(scrolled.result.value.visible >= 1);
        await send('Runtime.evaluate', { expression: `document.querySelectorAll('.dt-toast-region, #developer-tools-toasts').forEach(element => { element.scrollTop = 0; })` });
      });
    }
  } finally {
    connection?.socket.close();
    const stopped = once(browser, 'close');
    browser.kill('SIGTERM');
    await stopped;
    await rm(profile, { recursive: true, force: true });
  }
});
