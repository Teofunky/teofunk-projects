<?php
if (PHP_SAPI!=='cli'||getenv('ES_TEST_ALLOW_RESET')!=='1'||!getenv('ES_TEST_BOOTSTRAP')) { exit(2); }
require getenv('ES_TEST_BOOTSTRAP');
if (wp_get_environment_type()!=='local'||wp_parse_url(home_url(),PHP_URL_HOST)!=='127.0.0.1') { exit(2); }
use Kozlov\Standard\Admin;
wp_set_current_user(1);
$token=WP_Session_Tokens::get_instance(1)->create(time()+600);
$_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie(1,time()+600,'logged_in',$token);
$id=(int)$argv[1];$version=(int)$argv[2];
$_SERVER['REQUEST_METHOD']='POST';$_SERVER['HTTP_REFERER']=admin_url('admin.php?page=es-newsroom&issue='.$id);
$_POST=['action'=>'es_action','operation'=>'publish','issue_id'=>$id,'version'=>$version,'confirm'=>'1','_wpnonce'=>wp_create_nonce('es-publish-'.$id.'-'.$version)];$_REQUEST=$_POST;
// A real session cookie, nonce and explicit confirmation travel through the production handler.
Admin::handle();
