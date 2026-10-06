<?php
/**
 * Картинки из статей.
 *
 * В текстах адреса картинок записаны как /wp-content/uploads/год/месяц/файл. Если такого
 * файла на этом сервере ещё нет, сайт при первом показе сам скачивает его со старого сайта
 * и кладёт на место. Дальше картинка отдаётся уже отсюда. Вручную папку переносить не нужно.
 * Чтобы скопировать всё сразу: «Инструменты → Картинки со старого сайта».
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Адрес старого сайта, пока новый сайт открыт на другом домене. */
function jac_old_site() {
	$old  = untrailingslashit( (string) get_theme_mod( 'jac_old_site', '' ) );
	$host = function ( $url ) {
		return preg_replace( '~^www\.~i', '', strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
	};
	// Как только сайт открыт на адресе старого сайта (с www или без), перенос считается завершённым.
	if ( ! $old || $host( $old ) === $host( home_url() ) ) {
		return '';
	}
	return $old;
}

/** Путь из текста → файл на диске. */
function jac_upload_file( $path ) {
	return WP_CONTENT_DIR . substr( rawurldecode( strtok( $path, '?#' ) ), strlen( '/wp-content' ) );
}

/** Допустимый путь: только папка uploads и только известные типы файлов. */
function jac_upload_path_ok( $path ) {
	return (bool) preg_match( '~^/wp-content/uploads/[^?#\\\\:*"<>|]+\.(jpe?g|png|gif|webp|pdf|docx?|mp3)$~iu', $path ) && false === strpos( $path, '..' );
}

function jac_encode_path( $path ) {
	return implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
}

/**
 * Адрес картинки для вывода на странице.
 *
 * @param string $path  Путь вида /wp-content/uploads/...
 * @param int    $width Нужная ширина (480, 960 или 1440) либо 0, если файл нужен как есть.
 */
function jac_img( $path, $width = 0 ) {
	$path = (string) $path;
	if ( 0 !== strpos( $path, '/wp-content/uploads/' ) ) {
		return $path; // Картинка с другого сайта: не трогаем.
	}
	$file = jac_upload_file( $path );
	if ( file_exists( $file ) ) {
		return $width ? jac_img_resized( $path, $file, $width ) : $path;
	}
	if ( ! jac_old_site() || ! jac_upload_path_ok( rawurldecode( $path ) ) ) {
		return $path;
	}
	return home_url( '/?jac_img=' . rawurlencode( rawurldecode( $path ) ) . ( $width ? '&w=' . (int) $width : '' ) );
}

/** Старое название функции: оставлено для совместимости. */
function jac_upload_url( $path ) {
	return jac_img( $path );
}

/** Уменьшенная копия рядом с оригиналом: крупные фото с телефона не грузятся целиком. */
function jac_img_resized( $path, $file, $width ) {
	$info = pathinfo( $file );
	$ext  = strtolower( $info['extension'] ?? '' );
	if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp' ), true ) ) {
		return $path;
	}
	$name = $info['filename'] . '-jac' . (int) $width . '.' . $info['extension'];
	$url  = substr( $path, 0, strrpos( $path, '/' ) + 1 ) . $name;
	if ( file_exists( $info['dirname'] . '/' . $name ) ) {
		return $url;
	}
	$size = @getimagesize( $file ); // phpcs:ignore
	if ( ! $size || $size[0] <= $width * 1.15 ) {
		return $path;
	}
	$editor = wp_get_image_editor( $file );
	if ( is_wp_error( $editor ) ) {
		return $path;
	}
	$editor->resize( $width, null, false );
	$editor->set_quality( 84 );
	$saved = $editor->save( $info['dirname'] . '/' . $name );
	return is_wp_error( $saved ) ? $path : $url;
}

