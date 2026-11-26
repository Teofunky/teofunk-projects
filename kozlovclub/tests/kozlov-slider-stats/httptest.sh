#!/usr/bin/env bash
# HTTP checks against the local WordPress 7.1.5 with the plugin active.
cd /home/claude/wp || exit 1
B=http://127.0.0.1:8080
ok=0; bad=0
t() { if eval "$1"; then ok=$((ok+1)); echo "  ok   $2"; else bad=$((bad+1)); echo "  FAIL $2"; fi; }
setopt() { php -r "\$_SERVER['HTTP_HOST']='127.0.0.1:8080'; require '/home/claude/wp/site/wp-load.php'; update_option('kzsl_settings', $1);" ; }
login() { rm -f "$2"; curl -s -c "$2" -b "wordpress_test_cookie=WP%20Cookie%20check" -o /dev/null -d "log=$1&pwd=$3&wp-submit=Log+In&testcookie=1" "$B/wp-login.php"; }

setopt "array('enabled'=>1,'counter'=>'44419291','skip_editors'=>1,'next'=>1)"
H=$(curl -s "$B/"); P=$(curl -s "$B/?p=1")
t '[[ $(grep -c "id=\"kzsl-config\"" <<<"$H") == 1 ]]' 'home: config printed once'
t 'grep -q "\"counter\":44419291" <<<"$H" && grep -q "\"home\":true" <<<"$H" && grep -q "\"silent\":false" <<<"$H"' 'home: counter, home=true, silent=false'
t 'grep "kzsl.js?ver=1.0.1" <<<"$H" | grep -q " defer "' 'home: script in page with defer'
t '[[ $(grep -n "id=\"kzsl-config\"" <<<"$H" | cut -d: -f1) -lt $(grep -n "kzsl.js" <<<"$H" | cut -d: -f1) ]]' 'home: config comes before the script'
t 'grep -q "kzsl.js" <<<"$P" && grep -q "\"home\":false" <<<"$P"' 'post page: script present with home=false (next on)'
t '! grep -q "window.KZSL\|var kzsl" <<<"$H"' 'no global JS variable printed'

setopt "array('enabled'=>1,'counter'=>'44419291','skip_editors'=>1,'next'=>0)"
H=$(curl -s "$B/"); P=$(curl -s "$B/?p=1")
t 'grep -q "kzsl.js" <<<"$H" && ! grep -q "kzsl" <<<"$P"' 'next off: home only, nothing on other pages'

setopt "array('enabled'=>0,'counter'=>'44419291','skip_editors'=>1,'next'=>1)"
H=$(curl -s "$B/"); P=$(curl -s "$B/?p=1")
t '! grep -q "kzsl" <<<"$H" && ! grep -q "kzsl" <<<"$P"' 'disabled: not a single line of the plugin on the site'

setopt "array('enabled'=>1,'counter'=>'44419291','skip_editors'=>1,'next'=>1)"
F=$(curl -s "$B/?feed=rss2"); R=$(curl -s "$B/?rest_route=/wp/v2/posts")
t '! grep -q "kzsl" <<<"$F$R"' 'feed and REST: untouched'

login editor1 ed.jar editor-pass-123
H=$(curl -s -b ed.jar "$B/")
t 'grep -q "\"silent\":true" <<<"$H"' 'logged-in editor: silent=true'
C=$(curl -s -o /dev/null -w "%{http_code}" -b ed.jar "$B/wp-admin/options-general.php?page=kzsl")
t '[[ $C == 403 || $C == 500 ]] || ! curl -s -b ed.jar "$B/wp-admin/options-general.php?page=kzsl" | grep -q "Цели, которые нужно создать"' 'editor: no access to the settings page'
setopt "array('enabled'=>1,'counter'=>'44419291','skip_editors'=>0,'next'=>1)"
H=$(curl -s -b ed.jar "$B/")
t 'grep -q "\"silent\":false" <<<"$H"' 'skip_editors off: editor events are sent'

login admin ad.jar admin-pass-123
A=$(curl -s -b ad.jar "$B/wp-admin/options-general.php?page=kzsl")
t 'grep -q "Слайдер в Метрике" <<<"$A" && grep -q "kzsl_buy_next" <<<"$A" && grep -q "metrika.yandex.ru/goals?id=44419291" <<<"$A"' 'admin: settings page with the goal table and Metrika link'
L=$(curl -s -b ad.jar "$B/wp-admin/plugins.php")
t 'grep -q "options-general.php?page=kzsl" <<<"$L"' 'plugins list: «Настройки» link'
N=$(grep -o 'name="_wpnonce" value="[^"]*"' <<<"$A" | head -1 | sed 's/.*value="//;s/"//')
rm -f w3tc.log
code=$(curl -s -o /dev/null -w "%{http_code}" -b ad.jar -d "action=kzsl_save&_wpnonce=$N&_wp_http_referer=%2F&kzsl_enabled=1&kzsl_counter=98765432&kzsl_next=1" "$B/wp-admin/admin-post.php")
S=$(php -r "\$_SERVER['HTTP_HOST']='127.0.0.1:8080'; require '/home/claude/wp/site/wp-load.php'; echo json_encode(get_option('kzsl_settings'));")
t '[[ $code == 302 ]] && grep -q "\"counter\":\"98765432\"" <<<"$S" && grep -q "\"skip_editors\":0" <<<"$S"' "admin: save works (HTTP $code): $S"
t '[[ -f w3tc.log ]]' 'admin: save flushes page cache'
code=$(curl -s -o /dev/null -w "%{http_code}" -b ad.jar -d "action=kzsl_save&_wpnonce=wrong&kzsl_enabled=0" "$B/wp-admin/admin-post.php")
S2=$(php -r "\$_SERVER['HTTP_HOST']='127.0.0.1:8080'; require '/home/claude/wp/site/wp-load.php'; echo json_encode(get_option('kzsl_settings'));")
t '[[ "$S2" == "$S" ]]' "admin: bad nonce changes nothing (HTTP $code)"
code=$(curl -s -o /dev/null -w "%{http_code}" -b ed.jar -d "action=kzsl_save&_wpnonce=$N&kzsl_enabled=0" "$B/wp-admin/admin-post.php")
S3=$(php -r "\$_SERVER['HTTP_HOST']='127.0.0.1:8080'; require '/home/claude/wp/site/wp-load.php'; echo json_encode(get_option('kzsl_settings'));")
t '[[ "$S3" == "$S" ]]' "editor cannot save settings (HTTP $code)"
N=$(curl -s -b ad.jar "$B/wp-admin/options-general.php?page=kzsl" | grep -o 'name="_wpnonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b ad.jar -d "action=kzsl_save&_wpnonce=$N&kzsl_enabled=1&kzsl_counter=abc&kzsl_skip_editors=1&kzsl_next=1" "$B/wp-admin/admin-post.php"
S4=$(php -r "\$_SERVER['HTTP_HOST']='127.0.0.1:8080'; require '/home/claude/wp/site/wp-load.php'; echo json_encode(get_option('kzsl_settings'));")
t 'grep -q "\"counter\":\"44419291\"" <<<"$S4"' "admin: counter without digits falls back to 44419291"
t '[[ -f debug.log ]] && ! grep -qi "kzsl\|kozlov-slider" debug.log' 'debug.log: no PHP notices or errors from the plugin'
echo; echo "$ok ok, $bad failed"
