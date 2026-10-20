<?php
/** Destructive ONLY to plugin tables on an explicitly designated localhost test WordPress. */
if (PHP_SAPI!=='cli') { exit; }
if (getenv('ES_TEST_ALLOW_RESET')!=='1'||!getenv('ES_TEST_BOOTSTRAP')) { fwrite(STDERR,"Set ES_TEST_ALLOW_RESET=1 and ES_TEST_BOOTSTRAP for a disposable local WordPress.\n"); exit(2); }
require getenv('ES_TEST_BOOTSTRAP');
if (wp_get_environment_type()!=='local'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['127.0.0.1','localhost'],true)) { fwrite(STDERR,"Refusing nonlocal installation\n"); exit(2); }
use Kozlov\Standard\DB; use Kozlov\Standard\Repository as R; use Kozlov\Standard\Validation as V; use Kozlov\Standard\Duplicates as D; use Kozlov\Standard\Rest; use Kozlov\Standard\Migration;
$passed=0;
function ok($value,$name) { global $passed; if (!$value) { fwrite(STDERR,"FAIL: $name\n"); exit(1); } $passed++; echo "PASS: $name\n"; }
function err($value,$status=null) { return is_wp_error($value)&&($status===null||($value->get_error_data()['status']??null)===$status); }
require __DIR__.'/fixtures.php';

