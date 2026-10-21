/* Destructive regression tests, explicitly disposable localhost only. */
'use strict';
const {chromium}=require('playwright'),fs=require('fs'),cp=require('child_process'),path=require('path');
const root=process.env.ES_TEST_ROOT,base=process.env.ES_TEST_BASE_URL,output=process.env.ES_TEST_OUTPUT;
if(!root||process.env.ES_TEST_ALLOW_RESET!=='1'||new URL(base).hostname!=='127.0.0.1')throw Error('Disposable localhost opt-in required');
const php=code=>cp.execFileSync(process.env.ES_TEST_PHP,['-r',`use \\Kozlov\\Standard\\Repository as R;require ${JSON.stringify(process.env.ES_TEST_BOOTSTRAP)};if(wp_get_environment_type()!=='local'||wp_parse_url(home_url(),PHP_URL_HOST)!=='127.0.0.1')exit(2);${code}`],{encoding:'utf8'});
const b64=v=>Buffer.from(JSON.stringify(v)).toString('base64');
const state=JSON.parse(php("echo wp_json_encode(['runtime'=>get_option('es_runtime'),'theme'=>get_option('stylesheet'),'plugins'=>get_option('active_plugins'),'security'=>get_option('es_security')]);"));
const fixturePath=path.join(root,'runtime/wordpress/wp-content/mu-plugins/es-owner-import-fixture.php');
if(fs.existsSync(fixturePath))throw Error('Existing test fixture; inspect before cleaning');
fs.copyFileSync(path.join(__dirname,'owner-import-fixture.php'),fixturePath);
const setup=JSON.parse(php(`wp_set_current_user(1);update_option('es_db_version',ES_VERSION);update_option('es_security',['publisher_id'=>1]);update_option('es_runtime',['mode'=>'closed']);delete_option('es_test_manual_audit_failure');require ${JSON.stringify(path.join(__dirname,'fixtures.php'))};$d=fixture('2026-09-15','owner-manual');$d['editorial']['source_scan_window_start']='2026-09-01T10:00:00+03:00';$d['editorial']['source_scan_window_end']='2026-09-15T10:00:00+03:00';foreach($d['articles'] as &$story){$story['sources'][0]['published_at']='2026-09-01';$story['sources'][0]['verification_checked_at']='2026-09-15T10:00:00+03:00';}unset($story);$r=R::import(\\Kozlov\\Standard\\DB::json($d));if(is_wp_error($r))throw new Exception($r->get_error_message());$id=$r['id'];$a=$d['articles'][0];$a['headline']='Ручной заголовок до импорта';$saved=R::save_article($id,$r['version'],$a,true,'Проверка до замены','Прежняя заметка',$d['social'][0]);if(is_wp_error($saved))throw new Exception($saved->get_error_message());$other=get_user_by('login','es_other_admin');if(!$other)wp_insert_user(['user_login'=>'es_other_admin','user_pass'=>'local-only-other-admin','role'=>'administrator']);echo wp_json_encode(['id'=>$id,'raw'=>$d]);`));
const id=setup.id;let passed=0;
const ok=(v,n)=>{if(!v)throw Error('FAIL '+n);console.log('PASS '+n);passed++;};
const issue=()=>JSON.parse(php(`$r=\\Kozlov\\Standard\\Repository::issue(${id});echo wp_json_encode(['row'=>$r,'doc'=>\\Kozlov\\Standard\\Repository::doc($r),'articles'=>\\Kozlov\\Standard\\Repository::rows(${id},false)]);`));
const snapshot=()=>php(`global $wpdb;$s=[];foreach(['issues','articles','revisions','imports','events','article_sources','identities','relations','publications'] as $t)$s[$t]=$wpdb->get_results('SELECT * FROM '.\\Kozlov\\Standard\\DB::table($t).' ORDER BY 1',ARRAY_A);echo hash('sha256',serialize($s));`);
const payload=structuredClone(setup.raw);payload.articles[0].headline='Новая версия после ручного импорта';payload.articles.pop();payload.social.pop();payload.articles[2].id='new-manual-article';payload.social[2].article_id='new-manual-article';
(async()=>{const browser=await chromium.launch({executablePath:process.env.ES_TEST_CHROME,headless:true,args:['--no-sandbox']});try{
 const owner=await browser.newContext({viewport:{width:1280,height:950}});owner.setDefaultTimeout(15000);const p=await owner.newPage();
 const login=async(ctx,page,user,pass)=>{await page.goto(base+'/wp-login.php');await page.locator('#user_login').fill(user);await page.locator('#user_pass').fill(pass);await page.locator('#wp-submit').click();await page.waitForURL(/wp-admin/);};
 await login(owner,p,'es_owner','local-editor-test-pass');
 const editorUrl=base+'/wp-admin/admin.php?page=es-newsroom&issue='+id;
 const form=op=>p.locator('form').filter({has:p.locator(`[name=operation][value=${op}]`)});
 const prepare=async(raw=payload)=>{await p.goto(editorUrl);await p.getByRole('link',{name:'Заменить черновик из JSON…'}).click();await form('import_preview').locator('[name=raw]').fill(JSON.stringify(raw));return form('import_preview').evaluate(f=>Object.fromEntries(new FormData(f)));};
 const review=async(raw=payload)=>{await prepare(raw);await form('import_preview').getByRole('button').click();await p.getByRole('heading',{name:'Подтверждение ручного импорта'}).waitFor();return form('manual_import').evaluate(f=>Object.fromEntries(new FormData(f)));};
 const post=data=>owner.request.post(base+(data.operation==='import_preview'?'/wp-admin/admin.php?page=es-import&issue='+id:'/wp-admin/admin-post.php'),{form:data,maxRedirects:0});
 const original=issue(),stable=snapshot();
 let prepared=await prepare();let r=await post({...prepared,_wpnonce:''});ok(r.status()===403&&snapshot()===stable,'preparation requires CSRF nonce and makes no changes');
 r=await post({...prepared,raw:'{bad'});ok(r.status()===400&&snapshot()===stable,'invalid JSON rejected before confirmation');
 r=await post({...prepared,raw:JSON.stringify({...payload,edition_date:'2026-09-16'})});ok(r.status()===400&&snapshot()===stable,'JSON cannot redirect replacement to another date');
 r=await post({...prepared,raw:'x'.repeat(262145)});ok(r.status()===413&&snapshot()===stable,'oversized owner import rejected');
 let data=await review();ok(snapshot()===stable,'confirmation preparation creates no revision or write');
 ok((await p.locator('table').last().textContent()).includes('Будет убран')&&(await p.locator('table').last().textContent()).includes('Будет добавлен'),'confirmation shows additions and removals');
 ok(await form('manual_import').locator('[name=confirm]').isChecked()===false,'explicit replacement checkbox starts unchecked');
 await p.screenshot({path:path.join(output,'manual-import-confirmation.png'),fullPage:true});
 r=await post(data);ok(r.status()===403&&snapshot()===stable,'missing explicit owner confirmation rejected');
 r=await post({...data,confirm:'1',_wpnonce:''});ok(r.status()===403&&snapshot()===stable,'commit requires action nonce');
 r=await post({...data,confirm:'1',raw:data.raw.replace('Новая версия','Подменённая версия')});ok(r.status()===403&&snapshot()===stable,'payload cannot change after review');
 const other=await browser.newContext(),op=await other.newPage();await login(other,op,'es_other_admin','local-only-other-admin');
 r=await op.goto(base+'/wp-admin/admin.php?page=es-import&issue='+id);ok(r.status()===403,'another administrator cannot open owner replacement');
 r=await other.request.post(base+'/wp-admin/admin-post.php',{form:{...data,confirm:'1'},maxRedirects:0});ok(r.status()===403&&snapshot()===stable,'another administrator cannot submit owner replacement');
 const imp=await browser.newContext(),ip=await imp.newPage();await login(imp,ip,'es_import_test','local-import-test');r=await imp.request.post(base+'/wp-admin/admin-post.php',{form:{...data,confirm:'1'},maxRedirects:0});ok(r.status()===403&&snapshot()===stable,'importer cannot use manual replacement endpoint');
 const anon=await browser.newContext();r=await anon.request.post(base+'/wp-admin/admin-post.php',{form:{...data,confirm:'1'},maxRedirects:0});ok(r.status()!==302&&snapshot()===stable,'anonymous replacement denied');
 const direct=php(`wp_set_current_user(1);$r=\\Kozlov\\Standard\\Repository::manual_import_from_admin(${id},${original.row.version},base64_decode('${Buffer.from(data.raw).toString('base64')}'));echo is_wp_error($r)?$r->get_error_data()['status']:'unsafe';`);ok(direct==='403'&&snapshot()===stable,'direct repository call cannot bypass browser permit');
 // Both audit failures must roll back the complete draft and derived identities.
 for(const action of ['before_manual_import','manual_import']){php(`update_option('es_test_manual_audit_failure','${action}');`);r=await post({...data,confirm:'1'});php("delete_option('es_test_manual_audit_failure');");ok(r.status()===500&&snapshot()===stable,action+' failure rolls back all import tables');}
 // Edit between preparation and confirmation: the old form must not overwrite it.
 php(`wp_set_current_user(1);$r=\\Kozlov\\Standard\\Repository::issue(${id});$d=\\Kozlov\\Standard\\Repository::doc($r);$a=$d['articles'][0];$a['headline']='Правка из другой вкладки';$social=null;foreach($d['social'] as $item){if($item['article_id']===$a['id'])$social=$item;}$res=\\Kozlov\\Standard\\Repository::save_article(${id},$r['version'],$a,false,'','',$social);if(is_wp_error($res))throw new Exception($res->get_error_message());`);
 const changed=snapshot();r=await post({...data,confirm:'1'});ok(r.status()===409&&snapshot()===changed,'concurrent human edit makes confirmation stale');
 data=await review();const before=issue();
 const responses=await Promise.all([post({...data,confirm:'1'}),post({...data,confirm:'1'})]);ok(responses.map(x=>x.status()).sort().join(',')==='302,409','double submission replaces once and rejects stale retry');
 const after=issue();ok(Number(after.row.version)===Number(before.row.version)+1&&after.row.state==='draft'&&after.row.public_json===null&&Number(after.row.public_version)===0,'successful replacement increments only draft version');
 ok(after.doc.articles.length===3&&after.doc.articles[0].headline===payload.articles[0].headline&&after.doc.articles.some(a=>a.id==='new-manual-article'),'owner can replace previously human-edited draft');
 ok(after.articles.filter(a=>Number(a.active)).every(a=>!a.reviewed_by&&!a.reviewed_hash&&!a.review_note&&!a.new_fact)&&after.doc.editorial.ready_for_human_review===false,'all human approvals and readiness reset');
 ok(Number(after.row.human_touched)===1&&!after.doc.import_id,'replacement stays protected from automatic imports');
 const history=JSON.parse(php(`global $wpdb;echo wp_json_encode($wpdb->get_results('SELECT * FROM '.\\Kozlov\\Standard\\DB::table('revisions').' WHERE issue_id=${id} ORDER BY id DESC LIMIT 2',ARRAY_A));`));
 ok(history[0].action==='manual_import'&&history[1].action==='before_manual_import'&&Number(history[1].version)===Number(before.row.version)&&Number(history[1].actor_id)===1,'audit records owner backup and new revision');
 const backup=JSON.parse(history[1].snapshot);ok(JSON.stringify(backup.document)===JSON.stringify(before.doc)&&backup.reviews.length===before.doc.articles.length,'backup contains exact previous document and review data');
 const auto={...payload,import_id:'must-not-overwrite-owner-manual'};
 const blocked=php(`wp_set_current_user(1);$r=\\Kozlov\\Standard\\Repository::import(base64_decode('${Buffer.from(JSON.stringify(auto)).toString('base64')}'),${after.row.version});echo is_wp_error($r)?$r->get_error_data()['status']:'unsafe';`);ok(blocked==='409','ordinary import still rejects human-touched draft');
 const app=JSON.parse(php("$u=get_user_by('login','es_import_test');$a=WP_Application_Passwords::create_new_application_password($u->ID,['name'=>'local-manual-regression']);echo wp_json_encode(['password'=>$a[0],'uuid'=>$a[1]['uuid'],'user'=>$u->ID]);"));
 try {r=await anon.request.post(base+'/wp-json/ezhednevny-standard/v1/import',{headers:{Authorization:'Basic '+Buffer.from('es_import_test:'+app.password).toString('base64'),'If-Match':String(after.row.version)},data:auto});ok(r.status()===403||r.status()===409,'REST importer cannot overwrite owner replacement');r=await owner.request.post(base+'/wp-json/ezhednevny-standard/v1/manual-import',{data:auto});ok(r.status()===404,'no force or manual replacement REST route');}finally{php(`WP_Application_Passwords::delete_application_password(${app.user},'${app.uuid}');`);}
 // Restore the saved backup through the regular versioned editor form.
 await p.goto(editorUrl);const restore=p.locator('form').filter({has:p.locator(`[name=revision_id][value="${history[1].id}"]`)});await restore.getByRole('button').click();await p.waitForURL(/saved=1/);const restored=issue();const expected=structuredClone(before.doc);expected.editorial.ready_for_human_review=false;ok(JSON.stringify(restored.doc)===JSON.stringify(expected),'saved backup can be restored through normal editor');
 // Every trace of prior publication blocks replacement, even a withdrawn issue.
 data=await review();
 for(const [column,value] of [['state',"'published'"],['state',"'withdrawn'"],['public_version','3'],['public_json',"'{}'"],['published_at',"'2026-09-15 10:00:00'"],['number','7']]){
  const oldValue=restored.row[column];php(`global $wpdb;$wpdb->query('UPDATE '.\\Kozlov\\Standard\\DB::table('issues')." SET ${column}=${value} WHERE id=${id}");`);
  const denied=php(`wp_set_current_user(1);$r=\\Kozlov\\Standard\\Repository::manual_import_candidate(${id},${restored.row.version},base64_decode('${Buffer.from(JSON.stringify(payload)).toString('base64')}'));echo is_wp_error($r)?$r->get_error_data()['status']:'unsafe';`);ok(denied==='409','prior publication guard: '+column+'='+value);const publishedSnapshot=snapshot();r=await post({...data,confirm:'1'});ok(r.status()===409&&snapshot()===publishedSnapshot,'commit cannot overwrite publication trace: '+column);
  php(`global $wpdb;$wpdb->update(\\Kozlov\\Standard\\DB::table('issues'),['${column}'=>json_decode(base64_decode('${b64(oldValue)}'),true)],['id'=>${id}]);`);
 }
 php(`global $wpdb;$wpdb->query('UPDATE '.\\Kozlov\\Standard\\DB::table('articles')." SET public_json='{}' WHERE issue_id=${id} LIMIT 1");`);
 ok(php(`wp_set_current_user(1);$r=\\Kozlov\\Standard\\Repository::manual_import_candidate(${id},${restored.row.version},base64_decode('${Buffer.from(JSON.stringify(payload)).toString('base64')}'));echo is_wp_error($r)?$r->get_error_data()['status']:'unsafe';`)==='409','article public snapshot independently blocks replacement');
 php(`global $wpdb;$wpdb->update(\\Kozlov\\Standard\\DB::table('articles'),['public_json'=>null],['issue_id'=>${id}]);`);
 // Integration hooks and toolbar under native classic and nonclassic fallback shells.
 php("require_once ABSPATH.'wp-admin/includes/plugin.php';$r=activate_plugin('wpglobus/wpglobus.php');if(is_wp_error($r))throw new Exception($r->get_error_message());");await p.goto(editorUrl);await p.goto(editorUrl);
 const themes=['KozlovCLub','twentytwentyfive'];
 for(const theme of themes){
  php(`if(!wp_get_theme('${theme}')->exists())exit(2);switch_theme('${theme}');update_option('es_runtime',['mode'=>'public']);`);
  const page=await owner.newPage();let loads=0;await page.route('**/*',route=>{const url=route.request().url();if(url.startsWith('https://es-integration.invalid/')){loads++;return route.fulfill({contentType:'application/javascript',body:'window.esLocalAnalytics=(window.esLocalAnalytics||0)+1;'});}if(!url.startsWith(base))return route.abort();return route.continue();});
  r=await page.goto(base+'/gazeta/');await page.waitForLoadState('load');ok(await page.locator('#wpadminbar').count()===1&&await page.locator('#wpadminbar').isVisible(),theme+' native admin toolbar visible exactly once');
  ok((r.headers()['cache-control']||'').includes('no-store')&&(r.headers().vary||'').toLowerCase().includes('cookie'),theme+' logged-in HTML cannot enter shared cache');
  ok(await page.evaluate(()=>['head','body','footer','newspaper-head','newspaper-footer'].every(n=>window.esLocalHooks.filter(x=>x===n).length===1)),theme+' WordPress and public integration hooks run once');
  ok(loads===1&&await page.evaluate(()=>window.esLocalAnalytics)===1,theme+' public analytics executes once');
  const json=await page.locator('script[type="application/ld+json"]').allTextContents();const graph=json.flatMap(s=>JSON.parse(s)['@graph']||[]);ok(graph.some(n=>n['@id']==='#es-local-org')&&graph.some(n=>n['@id']==='#es-local-site')&&!graph.some(n=>String(n['@id']).includes('wrong-')),theme+' site SEO entities retained and stale page schema removed');
  ok(await page.locator('title').count()===1&&await page.locator('link[rel=canonical]').count()===1&&await page.locator('meta[name=robots]').count()===1&&await page.locator('meta[name=google-site-verification]').getAttribute('content')==='ES_LOCAL_VERIFICATION',theme+' single canonical metadata and verification retained');
  await p.goto(editorUrl);const preview=await p.getByRole('link',{name:'Предпросмотр газеты'}).getAttribute('href');loads=0;r=await page.goto(preview);await page.waitForLoadState('load');
  ok(loads===0&&(r.headers()['content-security-policy']||'').includes("connect-src 'self'"),theme+' private preview blocks external analytics');
  ok(await page.locator('#wpadminbar').count()===1&&await page.locator('link[rel=canonical],script[type="application/ld+json"]').count()===0,theme+' private toolbar retained without public SEO');
  ok(await page.evaluate(()=>['head','body','footer'].every(n=>window.esLocalHooks.filter(x=>x===n).length===1)&&!window.esLocalHooks.some(n=>n.startsWith('newspaper-'))),theme+' private native hooks retained; public-only hooks skipped');
  await page.screenshot({path:path.join(output,'toolbar-'+theme+'.png'),fullPage:false});await page.close();
 }
 console.log('TOTAL '+passed+' owner import and integration assertions passed');
}finally{php(`$s=json_decode(base64_decode('${b64(state)}'),true);switch_theme($s['theme']);update_option('active_plugins',$s['plugins']);update_option('es_runtime',$s['runtime']);update_option('es_security',$s['security']);delete_option('es_test_manual_audit_failure');`);fs.unlinkSync(fixturePath);await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});
