/* Run only through the local test wrapper documented in TESTING.md. */
const {chromium}=require('playwright');
const fs=require('fs'),cp=require('child_process'),path=require('path');
const root=process.env.ES_TEST_ROOT;
if(!root||process.env.ES_TEST_ALLOW_RESET!=='1')throw Error('Explicit local test settings required');
const php=(code)=>cp.execFileSync(process.env.ES_TEST_PHP||path.join(root,'runtime/php'),['-r',`require ${JSON.stringify(process.env.ES_TEST_BOOTSTRAP||root+'/runtime/wordpress/wp-load.php')}; ${code}`],{encoding:'utf8'});
const state=JSON.parse(fs.readFileSync(process.env.ES_TEST_STATE||path.join(root,'runtime/test-state.json'),'utf8'));
const base=process.env.ES_TEST_BASE_URL||'http://127.0.0.1:18080';const output=process.env.ES_TEST_OUTPUT||path.join(root,'runtime');let passed=0;
if(new URL(base).hostname!=='127.0.0.1')throw Error('Browser tests require an isolated 127.0.0.1 installation');
php("if(wp_get_environment_type()!=='local'||wp_parse_url(home_url(),PHP_URL_HOST)!=='127.0.0.1'){exit(2);}");
function ok(v,n){if(!v)throw Error('FAIL '+n);passed++;console.log('PASS '+n);}
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.ES_TEST_CHROME||undefined,headless:true,args:['--no-sandbox']});
 try {
 const anon=await browser.newContext();anon.setDefaultTimeout(15000);anon.setDefaultNavigationTimeout(20000);const page=await anon.newPage();const errors=[];page.on('pageerror',e=>errors.push(String(e)));
 let response=await page.goto(base+'/gazeta/');ok(response.status()===404,'newspaper root defaults to closed access');
 ok(!(await page.content()).includes('PRIVATE_'),'root has no private draft data');
 response=await anon.request.get(base+'/gazeta/2026-10-20/');ok(response.status()===404,'anonymous draft issue hidden');
 response=await anon.request.post(base+'/wp-json/ezhednevny-standard/v1/import',{data:state.fixture});ok(response.status()===403,'anonymous REST import denied');
 response=await anon.request.post(base+'/wp-json/ezhednevny-standard/v1/publish',{data:{id:state.issue_id}});ok(response.status()===404,'no publication REST route');
 const owner=await browser.newContext({viewport:{width:1440,height:1000}});owner.setDefaultTimeout(15000);owner.setDefaultNavigationTimeout(20000);const admin=await owner.newPage();
 await admin.goto(base+'/wp-login.php');await admin.locator('#user_login').fill('es_owner');await admin.locator('#user_pass').fill('local-editor-test-pass');await admin.locator('#wp-submit').click();await admin.waitForURL(/wp-admin/);
 await admin.goto(base+'/wp-admin/admin.php?page=es-newsroom&issue='+state.issue_id);
 ok((await admin.locator('h1').textContent()).includes('Ежедневный стандарт'),'editorial screen loaded');
 const preview=await admin.getByRole('link',{name:'Предпросмотр газеты'}).getAttribute('href');
 response=await anon.request.get(preview);ok(response.status()===404,'preview nonce without logged-in rights denied');
 const previewPage=await owner.newPage();response=await previewPage.goto(preview);ok(response.status()===200,'authorized preview renders');
 ok((response.headers()['cache-control']||'').includes('no-store'),'preview cannot be publicly cached');
 ok((await previewPage.locator('meta[name=robots]').getAttribute('content')).includes('noindex'),'preview noindex');
 ok(!(await previewPage.content()).includes('PRIVATE_'),'private evidence omitted from preview');
 const form=admin.locator('form').filter({has:admin.locator('input[name=operation][value=publish]')});
 const formData=await form.evaluate(f=>Object.fromEntries(new FormData(f)));
 const noNonce={...formData,confirm:'1'};delete noNonce._wpnonce;
 response=await owner.request.post(base+'/wp-admin/admin-post.php',{form:noNonce});ok(response.status()===403,'publication without nonce denied');
 response=await owner.request.post(base+'/wp-admin/admin-post.php',{form:formData});ok(response.status()===403,'publication without explicit checkbox denied');
 await form.locator('[name=confirm]').check();await form.getByRole('button').click();await admin.waitForURL(/saved=1/);
 response=await page.goto(base+'/gazeta/2026-10-20/');ok(response.status()===404,'manual approval does not open closed newspaper');
 await admin.goto(base+'/wp-admin/admin.php?page=es-runtime');const opening=admin.locator('form').filter({has:admin.locator('input[name=next][value=public]')});await opening.locator('[name=confirm]').check();await opening.getByRole('button').click();await admin.waitForURL(/saved=1/);
 response=await page.goto(base+'/gazeta/2026-10-20/');ok(response.status()===200,'separate owner confirmation opens approved issue');
 let html=await page.content();ok(!html.includes('PRIVATE_'),'published issue excludes private editorial data');
 ok((await page.locator('link[rel=canonical]').getAttribute('href'))===base+'/gazeta/2026-10-20/','issue canonical stable');
 ok((await page.locator('.dateline').textContent()).includes('001'),'first number assigned automatically');
 const article=await page.locator('.hero h2 a').getAttribute('href');response=await page.goto(article);ok(response.status()===200,'article permanent URL works');
 ok(await page.locator('.dlg-body p').count()===2&&await page.locator('.dlg-body strong').count()===1,'paragraphs and rich text render');
 const structured=JSON.parse(await page.locator('script[type="application/ld+json"]').textContent());ok(structured['@graph'].some(g=>g['@type']==='NewsArticle'),'NewsArticle JSON-LD renders');
 response=await anon.request.get(base+'/wp-json/ezhednevny-standard/v1/latest');ok(response.status()===200&&!(await response.text()).includes('PRIVATE_'),'public latest API only projected data');
 // Draft edits must leave published text and URLs untouched.
 const oldTitle=await page.locator('#es-newspaper h1').textContent();
 const edit=base+'/wp-admin/admin.php?page=es-newsroom&issue='+state.issue_id+'&article=article-0';await admin.goto(edit);
 ok(await admin.locator('#es_article_body').count()===1,'native WordPress rich editor present');
 await admin.locator('[name="article[headline]"]').fill('ИСПРАВЛЕНИЕ В ЧЕРНОВИКЕ: '+oldTitle);
 await admin.locator('button').filter({hasText:'Сохранить материал'}).click();await admin.waitForURL(/saved=1/);
 await page.reload();ok((await page.locator('#es-newspaper h1').textContent())===oldTitle,'saved working edit cannot overwrite published snapshot');
 await admin.goto(base+'/wp-admin/admin.php?page=es-newsroom&issue='+state.issue_id);
 ok(await admin.getByRole('button',{name:'Утвердить и опубликовать новую версию'}).isDisabled(),'edited article loses its approval');
 // Desktop/mobile and no-JS rendering of the approved issue.
 await page.goto(base+'/gazeta/2026-10-20/');await page.setViewportSize({width:1440,height:1000});await page.screenshot({path:path.join(output,'desktop.png'),fullPage:true});
 for(const width of [320,390,768,1440]){await page.setViewportSize({width,height:900});const over=await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth+1);ok(!over,'no horizontal overflow '+width+'px');}
 await page.setViewportSize({width:390,height:844});await page.screenshot({path:path.join(output,'mobile.png'),fullPage:true});
 await page.getByRole('button',{name:'Ночной свет'}).click();ok(await page.locator('#es-newspaper').evaluate(el=>el.classList.contains('night')),'night control works');
 const dl=page.waitForEvent('download');await page.locator('[data-png]').first().click();const download=await dl;await download.saveAs(path.join(output,'social-card.png'));ok(fs.statSync(path.join(output,'social-card.png')).size>1000,'social PNG export works');
 const nojs=await browser.newContext({javaScriptEnabled:false});const staticPage=await nojs.newPage();await staticPage.goto(base+'/gazeta/2026-10-20/');ok(await staticPage.locator('.hero h2 a').count()===1,'news content and links work without JS');
 // Branding writes change neither content nor permanent URLs.
 await admin.goto(base+'/wp-admin/admin.php?page=es-appearance');await admin.locator('[name="es_appearance[name]"]').fill('Музыкальная газета');await admin.locator('[name="es_appearance[motto]"]').fill('Тест нового девиза');await admin.getByRole('button',{name:'Сохранить оформление'}).click();await admin.waitForURL(/settings-updated=true/);
 await page.goto(article);ok((await page.title()).includes('Музыкальная газета'),'branding setting changes SEO title');ok((await page.locator('link[rel=canonical]').getAttribute('href'))===article,'brand change preserves article URL');ok((await page.locator('#es-newspaper h1').textContent())===oldTitle,'branding does not alter stored article');
 await page.setViewportSize({width:320,height:800});ok(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),'new brand fits mobile');
 php("delete_option('es_appearance'); update_option('blog_public',1);");
 response=await anon.request.get(base+'/gazeta/sitemap.xml');ok(response.status()===200&&(await response.text()).includes('sitemap-articles-1.xml'),'sitemap index contains article sitemap');
 response=await anon.request.get(base+'/gazeta/sitemap-articles-1.xml');ok(response.status()===200&&(await response.text()).includes(article),'sitemap includes published article');
 response=await anon.request.get(base+'/gazeta/sitemap-articles-99.xml');ok(response.status()===404,'out-of-range sitemap is 404');
 response=await anon.request.get(base+'/gazeta/archive/page/99999/');ok(response.status()===404,'out-of-range archive is 404');
 // API authentication of the restricted importer.
 const basic=Buffer.from('es_import_test:'+state.application_password).toString('base64');
 response=await anon.request.post(base+'/wp-json/ezhednevny-standard/v1/import',{headers:{Authorization:'Basic '+basic},data:state.fixture});ok(response.status()===200&&(await response.json()).unchanged,'application password idempotent retry works');
 response=await anon.request.post(base+'/wp-json/wp/v2/posts',{headers:{Authorization:'Basic '+basic},data:{title:'must not create',status:'publish'}});ok(response.status()===403,'importer cannot create ordinary WordPress posts');
 // Withdraw through an owner form; all descendants disappear immediately.
 await admin.goto(base+'/wp-admin/admin.php?page=es-newsroom&issue='+state.issue_id);const withdrawal=admin.locator('form').filter({has:admin.locator('input[name=operation][value=withdraw]')});await withdrawal.locator('[name=confirm]').check();await withdrawal.getByRole('button').click();await admin.waitForURL(/saved=1/);
 response=await anon.request.get(article);ok(response.status()===404,'withdrawal hides every article');response=await anon.request.get(base+'/gazeta/sitemap-articles-1.xml');ok(response.status()===404,'withdrawn articles removed from sitemap');
 ok(errors.length===0,'no public JavaScript errors');
 // Restore and republish using the real editor controls for later SQL duplicate/concurrency tests.
 await admin.goto(edit);await admin.locator('[name="article[headline]"]').fill(oldTitle);await admin.locator('[name=reviewed]').check();await admin.getByRole('button',{name:'Сохранить материал'}).click();await admin.waitForURL(/saved=1/);
 const rep=admin.locator('form').filter({has:admin.locator('input[name=operation][value=publish]')});await rep.locator('[name=confirm]').check();await rep.getByRole('button').click();await admin.waitForURL(/saved=1/);
 response=await anon.request.get(base+'/gazeta/2026-10-20/');ok(response.status()===200&&(await response.text()).includes('№ 001'),'republishing preserves issue number');
 php("update_option('blog_public',0);");
 console.log(`TOTAL ${passed} browser/HTTP assertions passed`);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
