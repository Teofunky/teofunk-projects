#!/usr/bin/env bash
# All checks for Kozlov Slider Stats in one run.
set -u
P=/home/claude/teofunk-projects/kozlovclub/plugins/kozlov-slider-stats
echo "== static: php -l, node --check"
for f in $(find $P -name '*.php'); do php -l "$f" | sed 's#/home/claude/teofunk-projects/##'; done
node --check $P/assets/kzsl.js && echo "kzsl.js: syntax ok"
echo; echo "== WordPress 7.1.5 (local, SQLite): activation, settings, options"
php /home/claude/wp/wptest.php
echo; echo "== WordPress 7.1.5 (local, HTTP): pages, admin, saving, editors"
/home/claude/wp/httptest.sh
echo; echo "== live kozlovclub.ru page, script injected locally, nothing sent to Metrika: desktop"
python3 t_desktop.py
echo; echo "== live page: iPhone 13 (facade, shadow theatre, facade off)"
python3 t_mobile.py
echo; echo "== live page: buy on the next page, debug, editors, filtered home"
python3 t_next.py
echo; echo "== live page: scenarios from the outside audit of 1.0.0"
python3 t_audit.py
