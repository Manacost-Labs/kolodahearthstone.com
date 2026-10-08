import { readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import assert from 'node:assert/strict';

// Supply credentials only through private, untracked fixture files.
const require = createRequire(process.env.PLAYWRIGHT_PACKAGE || import.meta.url);
const { chromium } = require('playwright');
const base = 'https://test.kolodahearthstone.com';
const fixture = JSON.parse(readFileSync('.artifacts/api-gallery/recheck/frontend-fixture.json', 'utf8'));
assert.equal(new URL(fixture.url).origin, base);
const httpCredentials = { ...JSON.parse(readFileSync('.artifacts/api-gallery/recheck/http-session.json', 'utf8')), origin: base };
const args = process.env.KOLODA_TEST_ORIGIN ? [`--host-resolver-rules=MAP test.kolodahearthstone.com ${process.env.KOLODA_TEST_ORIGIN}`, '--no-proxy-server'] : [];
const browser = await chromium.launch({ headless: true, args });
const report = { viewports: [], votes: [], errors: [] };
try {
  for (const touch of [false, true]) {
    const context = await browser.newContext({ httpCredentials, hasTouch: touch, isMobile: touch, viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();
    page.on('pageerror', error => report.errors.push(error.message.slice(0, 160)));
    await context.route('**/plausible/**', route => route.abort());
    assert.equal((await page.goto(fixture.url, { waitUntil: 'networkidle' })).status(), 200);
    const galleries = page.locator('.entry-content .gallery');
    assert.equal(await galleries.count(), 2);
    await page.waitForFunction(() => [...document.querySelectorAll('.entry-content .gallery img')].every(img => img.complete && img.naturalWidth > 0));
    const group = page.locator('.hs-gallery-rating').first();
    await group.locator('[data-score="5"]').waitFor();
    await page.waitForFunction(() => !document.querySelector('.hs-gallery-rating [data-score="5"]').disabled);
    for (const width of [320, 390, 520, 768, 1024, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      const metrics = await galleries.evaluateAll(nodes => nodes.map(gallery => {
        const items = [...gallery.querySelectorAll(':scope > .gallery-item')];
        const firstTop = items[0].getBoundingClientRect().top;
        return {
          display: getComputedStyle(gallery).display,
          row: items.filter(item => Math.abs(item.getBoundingClientRect().top - firstTop) < 1).length,
          width: gallery.getBoundingClientRect().width,
          background: getComputedStyle(gallery).backgroundColor,
          pageOverflow: document.documentElement.scrollWidth - innerWidth,
          targets: [...gallery.querySelectorAll('.hs-gallery-rating__stars button')].map(button => button.getBoundingClientRect().width),
        };
      }));
      for (const metric of metrics) {
        assert.equal(metric.display, 'grid');
        assert.equal(metric.background, 'rgba(0, 0, 0, 0)');
        assert.ok(metric.pageOverflow <= 1, JSON.stringify({ width, touch, metric }));
        if (width >= 1024) assert.equal(metric.row, 3);
        if (touch) assert.ok(metric.targets.every(target => target >= 44));
      }
      report.viewports.push({ width, touch, metrics });
    }
    if (!touch) {
      // Actual keyboard activation, one identity, update instead of duplicate vote.
      const vote = async (score, keyboard = false) => {
        const response = page.waitForResponse(response => response.url().includes('admin-ajax.php') && response.request().postData()?.includes('action=hs_api_gallery_vote'));
        const button = score ? group.locator(`[data-score="${score}"]`) : group.locator('.hs-gallery-rating__remove');
        if (keyboard) { await button.focus(); await page.keyboard.press('Enter'); } else await button.click();
        const result = await (await response).json();
        assert.equal(result.success, true);
        report.votes.push({ score, result: result.data });
        await page.waitForFunction(() => !document.querySelector('.hs-gallery-rating [data-score="5"]').disabled);
        return result.data;
      };
      await vote(5, true);
      await page.waitForTimeout(1200);
      const updated = await vote(4);
      assert.equal(updated.count, 1);
      assert.equal(updated.mine, 4);
      await page.reload({ waitUntil: 'networkidle' });
      await page.waitForFunction(() => document.querySelector('.hs-gallery-rating')?.dataset.mine === '4');
      await page.waitForTimeout(1200);
      const removed = await vote(0);
      assert.equal(removed.count, 0);
      assert.equal(removed.mine, 0);
      const badNonce = await page.evaluate(async ({ postId }) => {
        const imageId = document.querySelector('.hs-gallery-rating').dataset.image;
        const response = await fetch(hsGalleryRatings.url, { method: 'POST', body: new URLSearchParams({ action: 'hs_api_gallery_vote', post_id: postId, image_id: imageId, score: '5', nonce: 'invalid' }) });
        return response.status;
      }, { postId: String(fixture.post_id) });
      assert.equal(badNonce, 403);
      report.invalid_nonce = badNonce;
      await page.screenshot({ path: '.artifacts/api-gallery/screenshots/staging-frontend.png', fullPage: true });
    }
    await context.close();
  }
  assert.deepEqual(report.errors, []);
  writeFileSync('.artifacts/api-gallery/recheck/frontend.json', JSON.stringify(report, null, 2));
  console.log(JSON.stringify({ states: report.viewports.length, votes: 'create/update/reload/remove PASS', invalid_nonce: report.invalid_nonce, errors: report.errors }));
} finally { await browser.close(); }
