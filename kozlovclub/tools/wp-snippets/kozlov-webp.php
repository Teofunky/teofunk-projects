<?php
/**
 * kozlov-webp.php — конвертация в WebP только того, что использует афиша ПРЕДСТОЯЩИХ событий.
 *
 * Источник списка: индекс Kozlov Smart Search (/wp-json/kozlov-search/v1/index), в нём только
 * предстоящие концерты. Для каждого берутся:
 *   task=thumbs   превью карточек из uploads (размеры 450x33x, 450x575, 300x225)
 *   task=posters  постеры/баннеры страницы концерта из wp-content/files_mf (поля Magic Fields)
 * Конвертация через библиотеку WebP Express (те же cwebp/imagick/gd), качество 80, PNG в режиме auto.
 * Файл .webp кладётся рядом с оригиналом (как в настройках WebP Express: mingled, append).
 * Прошедшие события не затрагиваются.
 *
 * Куда положить: в корень сайта (public_html), рядом с wp-config.php.
 * Как запустить (в браузере, по очереди, страница сама листает пакеты):
 *   https://kozlovclub.ru/kozlov-webp.php?key=kozlov-2026-webp&task=thumbs
 *   https://kozlovclub.ru/kozlov-webp.php?key=kozlov-2026-webp&task=posters
 * Параметры: &force=0 не перезаписывать существующие .webp (по умолчанию перезаписывает), &batch=20 размер пакета.
 *
 * После завершения УДАЛИТЬ ФАЙЛ с сервера (и kozlov-webp-*.json, если остались).
 */

define( 'KW_KEY', 'kozlov-2026-webp' ); // можно поменять на свой

@set_time_limit( 55 );
@ini_set( 'memory_limit', '512M' );
error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
header( 'Content-Type: text/html; charset=utf-8' );
header( 'X-Robots-Tag: noindex' );

if ( ! isset( $_GET['key'] ) || $_GET['key'] !== KW_KEY ) {
	http_response_code( 403 );
	exit( 'forbidden' );
}

$root     = __DIR__;
$wc       = $root . '/wp-content';
$autoload = $wc . '/plugins/webp-express/vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
	exit( 'Не найден ' . htmlspecialchars( $autoload ) . '. Скрипт должен лежать в корне сайта, WebP Express должен быть установлен.' );
}

$task   = isset( $_GET['task'] ) ? preg_replace( '/[^a-z]/', '', $_GET['task'] ) : 'thumbs';
$offset = isset( $_GET['offset'] ) ? max( 0, (int) $_GET['offset'] ) : 0;
$batch  = isset( $_GET['batch'] ) ? max( 1, min( 100, (int) $_GET['batch'] ) ) : 20;
$force  = ! ( isset( $_GET['force'] ) && '0' === $_GET['force'] );
if ( ! in_array( $task, array( 'thumbs', 'posters' ), true ) ) {
	exit( 'task должен быть thumbs или posters' );
}

$list_file = $root . '/kozlov-webp-' . $task . '.json';

/* ---------- список файлов: строится один раз из индекса предстоящих событий ---------- */
if ( 0 === $offset || ! file_exists( $list_file ) ) {
	define( 'WP_USE_THEMES', false );
	require $root . '/wp-load.php';

	$req = new WP_REST_Request( 'GET', '/kozlov-search/v1/index' );
	$res = rest_do_request( $req );
	$idx = $res->get_data();
	if ( ! is_array( $idx ) || empty( $idx['ev'] ) || ! is_array( $idx['ev'] ) ) {
		exit( 'Индекс Kozlov Smart Search не получен (/kozlov-search/v1/index). Плагин активен?' );
	}
	$events = $idx['ev'];
	$files  = array();

	if ( 'thumbs' === $task ) {
		$uploads = $wc . '/uploads/';
		foreach ( $events as $e ) {
			$img = isset( $e[6] ) ? (string) $e[6] : '';
			if ( '' === $img ) {
				continue;
			}
			// в индексе лежит вариант -300x225; из него получаем базовое имя и расширение
			if ( ! preg_match( '/^(.*?)(?:-\d+x\d+)?\.(jpe?g|png)$/i', $img, $m ) ) {
				continue;
			}
			$base = $uploads . $m[1];
			$ext  = $m[2];
			foreach ( array( '450x338', '450x337', '450x336', '450x335', '450x575', '300x225' ) as $size ) {
				$p = $base . '-' . $size . '.' . $ext;
				if ( file_exists( $p ) ) {
					$files[ $p ] = 1;
				}
			}
		}
	} else {
		// постеры страниц концертов: любые значения постов, указывающие в files_mf
		global $wpdb;
		foreach ( $events as $e ) {
			$slug = isset( $e[5] ) ? (string) $e[5] : '';
			if ( '' === $slug ) {
				continue;
			}
			$post_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_status = 'publish' LIMIT 1", $slug ) );
			if ( ! $post_id ) {
				continue;
			}
			// Magic Fields хранит либо голое имя файла, либо путь/URL с files_mf: берём любые имена *.jpg/*.png
			// из значений мета-полей и проверяем, лежит ли такой файл в files_mf.
			$metas = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_value REGEXP %s", $post_id, '\\.(png|jpe?g)' ) );
			foreach ( $metas as $v ) {
				if ( preg_match_all( '/[^\s"\'<>\\\\\/]+\.(?:jpe?g|png)/i', (string) $v, $mm ) ) {
					foreach ( $mm[0] as $name ) {
						$p = $wc . '/files_mf/' . $name;
						if ( file_exists( $p ) ) {
							$files[ $p ] = 1;
						}
					}
				}
			}
		}
	}
	$files = array_keys( $files );
	file_put_contents( $list_file, json_encode( $files, JSON_UNESCAPED_UNICODE ) );
} else {
	$files = json_decode( file_get_contents( $list_file ), true );
	if ( ! is_array( $files ) ) {
		exit( 'Список повреждён, запусти заново без offset' );
	}
}

