<?php
/**
 * Админка: блок в анонсе (заглавное фото и программа), колонка в списке концертов,
 * страница «Новые страницы» со списком ближайших концертов без фото и настройки.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KEP_Admin {

	const PAGE = 'kep-pages';

	public static function init() {
		add_action( 'add_meta_boxes_' . KEP_POST_TYPE, array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_' . KEP_POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'wp_ajax_kep_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'manage_' . KEP_POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ), 20 );
		add_action( 'manage_' . KEP_POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'views_edit-' . KEP_POST_TYPE, array( __CLASS__, 'views' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_list' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'update_option_' . KEP_Settings::OPTION, array( __CLASS__, 'settings_changed' ), 10, 2 );
		add_action( 'add_option_' . KEP_Settings::OPTION, array( __CLASS__, 'settings_added' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( KEP_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'post_type' => KEP_POST_TYPE, 'page' => self::PAGE ), $args ), admin_url( 'edit.php' ) );
	}

	/* ---------- блок в анонсе ---------- */

	public static function add_box() {
		add_meta_box( 'kep-box-wrap', 'Новая страница концерта', array( __CLASS__, 'box' ), KEP_POST_TYPE, 'side', 'high' );
	}

	/**
	 * Состояние страницы концерта для редактора.
	 *
	 * @param int $post_id ID записи.
	 * @return array level, text
	 */
	public static function status( $post_id ) {
		$mode = KEP_Settings::get( 'mode' );
		if ( ! KEP_Data::hero_id( $post_id ) ) {
			return array( 'level' => 'off', 'text' => 'Сейчас старая страница. Загрузите заглавное фото, и концерт откроется в новом виде.' );
		}
		if ( 'all' === $mode ) {
			return array( 'level' => 'on', 'text' => 'Новая страница работает. Её видят все посетители.' );
		}
		if ( 'editors' === $mode ) {
			return array( 'level' => 'half', 'text' => 'Новая страница готова. Пока её видят только редакторы, гости видят старую (режим «Только редакторы»).' );
		}
		return array( 'level' => 'off', 'text' => 'Фото загружено, но плагин выключен: все видят старую страницу.' );
	}

	private static function check_for( $hero ) {
		if ( ! $hero ) {
			return null;
		}
		$meta = wp_get_attachment_metadata( $hero );
		$w    = is_array( $meta ) && isset( $meta['width'] ) ? $meta['width'] : 0;
		$h    = is_array( $meta ) && isset( $meta['height'] ) ? $meta['height'] : 0;
		return KEP_Data::check_size( $w, $h );
	}

	/**
	 * Подсказка названия программы из названия концерта: «…» после двоеточия.
	 *
	 * @param string $title Название.
	 * @return string
	 */
	public static function suggest_program( $title ) {
		$title = KEP_Parser::clean( $title );
		if ( ! preg_match( '/:\s*«([^»]{2,80})»/u', $title, $m ) ) {
			return '';
		}
		$p = trim( $m[1] );
		if ( $p === mb_strtoupper( $p, 'UTF-8' ) ) {
			$p = mb_strtolower( $p, 'UTF-8' );
			$p = mb_strtoupper( mb_substr( $p, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $p, 1, null, 'UTF-8' );
		}
		return $p;
	}

	public static function box( $post ) {
		$hero    = KEP_Data::hero_id( $post->ID );
		$program = KEP_Data::program( $post->ID );
		$status  = self::status( $post->ID );
		$check   = self::check_for( $hero );
		$img     = $hero ? wp_get_attachment_image_url( $hero, 'large' ) : '';
		$suggest = self::suggest_program( $post->post_title );
		$link    = get_permalink( $post->ID );
		?>
		<div id="kep-box" class="kep-a" data-post="<?php echo esc_attr( $post->ID ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'kep_box_' . $post->ID ) ); ?>">
			<p class="kep-a-status kep-a-status--<?php echo esc_attr( $status['level'] ); ?>" data-kep-status><?php echo esc_html( $status['text'] ); ?></p>

			<p class="kep-a-label">Заглавное фото</p>
			<div class="kep-a-frame<?php echo $img ? '' : ' is-empty'; ?>" data-kep-frame>
				<?php if ( $img ) : ?>
					<img src="<?php echo esc_url( $img ); ?>" alt="">
				<?php else : ?>
					Фото нет
				<?php endif; ?>
			</div>
			<p class="kep-a-check<?php echo $check ? ' kep-a-check--' . esc_attr( $check['level'] ) : ''; ?>" data-kep-check<?php echo $check ? '' : ' hidden'; ?>><?php echo $check ? esc_html( $check['text'] ) : ''; ?></p>
			<p class="kep-a-btns">
				<button type="button" class="button button-primary" data-kep-pick><?php echo $hero ? 'Заменить фото' : 'Загрузить фото'; ?></button>
				<button type="button" class="button-link kep-a-remove" data-kep-remove<?php echo $hero ? '' : ' hidden'; ?>>Убрать</button>
			</p>
			<details class="kep-a-help">
				<summary>Какое фото подходит</summary>
				<ul>
					<li>Горизонтальное, пропорции 16:10.</li>
					<li>Размер от 1600×1000 px, лучше 2400×1500 px.</li>
					<li>JPG. Лёгкие копии для телефонов WordPress сделает сам.</li>
					<li>Рамка выше показывает, что увидит посетитель. Если пропорции другие, края обрежутся: главное держите по центру.</li>
					<li>Баннер анонса в афише остаётся прежним.</li>
				</ul>
			</details>

			<p class="kep-a-label"><label for="kep-program">Название программы</label></p>
			<input type="text" id="kep-program" name="kep_program" class="widefat" value="<?php echo esc_attr( $program ); ?>" placeholder="Если у концерта есть своё название" maxlength="120">
			<?php if ( $suggest ) : ?>
				<p class="kep-a-suggest"><button type="button" class="button-link" data-kep-suggest="<?php echo esc_attr( $suggest ); ?>">Подставить из названия: «<?php echo esc_html( $suggest ); ?>»</button></p>
			<?php endif; ?>
			<p class="description">Показывается под названием концерта. Пустое поле: строки нет.</p>

			<p class="kep-a-saved" data-kep-saved aria-live="polite">Всё в этом блоке сохраняется сразу.</p>
			<?php if ( $link && 'auto-draft' !== $post->post_status ) : ?>
				<p class="kep-a-links">
					<a href="<?php echo esc_url( add_query_arg( 'kep', 'new', $link ) ); ?>" target="_blank" rel="noopener">Открыть новую страницу</a>
					<span aria-hidden="true">·</span>
					<a href="<?php echo esc_url( add_query_arg( 'kep', 'old', $link ) ); ?>" target="_blank" rel="noopener">старую</a>
				</p>
			<?php endif; ?>
			<input type="hidden" name="kep_hero_id" value="<?php echo esc_attr( $hero ? $hero : '' ); ?>" data-kep-hero>
			<?php wp_nonce_field( 'kep_box_' . $post->ID, 'kep_nonce' ); ?>
		</div>
		<?php
	}

	/**
	 * Сохранение вместе с анонсом (кнопка «Обновить»).
	 */
	public static function save( $post_id, $post ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['kep_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kep_nonce'] ) ), 'kep_box_' . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$changed = false;
		if ( isset( $_POST['kep_hero_id'] ) ) {
			$changed = self::set_hero( $post_id, absint( $_POST['kep_hero_id'] ) ) || $changed;
		}
		if ( isset( $_POST['kep_program'] ) ) {
			$changed = self::set_program( $post_id, sanitize_text_field( wp_unslash( $_POST['kep_program'] ) ) ) || $changed;
		}
		if ( $changed ) {
			KEP_Data::flush_post( $post_id );
		}
	}

	private static function set_hero( $post_id, $id ) {
		$old = (int) get_post_meta( $post_id, KEP_META_HERO, true );
		if ( $id && wp_attachment_is_image( $id ) ) {
			if ( $old === $id ) {
				return false;
			}
			update_post_meta( $post_id, KEP_META_HERO, $id );
			return true;
		}
		if ( $old ) {
			delete_post_meta( $post_id, KEP_META_HERO );
			return true;
		}
		return false;
	}

	private static function set_program( $post_id, $value ) {
		$value = mb_substr( trim( $value ), 0, 120, 'UTF-8' );
		$old   = (string) get_post_meta( $post_id, KEP_META_PROGRAM, true );
		if ( $old === $value ) {
			return false;
		}
		if ( '' === $value ) {
			delete_post_meta( $post_id, KEP_META_PROGRAM );
		} else {
			update_post_meta( $post_id, KEP_META_PROGRAM, $value );
		}
		return true;
	}

	/**
	 * Мгновенное сохранение из блока (фото и программа).
	 */
	public static function ajax_save() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! check_ajax_referer( 'kep_box_' . $post_id, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'страница устарела' ), 403 );
		}
		if ( KEP_POST_TYPE !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'нет прав на этот концерт' ), 403 );
		}
		$field = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		$value = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';
		if ( 'hero' === $field ) {
			$id = absint( $value );
			if ( $id && ! wp_attachment_is_image( $id ) ) {
				wp_send_json_error( array( 'message' => 'это не картинка' ), 400 );
			}
			self::set_hero( $post_id, $id );
		} elseif ( 'program' === $field ) {
			self::set_program( $post_id, sanitize_text_field( $value ) );
		} else {
			wp_send_json_error( array( 'message' => 'неизвестное поле' ), 400 );
		}
		KEP_Data::flush_post( $post_id );
		$hero = KEP_Data::hero_id( $post_id );
		wp_send_json_success(
			array(
				'hero'    => $hero,
				'img'     => $hero ? wp_get_attachment_image_url( $hero, 'large' ) : '',
				'check'   => self::check_for( $hero ),
				'status'  => self::status( $post_id ),
				'program' => KEP_Data::program( $post_id ),
			)
		);
	}

	/* ---------- список концертов ---------- */

	public static function columns( $cols ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'title' === $k ) {
				$out['kep'] = 'Новая страница';
			}
		}
		if ( ! isset( $out['kep'] ) ) {
			$out['kep'] = 'Новая страница';
		}
		return $out;
	}

	public static function column( $col, $post_id ) {
		if ( 'kep' !== $col ) {
			return;
		}
		$hero = KEP_Data::hero_id( $post_id );
		if ( $hero ) {
			echo '<span class="kep-a-col">' . wp_get_attachment_image( $hero, 'thumbnail', false, array( 'class' => 'kep-a-thumb', 'alt' => '' ) ) . '<span>есть</span></span>';
		} else {
			echo '<a class="kep-a-col kep-a-col--none" href="' . esc_url( get_edit_post_link( $post_id ) . '#kep-box-wrap' ) . '">нет фото</a>';
		}
	}

	public static function views( $views ) {
		$q    = new WP_Query(
			array(
				'post_type'      => KEP_POST_TYPE,
				'post_status'    => 'any',
				'meta_key'       => KEP_META_HERO, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
		$cur  = isset( $_GET['kep_hero'] ) ? sanitize_key( wp_unslash( $_GET['kep_hero'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$base = admin_url( 'edit.php?post_type=' . KEP_POST_TYPE );
		$views['kep_with']    = '<a href="' . esc_url( add_query_arg( 'kep_hero', 'with', $base ) ) . '"' . ( 'with' === $cur ? ' class="current" aria-current="page"' : '' ) . '>С новой страницей <span class="count">(' . (int) $q->found_posts . ')</span></a>';
		$views['kep_without'] = '<a href="' . esc_url( add_query_arg( 'kep_hero', 'without', $base ) ) . '"' . ( 'without' === $cur ? ' class="current" aria-current="page"' : '' ) . '>Без заглавного фото</a>';
		$views['kep_page']    = '<a href="' . esc_url( self::page_url() ) . '">Ближайшие без фото →</a>';
		return $views;
	}

	public static function filter_list( $q ) {
		if ( ! is_admin() || ! $q->is_main_query() || KEP_POST_TYPE !== $q->get( 'post_type' ) || empty( $_GET['kep_hero'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$mode = sanitize_key( wp_unslash( $_GET['kep_hero'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$mq   = (array) $q->get( 'meta_query' );
		$mq[] = array(
			'key'     => KEP_META_HERO,
			'compare' => 'with' === $mode ? 'EXISTS' : 'NOT EXISTS',
		);
		$q->set( 'meta_query', $mq );
	}

	/* ---------- страница «Новые страницы» ---------- */

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . KEP_POST_TYPE, 'Новые страницы концертов', 'Новые страницы', 'edit_posts', self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function register_settings() {
		register_setting(
			'kep',
			KEP_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'KEP_Settings', 'sanitize' ),
			)
		);
	}

	public static function settings_changed( $old, $new ) {
		$om = is_array( $old ) && isset( $old['mode'] ) ? $old['mode'] : '';
		$nm = is_array( $new ) && isset( $new['mode'] ) ? $new['mode'] : '';
		if ( $om !== $nm ) {
			KEP_Data::flush_all();
			set_transient( 'kep_mode_changed', 1, 60 );
		}
	}

	/**
	 * Настройки записаны впервые (например, после удаления опции): сбросить кэш.
	 */
	public static function settings_added( $option, $value ) {
		KEP_Data::flush_all();
		set_transient( 'kep_mode_changed', 1, 60 );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">Настройки и список</a>' );
		return $links;
	}

	/**
	 * Ближайшие концерты с ID записей и статусом фото.
	 *
	 * @return array|false
	 */
	private static function rows() {
		$events = KEP_Data::upcoming();
		if ( ! $events ) {
			return false;
		}
		$slugs = array();
		foreach ( $events as $e ) {
			$slugs[] = $e['slug'];
		}
		$ids = array();
		foreach ( array_chunk( $slugs, 200 ) as $chunk ) {
			$posts = get_posts(
				array(
					'post_type'              => KEP_POST_TYPE,
					'post_status'            => array( 'publish', 'future', 'private' ),
					'post_name__in'          => $chunk,
					'posts_per_page'         => -1,
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
				)
			);
			foreach ( $posts as $p ) {
				$ids[ $p->post_name ] = $p->ID;
			}
		}
		$rows = array();
		foreach ( $events as $e ) {
			$id     = isset( $ids[ $e['slug'] ] ) ? (int) $ids[ $e['slug'] ] : 0;
			$e['id']   = $id;
			$e['hero'] = $id ? KEP_Data::hero_id( $id ) : 0;
			$rows[]    = $e;
		}
		return $rows;
	}

	public static function page() {
		$rows     = self::rows();
		$s        = KEP_Settings::get();
		$tab      = isset( $_GET['tab'] ) && 'all' === $_GET['tab'] ? 'all' : 'todo'; // phpcs:ignore WordPress.Security.NonceVerification
		$is_admin = current_user_can( 'manage_options' );
		$modes    = array(
			'off'     => array( 'Выключено', 'Все видят старые страницы. Фото можно загружать, они подождут.' ),
			'editors' => array( 'Только редакторы', 'Новые страницы видят только те, кто вошёл в админку. Гости видят старые. Удобно, пока загружаете фото и проверяете.' ),
			'all'     => array( 'Для всех', 'Концерты с заглавным фото открываются в новом виде у всех посетителей. Без фото остаётся старая страница.' ),
		);
		echo '<div class="wrap kep-admin"><h1>Новые страницы концертов</h1>';
		$mode_changed = (bool) get_transient( 'kep_mode_changed' );
		if ( $mode_changed ) {
			delete_transient( 'kep_mode_changed' );
		}
		if ( $mode_changed || isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$msg = 'Настройки сохранены.';
			if ( $mode_changed ) {
				$msg .= ' Режим изменён. ' . ( function_exists( 'w3tc_flush_all' ) ? 'Кэш страниц сброшен.' : 'Если на сайте включён кэш страниц, сбросьте его.' );
			}
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		echo '<p class="kep-admin-lead">Концерт открывается в новом виде, как только в анонсе есть заглавное фото. Загружается в блоке «Новая страница концерта» справа в анонсе.</p>';
		echo '<p class="kep-admin-mode">Режим сейчас: <strong>' . esc_html( $modes[ $s['mode'] ][0] ) . '</strong>. ' . esc_html( $modes[ $s['mode'] ][1] );
		if ( $is_admin ) {
			echo ' <a href="#kep-settings">Изменить</a>';
		}
		echo '</p>';

		if ( false === $rows ) {
			echo '<div class="notice notice-warning inline"><p>Список ближайших концертов берётся из Kozlov Smart Search. Он не найден или выключен, поэтому списка нет. Фото можно загружать прямо в анонсах.</p></div>';
		} else {
			$total = count( $rows );
			$done  = 0;
			foreach ( $rows as $r ) {
				if ( $r['hero'] ) {
					$done++;
				}
			}
			$pct = $total ? (int) round( 100 * $done / $total ) : 0;
			echo '<div class="kep-progress"><div class="kep-progress-t"><strong>' . (int) $done . ' из ' . (int) $total . '</strong> ближайших концертов уже с новой страницей</div><div class="kep-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int) $pct . '"><span style="width:' . (int) $pct . '%"></span></div></div>';
			echo '<nav class="kep-tabs" aria-label="Фильтр">';
			echo '<a href="' . esc_url( self::page_url() ) . '"' . ( 'todo' === $tab ? ' class="is-on" aria-current="page"' : '' ) . '>Без фото (' . (int) ( $total - $done ) . ')</a>';
			echo '<a href="' . esc_url( self::page_url( array( 'tab' => 'all' ) ) ) . '"' . ( 'all' === $tab ? ' class="is-on" aria-current="page"' : '' ) . '>Все ближайшие (' . (int) $total . ')</a>';
			echo '</nav>';
			echo '<table class="widefat striped kep-table"><thead><tr><th scope="col">Дата</th><th scope="col">Сцена</th><th scope="col">Концерт</th><th scope="col">Заглавное фото</th><th scope="col"><span class="screen-reader-text">Действие</span></th></tr></thead><tbody>';
			$stages = KEP_Settings::stages();
			$today  = wp_date( 'Y-m-d' );
			$tomor  = wp_date( 'Y-m-d', time() + DAY_IN_SECONDS );
			$shown  = 0;
			foreach ( $rows as $r ) {
				if ( 'todo' === $tab && $r['hero'] ) {
					continue;
				}
				$shown++;
				$ts    = strtotime( $r['date'] . ' 12:00:00 UTC' );
				$label = $r['date'] === $today ? 'Сегодня' : ( $r['date'] === $tomor ? 'Завтра' : '' );
				$date  = $ts ? gmdate( 'j', $ts ) . ' ' . KEP_Render::MONTHS_SHORT[ (int) gmdate( 'n', $ts ) ] : $r['date'];
				echo '<tr><td class="kep-td-date">' . ( $label ? '<span class="kep-soon">' . esc_html( $label ) . '</span> ' : '' ) . esc_html( $date . ', ' . KEP_Render::show_time( $r['time'] ) ) . '</td>';
				echo '<td>' . esc_html( isset( $stages[ $r['stage'] ] ) ? $stages[ $r['stage'] ] : $r['stage'] ) . '</td>';
				echo '<td class="kep-td-title">' . ( $r['id'] ? '<a href="' . esc_url( get_edit_post_link( $r['id'] ) . '#kep-box-wrap' ) . '">' . esc_html( $r['title'] ) . '</a>' : esc_html( $r['title'] ) ) . '</td>';
				echo '<td>' . ( $r['hero'] ? wp_get_attachment_image( $r['hero'], 'thumbnail', false, array( 'class' => 'kep-a-thumb', 'alt' => '' ) ) : '<span class="kep-none">нет</span>' ) . '</td>';
				echo '<td class="kep-td-act">';
				if ( $r['id'] && $r['hero'] ) {
					echo '<a class="button" href="' . esc_url( get_permalink( $r['id'] ) ) . '" target="_blank" rel="noopener">Открыть</a>';
				} elseif ( $r['id'] ) {
					echo '<a class="button button-primary" href="' . esc_url( get_edit_post_link( $r['id'] ) . '#kep-box-wrap' ) . '">Загрузить фото</a>';
				}
				echo '</td></tr>';
			}
			if ( 0 === $shown ) {
				echo '<tr><td colspan="5">Все ближайшие концерты уже с новой страницей.</td></tr>';
			}
			echo '</tbody></table>';
		}

		if ( $is_admin ) {
			self::settings_form( $s, $modes );
			$err = get_option( 'kep_last_error' );
			if ( is_array( $err ) && ! empty( $err['time'] ) && $err['time'] > time() - 7 * DAY_IN_SECONDS ) {
				echo '<p class="kep-admin-err">Последний сбой ' . esc_html( wp_date( 'j.m H:i', $err['time'] ) ) . ': ' . esc_html( $err['why'] );
				if ( ! empty( $err['post_id'] ) ) {
					echo ' (<a href="' . esc_url( get_permalink( (int) $err['post_id'] ) ) . '">концерт</a>)';
				}
				echo '. Посетители видели старую страницу.</p>';
			}
		}
		echo '</div>';
	}

	private static function settings_form( $s, $modes ) {
		$name = KEP_Settings::OPTION;
		echo '<h2 id="kep-settings">Настройки</h2><form method="post" action="options.php" class="kep-settings">';
		settings_fields( 'kep' );
		echo '<fieldset class="kep-modes"><legend>Кому показывать новые страницы</legend>';
		foreach ( $modes as $k => $m ) {
			echo '<label class="kep-mode"><input type="radio" name="' . esc_attr( $name ) . '[mode]" value="' . esc_attr( $k ) . '"' . checked( $s['mode'], $k, false ) . '> <span><strong>' . esc_html( $m[0] ) . '</strong><span>' . esc_html( $m[1] ) . '</span></span></label>';
		}
		echo '</fieldset>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::field( 'Подпись к цене билета', $name . '[price_note]', $s['price_note'], 'Под ценой у концертов с билетами Ticketscloud. Пусто: подписи нет.' );
		self::field( 'Телефон для брони', $name . '[phone]', $s['phone'], 'Для депозитных вечеров и вопросов по билетам.' );
		foreach ( KEP_Settings::stages() as $code => $stage ) {
			self::field( '3D-тур: ' . $stage, $name . '[tour][' . $code . ']', $s['tour'][ $code ], 'u' === $code ? 'Пусто: кнопки «Зал в 3D» нет.' : '', 'url' );
		}
		self::field( 'Адрес: Маросейка', $name . '[addr_main]', $s['addr_main'] );
		self::field( 'Метро: Маросейка', $name . '[metro_main]', $s['metro_main'] );
		self::field( 'Адрес: Мясницкая', $name . '[addr_u]', $s['addr_u'] );
		self::field( 'Метро: Мясницкая', $name . '[metro_u]', $s['metro_u'], 'Пусто: строки с метро нет.' );
		echo '</tbody></table>';
		submit_button( 'Сохранить настройки' );
		echo '</form>';
	}

	private static function field( $label, $name, $value, $help = '', $type = 'text' ) {
		$id = 'kep-f-' . md5( $name );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td><input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text">';
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/* ---------- файлы админки ---------- */

	public static function assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || KEP_POST_TYPE !== $screen->post_type ) {
			return;
		}
		wp_enqueue_style( 'kep-admin', KEP_URL . 'assets/kep-admin.css', array(), KEP_VERSION );
		if ( 'post.php' === $hook || 'post-new.php' === $hook ) {
			wp_enqueue_media();
			wp_enqueue_script( 'kep-admin', KEP_URL . 'assets/kep-admin.js', array(), KEP_VERSION, true );
		}
	}
}
