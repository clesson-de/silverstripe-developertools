import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { JSDOM } from 'jsdom';

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));
const waitFor = async (predicate, attempts = 30) => {
  for (let index = 0; index < attempts; index += 1) {
    if (predicate()) return;
    // Keep retries short; polling in tests is configured to 1ms.
    // eslint-disable-next-line no-await-in-loop
    await settle();
  }
  throw new Error('Timed out while waiting for condition.');
};

test('analysis start button disables during load and can start a new run after completion', async () => {
  const responses = [];
  const polls = [];
  const executes = [];
  let pollCount = 0;
  const opens = [];
  const dom = new JSDOM(`<form>
    <input name="SecurityID" value="csrf-token">
    <div data-analysis data-analysis-run-url="/run" data-analysis-execute-url="/execute" data-analysis-status-url="/status" data-analysis-poll-ms="1" data-analysis-report-url="/latest" data-analysis-print-url="/pdf" data-analysis-loading="Running" data-analysis-failure="Failed">
      <button type="button" data-analysis-run>Start analysis</button>
      <button type="button" data-analysis-print disabled>Print</button>
      <span data-analysis-status></span>
      <div data-analysis-progress hidden><div></div></div>
      <div data-analysis-result></div>
    </div>
  </form>`, { runScripts: 'outside-only', url: 'https://example.test/' });

  dom.window.fetch = async (url, options = {}) => {
    if (url === '/latest') {
      return { ok: true, json: async () => ({ result_id: 'cached-id', html: '<p>cached</p>', generated_at: '2026-11-24T10:00:00Z' }) };
    }
    if (String(url).startsWith('/status')) {
      polls.push(url);
      await settle();
      pollCount += 1;
      if (pollCount % 2 === 1) {
        return { ok: true, json: async () => ({ status: 'running', progress: 60, run_id: 'run-1' }) };
      }
      return { ok: true, json: async () => ({ status: 'done', progress: 100, run_id: 'run-1', result_id: 'new-id', html: '<p>new report</p>', generated_at: '2026-11-24T10:01:00Z' }) };
    }
    if (url === '/execute') {
      executes.push(options);
      await settle();
      return { ok: true, text: async () => '' };
    }
    responses.push({ url, options });
    await settle();
    return { ok: true, json: async () => ({ run_id: 'run-1', status: 'running' }) };
  };
  dom.window.open = (url) => opens.push(url);

  dom.window.eval(readFileSync('client/admin/dist/bundle.js', 'utf8'));
  await settle();

  const document = dom.window.document;
  const button = document.querySelector('[data-analysis-run]');
  const status = document.querySelector('[data-analysis-status]');
  const progress = document.querySelector('[data-analysis-progress]');
  const result = document.querySelector('[data-analysis-result]');
  const print = document.querySelector('[data-analysis-print]');

  assert.match(result.innerHTML, /cached/);
  assert.equal(print.disabled, false);
  assert.equal(button.disabled, false);
  button.click();
  assert.equal(button.disabled, true);
  assert.equal(progress.hidden, false);
  assert.match(status.textContent, /Running/);

  await waitFor(() => button.disabled === false && polls.length >= 2);

  assert.equal(button.disabled, false);
  assert.equal(progress.hidden, true);
  assert.match(result.innerHTML, /new report/);
  assert.equal(print.disabled, false);
  assert.equal(responses.length, 1);
  assert.equal(executes.length, 1);
  assert.equal(polls.length, 2);

  print.click();
  assert.equal(opens[0], '/pdf?id=new-id');

  button.click();
  await waitFor(() => responses.length === 2 && polls.length >= 4);
  assert.equal(responses.length, 2);
  assert.equal(executes.length, 2);
  assert.equal(polls.length, 4);
  dom.window.close();
});







