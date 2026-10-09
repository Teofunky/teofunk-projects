<?php
/**
 * Страница «Настройки → Разметка концертов»: выключатель и самопроверка.
 * Страница только читает данные, кроме сохранения собственной настройки плагина.
 *
 * @package Kozlov_Event_Schema
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Kozlov_Event_Schema_Admin' ) ) {
	return;
}

/**
 * Admin.
 */
class Kozlov_Event_Schema_Admin {

	const PAGE = 'kozlov-event-schema';

	const GROUP = 'kozlov_event_schema';

	/** Сколько ближайших событий проверять. */
	const CHECK_LIMIT = 1000;

	/** Сколько строк показывать в таблице «Ближайшие концерты». */
	const LIST_LIMIT = 60;

	/**
	 * Хуки.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( KOZLOV_EVENT_SCHEMA_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Пункт меню.
	 */
	public static function menu() {
		add_options_page( 'Разметка концертов', 'Разметка концертов', 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	/**
	 * Настройка.
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			KOZLOV_EVENT_SCHEMA_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array( 'enabled' => 0 ),
			)
		);
	}

	/**
	 * Только один флажок.
	 *
	 * @param mixed $input Данные формы.
	 * @return array
	 */
	public static function sanitize( $input ) {
		return array( 'enabled' => ( is_array( $input ) && ! empty( $input['enabled'] ) ) ? 1 : 0 );
	}

	/**
	 * Ссылка «Настройки» в списке плагинов.
	 *
	 * @param array $links Ссылки.
	 * @return array
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">Настройки и проверка</a>' );
		return $links;
	}

	/**
	 * Страница.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap">';
		echo '<h1>Разметка концертов (schema.org Event)</h1>';
		try {
			self::render_settings();
			self::render_preview();
			self::render_check();
		} catch ( Throwable $e ) {
			echo '<div class="notice notice-error"><p>Ошибка проверки: ' . esc_html( $e->getMessage() ) . '. На работу сайта это не влияет.</p></div>';
		}
		echo '</div>';
	}

	/**
	 * Выключатель.
	 */
	private static function render_settings() {
		$enabled = kozlov_event_schema_is_enabled();
		echo '<p>Плагин добавляет в &lt;head&gt; страниц будущих концертов один блок JSON-LD с датой, сценой, адресом, ценой и картинкой. Данные берутся из полей анонса, которые уже заполняют редакторы. Тема, база и афиша не меняются.</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		echo '<table class="form-table" role="presentation"><tr><th scope="row">Разметка на сайте</th><td>';
		echo '<label><input type="checkbox" name="' . esc_attr( KOZLOV_EVENT_SCHEMA_OPTION ) . '[enabled]" value="1" ' . checked( $enabled, true, false ) . '> Выводить разметку на страницах концертов</label>';
		echo '<p class="description">Сейчас: <strong>' . ( $enabled ? 'включена' : 'выключена' ) . '</strong>. После включения или выключения очистите кэш страниц (Performance → Purge All Caches), иначе изменения появятся по мере обновления кэша.</p>';
		echo '</td></tr></table>';
		submit_button( 'Сохранить' );
		echo '</form>';
	}

	/**
	 * Просмотр разметки одного концерта.
	 */
	private static function render_preview() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- только чтение.
		$preview_id = isset( $_GET['kes_preview'] ) ? absint( wp_unslash( $_GET['kes_preview'] ) ) : 0;
		if ( ! $preview_id ) {
			return;
		}
		$post = get_post( $preview_id );
		echo '<h2>Просмотр разметки</h2>';
		if ( ! ( $post instanceof WP_Post ) || Kozlov_Event_Schema_Builder::POST_TYPE !== $post->post_type ) {
			echo '<p>Событие не найдено.</p>';
			return;
		}
		$result = Kozlov_Event_Schema_Builder::analyze( $post );
		$front  = Kozlov_Event_Schema_Builder::build_for_front( $post );
		echo '<p><strong>' . esc_html( Kozlov_Event_Schema_Builder::plain_text( $post->post_title ) ) . '</strong>, ' . esc_html( $result['info']['start'] ) . '. ';
		echo empty( $front['data'] ) ? 'На сайте разметка для него <strong>не выводится</strong>.' : 'На сайте выводится этот блок (когда разметка включена).';
		echo '</p>';
		self::render_issue_list( $result['issues'] );
		if ( empty( $result['data'] ) ) {
			return;
		}
		$snippet = '<script type="application/ld+json">' . "\n" . Kozlov_Event_Schema_Builder::to_json( $result['data'], true ) . "\n</script>";
		echo '<p>Для проверки скопируйте блок целиком и вставьте во вкладку «Код» на <a href="https://search.google.com/test/rich-results" target="_blank" rel="noopener noreferrer">search.google.com/test/rich-results</a>.</p>';
		echo '<textarea readonly rows="22" style="width:100%;max-width:900px;font-family:monospace;font-size:12px;" onclick="this.select()">' . esc_textarea( $snippet ) . '</textarea>';
		if ( ! empty( $front['data'] ) && kozlov_event_schema_is_enabled() ) {
			$test = 'https://search.google.com/test/rich-results?url=' . rawurlencode( (string) get_permalink( $post ) );
			echo '<p><a href="' . esc_url( $test ) . '" target="_blank" rel="noopener noreferrer">Проверить живую страницу в Google</a> (после очистки кэша страниц).</p>';
		}
	}

	/**
	 * Самопроверка всех ближайших событий.
	 */
	private static function render_check() {
		global $wpdb;

		echo '<h2>Проверка ближайших концертов</h2>';

		$table    = Kozlov_Event_Schema_Builder::table();
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- имя таблицы из $wpdb->prefix.
		$probe = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		$error = $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );
		if ( null === $probe || '' !== $error ) {
			echo '<div class="notice notice-error inline"><p>Не удалось прочитать таблицу календаря <code>' . esc_html( $table ) . '</code>. Разметка выводиться не будет, сайт работает как обычно.</p></div>';
			return;
		}

		$since = time() - DAY_IN_SECONDS;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT e.`post_id` FROM `{$table}` e INNER JOIN `{$wpdb->posts}` p ON p.ID = e.post_id WHERE p.post_type = %s AND p.post_status IN ('publish','future','draft','pending','private') AND e.`start` >= %d ORDER BY e.`start` ASC LIMIT %d", Kozlov_Event_Schema_Builder::POST_TYPE, $since, self::CHECK_LIMIT ) );
		$ids = array_map( 'intval', (array) $ids );

		if ( empty( $ids ) ) {
			echo '<p>Ближайших событий не найдено.</p>';
			return;
		}

		// Загружаем записи и их поля пачкой, чтобы не делать сотни запросов.
		$posts = get_posts(
			array(
				'post__in'         => $ids,
				'post_type'        => Kozlov_Event_Schema_Builder::POST_TYPE,
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page'   => count( $ids ),
				'orderby'          => 'post__in',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		Kozlov_Event_Schema_Builder::prefetch_rows( $ids );

		// Картинки записей тоже пачкой.
		$thumb_ids = array();
		foreach ( $posts as $post ) {
			$thumb = (int) get_post_meta( $post->ID, '_thumbnail_id', true );
			if ( $thumb > 0 ) {
				$thumb_ids[] = $thumb;
			}
		}
		if ( ! empty( $thumb_ids ) ) {
			get_posts(
				array(
					'post__in'         => array_values( array_unique( $thumb_ids ) ),
					'post_type'        => 'attachment',
					'post_status'      => 'inherit',
					'posts_per_page'   => count( $thumb_ids ),
					'no_found_rows'    => true,
					'suppress_filters' => true,
				)
			);
		}

		$labels   = Kozlov_Event_Schema_Builder::issue_labels();
		$statuses = array(
			'publish' => 'Опубликован',
			'future'  => 'Запланирован',
			'draft'   => 'Черновик',
			'pending' => 'На утверждении',
			'private' => 'Личный',
		);

		$counts    = array();
		$ready     = 0;
		$published = 0;
		$attention = array();
		$all       = array();

		foreach ( $posts as $post ) {
			$result   = Kozlov_Event_Schema_Builder::analyze( $post );
			$is_ready = ! empty( $result['data'] ) && ! Kozlov_Event_Schema_Builder::is_blocked( $result['issues'] );
			if ( 'publish' === $post->post_status ) {
				++$published;
			}
			if ( $is_ready ) {
				++$ready;
			}
			$shown = array_diff( $result['issues'], array( 'not_published', 'ended' ) );
			foreach ( $shown as $code ) {
				$counts[ $code ] = isset( $counts[ $code ] ) ? $counts[ $code ] + 1 : 1;
			}
			$row = array(
				'post'   => $post,
				'result' => $result,
				'ready'  => $is_ready,
				'shown'  => $shown,
			);
			if ( ! empty( $shown ) ) {
				$attention[] = $row;
			}
			$all[] = $row;
		}

		echo '<p>Проверено событий с датой от вчерашнего дня: <strong>' . (int) count( $posts ) . '</strong> (опубликовано: ' . (int) $published . '). Разметка готова к выводу: <strong>' . (int) $ready . '</strong>.</p>';

		if ( ! empty( $counts ) ) {
			arsort( $counts );
			echo '<ul style="list-style:disc;margin-left:20px;">';
			foreach ( $counts as $code => $count ) {
				$label = isset( $labels[ $code ] ) ? $labels[ $code ] : $code;
				echo '<li>' . esc_html( $label ) . ': <strong>' . (int) $count . '</strong></li>';
			}
			echo '</ul>';
		}

		if ( ! empty( $attention ) ) {
			echo '<h3>Требуют внимания (' . (int) count( $attention ) . ')</h3>';
			self::render_table( $attention, $statuses, $labels, true );
		}

		echo '<h3>Ближайшие концерты (первые ' . (int) min( self::LIST_LIMIT, count( $all ) ) . ')</h3>';
		self::render_table( array_slice( $all, 0, self::LIST_LIMIT ), $statuses, $labels, false );
	}

	/**
	 * Таблица событий.
	 *
	 * @param array $rows       Строки.
	 * @param array $statuses   Названия статусов.
	 * @param array $labels     Названия проблем.
	 * @param bool  $show_issues Показывать столбец проблем.
	 */
	private static function render_table( array $rows, array $statuses, array $labels, $show_issues ) {
		echo '<table class="widefat striped" style="max-width:1200px;"><thead><tr>';
		echo '<th>Дата</th><th>Концерт</th><th>Статус</th><th>Сцена</th><th>Стоимость</th><th>Разметка</th>';
		if ( $show_issues ) {
			echo '<th>Что не так</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$post    = $row['post'];
			$info    = $row['result']['info'];
			$edit    = get_edit_post_link( $post->ID, 'raw' );
			$preview = add_query_arg(
				array(
					'page'        => self::PAGE,
					'kes_preview' => $post->ID,
				),
				admin_url( 'options-general.php' )
			);
			$status  = isset( $statuses[ $post->post_status ] ) ? $statuses[ $post->post_status ] : $post->post_status;
			echo '<tr>';
			echo '<td>' . esc_html( $info['start'] ) . '</td>';
			echo '<td>';
			if ( $edit ) {
				echo '<a href="' . esc_url( $edit ) . '">' . esc_html( Kozlov_Event_Schema_Builder::plain_text( $post->post_title ) ) . '</a>';
			} else {
				echo esc_html( Kozlov_Event_Schema_Builder::plain_text( $post->post_title ) );
			}
			echo '</td>';
			echo '<td>' . esc_html( $status ) . '</td>';
			echo '<td>' . esc_html( '' !== $info['stage'] ? $info['stage'] : 'не отмечена' ) . '</td>';
			echo '<td>' . esc_html( $info['price'] ) . '</td>';
			echo '<td>' . ( $row['ready'] ? 'выводится' : 'нет' ) . ' · <a href="' . esc_url( $preview ) . '">показать</a></td>';
			if ( $show_issues ) {
				$texts = array();
				foreach ( $row['shown'] as $code ) {
					$texts[] = isset( $labels[ $code ] ) ? $labels[ $code ] : $code;
				}
				echo '<td>' . esc_html( implode( '; ', $texts ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Список проблем одного события.
	 *
	 * @param string[] $issues Коды.
	 */
	private static function render_issue_list( array $issues ) {
		if ( empty( $issues ) ) {
			echo '<p>Замечаний нет.</p>';
			return;
		}
		$labels = Kozlov_Event_Schema_Builder::issue_labels();
		echo '<ul style="list-style:disc;margin-left:20px;">';
		foreach ( $issues as $code ) {
			echo '<li>' . esc_html( isset( $labels[ $code ] ) ? $labels[ $code ] : $code ) . '</li>';
		}
		echo '</ul>';
	}
}
