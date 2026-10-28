import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { JSDOM } from 'jsdom';

function page(fetch) {
  const dom = new JSDOM(`<form>
    <input name="SecurityID" value="test-csrf">
    <div class="developer-tools-monitoring" data-loading="Loading" data-failure="Failed">
      <table><tbody><tr>
        <td class="col-Name">Customer</td><td class="col-LastStatus">not checked</td>
        <td class="col-LastCheckedAt"></td><td class="col-LastChangeAt"></td>
        <td class="col-ActionRequired"></td>
        <td><button type="button" data-monitoring-expand="/details/1" aria-expanded="false">Details</button>
        <button type="button" data-monitoring-check="/check/1">Check</button></td>
      </tr></tbody></table>
    </div></form>`, { runScripts: 'outside-only', url: 'https://monitor.example/' });
  dom.window.fetch = fetch;
  dom.window.eval(readFileSync('client/admin/dist/bundle.js', 'utf8'));
  return dom;
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

test('row click expands a plain HTML table and button collapses it', async () => {
  let requests = 0;
  const dom = page(async () => {
    requests += 1;
    return { ok: true, text: async () => '<table><tbody><tr><td>PHP</td><td>8.3</td></tr></tbody></table>' };
  });
  const document = dom.window.document;
  document.querySelector('.col-Name').click();
  await settle();
  assert.equal(document.querySelectorAll('.monitoring-detail-row').length, 1);
  assert.equal(document.querySelector('.monitoring-detail-row td').colSpan, 6);
  assert.match(document.querySelector('.monitoring-detail-row').textContent, /PHP/);
  assert.equal(document.querySelector('[data-monitoring-expand]').getAttribute('aria-expanded'), 'true');
  document.querySelector('[data-monitoring-expand]').click();
  assert.equal(document.querySelector('.monitoring-detail-row').hidden, true);
  assert.equal(requests, 1);
  dom.window.close();
});

test('manual check uses POST and CSRF and updates row and details', async () => {
  const dom = page(async (url, options) => {
    assert.equal(url, '/check/1');
    assert.equal(options.method, 'POST');
    assert.equal(options.body.get('SecurityID'), 'test-csrf');
    return { ok: true, json: async () => ({
      html: '<p>Updated details</p>', status_label: 'changed', checked_at: '2026-10-26', change_at: '2026-10-26',
      action_required_html: '<span role="img" aria-label="Action required" class="monitoring-status--update"></span>',
    }) };
  });
  const document = dom.window.document;
  document.querySelector('[data-monitoring-check]').click();
  await settle();
  assert.equal(document.querySelector('.col-LastStatus').textContent, 'changed');
  assert.equal(document.querySelector('.col-LastCheckedAt').textContent, '2026-10-26');
  assert.equal(document.querySelector('.col-ActionRequired [role="img"]').getAttribute('aria-label'), 'Action required');
  assert.match(document.querySelector('.monitoring-detail-row').textContent, /Updated details/);
  assert.equal(document.querySelector('[data-monitoring-check]').disabled, false);
  dom.window.close();
});

test('failed detail request is visible instead of an empty successful table', async () => {
  const dom = page(async () => ({ ok: false, status: 403 }));
  dom.window.document.querySelector('[data-monitoring-expand]').click();
  await settle();
  assert.match(dom.window.document.querySelector('.monitoring-detail-row').textContent, /Failed.*403/);
  dom.window.close();
});
