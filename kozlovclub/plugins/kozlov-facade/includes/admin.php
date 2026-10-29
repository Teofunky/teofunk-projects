<?php
/**
 * Admin: «Живой фасад» menu with the mode switch and the slide list.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const KZF_CAP = 'edit_others_posts'; // editors and administrators

add_action( 'admin_menu', 'kzf_admin_menu' );
function kzf_admin_menu() {
	add_menu_page( 'Живой фасад', 'Живой фасад', KZF_CAP, 'kzf', 'kzf_admin_page', 'dashicons-building', 58 );
}

add_action( 'admin_enqueue_scripts', 'kzf_admin_assets' );
function kzf_admin_assets( $hook ) {
	if ( 'toplevel_page_kzf' !== $hook ) {
		return;
	}
	wp_enqueue_media();
	wp_enqueue_style( 'kzf-admin', KZF_URL . 'assets/admin.css', array(), KZF_VER );
	wp_enqueue_script( 'kzf-admin', KZF_URL . 'assets/admin.js', array(), KZF_VER, true );
}

add_action( 'admin_post_kzf_save', 'kzf_admin_save' );
function kzf_admin_save() {
	if ( ! current_user_can( KZF_CAP ) ) {
		wp_die( 'Недостаточно прав.' );
	}
	check_admin_referer( 'kzf_save' );

	$settings                = kzf_settings();
	$mode                    = isset( $_POST['kzf_mode'] ) ? sanitize_key( wp_unslash( $_POST['kzf_mode'] ) ) : 'off';
	$settings['mode']        = in_array( $mode, KZF_MODES, true ) ? $mode : 'off';
	$settings['street_sync'] = empty( $_POST['kzf_street_sync'] ) ? 0 : 1;
	update_option( 'kzf_settings', $settings, false );

	$slides = array();
	if ( isset( $_POST['kzf_slides'] ) && is_array( $_POST['kzf_slides'] ) ) {
		foreach ( $_POST['kzf_slides'] as $raw ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field
			$s = kzf_sanitize_slide( $raw, true );
			if ( 'event' === $s['type'] && ! $s['event_url'] ) {
				continue;
			}
			$slides[] = $s;
		}
	}
	update_option( 'kzf_slides', $slides, false );

	foreach ( $slides as $s ) { // fresh Джазета on the next view
		if ( 'gazeta' === $s['type'] ) {
			delete_transient( 'kzf_gazeta_' . md5( $s['link'] ? $s['link'] : home_url( '/gazeta/' ) ) );
		}
	}

	wp_safe_redirect( add_query_arg( array( 'page' => 'kzf', 'updated' => 1 ), admin_url( 'admin.php' ) ) );
	exit;
}

function kzf_field( $slide, $i, $key, $label, $type = 'text', $placeholder = '', $hint = '' ) {
	$name = 'kzf_slides[' . $i . '][' . $key . ']';
	$val  = isset( $slide[ $key ] ) ? $slide[ $key ] : '';
	echo '<label class="kzf-f"><span>' . esc_html( $label ) . '</span>';
	printf(
		'<input type="%s" name="%s" value="%s" placeholder="%s">',
		esc_attr( $type ),
		esc_attr( $name ),
		esc_attr( $val ),
		esc_attr( $placeholder )
	);
	if ( $hint ) {
		echo '<em>' . esc_html( $hint ) . '</em>';
	}
	echo '</label>';
}

function kzf_slide_row( $slide, $i ) {
	$s    = kzf_sanitize_slide( $slide );
	$type = $s['type'];
	$img  = $s['image_id'] ? wp_get_attachment_image_url( $s['image_id'], 'thumbnail' ) : '';
	?>
	<div class="kzf-row-admin" data-type="<?php echo esc_attr( $type ); ?>">
		<div class="kzf-row-head">
			<span class="kzf-num"></span>
			<select name="kzf_slides[<?php echo esc_attr( $i ); ?>][type]" class="kzf-type">
				<option value="event" <?php selected( $type, 'event' ); ?>>Мероприятие</option>
				<option value="image" <?php selected( $type, 'image' ); ?>>Картинка со ссылкой</option>
				<option value="gazeta" <?php selected( $type, 'gazeta' ); ?>>Джазета (свежий номер)</option>
			</select>
			<label class="kzf-on"><input type="checkbox" name="kzf_slides[<?php echo esc_attr( $i ); ?>][enabled]" value="1" <?php checked( $s['enabled'] ); ?>> Показывать</label>
			<span class="kzf-tools">
				<button type="button" class="button kzf-up" aria-label="Выше">↑</button>
				<button type="button" class="button kzf-down" aria-label="Ниже">↓</button>
				<button type="button" class="button-link-delete kzf-del">Удалить</button>
			</span>
		</div>
		<div class="kzf-fields">
			<div class="kzf-only" data-for="event">
				<?php kzf_field( $s, $i, 'event_url', 'Ссылка на мероприятие', 'url', home_url( '/event/...' ), 'Вставьте адрес страницы концерта. Название, фото, дата, сцена, цена и Ticketscloud подтянутся сами.' ); ?>
			</div>
			<div class="kzf-only" data-for="image">
				<div class="kzf-f kzf-media">
					<span>Картинка</span>
					<input type="hidden" class="kzf-image-id" name="kzf_slides[<?php echo esc_attr( $i ); ?>][image_id]" value="<?php echo esc_attr( $s['image_id'] ); ?>">
					<img class="kzf-thumb" src="<?php echo esc_url( $img ); ?>" alt="" <?php echo $img ? '' : 'hidden'; ?>>
					<button type="button" class="button kzf-pick">Выбрать картинку</button>
					<button type="button" class="button-link kzf-clear" <?php echo $img ? '' : 'hidden'; ?>>Убрать</button>
				</div>
			</div>
			<?php kzf_field( $s, $i, 'label', 'Подпись над карточкой', 'text', 'Концерт / Джазета · свежий номер / Фестиваль', 'Говорит, что это, а не насколько это важно.' ); ?>
			<?php kzf_field( $s, $i, 'title', 'Заголовок', 'text', '', 'У мероприятия и Джазеты можно оставить пустым: возьмётся само.' ); ?>
			<?php kzf_field( $s, $i, 'subtitle', 'Подзаголовок', 'text', '«Название программы»' ); ?>
			<div class="kzf-only" data-for="event image">
				<?php kzf_field( $s, $i, 'date_text', 'Дата и время', 'text', 'Сб, 31 октября · 20:00', 'У мероприятия пусто: возьмётся из афиши.' ); ?>
				<?php kzf_field( $s, $i, 'meta_text', 'Сцена и цена', 'text', 'Главная сцена · от 1 500 ₽', 'У мероприятия пусто: возьмётся из афиши.' ); ?>
			</div>
			<div class="kzf-only" data-for="event">
				<?php kzf_field( $s, $i, 'tc_id', 'Ticketscloud ID', 'text', '6aaaa54c1d67876205182ca2', 'Пусто: найдётся в полях мероприятия. Без него кнопка ведёт на страницу концерта.' ); ?>
			</div>
			<div class="kzf-only" data-for="image gazeta">
				<?php kzf_field( $s, $i, 'link', 'Ссылка', 'url', 'https://kozlovclub.ru/...', 'Для Джазеты пусто: /gazeta/.' ); ?>
				<?php kzf_field( $s, $i, 'button_text', 'Текст кнопки', 'text', 'Подробнее' ); ?>
			</div>
			<?php kzf_field( $s, $i, 'hide_after', 'Скрыть после', 'date', '', 'Мероприятие скрывается само после начала. Для остального задайте дату.' ); ?>
		</div>
		<?php
		if ( 'event' === $type && $s['event_url'] ) {
			$r = kzf_resolve( array_merge( $s, array( 'enabled' => 1 ) ) );
			echo '<div class="kzf-preview">';
			if ( $r ) {
				echo '<strong>Покажется так:</strong> ' . esc_html( implode( ' · ', array_filter( array( $r['when'], $r['title'], $r['subtitle'], $r['meta'] ) ) ) );
				echo $r['tc_id'] ? ' · <span class="kzf-ok">билеты через Ticketscloud</span>' : ' · <span class="kzf-warn">Ticketscloud ID не найден, кнопка ведёт на страницу концерта</span>';
			} else {
				echo '<span class="kzf-warn">Мероприятие не найдено или уже прошло: слайд не покажется.</span>';
			}
			echo '</div>';
		}
		?>
	</div>
	<?php
}

function kzf_admin_page() {
	if ( ! current_user_can( KZF_CAP ) ) {
		return;
	}
	$set    = kzf_settings();
	$slides = kzf_slides();
	?>
	<div class="wrap kzf-admin">
		<h1>Живой фасад</h1>
		<p class="kzf-lead">Анимированный фасад клуба с карточками вместо слайдера на главной. Только на телефонах (до 767 px), компьютерная версия не меняется. Под блоком остаётся всё, что есть на странице сейчас.</p>
		<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success is-dismissible"><p>Сохранено. Если на сайте включён кэш страниц, сбросьте его.</p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="kzf_save">
			<?php wp_nonce_field( 'kzf_save' ); ?>

			<h2>Кто видит</h2>
			<fieldset class="kzf-modes">
				<label><input type="radio" name="kzf_mode" value="off" <?php checked( $set['mode'], 'off' ); ?>> <strong>Выключено.</strong> Везде обычный слайдер.</label>
				<label><input type="radio" name="kzf_mode" value="editors" <?php checked( $set['mode'], 'editors' ); ?>> <strong>Только редакторы и администраторы</strong> под своим логином на телефоне. Посетители видят обычный слайдер.</label>
				<label><input type="radio" name="kzf_mode" value="all" <?php checked( $set['mode'], 'all' ); ?>> <strong>Все посетители с телефонов.</strong></label>
			</fieldset>
			<p class="kzf-hint">Быстро выключить для себя: добавьте <code>?kzf=off</code> к адресу главной. Если блок не смог загрузиться, автоматически показывается обычный слайдер.</p>
			<label class="kzf-check"><input type="checkbox" name="kzf_street_sync" value="1" <?php checked( $set['street_sync'] ); ?>> Саксофонист играет вместе с уличным, в :00 и :30 по Москве</label>

			<h2>Карточки под домом</h2>
			<p class="kzf-hint">Листаются пальцем, по порядку этого списка. Мероприятие: достаточно ссылки. Картинка со ссылкой: для всего остального (фестиваль, мастер-класс, новость).</p>
			<div class="kzf-list" id="kzf-list">
				<?php foreach ( $slides as $i => $s ) { kzf_slide_row( $s, $i ); } ?>
			</div>
			<p><button type="button" class="button" id="kzf-add">+ Добавить карточку</button></p>
			<template id="kzf-tpl"><?php kzf_slide_row( array( 'type' => 'event', 'enabled' => 1 ), '__i__' ); ?></template>

			<?php submit_button( 'Сохранить' ); ?>
		</form>
	</div>
	<?php
}
