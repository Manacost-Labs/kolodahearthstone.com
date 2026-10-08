import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import assert from 'node:assert/strict';

// The package path is optional for servers that already have a pinned toolchain.
const require = createRequire(process.env.PLAYWRIGHT_PACKAGE || import.meta.url);
const { chromium } = require('playwright');
const markup = JSON.parse(readFileSync('.artifacts/api-gallery/markup.json', 'utf8'));
const preview = `data:image/svg+xml,${encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="240" height="336"><rect x="6" y="6" width="228" height="324" rx="16" fill="#eee" stroke="#777"/><text x="120" y="160" text-anchor="middle" font-size="18">Карта Hearthstone</text></svg>')}`;
for (const key of Object.keys(markup)) {
  // Only image transport is substituted; all gallery/rating markup comes from WP.
  markup[key] = markup[key].replace(/<img\b[^>]*>/g, img => img.replace(/\s(?:src|srcset|sizes|width|height)=(['"])[\s\S]*?\1/g, '') .replace(/\s*\/?\s*>$/, ` src="${preview}" width="240" height="336">`));
}
const theme = readFileSync('.artifacts/api-gallery/blocksy.css', 'utf8');
const blockStyles = readFileSync('.artifacts/api-gallery/core-gallery.css', 'utf8');
const ratings = readFileSync('wordpress/plugins/hs-api-gallery/hs-api-gallery/ratings.css', 'utf8');
const adapter = process.env.KOLODA_GALLERY_PHASE === 'before' ? '' : readFileSync('wordpress/mu-plugins/koloda-native-gallery/layout.css', 'utf8');
const browser = await chromium.launch({ headless: true });
const report = [];
mkdirSync('.artifacts/api-gallery/screenshots', { recursive: true });
try {
  for (const touch of [false, true]) {
    for (const width of [320, 390, 520, 768, 1024, 1440]) {
      const context = await browser.newContext({ hasTouch: touch, isMobile: touch, viewport: { width, height: 1000 } });
      const page = await context.newPage();
      await page.route('**/*', route => route.abort());
      for (const rated of [false, true]) {
        for (const columns of [1, 2, 3, 4, 9]) {
          const html = markup[`${columns}-${rated ? 'rated' : 'plain'}`];
          await page.setContent(`<meta name="viewport" content="width=device-width, initial-scale=1"><style>${theme}\n${blockStyles}\n${ratings}\n${adapter}</style><main style="max-width:740px;margin:20px auto;padding:0 20px"><article class="entry-content">${html}</article><div class="widget_media_gallery">${markup['3-plain'].replace(/<img\b[^>]*>/g, '')}</div><figure class="wp-block-gallery"><figure class="wp-block-image">Отдельный блок</figure></figure></main>`);
          const metrics = await page.locator('.entry-content .gallery').evaluate(gallery => {
            const items = [...gallery.querySelectorAll(':scope > .gallery-item')];
            const rects = items.map(item => item.getBoundingClientRect());
            const firstRow = rects.filter(rect => Math.abs(rect.top - rects[0].top) < 1);
            return { count: items.length, row: firstRow.length, display: getComputedStyle(gallery).display, overflow: document.documentElement.scrollWidth - innerWidth,
              background: getComputedStyle(gallery).backgroundColor, coarse: matchMedia('(any-pointer: coarse)').matches,
              targets: [...gallery.querySelectorAll('.hs-gallery-rating__stars button')].map(button => button.getBoundingClientRect().width) };
          });
          assert.equal(metrics.display, 'grid');
          assert.equal(metrics.count, 6);
          assert.ok(metrics.row <= columns && metrics.row >= 1);
          if (width >= 768 && columns <= 3) assert.equal(metrics.row, columns);
          if (width === 320) assert.equal(metrics.row, 1);
          assert.ok(metrics.overflow <= 1, JSON.stringify({ width, columns, rated, metrics }));
          assert.equal(metrics.background, 'rgba(0, 0, 0, 0)');
          if (touch && rated) assert.ok(metrics.targets.every(size => size >= 44), JSON.stringify({ width, columns, rated, touch, metrics }));
          assert.equal(await page.locator('.wp-block-gallery').evaluate(el => getComputedStyle(el).display), 'flex');
          assert.equal(await page.locator('.widget_media_gallery .gallery').evaluate(el => getComputedStyle(el).gridTemplateColumns.split(' ').length), 3);
          report.push({ width, touch, columns, rated, ...metrics });
        }
      }
      // Capture only after all metrics: screenshot emulation can reset pointers.
      await page.setContent(`<meta name="viewport" content="width=device-width, initial-scale=1"><style>${theme}\n${ratings}\n${adapter}</style><main style="max-width:740px;margin:20px auto;padding:0 20px"><article class="entry-content">${markup['3-rated']}</article></main>`);
      await page.screenshot({ path: `.artifacts/api-gallery/screenshots/${width}-${touch ? 'touch' : 'pointer'}-rated.png`, fullPage: true });
      await context.close();
    }
  }
  writeFileSync('.artifacts/api-gallery/layout-report.json', JSON.stringify(report, null, 2));
  console.log(`Native Blocksy galleries: ${report.length} layout states passed.`);
} finally {
  await browser.close();
}