require $autoload;

$total = count( $files );
$slice = array_slice( $files, $offset, $batch );

$options = array(
	'quality'       => 80,
	'alpha-quality' => 80,
	'metadata'      => 'none',
	'encoding'      => 'auto',
	'jpeg'          => array( 'encoding' => 'lossy', 'quality' => 80 ),
	'png'           => array( 'encoding' => 'auto', 'quality' => 80 ),
);

$t0    = microtime( true );
$done  = 0;
$skip  = 0;
$fail  = 0;
$saved = 0;
$log   = array();

foreach ( $slice as $src ) {
	$dst = $src . '.webp';
	if ( ! $force && file_exists( $dst ) ) {
		$skip++;
		$log[] = 'skip ' . basename( $src );
		continue;
	}
	try {
		\WebPConvert\WebPConvert::convert( $src, $dst, $options );
		$s      = filesize( $src );
		$d      = filesize( $dst );
		$saved += max( 0, $s - $d );
		$done++;
		$log[]  = sprintf( 'ok   %s  %d → %d КБ', basename( $src ), round( $s / 1024 ), round( $d / 1024 ) );
	} catch ( \Throwable $e ) {
		$fail++;
		$log[] = 'FAIL ' . basename( $src ) . ': ' . $e->getMessage();
	}
	if ( microtime( true ) - $t0 > 40 ) {
		break; // не упираться в лимит времени хостинга
	}
}

$processed = $offset + $done + $skip + $fail;
$finished  = $processed >= $total;
$next      = sprintf( '%s?key=%s&task=%s&offset=%d&batch=%d&force=%s', basename( __FILE__ ), rawurlencode( KW_KEY ), $task, $processed, $batch, $force ? '1' : '0' );

if ( $finished ) {
	@unlink( $list_file );
}
?>
<!DOCTYPE html>
<html lang="ru"><head><meta charset="utf-8">
<?php if ( ! $finished && $total > 0 ) : ?>
<meta http-equiv="refresh" content="1;url=<?php echo htmlspecialchars( $next ); ?>">
<?php endif; ?>
<title>kozlov-webp: <?php echo $task; ?></title>
<style>body{font:14px/1.5 -apple-system,Segoe UI,sans-serif;margin:24px;color:#222}pre{background:#f4f4f4;padding:12px;overflow:auto}b{font-size:18px}</style>
</head><body>
<b><?php echo $finished ? 'Готово' : 'Идёт конвертация'; ?>: <?php echo $task; ?> — <?php echo $processed; ?> / <?php echo $total; ?> файлов</b>
<p>Пакет: ок <?php echo $done; ?>, пропущено <?php echo $skip; ?>, ошибок <?php echo $fail; ?>, сэкономлено в пакете <?php echo round( $saved / 1024 ); ?> КБ, время <?php echo round( microtime( true ) - $t0, 1 ); ?> с.</p>
<?php if ( ! $finished && $total > 0 ) : ?>
<p>Следующий пакет через секунду, не закрывай вкладку. <a href="<?php echo htmlspecialchars( $next ); ?>">Вручную →</a></p>
<?php elseif ( 0 === $total ) : ?>
<p>Список пуст: ничего не найдено. Проверь, что индекс Smart Search отдаёт события.</p>
<?php else : ?>
<p><strong>Всё. Теперь удали этот файл с сервера.</strong></p>
<?php endif; ?>
<pre><?php echo htmlspecialchars( implode( "\n", $log ) ); ?></pre>
</body></html>