/** Скачивает один файл со старого сайта на его место в uploads. */
function jac_fetch_old_file( $path ) {
	$old = jac_old_site();
	if ( ! $old || ! jac_upload_path_ok( $path ) ) {
		return false;
	}
	$file = jac_upload_file( $path );
	if ( file_exists( $file ) ) {
		return true;
	}
	$response = wp_remote_get( $old . jac_encode_path( $path ), array(
		'timeout'             => 25,
		'redirection'         => 3,
		'limit_response_size' => 25 * MB_IN_BYTES,
		'user-agent'          => 'JazzArtClub site move; ' . home_url( '/' ),
	) );
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return false;
	}
	$body = wp_remote_retrieve_body( $response );
	$type = (string) wp_remote_retrieve_header( $response, 'content-type' );
	// Вместо картинки сервер может вернуть страницу с ошибкой: такое не сохраняем.
	if ( strlen( $body ) < 200 || 0 === stripos( $type, 'text/' ) ) {
		return false;
	}
	if ( preg_match( '~\.(jpe?g|png|gif|webp)$~i', $path ) && false === @getimagesizefromstring( $body ) ) { // phpcs:ignore
		return false;
	}
	if ( ! wp_mkdir_p( dirname( $file ) ) ) {
		return false;
	}
	return false !== file_put_contents( $file, $body );
}

/** Адрес ?jac_img=…: скачать файл при первом показе и перейти на него. */
add_action( 'init', function () {
	if ( empty( $_GET['jac_img'] ) ) { // phpcs:ignore
		return;
	}
	$path  = (string) wp_unslash( $_GET['jac_img'] ); // phpcs:ignore
	$width = isset( $_GET['w'] ) ? absint( $_GET['w'] ) : 0; // phpcs:ignore
	$width = in_array( $width, array( 480, 960, 1440 ), true ) ? $width : 0;

	if ( ! jac_upload_path_ok( $path ) || ! jac_fetch_old_file( $path ) ) {
		status_header( 404 );
		nocache_headers();
		exit;
	}
	$url = $width ? jac_img_resized( $path, jac_upload_file( $path ), $width ) : $path;
	wp_redirect( home_url( jac_encode_path( $url ) ), 302 ); // phpcs:ignore
	exit;
}, 1 );

// В текстах статей: и картинки, и ссылки на файлы.
add_filter( 'the_content', function ( $html ) {
	if ( false === strpos( $html, '/wp-content/uploads/' ) || ! jac_old_site() ) {
		return $html;
	}
	return preg_replace_callback( '~(\b(?:src|href)=["\'])(/wp-content/uploads/[^"\']+)~i', function ( $m ) {
		return $m[1] . esc_url( jac_img( $m[2] ) );
	}, $html );
}, 20 );

// Пробная копия на другом адресе не должна попадать в поисковики вместо настоящего сайта.
add_filter( 'wp_robots', function ( $robots ) {
	if ( jac_old_site() ) {
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
		unset( $robots['max-image-preview'] );
	}
	return $robots;
} );

/** Первая картинка из текста записи (путь как в тексте). */
function jac_first_image( $post = null ) {
	$post = get_post( $post );
	if ( $post && preg_match( '~<img\b[^>]*\bsrc=["\']([^"\']+)["\']~i', $post->post_content, $m ) ) {
		return $m[1];
	}
	return '';
}

/** Все файлы из uploads, на которые ссылаются опубликованные тексты. */
function jac_referenced_uploads() {
	global $wpdb;
	$paths = array();
	$rows  = $wpdb->get_col( "SELECT post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page') AND post_content LIKE '%/wp-content/uploads/%'" );
	foreach ( $rows as $content ) {
		if ( preg_match_all( '~(?:src|href)=["\'](/wp-content/uploads/[^"\']+)~i', $content, $m ) ) {
			foreach ( $m[1] as $path ) {
				$path = rawurldecode( $path );
				if ( jac_upload_path_ok( $path ) ) {
					$paths[ $path ] = true;
				}
			}
		}
	}
	return array_keys( $paths );
}

/* ------------------------------------------------------------
 * «Инструменты → Картинки со старого сайта»: скопировать всё сразу
 * ------------------------------------------------------------ */
