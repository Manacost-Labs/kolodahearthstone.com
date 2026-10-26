import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';
const { chromium, expect } = await import(pathToFileURL('/srv/projects/wordpress/hs-manacost.ru/node_modules/@playwright/test/index.mjs').href);
assert.equal(process.env.SYNC_STAGE_DISPOSABLE,'1');
const base='http://127.0.0.1:18798';
const browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});
try {
  const anonymous=await browser.newContext({serviceWorkers:'block'});
  const page=await anonymous.newPage();
  const errors=[];
  page.on('pageerror',error=>errors.push(error.message));
  for(const width of [320,1440]) {
    await page.setViewportSize({width,height:900});
    const response=await page.goto(`${base}/?p=${process.env.SYNC_STAGE_POST}`);
    assert.equal(response.status(),200);
    const card=page.locator('.hs-card-tooltip').filter({hasText:'Безднокрыл'});
    await expect(card).toBeVisible();
    await card.hover();
    await expect(page.locator('.hs-tooltip-box img')).toBeVisible();
    await expect.poll(()=>page.locator('.hs-tooltip-box img').evaluate(image=>image.complete && image.naturalWidth>0)).toBe(true);
    assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
    await page.mouse.move(0,0);
  }
  assert.deepEqual(errors,[]);
  const context=await browser.newContext({viewport:{width:1440,height:900},serviceWorkers:'block'});
  const editor=await context.newPage();
  await editor.goto(`${base}/wp-login.php`);
  await editor.locator('#user_login').fill('sync-admin');
  await editor.locator('#user_pass').fill(process.env.SYNC_STAGE_ADMIN);
  await editor.locator('#wp-submit').click();
  await editor.waitForURL(url=>url.origin===base && url.pathname.startsWith('/wp-admin/'));
  await editor.goto(`${base}/wp-admin/post-new.php`);
  await editor.locator('#title').fill('Shared automatic draft');
  await expect.poll(()=>editor.evaluate(()=>Boolean(window.tinyMCE?.get('content')?.initialized))).toBe(true);
  await editor.evaluate(()=>window.tinyMCE.get('content').setContent('<p>[hs_card id="BE_222t"]Безднокрыл[/hs_card]</p>'));
  await editor.locator('#save-post').click();
  await editor.waitForURL(/post\.php/);
  await expect(editor.locator('#title')).toHaveValue('Shared automatic draft');
  if (process.env.SYNC_STAGE_EDITORIAL === '1') {
    const source = process.env.SYNC_STAGE_SOURCE;
    const { insertComponents, verifyComponents } = await import(pathToFileURL(`${source}/tests/article-cards/components.mjs`).href);
    const { insertInlineImage, verifyInlineImage } = await import(pathToFileURL(`${source}/tests/article-cards/inline-image.mjs`).href);
    await editor.waitForFunction(() => window.tinymce?.get('content')?.initialized);
    await editor.evaluate(() => tinymce.get('content').setContent('<p>Первый абзац.</p>'));
    await insertComponents(editor);
    await editor.getByRole('button', { name: 'Вставить статью', exact: true }).click();
    await editor.locator('#hs-ac-source').selectOption('koloda');
    await editor.waitForFunction(() => document.querySelector('#hs-ac-results').getAttribute('aria-busy') === 'false');
    await editor.locator('.hs-ac-choice').first().click();
    await editor.locator('#hs-ac-insert').click();
    await editor.waitForFunction(() => !document.querySelector('#hs-article-cards-dialog').open);
    await insertInlineImage(editor);
    await editor.evaluate(() => window.scrollTo(0, 0));
    await Promise.all([editor.waitForNavigation(), editor.locator('#save-post').click()]);
    await editor.waitForFunction(() => window.tinymce?.get('content')?.initialized);
    assert.match(await editor.evaluate(() => tinymce.get('content').getContent()), /hs-inline-image/);
    const id = new URL(editor.url()).searchParams.get('post');
    const preview = await context.newPage();
    await preview.goto(`${base}/?p=${id}&preview=true`);
    await verifyComponents(preview, '.entry-content');
    await verifyInlineImage(preview);
    for (const width of [320,390,768,1024,1440]) {
      await preview.setViewportSize({width,height:900});
      assert.ok(await preview.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    }
    await preview.close();
  }
  await editor.goto(`${base}/wp-admin/media-new.php`);
  const nonce=await editor.evaluate(()=>window._wpPluploadSettings.defaults.multipart_params._wpnonce);
  const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j5V8AAAAASUVORK5CYII=','base64');
  const urls=[];
  for(let i=0;i<2;i++) {
    const response=await context.request.post(`${base}/wp-admin/async-upload.php`,{multipart:{action:'upload-attachment',_wpnonce:nonce,'async-upload':{name:'same-sync-image.png',mimeType:'image/png',buffer:png}}});
    assert.equal(response.status(),200);
    const data=await response.json();
    assert.equal(data.success,true);
    urls.push(data.data.url);
    const original=await context.request.get(data.data.url);
    assert.deepEqual(await original.body(),png);
  }
  assert.notEqual(urls[0],urls[1]);
  console.log(JSON.stringify({php:'8.4',theme:'Blocksy 2.1.40',tooltip:true,viewports:[320,1440],draft_save:true,duplicate_uploads:true,originals_unchanged:true}));
} finally { await browser.close(); }