wp_set_current_user(1); delete_option('es_runtime_fault'); delete_option('es_runtime'); \Kozlov\Standard\Runtime::load(); DB::activate();
ok(\Kozlov\Standard\Runtime::mode()==='closed','new activation defaults to closed editorial mode');
global $wpdb;
foreach (['imports','relations','identities','article_sources','publications','events','revisions','articles','issues','state'] as $t) { $wpdb->query('TRUNCATE TABLE '.DB::table($t)); }
$wpdb->query('INSERT INTO '.DB::table('state')." (name,value) VALUES ('number',0),('catalog',1),('write',0)");
$core_before=$wpdb->get_results('SELECT ID,post_type,post_status,post_content FROM '.$wpdb->posts.' ORDER BY ID',ARRAY_A);
$importer=get_user_by('login','es_import_test'); $importer=$importer?$importer->ID:wp_insert_user(['user_login'=>'es_import_test','user_pass'=>'local-import-test','role'=>'es_importer']);
$editor=get_user_by('login','es_editor_test');$editor=$editor?$editor->ID:wp_insert_user(['user_login'=>'es_editor_test','user_pass'=>'local-editor-test','role'=>'es_editor']);
$author=get_user_by('login','es_author_test');$author=$author?$author->ID:wp_insert_user(['user_login'=>'es_author_test','user_pass'=>'local-author-test','role'=>'author']);
$fixture=fixture();$raw=DB::json($fixture);
ok(!err(V::parse($raw)),'schema 1.1 accepts complete synthetic draft');
$old=$fixture;$old['schema_version']='1.0';foreach($old['articles'] as &$a)unset($a['event_id'],$a['previous_article_ids'],$a['development_note']);unset($a);
ok(!err(V::parse(DB::json($old))),'original schema 1.0 gets event_id fallback');
foreach (['publication'=>['status'=>'publish'],'edition_number'=>1,'extra'=>'injection'] as $k=>$v) { $x=$fixture;$x[$k]=$v;ok(err(V::parse(DB::json($x))),'reject prohibited field/value '.$k); }
ok(err(V::parse(str_repeat('x',V::MAX_BYTES+1)),413),'body size limit');
ok(err(V::parse('{bad')),'invalid JSON fails');
$x=$fixture;$x['edition_date']='2026-02-30';ok(err(V::parse(DB::json($x))),'impossible date fails');
$x=$fixture;$x['articles'][1]['id']=$x['articles'][0]['id'];ok(err(V::parse(DB::json($x))),'duplicate article IDs fail');
$x=$fixture;$x['articles'][0]['claims'][0]['source_ids']=['missing'];ok(err(V::parse(DB::json($x))),'dangling fact source fails');
foreach (['javascript:alert(1)','https://127.0.0.1/a','https://name:secret@example.com/a','http://example.com/'] as $url) { $x=$fixture;$x['articles'][0]['sources'][0]['url']=$url;ok(err(V::parse(DB::json($x))),'unsafe URL rejected'); }
$x=$fixture;$x['articles'][0]['body_paragraphs']=['<p>A safe paragraph <strong>bold</strong></p><script>alert(1)</script><img src=x onerror=alert(1)>'];$clean=V::parse(DB::json($x));
ok(!err($clean)&&strpos($clean['articles'][0]['body_paragraphs'][0],'<script')===false&&strpos($clean['articles'][0]['body_paragraphs'][0],'<strong>')!==false,'XSS removed and formatting retained');
ok(substr_count(V::body($fixture['articles'][0]['body_paragraphs']),'<p>')===2,'paragraph boundaries retained');
$x=$fixture['articles'][0];$x['claims'][0]['verification']='verified_independent';ok(count(V::article_blockers($x))>0,'one chain cannot claim independent confirmation');
$x['sources'][]=$x['sources'][0];$x['sources'][1]['id']='s2';$x['sources'][1]['url']='https://www.php.net/manual/en/';$x['sources'][0]['type']=$x['sources'][1]['type']='independent_editorial';$x['claims'][0]['source_ids'][]='s2';ok(count(V::article_blockers($x))>0,'two syndicated sources are not independent');
$x['sources'][1]['dependency_group']='separate-chain';ok(!V::article_blockers($x),'two independent structural chains pass');
$x=$fixture['articles'][0];$x['claims'][0]['verification']='attributed_single';$x['claims'][0]['editorial_note']='';ok(count(V::article_blockers($x))>0,'single source requires attribution note');
$x=$fixture['articles'][0];$x['sources'][0]['verification_checked_at']=null;ok(count(V::article_blockers($x))>0,'unexamined source blocks');
wp_set_current_user(0);ok(err(R::import($raw),403),'anonymous import denied');
wp_set_current_user($author);ok(err(R::import($raw),403),'ordinary author import denied');
wp_set_current_user($importer);ok(!current_user_can('edit_posts')&&!current_user_can('es_edit')&&!current_user_can('es_publish'),'importer role least privilege');
$r=R::import($raw);ok(!err($r),'importer can create draft');$id=$r['id'];
ok(R::issue($id)['state']==='draft'&&R::issue($id)['number']===null,'import never publishes or numbers');
ok(count(R::rows($id))===4,'four separately stored articles');
ok((int)$wpdb->get_var('SELECT COUNT(*) FROM '.DB::table('events'))===4,'event identities stored');
ok((int)$wpdb->get_var('SELECT COUNT(*) FROM '.DB::table('article_sources'))===4,'source relations stored');
$r2=R::import($raw);ok($r2['unchanged']&&$r2['id']===$id,'same payload is idempotent');
$x=$fixture;$x['articles'][0]['headline'].=' revised';ok(err(R::import(DB::json($x),1),409),'import_id cannot be reused for different payload');
$x['import_id']='test-base-v2';ok(err(R::import(DB::json($x)),428),'updates require If-Match');ok(err(R::import(DB::json($x),99),409),'stale import rejected');
$r3=R::import(DB::json($x),1);ok(!err($r3)&&$r3['version']===2,'versioned draft update works');
wp_set_current_user($editor);ok(!current_user_can('es_publish')&&!current_user_can('edit_posts'),'editor role cannot publish or edit normal posts');
$row=R::issue($id);$d=R::doc($row);$a=$d['articles'][0];
$r4=R::save_article($id,2,$a,true,'Synthetic test review; no real fact verification','',$d['social'][0]);ok(!err($r4),'human article review recorded');
ok(err(R::save_article($id,2,$a,false,'','')), 'stale editor form rejected');
wp_set_current_user($importer);$x['import_id']='test-base-v3';ok(err(R::import(DB::json($x),3),409),'human edits protected from importer');
wp_set_current_user(1);ok(err(R::publish_from_admin($id,3),403),'direct PHP publication path has no browser permit');
$row=R::issue($id);ok(count(R::blockers($row))>0,'remaining unreviewed articles block publication');
foreach (R::rows($id) as $ar) {$row=R::issue($id);$a=json_decode($ar['draft_json'],true);$ss=null;foreach(R::doc($row)['social'] as $s)if($s['article_id']===$a['id'])$ss=$s;$res=R::save_article($id,$row['version'],$a,true,'Synthetic review for integration test','',$ss);ok(!err($res),'review article '.$a['id']);}
ok(!R::blockers(R::issue($id)),'reviewed issue structurally ready');
$pub=V::public_article(R::doc(R::issue($id))['articles'][0]);$json=DB::json($pub);ok(strpos($json,'PRIVATE_')===false&&!isset($pub['claims']),'public projection omits private notes');
ok(D::canonical('https://EXAMPLE.com/a?b=2&utm_source=x&a=1#part')==='https://example.com/a?a=1&b=2','canonical URLs strip tracking and sort query');
$rollback=fixture('2026-10-19','rollback');$before=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.DB::table('issues'));
$rt=DB::table('revisions');$wpdb->query("RENAME TABLE $rt TO {$rt}_fault");$wpdb->suppress_errors(true);$result=R::import(DB::json($rollback));$wpdb->suppress_errors(false);$wpdb->query("RENAME TABLE {$rt}_fault TO $rt");
ok(err($result)&&$before===(int)$wpdb->get_var('SELECT COUNT(*) FROM '.DB::table('issues')),'failed audit write rolls back complete import');
ok((int)$wpdb->get_var("SELECT COUNT(*) FROM ".DB::table('events')." WHERE event_id LIKE 'rollback-%'")===0,'rollback removes derived event identities');
ok($core_before===$wpdb->get_results('SELECT ID,post_type,post_status,post_content FROM '.$wpdb->posts.' ORDER BY ID',ARRAY_A),'existing WordPress content unchanged');
$app=WP_Application_Passwords::create_new_application_password($importer,['name'=>'es-local-integration-'.wp_generate_uuid4()]);
if (is_wp_error($app)) { fwrite(STDERR,$app->get_error_message()."\n"); exit(1); }
$state=['issue_id'=>$id,'version'=>(int)R::issue($id)['version'],'importer'=>$importer,'editor'=>$editor,'author'=>$author,'application_password'=>$app[0],'fixture'=>$fixture];
file_put_contents(getenv('ES_TEST_STATE')?:sys_get_temp_dir().'/es-test-state.json',wp_json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
chmod(getenv('ES_TEST_STATE')?:sys_get_temp_dir().'/es-test-state.json',0600);
echo "TOTAL $passed assertions passed\n";
