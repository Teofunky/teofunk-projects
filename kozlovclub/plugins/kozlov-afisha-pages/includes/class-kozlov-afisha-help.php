<?php
/**
 * Справка в админке: «Настройки → Страницы афиши».
 *
 * Только показывает: как писать шорткод, какие жанры есть в ближайших концертах,
 * на каких страницах стоит шорткод и сколько концертов он сейчас выводит.
 * Настроек нет, в базу ничего не пишет.
 *
 * @package Kozlov_Afisha_Pages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Kozlov_Afisha_Help' ) ) {
	return;
}

/**
 * Help page.
 */
class Kozlov_Afisha_Help {

	const SLUG = 'kozlov-afisha-pages';

	/**
	 * Хуки.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( KOZLOV_AFISHA_PAGES_FILE ), array( __CLASS__, 'action_link' ) );
	}

	/**
	 * Пункт меню.
	 */
	public static function menu() {
		add_options_page( 'Страницы афиши', 'Страницы афиши', 'edit_pages', self::SLUG, array( __CLASS__, 'page' ) );
	}

	/**
	 * Ссылка «Справка» в списке плагинов.
	 *
	 * @param array $links Ссылки.
	 * @return array
	 */
	public static function action_link( $links ) {
		$links = is_array( $links ) ? $links : array();
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ) . '">Справка</a>' );
		return $links;
	}

	/**
	 * Страница справки.
	 */
	public static function page() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>Страницы афиши</h1>';
		try {
			self::usage();
			self::data_check();
			self::pages();
			self::genres();
		} catch ( Throwable $e ) {
			echo '<div class="notice notice-error"><p>Не удалось собрать справку: ' . esc_html( $e->getMessage() ) . '</p></div>';
		}
		echo '</div>';
	}

	/**
	 * Как пользоваться.
	 */
	private static function usage() {
		echo '<h2>Как пользоваться</h2>';
		echo '<p>Создайте обычную страницу, напишите вступительный текст и поставьте шорткод отдельной строкой. Плагин выведет карточки концертов как на главной. Заголовок H1 страницы берётся из её названия, title и description задаются в блоке Yoast.</p>';
		echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th>Шорткод</th><th>Что выводит</th></tr></thead><tbody>';
		$rows = array(
			'[kozlov_afisha genre="jazz"]'            => 'Ближайшие 60 дней, жанр «Джаз» (до 60 концертов), по месяцам',
			'[kozlov_afisha genre="blues,soul" days="120"]' => 'Блюз или соул, ближайшие 120 дней',
			'[kozlov_afisha when="today"]'            => 'Концерты сегодня. Если сегодня уже всё закончилось, ближайший день с концертами',
			'[kozlov_afisha when="weekend"]'          => 'Пятница, суббота, воскресенье этих выходных (с понедельника по четверг: ближайших)',
			'[kozlov_afisha free="1"]'                => 'Только «Вход свободный»',
			'[kozlov_afisha stage="the_beat"]'        => 'Только сцена The Beat (main_scene, mansarda, the_beat, myasnitskaya)',
			'[kozlov_afisha genre="jazz" limit="30"]' => 'Не больше 30 концертов',
		);
		foreach ( $rows as $code => $text ) {
			echo '<tr><td><code>' . esc_html( $code ) . '</code></td><td>' . esc_html( $text ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">Параметры можно сочетать. Ещё: <code>group="month|day|none"</code> (группировка), <code>empty="…"</code> (текст, если концертов нет), <code>more="0"</code> (без ссылки «Вся афиша клуба»). Жанр можно писать по-русски или по-английски, регистр не важен. Готовый список хранится до 5 минут и сбрасывается при сохранении любого анонса. W3 Total Cache такие страницы целиком не кэширует. Ошибки в параметрах и в чтении календаря видны на странице только вошедшим редакторам.</p>';
		echo '<p class="description"><strong>Перед отключением плагина</strong> переведите страницы с афишей в черновики, иначе на них будет виден текст шорткода.</p>';
	}

	/**
	 * Проверка данных календаря.
	 */
	private static function data_check() {
		echo '<h2>Проверка данных</h2>';
		$d = Kozlov_Afisha_Data::diagnostics( 60 );
		if ( '' !== $d['error'] ) {
			echo '<div class="notice notice-error inline"><p>Календарь не читается: ' . esc_html( $d['error'] ) . '. Страницы афиши сейчас пустые.</p></div>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:980px"><tbody>';
		echo '<tr><td>Строк календаря на ближайшие 60 дней (с запасом)</td><td>' . (int) $d['rows'] . '</td></tr>';
		echo '<tr><td>Концертов, которые выводятся без фильтров</td><td>' . (int) $d['shown'] . '</td></tr>';
		echo '<tr><td>Пропущено повторяющихся событий календаря</td><td>' . (int) $d['recurring'] . ( $d['recurring'] > 0 ? ' <strong style="color:#d63638">(они не попадут на страницы афиши)</strong>' : '' ) . '</td></tr>';
		echo '<tr><td>Пропущено с нечитаемой датой</td><td>' . (int) $d['bad_time'] . '</td></tr>';
		echo '</tbody></table>';
	}

	/**
	 * Страницы, где стоит шорткод.
	 */
	private static function pages() {
		global $wpdb;

		echo '<h2>Страницы с афишей</h2>';
		$like = '%' . $wpdb->esc_like( '[kozlov_afisha' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status IN ('publish','draft','pending','private','future') AND post_content LIKE %s ORDER BY menu_order, post_title LIMIT 100", $like ) );
		if ( empty( $ids ) ) {
			echo '<p>Пока нет страниц с шорткодом <code>[kozlov_afisha]</code>.</p>';
			return;
		}

		$statuses = get_post_statuses();
		echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th>Страница</th><th>Статус</th><th>Шорткод</th><th>Концертов сейчас</th></tr></thead><tbody>';
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( ! ( $post instanceof WP_Post ) ) {
				continue;
			}
			$codes = self::shortcodes_in( (string) $post->post_content );
			$count = array();
			foreach ( $codes as $atts ) {
				$result  = Kozlov_Afisha_Data::select( Kozlov_Afisha_Data::normalize_args( $atts ) );
				$count[] = count( $result['items'] ) . ( $result['total'] > count( $result['items'] ) ? ' из ' . $result['total'] : '' ) . ( '' !== $result['fallback'] ? ' (ближайший день)' : '' );
			}
			$title  = '' !== $post->post_title ? $post->post_title : '(без названия)';
			$status = isset( $statuses[ $post->post_status ] ) ? $statuses[ $post->post_status ] : $post->post_status;
			echo '<tr><td><a href="' . esc_url( (string) get_edit_post_link( $post->ID ) ) . '">' . esc_html( $title ) . '</a>';
			echo ' &nbsp; <a href="' . esc_url( (string) get_permalink( $post ) ) . '" target="_blank" rel="noopener">открыть</a></td>';
			echo '<td>' . esc_html( $status ) . '</td>';
			echo '<td>' . esc_html( implode( ' ', array_map( array( __CLASS__, 'code_text' ), $codes ) ) ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', $count ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Параметры всех шорткодов [kozlov_afisha] в тексте.
	 *
	 * @param string $content Текст страницы.
	 * @return array[]
	 */
	private static function shortcodes_in( $content ) {
		$found = array();
		if ( ! preg_match_all( '/' . get_shortcode_regex( array( 'kozlov_afisha' ) ) . '/s', $content, $matches, PREG_SET_ORDER ) ) {
			return $found;
		}
		foreach ( $matches as $m ) {
			if ( '[' === $m[1] && ']' === $m[6] ) {
				continue; // Экранированный [[kozlov_afisha]].
			}
			$atts    = shortcode_parse_atts( $m[3] );
			$found[] = is_array( $atts ) ? $atts : array();
		}
		return $found;
	}

	/**
	 * Шорткод обратно в текст (для таблицы).
	 *
	 * @param array $atts Параметры.
	 * @return string
	 */
	private static function code_text( $atts ) {
		$parts = array( 'kozlov_afisha' );
		foreach ( $atts as $key => $value ) {
			$parts[] = is_int( $key ) ? $value : $key . '="' . $value . '"';
		}
		return '[' . implode( ' ', $parts ) . ']';
	}

	/**
	 * Жанры ближайших концертов.
	 */
	private static function genres() {
		echo '<h2>Жанры в анонсах на ближайшие 120 дней</h2>';
		$stats = Kozlov_Afisha_Data::genre_stats( 120 );
		if ( empty( $stats ) ) {
			echo '<p>Нет ближайших концертов с отмеченными жанрами (или календарь не отвечает).</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th>Жанр в анонсе</th><th>Что писать в genre=""</th><th>Концертов</th></tr></thead><tbody>';
		foreach ( $stats as $label => $row ) {
			echo '<tr><td>' . esc_html( $label ) . '</td><td><code>' . implode( '</code> или <code>', array_map( 'esc_html', $row['keys'] ) ) . '</code></td><td>' . (int) $row['count'] . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">Метки вроде «Вход свободный», «Фестиваль», «Лекция» в анонсах стоят в том же списке, что и жанры, поэтому они тоже здесь.</p>';
	}
}
