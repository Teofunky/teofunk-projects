/* Destructive fault injection. Disposable local WordPress only; NEVER use against a real site/copy. */
'use strict';
const {chromium}=require('playwright');
const fs=require('fs'),cp=require('child_process'),path=require('path');
const root=process.env.ES_TEST_ROOT, bootstrap=process.env.ES_TEST_BOOTSTRAP;
if(!root||!bootstrap||process.env.ES_TEST_ALLOW_RESET!=='1')throw Error('Explicit disposable local settings required');
const php=(code,before='')=>cp.execFileSync(process.env.ES_TEST_PHP,['-r',before+'require '+JSON.stringify(bootstrap)+';'+code],{encoding:'utf8'});
const base=process.env.ES_TEST_BASE_URL;
if(new URL(base).hostname!=='127.0.0.1')throw Error('Only isolated 127.0.0.1 permitted');
php("if(wp_get_environment_type()!=='local'||wp_parse_url(home_url(),PHP_URL_HOST)!=='127.0.0.1')exit(2);");
const state=JSON.parse(fs.readFileSync(process.env.ES_TEST_STATE,'utf8'));
const plugin=php('echo ES_DIR;'), mu=php('echo WPMU_PLUGIN_DIR;');
const fixture=path.join(mu,'es-safety-fixture.php'), kill=path.join(plugin,'DISABLE-GAZETA');
if(fs.existsSync(fixture)||fs.existsSync(kill))throw Error('Preexisting safety fixtures: refusing to overwrite');
const previous=php("echo wp_json_encode(['runtime'=>get_option('es_runtime'),'public'=>get_option('blog_public')]);");
let passed=0,restored=[],subscriber=0,otherAdmin=0;
function ok(v,label){if(!v)throw Error('FAIL '+label);passed++;console.log('PASS '+label);}
function mode(){return php('echo \\Kozlov\\Standard\\Runtime::mode();');}
function snapshot(){return php("global $wpdb;$v=[];foreach(['issues','articles','events','article_sources','identities','relations','publications','imports','revisions'] as $t){$v[$t]=$wpdb->get_results('SELECT * FROM '.$wpdb->prefix.'es_'.$t,ARRAY_A);}$v['posts']=$wpdb->get_results('SELECT * FROM '.$wpdb->posts,ARRAY_A);$v['theme']=get_option('stylesheet');$v['template']=get_option('template');$v['theme_mods']=get_theme_mods();$v['owner']=get_option('es_security');echo hash('sha256',serialize($v));");}
function mutate(file,body){const original=fs.readFileSync(file);restored.push([file,original]);fs.writeFileSync(file,body(original.toString()));}
function restore(){for(const [file,bytes] of restored.reverse())fs.writeFileSync(file,bytes);restored=[];}
(async()=>{
const browser=await chromium.launch({executablePath:process.env.ES_TEST_CHROME,headless:true,args:['--no-sandbox']});
try {
 const context=async()=>{const c=await browser.newContext();c.setDefaultTimeout(15000);c.setDefaultNavigationTimeout(20000);return c;};
 const anon=await context();
 async function login(name,password){const c=await context(),p=await c.newPage();await p.goto(base+'/wp-login.php');await p.locator('#user_login').fill(name);await p.locator('#user_pass').fill(password);await p.locator('#wp-submit').click();await p.waitForURL(/wp-admin/);return {c,p};}
 const owner=await login('es_owner','local-editor-test-pass');
 async function formData(next,session=owner){await session.p.goto(base+'/wp-admin/admin.php?page=es-runtime');return session.p.locator('form').filter({has:session.p.locator('input[name=next][value='+next+']')}).evaluate(f=>Object.fromEntries(new FormData(f)));}
 async function change(next,confirm=true){const data=await formData(next);if(confirm)data.confirm='1';const r=await owner.c.request.post(base+'/wp-admin/admin-post.php',{form:data,maxRedirects:0});if(r.status()!==302)throw Error('Mode change '+next+' failed '+r.status());}
 fs.mkdirSync(mu,{recursive:true});fs.writeFileSync(fixture,`<?php
 if(wp_get_environment_type()!=='local')return;
 add_action('send_headers',static function(){header('X-ES-Test-Modules: '.(class_exists('Kozlov\\\\Standard\\\\Repository',false)?'loaded':'absent'));});
 add_action('es_newspaper_head',static function(){echo '<meta name="es-safety-hook" content="1">';});
 add_action('es_newspaper_footer',static function(){echo '<i id="es-safety-footer"></i>';});
 `);
 let r=await anon.request.get(base+'/');ok(r.status()===200&&r.headers()['x-es-test-modules']==='absent','unrelated front page does not load editorial modules');
 await change('closed');ok(mode()==='closed','owner can close previously public newspaper');
 php("update_option('blog_public',1);");
 const issue=base+'/gazeta/2026-10-20/';r=await owner.p.goto(issue);
 ok(r.status()===200,'owner reads approved issue in closed mode');
 ok(r.headers()['x-es-test-modules']==='loaded','module diagnostic confirms positive newspaper load');
 const article=await owner.p.locator('.hero h2 a').getAttribute('href');
 const urls=['/gazeta/','/gazeta/2026-10-20/','/gazeta/archive/','/gazeta/archive/page/1/','/gazeta/issue/2026-10-20/',new URL(article).pathname,'/?es_view=latest','/?es_view=issue&es_key=2026-10-20','/?es_view=article&es_key='+new URL(article).pathname.split('/')[3],'/gazeta/preview/'+state.issue_id+'/','/gazeta/sitemap.xml','/gazeta/sitemap-issues-1.xml','/gazeta/sitemap-articles-1.xml'];
 for(const url of urls){r=await anon.request.get(base+url,{maxRedirects:0});ok(r.status()===404&&!r.headers().location&&!(await r.text()).includes('ИСПРАВЛЕНИЕ'),'anonymous closed route hidden: '+url);}
 r=await anon.request.head(issue);ok(r.status()===404&&(r.headers()['cache-control']||'').includes('no-store'),'HEAD respects closed access and no-store');
 r=await anon.request.get(base+'/robots.txt');ok(!(await r.text()).includes('/gazeta/sitemap'),'robots does not advertise private newspaper');
 r=await anon.request.get(base+'/wp-sitemap.xml');ok(!(await r.text()).includes('gazeta'),'core sitemap does not expose newspaper data');
 for(const api of ['/wp-json/ezhednevny-standard/v1/latest','/?rest_route=/ezhednevny-standard/v1/latest']){r=await anon.request.get(base+api);ok(r.status()===404&&(r.headers()['cache-control']||'').includes('no-store')&&(r.headers()['x-robots-tag']||'').includes('noindex'),'closed REST denies anonymous: '+api);}
 r=await anon.request.get(base+'/wp-json/wp/v2/types');ok(r.status()===200,'unrelated WordPress REST API remains available');
 await owner.p.goto(issue);r=await owner.c.request.get(issue);const h=r.headers();ok(h['cache-control'].includes('no-store')&&h.vary.toLowerCase().includes('cookie')&&h['x-robots-tag'].includes('noindex')&&h['referrer-policy']==='no-referrer','private newspaper has no-store Cookie noindex and no-referrer');
 ok(await owner.p.locator('link[rel=canonical],script[type="application/ld+json"],meta[name="es-safety-hook"],#es-safety-footer').count()===0,'closed HTML excludes public SEO and integration hooks');
 const social=JSON.parse(await owner.p.locator('#social-data').textContent());ok(social.preview===true&&social.issue_url===''&&social.items.every(x=>x.url===''),'closed social exports use draft mode and contain no share URLs');
 r=await owner.c.request.get(base+'/gazeta/sitemap.xml');ok(r.status()===404,'sitemap remains hidden even from logged-in owner');
 const editor=await login('es_editor_test','local-editor-test');r=await editor.c.request.get(article);ok(r.status()===200,'dedicated editor reads closed articles');
 const author=await login('es_author_test','local-author-test');r=await author.c.request.get(issue);ok(r.status()===404,'ordinary WordPress author cannot read newspaper');
 const importer=await login('es_import_test','local-import-test');r=await importer.c.request.get(issue);ok(r.status()===404,'importer cannot read private newspaper');
 subscriber=Number(php("echo wp_insert_user(['user_login'=>'es_safety_sub_'.wp_generate_password(8,false),'user_pass'=>'local-safety-sub','role'=>'subscriber']);"));
 const subName=php('echo get_userdata('+subscriber+')->user_login;');const sub=await login(subName,'local-safety-sub');r=await sub.c.request.get(article);ok(r.status()===404,'subscriber cannot read private article');
 const basic=Buffer.from('es_import_test:'+state.application_password).toString('base64');
 r=await anon.request.get(base+'/wp-json/ezhednevny-standard/v1/latest',{headers:{Authorization:'Basic '+basic}});ok(r.status()===404,'import application password grants no private read access');
 r=await anon.request.post(base+'/wp-json/ezhednevny-standard/v1/import',{headers:{Authorization:'Basic '+basic},data:state.fixture});ok(r.status()===200&&(await r.json()).unchanged,'restricted idempotent import works in closed mode');
 let data=await formData('public');let noNonce={...data,confirm:'1'};delete noNonce._wpnonce;
 r=await owner.c.request.post(base+'/wp-admin/admin-post.php',{form:noNonce});ok(r.status()===403&&mode()==='closed','opening without nonce denied');
 r=await owner.c.request.post(base+'/wp-admin/admin-post.php',{form:data});ok(r.status()===403&&mode()==='closed','opening without explicit confirmation denied');
 r=await editor.c.request.post(base+'/wp-admin/admin-post.php',{form:{...data,confirm:'1'}});ok(r.status()===403&&mode()==='closed','editor cannot open newspaper');
 otherAdmin=Number(php("echo wp_insert_user(['user_login'=>'es_safety_admin_'.wp_generate_password(8,false),'user_pass'=>'local-safety-admin','role'=>'administrator']);"));
 const adminName=php('echo get_userdata('+otherAdmin+')->user_login;');const other=await login(adminName,'local-safety-admin');await other.p.goto(base+'/wp-admin/admin.php?page=es-runtime');
 ok(await other.p.locator('input[name=next][value=public]').count()===0,'unselected administrator has no public opening control');
 r=await other.c.request.post(base+'/wp-admin/admin-post.php',{form:{...data,confirm:'1'}});ok(r.status()===403&&mode()==='closed','unselected administrator cannot open newspaper');
 await change('public');r=await anon.request.get(issue);ok(r.status()===200&&(await r.text()).includes('es-safety-hook'),'owner confirmed opening exposes only approved snapshot');
 await change('closed');
 const stable=snapshot();
 await change('paused');ok(mode()==='paused','pause control stops newspaper');
 r=await owner.c.request.get(issue);ok(r.status()===404,'pause hides newspaper even from owner');
 r=await anon.request.post(base+'/wp-json/ezhednevny-standard/v1/import',{headers:{Authorization:'Basic '+basic},data:state.fixture});ok(r.status()===404,'pause denies importer API writes');
 const blocked=php("wp_set_current_user(1);$r=\\Kozlov\\Standard\\Repository::import('"+JSON.stringify(state.fixture).replace(/'/g,"\\'")+"');echo is_wp_error($r)?'blocked':'allowed';");ok(blocked==='blocked','pause also guards repository write entry');
 r=await anon.request.get(base+'/');ok(r.status()===200&&r.headers()['x-es-test-modules']==='absent','home remains independent while paused');
 await change('closed');ok(snapshot()===stable,'pause/resume preserves material history owner theme and WordPress content');
 // Test the early file gate while module code is deliberately unparsable.
 mutate(path.join(plugin,'includes/class-validation.php'),s=>s+'\nfunction { invalid syntax');
 fs.writeFileSync(kill,'');
 r=await owner.c.request.get(base+'/wp-admin/plugins.php');ok(r.status()===200&&(await r.text()).includes('DISABLE-GAZETA'),'emergency file bypasses broken module and leaves admin usable');
 r=await anon.request.get(base+'/wp-json/ezhednevny-standard/v1/latest');ok(r.status()===404,'emergency file removes newspaper API');
 ok(php("echo class_exists('Kozlov\\\\Standard\\\\Repository',false)?'loaded':'absent';")==='absent','emergency file prevents module loading');
 fs.unlinkSync(kill);
 ok(php("echo get_option('es_runtime')['mode'];")==='paused','removing emergency flag cannot reopen formerly public access');
 ok(php("echo class_exists('Kozlov\\\\Standard\\\\Runtime',false)?'loaded':'absent';","define('KOZLOV_GAZETA_DISABLED',true);")==='absent','wp-config emergency constant bypasses even runtime loader');
 r=await anon.request.get(base+'/');ok(r.status()===200,'broken editorial module cannot break unrelated home');
 r=await owner.c.request.get(base+'/wp-admin/plugins.php');ok(r.status()===200,'module ParseError leaves WordPress admin usable');
 r=await owner.c.request.get(issue);ok(r.status()===404||r.status()===503,'module ParseError returns controlled unavailable response');
 ok(mode()==='paused'&&php("echo get_option('es_runtime_fault')['type'];")==='ParseError','module ParseError persists automatic pause');
 await owner.p.goto(base+'/wp-admin/admin.php?page=es-runtime');ok(await owner.p.getByRole('heading',{name:'Режим и остановка газеты'}).count()===1,'minimal recovery screen works while module is broken');
 data=await formData('closed');r=await owner.c.request.post(base+'/wp-admin/admin-post.php',{form:{...data,confirm:'1'}});ok(r.status()===503&&mode()==='paused','resume with still broken module fails closed');
 restore();await change('closed');ok(mode()==='closed'&&php("echo get_option('es_runtime_fault')?'fault':'clean';")==='clean','corrected module resumes only into closed mode');
 // Rendering exception must discard the buffered partial newspaper before 503.
 mutate(path.join(plugin,'templates/newspaper.php'),s=>s.replace("if (!defined('ABSPATH')) { exit; }","if (!defined('ABSPATH')) { exit; } echo 'ES_SECRET_PARTIAL_RENDER'; throw new \\RuntimeException('LOCAL_FAULT');"));
 r=await owner.c.request.get(issue);const failureBody=await r.text();ok(r.status()===503&&!failureBody.includes('ES_SECRET_PARTIAL_RENDER')&&!failureBody.includes('LOCAL_FAULT'),'template exception discards partial content and returns generic 503');
 ok(mode()==='paused','template exception automatically pauses next request');
 restore();await change('closed');ok(snapshot()===stable,'failure and recovery preserve all content and original site settings');
 // Native plugin lifecycle, not just direct method calls.
 await change('public');
 php("require_once ABSPATH.'wp-admin/includes/plugin.php';deactivate_plugins(plugin_basename(ES_FILE));");
 r=await anon.request.get(base+'/');ok(r.status()===200&&r.headers()['x-es-test-modules']==='absent','native WordPress deactivation keeps main site available');
 php("require_once ABSPATH.'wp-admin/includes/plugin.php';wp_set_current_user(1);$r=activate_plugin('kozlov-jazzeta/kozlov-jazzeta.php');if(is_wp_error($r)){fwrite(STDERR,$r->get_error_message());exit(2);}");
 ok(snapshot()===stable&&mode()==='closed','native reactivation preserves database and closed access');
 php("update_option('es_runtime',['mode'=>'invalid']);");ok(mode()==='closed','unknown mode value fails closed');
 r=await anon.request.get(issue);ok(r.status()===404,'unknown mode never enables anonymous access');
 console.log(`TOTAL ${passed} safety/closed-launch assertions passed`);
} finally {
 restore();if(fs.existsSync(kill))fs.unlinkSync(kill);if(fs.existsSync(fixture))fs.unlinkSync(fixture);
 const encoded=Buffer.from(previous).toString('base64');
 php("delete_option('es_runtime_fault');$s=json_decode(base64_decode('"+encoded+"'),true);update_option('es_runtime',$s['runtime']);update_option('blog_public',$s['public']);require_once ABSPATH.'wp-admin/includes/user.php';"+(subscriber?'wp_delete_user('+subscriber+');':'')+(otherAdmin?'wp_delete_user('+otherAdmin+');':''));
 await browser.close();
}
})().catch(e=>{console.error(e);process.exit(1)});
