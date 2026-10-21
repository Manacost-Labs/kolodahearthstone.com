// The Vicious Syndicate radar must leave the main thread idle once its layout settles.
// Usage: PLAYWRIGHT_PACKAGE=/path/to/node_modules/ node tests/graphs/radar-idle.mjs
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(process.env.PLAYWRIGHT_PACKAGE || import.meta.url);
const { chromium } = require('playwright');
const script = readFileSync(new URL('../../wordpress/plugins/hs-parses-graphs-wp/assets/js/vs-radar.js', import.meta.url), 'utf8');

// A realistic radar: 40 cards with weighted links, like the live dataset.
const nodes = Array.from({ length: 40 }, (_, i) => ({ name: `Card ${i}`, radius: 6 + (i % 9) }));
const edges = nodes.flatMap((n, i) => [1, 3, 7].map(step => ({ source: n.name, target: nodes[(i + step) % nodes.length].name, weight: 0.2 + (i % 5) / 10 })));
const payload = JSON.stringify({ radars: [{ class: 'Mage', archetype: null, nodes, edges }] });
const page = `<!doctype html><html><body style="margin:0">
<div class="hs-vs-radar-wrapper" data-radars-url="/radars.json" data-start-class="Mage">
<div class="hs-vs-class-tabs"></div><div class="hs-vs-archetype-tabs"></div>
<input class="hs-vs-radar-search"><button class="hs-vs-radar-reset-btn">reset</button>
<canvas class="hs-vs-radar-canvas" width="600" height="600"></canvas>
<div class="hs-vs-radar-hover-info"></div><table class="hs-vs-nodes-table"><tbody></tbody></table>
<div class="hs-vs-selected-card-title"></div><div class="hs-vs-selected-card-details"></div></div>
<div style="height:4000px"></div>
<script>
window.radarFrames = 0;
const raf = window.requestAnimationFrame.bind(window);
window.requestAnimationFrame = cb => raf(t => { window.radarFrames++; cb(t); });
</script>
<script src="/vs-radar.js"></script></body></html>`;

const server = createServer((request, response) => {
	const types = { '/': ['text/html', page], '/vs-radar.js': ['text/javascript', script], '/radars.json': ['application/json', payload] };
	const [type, body] = types[request.url] || ['text/plain', ''];
	response.writeHead(types[request.url] ? 200 : 404, { 'Content-Type': type });
	response.end(body);
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch({ headless: true, ...(process.env.RADAR_TEST_CHROMIUM ? { executablePath: process.env.RADAR_TEST_CHROMIUM } : {}) });
try {
	const tab = await browser.newPage({ viewport: { width: 900, height: 800 } });
	const errors = [];
	tab.on('pageerror', error => errors.push(String(error)));
	await tab.goto(origin, { waitUntil: 'load' });
	await tab.waitForFunction(() => document.querySelectorAll('.hs-vs-nodes-table tbody tr').length === 40);
	const framesIn = async ms => {
		const start = await tab.evaluate(() => window.radarFrames);
		await tab.waitForTimeout(ms);
		return (await tab.evaluate(() => window.radarFrames)) - start;
	};

	assert.ok(await framesIn(300) > 5, 'the layout animates while it settles');
	const settleBy = Date.now() + 16000;
	while (await framesIn(700) > 0) assert.ok(Date.now() < settleBy, 'the layout settles within 16 s');
	assert.equal(await framesIn(1000), 0, 'a settled radar schedules no frames');

	const box = await tab.locator('canvas').boundingBox();
	await tab.mouse.move(box.x + 300, box.y + 300);
	await tab.mouse.down();
	await tab.mouse.move(box.x + 360, box.y + 340, { steps: 8 });
	assert.ok(await framesIn(200) > 3, 'dragging wakes the layout');
	await tab.mouse.up();

	await tab.evaluate(() => scrollTo(0, 3000));
	await tab.waitForTimeout(300);
	assert.equal(await framesIn(800), 0, 'an off-screen radar stays idle');
	await tab.evaluate(() => scrollTo(0, 0));
	assert.ok(await framesIn(300) > 0, 'returning to the radar resumes an unsettled layout');
	assert.deepEqual(errors, []);
	console.log('Radar idles when settled or off-screen and wakes on interaction.');
} finally {
	await browser.close();
	server.close();
}
