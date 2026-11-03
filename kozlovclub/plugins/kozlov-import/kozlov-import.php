<?php
/**
 * Plugin Name: Kozlov Import
 * Description: Перенос контента старого kozlovclub.ru (дамп базы от 22.10.2026) в Kozlov Core: пользователи, страницы, меню, концерты, фото.
 * Version: 1.2.0
 * Requires PHP: 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KI_DIR', plugin_dir_path( __FILE__ ) );
define( 'KI_URL', plugin_dir_url( __FILE__ ) );
define( 'KI_VER', '1.2.0' );

require_once KI_DIR . 'includes/class-ki-importer.php';
require_once KI_DIR . 'includes/class-ki-media.php';
require_once KI_DIR . 'includes/class-ki-settings.php';

add_action( 'admin_menu', function () {
	add_management_page( 'Импорт Kozlov', 'Импорт Kozlov', 'manage_options', 'kozlov-import', 'ki_page' );
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'tools_page_kozlov-import' !== $hook ) {
		return;
	}
	wp_enqueue_script( 'ki-admin', KI_URL . 'assets/admin.js', array( 'jquery' ), KI_VER, true );
	wp_localize_script( 'ki-admin', 'KI', array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'ki_step' ) ) );
	wp_enqueue_style( 'ki-admin', KI_URL . 'assets/admin.css', array(), KI_VER );
} );

function ki_meta() {
	static $m = null;
	if ( null === $m ) {
		$m = KI_Importer::load( 'meta' );
	}
	return $m;
}

function ki_page() {
	if ( ! function_exists( 'kozlov_concert' ) ) {
		echo '<div class="wrap"><h1>Импорт Kozlov</h1><div class="notice notice-error"><p>Сначала установите и активируйте плагин Kozlov Core.</p></div></div>';
		return;
	}
	$m = ki_meta();
	$st = get_option( 'ki_state', array() );
	$pending = KI_Media::count( 'pending' );
	$done    = KI_Media::count( 'done' );
	$failed  = KI_Media::count( 'failed' );
	?>
	<div class="wrap ki-wrap"><h1>Импорт Kozlov</h1>
	<p>Источник: <?php echo esc_html( $m['source'] ); ?>. В пакете: пользователей <?php echo count( $m['users'] ); ?>, страниц <?php echo count( $m['pages'] ); ?>, пунктов меню <?php echo count( $m['menu'] ); ?>, концертов <?php echo (int) $m['concert_total']; ?> (с <?php echo esc_html( $m['since'] ); ?>, из них будущих <?php echo (int) $m['concert_future']; ?>).</p>
	<p>Каждый шаг можно запускать повторно: уже перенесённые записи обновляются, а не дублируются. Порядок: сверху вниз.</p>
	<table class="widefat ki-steps">
		<tr><td><strong>0. Настройки сайта и Yoast</strong><br><span class="description">Название, часовой пояс, ЧПУ, шаблоны Yoast, соцсети, настройки наших плагинов.</span></td><td><button class="button" data-step="settings">Запустить</button></td><td class="ki-status" data-for="settings"><?php echo esc_html( $st['settings'] ?? '' ); ?></td></tr>
		<tr><td><strong>1. Пользователи</strong><br><span class="description">Редакторы с их паролями и ролями.</span></td><td><button class="button" data-step="users">Запустить</button></td><td class="ki-status" data-for="users"><?php echo esc_html( $st['users'] ?? '' ); ?></td></tr>
		<tr><td><strong>2. Страницы</strong><br><span class="description">О клубе, Контакты, Правила, FAQ и другие.</span></td><td><button class="button" data-step="pages">Запустить</button></td><td class="ki-status" data-for="pages"><?php echo esc_html( $st['pages'] ?? '' ); ?></td></tr>
		<tr><td><strong>3. Меню</strong><br><span class="description">Главное меню сайта.</span></td><td><button class="button" data-step="menu">Запустить</button></td><td class="ki-status" data-for="menu"><?php echo esc_html( $st['menu'] ?? '' ); ?></td></tr>
		<tr><td><strong>4. Концерты</strong><br><span class="description">Сначала будущие, затем архив. Фото регистрируются, файлы качаются на шаге 5.</span></td><td><button class="button button-primary" data-step="concerts">Запустить</button></td><td class="ki-status" data-for="concerts"><?php echo esc_html( $st['concerts'] ?? '' ); ?></td></tr>
		<tr><td><strong>5. Фото</strong><br><span class="description">Файлы берутся со старого сайта, если он доступен, иначе из Wayback Machine. Ожидают: <b id="ki-pending"><?php echo $pending; ?></b>, скачано <?php echo $done; ?>, не найдено <?php echo $failed; ?>.</span></td><td><button class="button" data-step="media">Запустить</button> <button class="button" data-step="media_retry">Повторить ненайденные</button></td><td class="ki-status" data-for="media"><?php echo esc_html( $st['media'] ?? '' ); ?></td></tr>
		<tr><td><strong>6. Джазета</strong><br><span class="description">Выпуски и статьи. Сначала установите плагин Kozlov Jazzeta.</span></td><td><button class="button" data-step="jazzeta">Запустить</button></td><td class="ki-status" data-for="jazzeta"><?php echo esc_html( $st['jazzeta'] ?? '' ); ?></td></tr>
	</table>
	<p><button class="button" id="ki-stop" disabled>Остановить</button></p>
	<pre id="ki-log" class="ki-log"></pre>
	</div>
	<?php
}

add_action( 'wp_ajax_ki_step', function () {
	check_ajax_referer( 'ki_step', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'нет прав' );
	}
	@set_time_limit( 120 );
	wp_raise_memory_limit( 'admin' );
	$step   = sanitize_key( $_POST['step'] ?? '' );
	$offset = (int) ( $_POST['offset'] ?? 0 );
	try {
		switch ( $step ) {
			case 'settings':
				$r = KI_Settings::site(); break;
			case 'jazzeta':
				$r = KI_Settings::jazzeta(); break;
			case 'users':
				$r = KI_Importer::users(); break;
			case 'pages':
				$r = KI_Importer::pages(); break;
			case 'menu':
				$r = KI_Importer::menu(); break;
			case 'concerts':
				$r = KI_Importer::concerts( $offset ); break;
			case 'media':
				$r = KI_Media::run( 6, false ); break;
			case 'media_retry':
				$r = KI_Media::run( 6, true ); break;
			default:
				wp_send_json_error( 'неизвестный шаг' );
		}
	} catch ( Throwable $e ) {
		wp_send_json_error( $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
	}
	$st = get_option( 'ki_state', array() );
	$st[ 'media_retry' === $step ? 'media' : $step ] = $r['status'];
	update_option( 'ki_state', $st, false );
	wp_send_json_success( $r );
} );
