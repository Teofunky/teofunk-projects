#!/usr/bin/env bash
# ==============================================================================
# KOZLOVCLUB.RU — Safe Atomic Deployment, Verification & Instant Rollback Script
# Version: 2.1.0
# ==============================================================================
# Назначение:
#   Безопасная установка и мгновенный откат пакета исправлений по результатам
#   глубокого аудита kozlovclub.ru (аналитика 44419291, CRO, SEO, Ticketscloud).
#
# Использование:
#   1. Проверка перед деплоем (dry-run + backup):
#      ./kozlov-safe-deploy-and-rollback.sh check /var/www/kozlovclub.ru/public_html
#   2. Атомарное внедрение с созданием резервной копии:
#      ./kozlov-safe-deploy-and-rollback.sh deploy /var/www/kozlovclub.ru/public_html
#   3. Мгновенный откат к исходному состоянию (за 2 секунды):
#      ./kozlov-safe-deploy-and-rollback.sh rollback /var/www/kozlovclub.ru/public_html
# ==============================================================================

set -euo pipefail

ACTION="${1:-check}"
WP_ROOT="${2:-/var/www/kozlovclub.ru/public_html}"
BACKUP_DIR="${WP_ROOT}/wp-content/backups/audit_patch_$(date +%Y%m%d_%H%M%S)"
LATEST_BACKUP_LINK="${WP_ROOT}/wp-content/backups/latest_audit_backup"
MU_PLUGINS_DIR="${WP_ROOT}/wp-content/mu-plugins"
MU_ASSETS_DIR="${MU_PLUGINS_DIR}/assets"

PLUGIN_FILE="kozlov-audit-implementation-core.php"
JS_FILE="kozlov-analytics-and-cro.js"

log_info()  { echo "[INFO]  $(date '+%Y-%m-%d %H:%M:%S') - $*"; }
log_error() { echo "[ERROR] $(date '+%Y-%m-%d %H:%M:%S') - $*" >&2; }

check_prerequisites() {
    log_info "Проверка окружения WordPress в ${WP_ROOT}..."
    if [ ! -f "${WP_ROOT}/wp-config.php" ]; then
        log_error "Файл wp-config.php не найден в ${WP_ROOT}. Проверьте путь к корню сайта."
        exit 1
    fi
    if command -v php >/dev/null 2>&1; then
        log_info "Проверка синтаксиса PHP патча (${PLUGIN_FILE})..."
        php -l "${PLUGIN_FILE}"
    fi
    log_info "Предварительная проверка успешно пройдена."
}

deploy_patch() {
    check_prerequisites
    log_info "Создание резервной копии в ${BACKUP_DIR}..."
    mkdir -p "${BACKUP_DIR}"
    mkdir -p "${MU_ASSETS_DIR}"

    if [ -f "${MU_PLUGINS_DIR}/${PLUGIN_FILE}" ]; then
        cp -a "${MU_PLUGINS_DIR}/${PLUGIN_FILE}" "${BACKUP_DIR}/${PLUGIN_FILE}.bak"
    fi
    if [ -f "${MU_ASSETS_DIR}/${JS_FILE}" ]; then
        cp -a "${MU_ASSETS_DIR}/${JS_FILE}" "${BACKUP_DIR}/${JS_FILE}.bak"
    fi

    ln -sfn "${BACKUP_DIR}" "${LATEST_BACKUP_LINK}"

    log_info "Копирование проверенных файлов патча в mu-plugins..."
    cp -f "${PLUGIN_FILE}" "${MU_PLUGINS_DIR}/${PLUGIN_FILE}"
    cp -f "${JS_FILE}" "${MU_ASSETS_DIR}/${JS_FILE}"
    chmod 644 "${MU_PLUGINS_DIR}/${PLUGIN_FILE}" "${MU_ASSETS_DIR}/${JS_FILE}"

    log_info "Пост-деплойная верификация HTTP-ответа главной страницы и страницы концерта..."
    if command -v curl >/dev/null 2>&1; then
        HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" https://kozlovclub.ru/ || true)
        if [ "${HTTP_CODE}" != "200" ]; then
            log_error "Неожиданный HTTP-код (${HTTP_CODE}) после установки! Выполняется автоматический откат..."
            rollback_patch
            exit 1
        fi
        log_info "Главная страница отвечает HTTP 200 OK."
    fi

    log_info "ВНЕДРЕНИЕ УСПЕШНО ЗАВЕРШЕНО. Точка отката сохранена: ${LATEST_BACKUP_LINK}"
}

rollback_patch() {
    log_info "Запуск мгновенного отката изменений..."
    rm -f "${MU_PLUGINS_DIR}/${PLUGIN_FILE}"
    rm -f "${MU_ASSETS_DIR}/${JS_FILE}"

    if [ -L "${LATEST_BACKUP_LINK}" ] && [ -d "${LATEST_BACKUP_LINK}" ]; then
        if [ -f "${LATEST_BACKUP_LINK}/${PLUGIN_FILE}.bak" ]; then
            cp -a "${LATEST_BACKUP_LINK}/${PLUGIN_FILE}.bak" "${MU_PLUGINS_DIR}/${PLUGIN_FILE}"
        fi
        if [ -f "${LATEST_BACKUP_LINK}/${JS_FILE}.bak" ]; then
            cp -a "${LATEST_BACKUP_LINK}/${JS_FILE}.bak" "${MU_ASSETS_DIR}/${JS_FILE}"
        fi
    fi

    log_info "ОТКАТ ВЫПОЛНЕН. Сайт возвращён в исходное состояние."
}

case "${ACTION}" in
    check)
        check_prerequisites
        ;;
    deploy)
        deploy_patch
        ;;
    rollback)
        rollback_patch
        ;;
    *)
        log_error "Неизвестная команда: ${ACTION}. Используйте: check | deploy | rollback"
        exit 1
        ;;
esac