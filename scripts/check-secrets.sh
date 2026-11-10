#!/usr/bin/env bash
# Проверка файлов, подготовленных к коммиту, на секреты и тяжёлые файлы.
# Запуск вручную: scripts/check-secrets.sh            (проверяет staged-файлы)
#                 scripts/check-secrets.sh --all      (проверяет весь репозиторий)
set -u

MAX_BYTES=$((5 * 1024 * 1024))
fail=0

if [ "${1:-}" = "--all" ]; then
  files=$(git ls-files)
else
  files=$(git diff --cached --name-only --diff-filter=ACM)
fi

[ -z "$files" ] && exit 0

# Форматы ключей сервисов: регистр важен
exact=(
  "define\(\s*['\"](DB_PASSWORD|AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY)['\"]\s*,\s*['\"][^'\"]{6,}"
  "sk-ant-[A-Za-z0-9_-]{20,}"
  "gh[pousr]_[A-Za-z0-9]{30,}"
  "github_pat_[A-Za-z0-9_]{30,}"
  "(^|[^A-Za-z0-9+/])AKIA[0-9A-Z]{16}([^A-Za-z0-9+/]|$)"
  "-----BEGIN [A-Z ]*PRIVATE KEY-----"
  "xox[baprs]-[A-Za-z0-9-]{10,}"
  "(^|[^0-9])[0-9]{8,10}:AA[A-Za-z0-9_-]{30,}"
)
# Пары «ключ = значение»: регистр не важен
loose=(
  "(api[_-]?key|api[_-]?secret|access[_-]?token|client[_-]?secret|password|passwd)['\"]?\s*(=>|[:=])\s*['\"][^'\"[:space:]]{8,}['\"]"
)

while IFS= read -r f; do
  [ -f "$f" ] || continue
  case "$f" in
    */migrations/*.sql|*/schema.sql) ;;  # схемы и миграции без данных разрешены
    *.sql|*.sql.gz|*wp-config.php|*.env|*.pem|*.key|*.p12)
      echo "ЗАПРЕЩЁННЫЙ ФАЙЛ: $f"; fail=1; continue ;;
  esac
  size=$(wc -c < "$f" | tr -d ' ')
  if [ "$size" -gt "$MAX_BYTES" ] && ! grep -qxF -- "$f" .githooks/allow-large.txt 2>/dev/null; then
    echo "СЛИШКОМ БОЛЬШОЙ ($((size / 1024 / 1024)) МБ): $f"; fail=1
  fi
  # Бинарные файлы не сканируем на текстовые шаблоны
  if file -b --mime "$f" | grep -q 'charset=binary'; then continue; fi
  hit=""
  for p in "${exact[@]}"; do
    hit=$(grep -nE -m1 -- "$p" "$f" 2>/dev/null | cut -c1-160); [ -n "$hit" ] && break
  done
  if [ -z "$hit" ]; then
    for p in "${loose[@]}"; do
      # значение в кавычках должно содержать цифру: так не ловятся 'new-password' и подобные слова
      hit=$(grep -noE -i -- "$p" "$f" 2>/dev/null | grep -E "[0-9][^'\"]*['\"]$" | head -1 | cut -c1-160)
      [ -n "$hit" ] && break
    done
  fi
  if [ -z "$hit" ]; then
    # Ключи OpenAI: sk-... с цифрами и заглавными буквами (чтобы не ловить обычные адреса страниц)
    k=$(grep -oE -- "(^|[^A-Za-z0-9_-])sk-(proj-)?[A-Za-z0-9_-]{32,}" "$f" 2>/dev/null | grep -E '[0-9]' | grep -E '[A-Z]' | head -1)
    [ -n "$k" ] && hit="$(grep -nF -- "${k#?}" "$f" | head -1 | cut -d: -f1): ключ вида sk-"
  fi
  if [ -n "$hit" ]; then
    echo "ПОХОЖЕ НА СЕКРЕТ: $f:$hit"; fail=1
  fi
  # Пароли приложений WordPress: шесть групп по 4 символа, с цифрой и заглавной буквой
  ap=$(grep -oE -- "\b[A-Za-z0-9]{4}( [A-Za-z0-9]{4}){5}\b" "$f" 2>/dev/null | grep -E '[0-9]' | grep -E '[A-Z]' | head -1)
  if [ -n "$ap" ]; then
    line=$(grep -nF -- "$ap" "$f" | head -1 | cut -d: -f1)
    echo "ПОХОЖЕ НА ПАРОЛЬ ПРИЛОЖЕНИЯ WP: $f:$line"; fail=1
  fi
done <<< "$files"

if [ "$fail" -ne 0 ]; then
  echo
  echo "Коммит остановлен. Уберите секрет или файл. Если это ложная тревога, проверьте строку глазами и закоммитьте с --no-verify."
  exit 1
fi
exit 0
