<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KC_Meta {

	/** Поля концерта: ключ => [тип, подпись]. Тип: string|int|bool|array. */
	public static function fields() {
		return array(
			'kc_start'        => array( 'string', 'Начало (Y-m-d H:i:s, московское время)' ),
			'kc_end'          => array( 'string', 'Окончание' ),
			'kc_price_type'   => array( 'string', 'Тип цены' ),
			'kc_price'        => array( 'int', 'Цена от, руб.' ),
			'kc_price_text'   => array( 'string', 'Текст цены' ),
			'kc_tc_event'     => array( 'string', 'Код события Ticketscloud' ),
			'kc_program'      => array( 'string', 'Название программы' ),
			'kc_booking'      => array( 'bool', 'Бронь стола (виджет)' ),
			'kc_register_btn' => array( 'bool', 'Кнопка «Регистрация»' ),
			'kc_sold_out'     => array( 'bool', 'Билетов нет' ),
			'kc_cancelled'    => array( 'bool', 'Отменён' ),
			'kc_hero'         => array( 'int', 'Большое фото (ID вложения)' ),
			'kc_slider_show'  => array( 'bool', 'Показывать в слайдере' ),
			'kc_slider_title' => array( 'string', 'Заголовок в слайдере' ),
			'kc_slider_img'   => array( 'int', 'Фото для слайдера (ID)' ),
			'kc_videos'       => array( 'array', 'Видео' ),
			'kc_artists'      => array( 'array', 'Артисты с фото' ),
			'kc_text_1'       => array( 'string', 'Текстовый блок 1' ),
			'kc_text_2'       => array( 'string', 'Текстовый блок 2' ),
			'kc_additional'   => array( 'string', 'Дополнительная информация' ),
			'kc_quote'        => array( 'string', 'Цитата' ),
			'kc_quote_author' => array( 'string', 'Автор цитаты' ),
			'kc_partners'     => array( 'array', 'Партнёры' ),
			'kc_partners_title' => array( 'string', 'Заголовок партнёров' ),
			'kc_ext_links'    => array( 'array', 'Внешние ссылки на билеты' ),
			'kc_legacy_id'    => array( 'int', 'ID на старом сайте' ),
		);
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 7 );
		add_action( 'add_meta_boxes_' . KC_POST_TYPE, array( __CLASS__, 'boxes' ) );
		add_action( 'save_post_' . KC_POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function register() {
		foreach ( self::fields() as $key => $f ) {
			$args = array( 'object_subtype' => KC_POST_TYPE, 'single' => true, 'show_in_rest' => 'array' !== $f[0], 'auth_callback' => function () { return current_user_can( 'edit_posts' ); } );
			switch ( $f[0] ) {
				case 'int':
					$args['type'] = 'integer'; $args['sanitize_callback'] = 'absint'; $args['default'] = 0; break;
				case 'bool':
					$args['type'] = 'boolean'; $args['sanitize_callback'] = 'rest_sanitize_boolean'; $args['default'] = false; break;
				case 'array':
					$args['type'] = 'array'; $args['show_in_rest'] = false; $args['default'] = array(); break;
				default:
					$args['type'] = 'string'; $args['sanitize_callback'] = 'kc_sanitize_text_field_multiline'; $args['default'] = '';
			}
			register_meta( 'post', $key, $args );
		}
	}

	public static function assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || KC_POST_TYPE !== $screen->post_type ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_editor();
		wp_enqueue_style( 'kc-admin', KC_URL . 'assets/admin.css', array(), KC_VERSION );
		wp_enqueue_script( 'kc-admin', KC_URL . 'assets/admin.js', array( 'jquery', 'editor' ), KC_VERSION, true );
		wp_localize_script( 'kc-admin', 'KC_EDITOR', array( 'tinymce' => self::editor_settings( 'x' )['tinymce'], 'quicktags' => self::editor_settings( 'x' )['quicktags'] ) );
	}

	public static function boxes() {
		add_meta_box( 'kc_main', 'Концерт: дата, сцена, билеты', array( __CLASS__, 'box_main' ), KC_POST_TYPE, 'normal', 'high' );
		add_meta_box( 'kc_media', 'Фото, видео, артисты, блоки', array( __CLASS__, 'box_media' ), KC_POST_TYPE, 'normal', 'default' );
		add_meta_box( 'kc_slider', 'Слайдер на главной', array( __CLASS__, 'box_slider' ), KC_POST_TYPE, 'side', 'default' );
	}

	private static function v( $id, $key ) {
		return get_post_meta( $id, $key, true );
	}

	public static function box_main( $post ) {
		wp_nonce_field( 'kc_save', 'kc_nonce' );
		$id    = $post->ID;
		$start = self::v( $id, 'kc_start' );
		$date  = $start ? substr( $start, 0, 10 ) : '';
		$time  = $start ? substr( $start, 11, 5 ) : '20:00';
		$end   = self::v( $id, 'kc_end' );
		$endt  = $end ? substr( $end, 11, 5 ) : '';
		$stage = wp_get_post_terms( $id, KC_TAX_STAGE, array( 'fields' => 'ids' ) );
		$stage = ( $stage && ! is_wp_error( $stage ) ) ? (int) $stage[0] : 0;
		$stages = get_terms( array( 'taxonomy' => KC_TAX_STAGE, 'hide_empty' => false ) );
		$ptype = self::v( $id, 'kc_price_type' ) ?: 'ticket';
		?>
		<div class="kc-grid">
			<p><label>Дата<br><input type="date" name="kc_date" value="<?php echo esc_attr( $date ); ?>" required></label></p>
			<p><label>Начало<br><input type="time" name="kc_time" value="<?php echo esc_attr( $time ); ?>" required></label></p>
			<p><label>Окончание (необязательно)<br><input type="time" name="kc_end_time" value="<?php echo esc_attr( $endt ); ?>"></label></p>
			<p><label>Сцена<br><select name="kc_stage" required><option value="">— выберите —</option>
				<?php foreach ( $stages as $t ) : ?><option value="<?php echo (int) $t->term_id; ?>" <?php selected( $stage, $t->term_id ); ?>><?php echo esc_html( $t->name ); ?></option><?php endforeach; ?>
			</select></label></p>
		</div>
		<p class="kc-help">Концерт в полночь записывайте как 23:59 предыдущего дня, иначе он уйдёт на следующий день афиши. Время московское, без поясов.</p>
		<hr>
		<div class="kc-grid">
			<p><label>Тип<br><select name="kc_price_type" id="kc_price_type">
				<?php foreach ( kc_price_types() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $ptype, $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?>
			</select></label></p>
			<p><label>Цена от / депозит, руб.<br><input type="number" min="0" step="50" name="kc_price" value="<?php echo esc_attr( self::v( $id, 'kc_price' ) ); ?>"></label></p>
			<p><label>Текст цены (пусто = автоматически)<br><input type="text" name="kc_price_text" value="<?php echo esc_attr( self::v( $id, 'kc_price_text' ) ); ?>" placeholder="от 1 500 руб."></label></p>
			<p><label>Код события Ticketscloud<br><input type="text" name="kc_tc_event" value="<?php echo esc_attr( self::v( $id, 'kc_tc_event' ) ); ?>" placeholder="6a8f66e56c1f0bb99212fc17" class="code"></label></p>
		</div>
		<p><label>Название программы (отдельно от имени артиста)<br><input type="text" name="kc_program" value="<?php echo esc_attr( self::v( $id, 'kc_program' ) ); ?>" class="widefat" placeholder="«Песни странников»"></label></p>
		<p class="kc-flags">
			<label><input type="checkbox" name="kc_booking" value="1" <?php checked( self::v( $id, 'kc_booking' ) ); ?>> Бронь стола (виджет Remarked)</label>
			<label><input type="checkbox" name="kc_register_btn" value="1" <?php checked( self::v( $id, 'kc_register_btn' ) ); ?>> Кнопка «Регистрация»</label>
			<label><input type="checkbox" name="kc_sold_out" value="1" <?php checked( self::v( $id, 'kc_sold_out' ) ); ?>> Билетов нет</label>
			<label><input type="checkbox" name="kc_cancelled" value="1" <?php checked( self::v( $id, 'kc_cancelled' ) ); ?>> Отменён</label>
		</p>
		<?php if ( $l = (int) self::v( $id, 'kc_legacy_id' ) ) : ?><p class="kc-help">Перенесён со старого сайта, ID <?php echo $l; ?>.</p><?php endif; ?>
		<?php
	}

	/** Настройки визуального редактора для текстовых блоков: вкладки «Визуально» и «Текст», без кнопки «Добавить медиафайл». */
	public static function editor_settings( $name, $rows = 6 ) {
		return array(
			'textarea_name' => $name,
			'textarea_rows' => $rows,
			'media_buttons' => false,
			'teeny'         => false,
			'quicktags'     => array( 'buttons' => 'strong,em,link,ul,ol,li,close' ),
			'tinymce'       => array(
				'toolbar1'  => 'formatselect,bold,italic,link,unlink,bullist,numlist,blockquote,removeformat,undo,redo',
				'toolbar2'  => '',
				'block_formats' => 'Абзац=p;Заголовок 3=h3;Заголовок 4=h4',
				'wpautop'   => true,
			),
		);
	}

	private static function editor( $name, $value, $rows = 6, $id = '' ) {
		wp_editor( (string) $value, $id ?: sanitize_key( $name ), self::editor_settings( $name, $rows ) );
	}

	public static function box_media( $post ) {
		$id = $post->ID;
		$hero = (int) self::v( $id, 'kc_hero' );
		$videos = (array) self::v( $id, 'kc_videos' );
		$artists = (array) self::v( $id, 'kc_artists' );
		?>
		<p><strong>Анонсное фото</strong> ставится как «Изображение записи» справа (4:3, от 800×600). <strong>Большое фото</strong> для страницы концерта, по желанию:</p>
		<div class="kc-image" data-key="kc_hero">
			<input type="hidden" name="kc_hero" value="<?php echo $hero; ?>">
			<div class="kc-image-preview"><?php echo $hero ? wp_get_attachment_image( $hero, 'medium' ) : ''; ?></div>
			<button type="button" class="button kc-pick">Выбрать</button> <button type="button" class="button-link kc-clear">Убрать</button>
		</div>
		<p><label><strong>Видео</strong> (YouTube, RuTube, VK; по одной ссылке в строке)<br><textarea name="kc_videos" rows="3" class="widefat"><?php echo esc_textarea( implode( "\n", array_filter( array_map( 'strval', $videos ) ) ) ); ?></textarea></label></p>
		<p><strong>Артисты с фото</strong></p>
		<div class="kc-artists" id="kc_artists">
			<?php foreach ( $artists as $i => $a ) : self::artist_row( $i, $a ); endforeach; ?>
		</div>
		<p><button type="button" class="button" id="kc_add_artist">Добавить артиста</button></p>
		<template id="kc_artist_tpl"><?php self::artist_row( '__i__', array() ); ?></template>
		<p class="kc-label"><strong>Текстовый блок 1</strong> <span class="kc-help-inline">вкладки «Визуально» и «Текст» (HTML)</span></p>
		<?php self::editor( 'kc_text_1', self::v( $id, 'kc_text_1' ), 8 ); ?>
		<p class="kc-label"><strong>Текстовый блок 2</strong></p>
		<?php self::editor( 'kc_text_2', self::v( $id, 'kc_text_2' ), 8 ); ?>
		<p class="kc-label"><strong>Цитата</strong></p>
		<?php self::editor( 'kc_quote', self::v( $id, 'kc_quote' ), 3 ); ?>
		<p><label>Автор цитаты<br><input type="text" name="kc_quote_author" class="widefat" value="<?php echo esc_attr( self::v( $id, 'kc_quote_author' ) ); ?>"></label></p>
		<p class="kc-label"><strong>Дополнительная информация</strong> (условия, возраст, депозит)</p>
		<?php self::editor( 'kc_additional', self::v( $id, 'kc_additional' ), 4 ); ?>
		<?php
	}

	private static function artist_row( $i, $a ) {
		$img = isset( $a['image'] ) ? (int) $a['image'] : 0;
		?>
		<div class="kc-artist kc-image" data-key="kc_artists">
			<input type="hidden" name="kc_artists[<?php echo esc_attr( $i ); ?>][image]" value="<?php echo $img; ?>">
			<div class="kc-image-preview"><?php echo $img ? wp_get_attachment_image( $img, 'thumbnail' ) : ''; ?></div>
			<div class="kc-artist-text">
				<input type="text" name="kc_artists[<?php echo esc_attr( $i ); ?>][name]" class="widefat" placeholder="Имя" value="<?php echo esc_attr( $a['name'] ?? '' ); ?>">
				<?php if ( '__i__' === $i ) : ?>
					<textarea name="kc_artists[__i__][text]" id="kc_artist_text___i__" rows="5" class="widefat kc-artist-editor" placeholder="Описание"></textarea>
				<?php else : ?>
					<?php self::editor( 'kc_artists[' . $i . '][text]', $a['text'] ?? '', 5, 'kc_artist_text_' . (int) $i ); ?>
				<?php endif; ?>
				<button type="button" class="button kc-pick">Фото</button> <button type="button" class="button-link kc-clear">Убрать фото</button> <button type="button" class="button-link kc-remove">Удалить артиста</button>
			</div>
		</div>
		<?php
	}

	public static function box_slider( $post ) {
		$id  = $post->ID;
		$img = (int) self::v( $id, 'kc_slider_img' );
		?>
		<p><label><input type="checkbox" name="kc_slider_show" value="1" <?php checked( self::v( $id, 'kc_slider_show' ) ); ?>> Показывать на главной</label></p>
		<p><label>Заголовок (пусто = название концерта)<br><input type="text" name="kc_slider_title" class="widefat" value="<?php echo esc_attr( self::v( $id, 'kc_slider_title' ) ); ?>"></label></p>
		<p>Фото для слайдера (3:4, 300×400):</p>
		<div class="kc-image" data-key="kc_slider_img">
			<input type="hidden" name="kc_slider_img" value="<?php echo $img; ?>">
			<div class="kc-image-preview"><?php echo $img ? wp_get_attachment_image( $img, 'medium' ) : ''; ?></div>
			<button type="button" class="button kc-pick">Выбрать</button> <button type="button" class="button-link kc-clear">Убрать</button>
		</div>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['kc_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['kc_nonce'] ), 'kc_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$p = wp_unslash( $_POST );
		$date = isset( $p['kc_date'] ) ? preg_replace( '/[^0-9-]/', '', $p['kc_date'] ) : '';
		$time = isset( $p['kc_time'] ) ? preg_replace( '/[^0-9:]/', '', $p['kc_time'] ) : '';
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			update_post_meta( $post_id, 'kc_start', $date . ' ' . $time . ':00' );
			$endt = isset( $p['kc_end_time'] ) ? preg_replace( '/[^0-9:]/', '', $p['kc_end_time'] ) : '';
			if ( preg_match( '/^\d{2}:\d{2}$/', $endt ) ) {
				$end = $date . ' ' . $endt . ':00';
				if ( $endt < $time ) { // за полночь
					$end = gmdate( 'Y-m-d', strtotime( $date . ' +1 day' ) ) . ' ' . $endt . ':00';
				}
				update_post_meta( $post_id, 'kc_end', $end );
			} else {
				delete_post_meta( $post_id, 'kc_end' );
			}
		}
		if ( isset( $p['kc_stage'] ) ) {
			wp_set_post_terms( $post_id, $p['kc_stage'] ? array( (int) $p['kc_stage'] ) : array(), KC_TAX_STAGE, false );
		}
		$ptype = isset( $p['kc_price_type'] ) && isset( kc_price_types()[ $p['kc_price_type'] ] ) ? $p['kc_price_type'] : 'info';
		update_post_meta( $post_id, 'kc_price_type', $ptype );
		update_post_meta( $post_id, 'kc_price', absint( $p['kc_price'] ?? 0 ) );
		foreach ( array( 'kc_price_text', 'kc_tc_event', 'kc_program', 'kc_slider_title', 'kc_quote_author' ) as $k ) {
			update_post_meta( $post_id, $k, sanitize_text_field( $p[ $k ] ?? '' ) );
		}
		foreach ( array( 'kc_text_1', 'kc_text_2', 'kc_additional', 'kc_quote' ) as $k ) {
			update_post_meta( $post_id, $k, wp_kses_post( $p[ $k ] ?? '' ) );
		}
		foreach ( array( 'kc_booking', 'kc_register_btn', 'kc_sold_out', 'kc_cancelled', 'kc_slider_show' ) as $k ) {
			update_post_meta( $post_id, $k, empty( $p[ $k ] ) ? 0 : 1 );
		}
		foreach ( array( 'kc_hero', 'kc_slider_img' ) as $k ) {
			update_post_meta( $post_id, $k, absint( $p[ $k ] ?? 0 ) );
		}
		$videos = array();
		foreach ( preg_split( '/[\r\n]+/', (string) ( $p['kc_videos'] ?? '' ) ) as $line ) {
			$line = esc_url_raw( trim( $line ) );
			if ( $line ) {
				$videos[] = $line;
			}
		}
		update_post_meta( $post_id, 'kc_videos', $videos );
		$artists = array();
		foreach ( (array) ( $p['kc_artists'] ?? array() ) as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			$row = array( 'image' => absint( $a['image'] ?? 0 ), 'name' => sanitize_text_field( $a['name'] ?? '' ), 'text' => wp_kses_post( $a['text'] ?? '' ) );
			if ( $row['image'] || $row['name'] || $row['text'] ) {
				$artists[] = $row;
			}
		}
		update_post_meta( $post_id, 'kc_artists', $artists );
		// Жанр «Вход свободный» и тип цены согласованы
		if ( 'free' === $ptype ) {
			wp_set_post_terms( $post_id, array( 'free' ), KC_TAX_GENRE, true );
		}
	}
}

function kc_sanitize_text_field_multiline( $v ) {
	return is_string( $v ) ? wp_kses_post( $v ) : '';
}
