<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KC_Admin {

	public static function init() {
		add_filter( 'manage_' . KC_POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . KC_POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'manage_edit-' . KC_POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'admin_query' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filters' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'settings' ) );
		add_action( KC_TAX_STAGE . '_edit_form_fields', array( __CLASS__, 'stage_color_field' ) );
		add_action( 'edited_' . KC_TAX_STAGE, array( __CLASS__, 'stage_color_save' ) );
		add_filter( 'post_updated_messages', array( __CLASS__, 'messages' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function columns( $cols ) {
		$new = array();
		foreach ( $cols as $k => $v ) {
			if ( 'title' === $k ) {
				$new['kc_date'] = 'Дата';
				$new['title'] = 'Концерт';
				$new['kc_price'] = 'Цена';
				$new['kc_tc'] = 'Ticketscloud';
				continue;
			}
			if ( in_array( $k, array( 'date', 'author' ), true ) ) {
				continue;
			}
			$new[ $k ] = $v;
		}
		$new['date'] = 'Опубликован';
		return $new;
	}

	public static function column( $col, $id ) {
		$c = kozlov_concert( $id );
		if ( ! $c ) {
			return;
		}
		switch ( $col ) {
			case 'kc_date':
				if ( $c['start'] ) {
					echo '<strong>' . esc_html( $c['day'] . ' ' . mb_strtolower( $c['month_short'] ) ) . '</strong> ' . esc_html( $c['time'] ) . '<br><span class="description">' . esc_html( $c['weekday'] ) . ( $c['is_past'] ? ', прошёл' : '' ) . '</span>';
				} else {
					echo '<span style="color:#d63638">нет даты</span>';
				}
				break;
			case 'kc_price':
				echo esc_html( $c['price']['text'] );
				if ( $c['sold_out'] ) echo '<br><span style="color:#d63638">билетов нет</span>';
				if ( $c['cancelled'] ) echo '<br><span style="color:#d63638">отменён</span>';
				break;
			case 'kc_tc':
				echo $c['tc_event'] ? '<code>' . esc_html( $c['tc_event'] ) . '</code>' : ( 'ticket' === $c['price']['type'] ? '<span style="color:#d63638">нет кода</span>' : '—' );
				break;
		}
	}

	public static function sortable( $cols ) {
		$cols['kc_date'] = 'kc_date';
		return $cols;
	}

	public static function admin_query( $q ) {
		if ( ! is_admin() || ! $q->is_main_query() || KC_POST_TYPE !== $q->get( 'post_type' ) ) {
			return;
		}
		$orderby = $q->get( 'orderby' );
		if ( ! $orderby || 'kc_date' === $orderby ) {
			$q->set( 'meta_key', 'kc_start' );
			$q->set( 'orderby', 'meta_value' );
			if ( ! $q->get( 'order' ) || ! $orderby ) {
				$q->set( 'order', 'ASC' );
			}
			// по умолчанию сначала ближайшие будущие
			if ( ! $orderby && empty( $_GET['kc_when'] ) ) {
				$q->set( 'meta_query', array( array( 'key' => 'kc_start', 'value' => wp_date( 'Y-m-d 00:00:00' ), 'compare' => '>=', 'type' => 'CHAR' ) ) );
			}
		}
		if ( ! empty( $_GET['kc_when'] ) ) {
			$w = sanitize_key( $_GET['kc_when'] );
			$today = wp_date( 'Y-m-d' );
			$mq = array();
			if ( 'past' === $w ) {
				$mq[] = array( 'key' => 'kc_start', 'value' => $today . ' 00:00:00', 'compare' => '<', 'type' => 'CHAR' );
				$q->set( 'order', 'DESC' );
			} elseif ( 'today' === $w ) {
				$mq[] = array( 'key' => 'kc_start', 'value' => array( $today . ' 00:00:00', $today . ' 23:59:59' ), 'compare' => 'BETWEEN', 'type' => 'CHAR' );
			} elseif ( preg_match( '/^\d{4}-\d{2}$/', $w ) ) {
				$mq[] = array( 'key' => 'kc_start', 'value' => array( $w . '-01 00:00:00', $w . '-31 23:59:59' ), 'compare' => 'BETWEEN', 'type' => 'CHAR' );
			} elseif ( 'nodate' === $w ) {
				$mq[] = array( 'key' => 'kc_start', 'compare' => 'NOT EXISTS' );
			}
			if ( $mq ) {
				$q->set( 'meta_query', $mq );
			}
		}
		if ( ! empty( $_GET['kc_stage'] ) ) {
			$q->set( 'tax_query', array( array( 'taxonomy' => KC_TAX_STAGE, 'field' => 'slug', 'terms' => sanitize_title( $_GET['kc_stage'] ) ) ) );
		}
	}

	public static function filters( $type ) {
		if ( KC_POST_TYPE !== $type ) {
			return;
		}
		$w = isset( $_GET['kc_when'] ) ? sanitize_key( $_GET['kc_when'] ) : '';
		$opts = array( '' => 'Будущие', 'today' => 'Сегодня', 'past' => 'Прошедшие', 'nodate' => 'Без даты' );
		for ( $i = -1; $i < 4; $i++ ) {
			$m = wp_date( 'Y-m', strtotime( "first day of $i month" ) );
			$opts[ $m ] = wp_date( 'F Y', strtotime( $m . '-01' ) );
		}
		echo '<select name="kc_when">';
		foreach ( $opts as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $w, $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select>';
		$s = isset( $_GET['kc_stage'] ) ? sanitize_title( $_GET['kc_stage'] ) : '';
		echo '<select name="kc_stage"><option value="">Все сцены</option>';
		foreach ( get_terms( array( 'taxonomy' => KC_TAX_STAGE, 'hide_empty' => false ) ) as $t ) {
			echo '<option value="' . esc_attr( $t->slug ) . '" ' . selected( $s, $t->slug, false ) . '>' . esc_html( $t->name ) . '</option>';
		}
		echo '</select>';
	}

	public static function menu() {
		add_options_page( 'Kozlov Core', 'Kozlov Core', 'manage_options', 'kozlov-core', array( __CLASS__, 'settings_page' ) );
	}

	public static function settings() {
		register_setting( 'kozlov_core', 'kc_settings', array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ), 'default' => array() ) );
	}

	public static function sanitize_settings( $v ) {
		$v = is_array( $v ) ? $v : array();
		return array(
			'tc_token'         => sanitize_text_field( $v['tc_token'] ?? '' ),
			'phone'            => sanitize_text_field( $v['phone'] ?? '' ),
			'address'          => sanitize_text_field( $v['address'] ?? '' ),
			'deposit_text'     => wp_kses_post( $v['deposit_text'] ?? '' ),
			'no_deposit_text'  => wp_kses_post( $v['no_deposit_text'] ?? '' ),
		);
	}

	public static function get( $key, $default = '' ) {
		$s = get_option( 'kc_settings', array() );
		return isset( $s[ $key ] ) && '' !== $s[ $key ] ? $s[ $key ] : $default;
	}

	public static function settings_page() {
		$s = wp_parse_args( get_option( 'kc_settings', array() ), array( 'tc_token' => '', 'phone' => '+7 (495) 296-12-00', 'address' => 'ул. Маросейка, дом 9/2', 'deposit_text' => '', 'no_deposit_text' => '' ) );
		$n = wp_count_posts( KC_POST_TYPE );
		?>
		<div class="wrap"><h1>Kozlov Core</h1>
		<p>Концертов: опубликовано <?php echo (int) $n->publish; ?>, черновиков <?php echo (int) $n->draft; ?>. REST: <code><?php echo esc_html( rest_url( 'kozlov/v1/concerts' ) ); ?></code></p>
		<form method="post" action="options.php"><?php settings_fields( 'kozlov_core' ); ?>
		<table class="form-table">
			<tr><th>Токен организатора Ticketscloud</th><td><input type="text" name="kc_settings[tc_token]" class="regular-text code" value="<?php echo esc_attr( $s['tc_token'] ); ?>"><p class="description">Общий для всех кнопок «Купить билет». Код конкретного события задаётся в каждом концерте.</p></td></tr>
			<tr><th>Телефон</th><td><input type="text" name="kc_settings[phone]" class="regular-text" value="<?php echo esc_attr( $s['phone'] ); ?>"></td></tr>
			<tr><th>Адрес</th><td><input type="text" name="kc_settings[address]" class="regular-text" value="<?php echo esc_attr( $s['address'] ); ?>"></td></tr>
			<tr><th>Текст для вечеров с депозитом</th><td><?php wp_editor( $s['deposit_text'], 'kc_deposit_text', array( 'textarea_name' => 'kc_settings[deposit_text]', 'textarea_rows' => 5, 'media_buttons' => false ) ); ?></td></tr>
			<tr><th>Текст для вечеров без депозита</th><td><?php wp_editor( $s['no_deposit_text'], 'kc_no_deposit_text', array( 'textarea_name' => 'kc_settings[no_deposit_text]', 'textarea_rows' => 5, 'media_buttons' => false ) ); ?></td></tr>
		</table><?php submit_button(); ?></form></div>
		<?php
	}

	public static function stage_color_field( $term ) {
		$c = get_term_meta( $term->term_id, 'kc_color', true ) ?: '#f24841';
		echo '<tr class="form-field"><th><label for="kc_color">Цвет точки</label></th><td><input type="color" name="kc_color" id="kc_color" value="' . esc_attr( $c ) . '"><p class="description">Точка перед названием сцены в карточке.</p></td></tr>';
	}

	public static function stage_color_save( $term_id ) {
		if ( isset( $_POST['kc_color'] ) && current_user_can( 'manage_categories' ) ) {
			update_term_meta( $term_id, 'kc_color', sanitize_hex_color( wp_unslash( $_POST['kc_color'] ) ) );
		}
	}

	public static function messages( $m ) {
		$m[ KC_POST_TYPE ] = array_fill( 0, 11, 'Концерт сохранён.' );
		$m[ KC_POST_TYPE ][6] = 'Концерт опубликован. <a href="' . esc_url( get_permalink() ) . '">Открыть</a>';
		$m[ KC_POST_TYPE ][1] = 'Концерт обновлён. <a href="' . esc_url( get_permalink() ) . '">Открыть</a>';
		return $m;
	}

	public static function notices() {
		$s = get_current_screen();
		if ( $s && 'post' === $s->base && KC_POST_TYPE === $s->post_type && isset( $_GET['post'] ) ) {
			$id = (int) $_GET['post'];
			if ( 'publish' === get_post_status( $id ) && ! get_post_meta( $id, 'kc_start', true ) ) {
				echo '<div class="notice notice-error"><p>У концерта нет даты: он не попадёт в афишу.</p></div>';
			}
		}
	}
}
