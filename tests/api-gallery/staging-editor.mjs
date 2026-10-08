import {createRequire} from 'node:module';
import {readFileSync,writeFileSync} from 'node:fs';
import assert from 'node:assert/strict';
const {chromium}=createRequire(process.env.PLAYWRIGHT_PACKAGE || import.meta.url)('playwright');
const fixture=JSON.parse(readFileSync('.artifacts/api-gallery/staging-session.json','utf8'));
const base='https://test.kolodahearthstone.com';
const httpCredentials={...JSON.parse(readFileSync('.artifacts/api-gallery/recheck/http-session.json','utf8')),origin:base}; const args=process.env.KOLODA_TEST_ORIGIN ? [`--host-resolver-rules=MAP test.kolodahearthstone.com ${process.env.KOLODA_TEST_ORIGIN}`, '--no-proxy-server'] : []; const browser=await chromium.launch({headless:true,args});const context=await browser.newContext({viewport:{width:1440,height:1000},httpCredentials});await context.addCookies(fixture.cookies);const page=await context.newPage(); const report={post_id:fixture.post_id,errors:[],viewports:[]};page.on('pageerror',error=>report.errors.push(error.message.slice(0,160)));
await context.route('**/plausible/**',route=>route.abort());
try {
 await page.goto(`${base}/wp-admin/post.php?post=${fixture.post_id}&action=edit`,{waitUntil:'domcontentloaded'});
 await page.waitForFunction(()=>window.tinymce?.get('content')?.initialized);
 await page.getByRole('button',{name:'Создать галерею из API',exact:true}).click();
 await page.locator('#hs-api-gallery-results input[type=checkbox]').first().waitFor({timeout:30000});
 await page.locator('#hs-api-gallery-library').selectOption('diamond-cards');
 await page.locator('#hs-api-gallery-results .hs-api-gallery__variant').first().waitFor({timeout:30000});
 for(const width of [1440,1024,768,520,390,320]) {await page.setViewportSize({width,height:1000}); const fits=await page.locator('#hs-api-gallery-dialog').evaluate(d=>({scroll:d.scrollWidth,width:d.clientWidth,left:d.getBoundingClientRect().left,right:d.getBoundingClientRect().right}));assert.ok(fits.scroll<=fits.width+1&&fits.left>=0&&fits.right<=width);report.viewports.push({width,...fits});}
 await page.setViewportSize({width:1440,height:1000});
 const choices=page.locator('#hs-api-gallery-results input[type=checkbox]');
 for(let i=0;i<3;i++) await choices.nth(i).check();
 await page.locator('#hs-api-gallery-ratings').check();
 const started=performance.now();await page.getByRole('button',{name:'Настроить галерею',exact:true}).click();
 const modal=page.locator('.media-modal:visible'); await modal.locator('select[data-setting="columns"]').waitFor({timeout:90000});
 report.import_ms=performance.now()-started;await modal.locator('select[data-setting="columns"]').selectOption('3');
 await modal.locator('.media-button-insert').click();
 await page.waitForFunction(()=>tinymce.get('content').getContent().includes('[gallery'));
 report.content=await page.evaluate(()=>tinymce.get('content').getContent());assert.match(report.content,/hs_ratings="1"/);report.attachments=report.content.match(/ids="([\d,]+)"/)[1].split(',').map(Number);assert.equal(report.attachments.length,3);
 await page.getByRole('button',{name:'Создать галерею из API',exact:true}).click(); assert.equal(await page.locator('#hs-api-gallery-results input:checked').count(),0); assert.equal(await page.locator('#hs-api-gallery-create').isDisabled(),true);await page.keyboard.press('Escape'); assert.equal(await page.evaluate(()=>document.activeElement.id),'hs-api-gallery-open');
 await page.locator('#save-post').click(); await page.waitForLoadState('domcontentloaded'); await page.waitForFunction(()=>window.tinymce?.get('content')?.initialized);assert.match(await page.evaluate(()=>tinymce.get('content').getContent()),/hs_ratings="1"/);
 await page.screenshot({path:'.artifacts/api-gallery/screenshots/staging-editor.png',fullPage:true});
 writeFileSync('.artifacts/api-gallery/staging-flow.json',JSON.stringify(report,null,2));console.log(JSON.stringify({post_id:report.post_id,attachments:report.attachments,import_ms:Math.round(report.import_ms),viewports:report.viewports.length,errors:report.errors}));
} catch(error) {await page.screenshot({path:'.artifacts/api-gallery/screenshots/staging-flow-failure.png',fullPage:true});throw error;} finally {await browser.close()}
