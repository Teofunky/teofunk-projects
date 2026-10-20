/* Regression cases prompted by the independent 0.3.0 audit. Disposable localhost only. */
'use strict';
const {chromium}=require('playwright');
const fs=require('fs'),cp=require('child_process'),path=require('path');
const root=process.env.ES_TEST_ROOT;
if(!root||process.env.ES_TEST_ALLOW_RESET!=='1')throw Error('Explicit local test settings required');
const php=code=>cp.execFileSync(process.env.ES_TEST_PHP||path.join(root,'runtime/php'),['-r',`require ${JSON.stringify(process.env.ES_TEST_BOOTSTRAP||root+'/runtime/wordpress/wp-load.php')}; ${code}`],{encoding:'utf8'});
const base=process.env.ES_TEST_BASE_URL||'http://127.0.0.1:18080';
if(new URL(base).hostname!=='127.0.0.1')throw Error('Only 127.0.0.1 is permitted');
php("if(wp_get_environment_type()!=='local'||wp_parse_url(home_url(),PHP_URL_HOST)!=='127.0.0.1')exit(2);");
const output=process.env.ES_TEST_OUTPUT||path.join(root,'runtime');
const state=JSON.parse(fs.readFileSync(process.env.ES_TEST_STATE||path.join(root,'runtime/test-state.json'),'utf8'));
const b64=v=>Buffer.from(JSON.stringify(v)).toString('base64');
const previous=JSON.parse(php("echo wp_json_encode(['appearance'=>get_option('es_appearance',null),'public'=>get_option('blog_public'),'permalinks'=>get_option('permalink_structure')]);"));
let passed=0,logo=0,fixtureInstalled=false;
function ok(v,label){if(!v)throw Error('FAIL '+label);passed++;console.log('PASS '+label);}
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.ES_TEST_CHROME||undefined,headless:true,args:['--no-sandbox']});
 try {
 const ctx=await browser.newContext();ctx.setDefaultTimeout(15000);ctx.setDefaultNavigationTimeout(20000);
 const page=await ctx.newPage(),issue=base+'/gazeta/2026-10-20/';
 let r=await ctx.request.get(base+'/gazeta/issue/2026-10-20/',{maxRedirects:0});
 ok(r.status()===301&&r.headers().location===issue,'legacy issue URL redirects directly to short canonical');
 r=await ctx.request.get(base+'/gazeta/issue/2026-10-21/',{maxRedirects:0});ok(r.status()===404,'legacy URL does not redirect an unpublished issue');
 r=await ctx.request.get(base+'/gazeta/2026-02-30/');ok(r.status()===404,'impossible short date returns 404');
 await page.goto(issue);ok(await page.locator('link[rel=canonical]').getAttribute('href')===issue,'short issue canonical');
 let schema=JSON.parse(await page.locator('script[type="application/ld+json"]').textContent());ok(!JSON.stringify(schema).includes('/gazeta/issue/'),'issue structured data uses short URLs');
 const article=await page.locator('.hero h2 a').getAttribute('href');await page.goto(article);schema=JSON.parse(await page.locator('script[type="application/ld+json"]').textContent());ok(schema['@graph'].some(g=>g['@type']==='NewsArticle'&&g.isPartOf.url===issue),'article isPartOf points to short issue URL');
 await page.goto(base+'/gazeta/archive/');ok(await page.locator('.archive-panel a').first().getAttribute('href')===issue,'archive uses short issue URL');
 php("update_option('blog_public',1);");r=await ctx.request.get(base+'/gazeta/sitemap-issues-1.xml');const xml=await r.text();ok(xml.includes(issue)&&!xml.includes('/gazeta/issue/'),'sitemap uses short issue URL');
 php("update_option('permalink_structure','');");const fallback=php("echo \\Kozlov\\Standard\\Front::url('issue/2026-10-20');");r=await ctx.request.get(fallback);ok(r.status()===200&&fallback.includes('es_view=issue'),'plain permalink fallback still resolves issue');php("update_option('permalink_structure',json_decode(base64_decode('"+b64(previous.permalinks)+"')));");
 const fixture=Buffer.from(fs.readFileSync(path.join(__dirname,'integration-hook-fixture.php'))).toString('base64');
 php("$f=WPMU_PLUGIN_DIR.'/es-newspaper-audit-fixture.php';if(file_exists($f))exit(2);wp_mkdir_p(WPMU_PLUGIN_DIR);if(file_put_contents($f,base64_decode('"+fixture+"'))===false)exit(2);");fixtureInstalled=true;
 await page.goto(issue);ok(await page.locator('meta[name="es-audit-head"]').count()===1&&await page.locator('#es-audit-footer').count()===1,'isolated integration hooks run on public newspaper');
 ok(await page.locator('meta[name="es-unwanted-theme-hook"]').count()===0,'integration hooks do not invoke theme wp_head');
 const owner=await browser.newContext(),admin=await owner.newPage();owner.setDefaultTimeout(15000);
 await admin.goto(base+'/wp-login.php');await admin.locator('#user_login').fill('es_owner');await admin.locator('#user_pass').fill('local-editor-test-pass');await admin.locator('#wp-submit').click();await admin.waitForURL(/wp-admin/);
 await admin.goto(base+'/wp-admin/admin.php?page=es-newsroom&issue='+state.issue_id);const preview=await admin.getByRole('link',{name:'Предпросмотр газеты'}).getAttribute('href');await admin.goto(preview);
 ok(await admin.locator('meta[name="es-audit-head"],#es-audit-footer').count()===0,'integration hooks never run in editorial preview');
 const brands=['Джаз','Jazz Москва — Music','W'.repeat(80),'Международная'+ 'М'.repeat(50)+' газета'];
 for(const [index,name] of brands.entries()){
  const appearance={name,subtitle:'Подзаголовок'.repeat(12),motto:'Девиз'.repeat(24)};
  php("update_option('es_appearance',json_decode(base64_decode('"+b64(appearance)+"'),true));");
  for(const width of [320,390]){
   await page.setViewportSize({width,height:900});await page.goto(issue);
   ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`brand boundary ${index+1}: no overflow at ${width}px`);
  }
  if(index===2){const dl=page.waitForEvent('download');await page.locator('[data-png]').first().click();const download=await dl;await download.saveAs(path.join(output,'long-brand-card.png'));ok(fs.statSync(path.join(output,'long-brand-card.png')).size>1000,'80-character single-word brand exports PNG');await page.screenshot({path:path.join(output,'long-brand-mobile.png'),fullPage:true});}
 }
 const png=await page.evaluate(()=>{const c=document.createElement('canvas');c.width=1200;c.height=240;const x=c.getContext('2d');x.fillStyle='#f3f0e7';x.fillRect(0,0,1200,240);x.fillStyle='#191d1b';x.font='bold 90px serif';x.fillText('LOCAL LOGO TEST',100,150);return c.toDataURL('image/png').split(',')[1];});
 logo=Number(php("$u=wp_upload_bits('es-audit-logo.png',null,base64_decode('"+png+"'));if($u['error'])exit(2);$id=wp_insert_attachment(['post_title'=>'Local audit fixture','post_mime_type'=>'image/png','post_status'=>'inherit'],$u['file']);if(is_wp_error($id))exit(2);wp_update_attachment_metadata($id,['width'=>1200,'height'=>240,'file'=>_wp_relative_upload_path($u['file'])]);echo $id;"));
 await admin.goto(base+'/wp-admin/admin.php?page=es-appearance');await admin.locator('[name="es_appearance[logo_id]"]').fill(String(logo));await admin.getByRole('button',{name:'Сохранить оформление'}).click();await admin.waitForURL(/settings-updated=true/);
 for(const width of [320,390]){await page.setViewportSize({width,height:900});await page.goto(issue);ok(await page.locator('.mast-logo img').evaluate(el=>el.complete&&el.naturalWidth===1200&&el.getBoundingClientRect().width<=innerWidth)&&await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`media logo fits and loads at ${width}px`);}
 ok(await page.locator('link[rel=canonical]').getAttribute('href')===issue,'brand boundary and logo changes preserve canonical');
 console.log(`TOTAL ${passed} audit regression assertions passed`);
 }finally{
  const saved=b64(previous);
  php("$s=json_decode(base64_decode('"+saved+"'),true);if($s['appearance']===null){delete_option('es_appearance');}else{update_option('es_appearance',$s['appearance']);}update_option('blog_public',$s['public']);update_option('permalink_structure',$s['permalinks']);"+(logo?"wp_delete_attachment("+logo+",true);":""));
  if(fixtureInstalled){const f=path.join(php('echo WPMU_PLUGIN_DIR;'),'es-newspaper-audit-fixture.php');if(fs.existsSync(f))fs.unlinkSync(f);}
  await browser.close();
 }
})().catch(e=>{console.error(e);process.exit(1)});