add_action( 'admin_menu', function () {
	add_management_page( 'Картинки со старого сайта', 'Картинки со старого сайта', 'manage_options', 'jac-images', function () {
		$paths   = jac_referenced_uploads();
		$missing = array_values( array_filter( $paths, function ( $p ) {
			return ! file_exists( jac_upload_file( $p ) );
		} ) );
		echo '<div class="wrap"><h1>Картинки со старого сайта</h1>';
		echo '<p style="font-size:15px;max-width:46em">В статьях используется файлов: <strong>' . count( $paths ) . '</strong>. Уже лежат на этом сервере: <strong>' . ( count( $paths ) - count( $missing ) ) . '</strong>. Осталось скопировать: <strong id="jac-left">' . count( $missing ) . '</strong>.</p>';
		if ( ! $missing ) {
			echo '<p style="font-size:15px"><strong>Все картинки на месте.</strong> Старый сайт для них больше не нужен.</p></div>';
			return;
		}
		if ( ! jac_old_site() ) {
			echo '<p>Адрес старого сайта не задан: «Внешний вид → Настроить → Перенос со старого сайта».</p></div>';
			return;
		}
		echo '<p style="font-size:15px;max-width:46em">Сайт и так подтягивает каждую картинку при первом показе страницы. Кнопка ниже копирует всё разом: это нужно сделать до отключения старого сайта (' . esc_html( jac_old_site() ) . '). Вкладку не закрывайте, пока счётчик не дойдёт до нуля.</p>';
		echo '<p><button type="button" class="button button-primary button-hero" id="jac-copy">Скопировать все картинки</button> <span id="jac-status" style="font-size:15px;margin-left:12px"></span></p>';
		?>
		<script>
		(function () {
			var btn = document.getElementById('jac-copy'), status = document.getElementById('jac-status'), left = document.getElementById('jac-left');
			var failed = 0;
			function step() {
				var data = new FormData();
				data.append('action', 'jac_copy_images');
				data.append('_wpnonce', '<?php echo esc_js( wp_create_nonce( 'jac_copy_images' ) ); ?>');
				data.append('skip', failed);
				fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (!res || !res.success) { status.textContent = 'Остановилось. Нажмите кнопку ещё раз.'; btn.disabled = false; return; }
						failed += res.data.failed;
						left.textContent = res.data.left;
						status.textContent = 'Копирую… осталось ' + res.data.left + (failed ? ' (не найдено на старом сайте: ' + failed + ')' : '');
						if (res.data.left > failed) { step(); } else { status.textContent = failed ? 'Готово. Не найдено на старом сайте: ' + failed + '.' : 'Готово, все картинки на месте.'; }
					})
					.catch(function () { status.textContent = 'Связь прервалась. Нажмите кнопку ещё раз.'; btn.disabled = false; });
			}
			btn.addEventListener('click', function () { btn.disabled = true; status.textContent = 'Копирую…'; step(); });
		})();
		</script>
		<?php
		echo '</div>';
	} );
} );

add_action( 'wp_ajax_jac_copy_images', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error();
	}
	check_ajax_referer( 'jac_copy_images' );
	$skip    = isset( $_POST['skip'] ) ? absint( $_POST['skip'] ) : 0;
	$missing = array_values( array_filter( jac_referenced_uploads(), function ( $p ) {
		return ! file_exists( jac_upload_file( $p ) );
	} ) );
	$failed = 0;
	// Файлы, которых нет и на старом сайте, остаются в начале списка: пропускаем их.
	foreach ( array_slice( $missing, $skip, 6 ) as $path ) {
		if ( ! jac_fetch_old_file( $path ) ) {
			++$failed;
		}
	}
	$left = count( array_filter( $missing, function ( $p ) {
		return ! file_exists( jac_upload_file( $p ) );
	} ) );
	wp_send_json_success( array( 'left' => $left, 'failed' => $failed ) );
} );
