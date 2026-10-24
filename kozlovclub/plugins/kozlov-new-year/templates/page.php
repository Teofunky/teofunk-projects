<?php
/**
 * Точка входа шаблона страницы. Если вывод упал, показывается обычный шаблон темы.
 *
 * @package Kozlov_New_Year
 */

defined( 'ABSPATH' ) || exit;

$kny_page_id = (int) get_queried_object_id();
$kny_post    = get_post( $kny_page_id );
$kny_public  = $kny_post && 'publish' === $kny_post->post_status && empty( $kny_post->post_password );

// Страница под паролем: пусть тема покажет свою форму ввода пароля.
if ( $kny_post && post_password_required( $kny_post ) ) {
	include $GLOBALS['kny_fallback_template'];
	return;
}

$kny_ok = kny_safe_render(
	kny_get_data(),
	array(
		'url'     => get_permalink( $kny_page_id ),
		'noindex' => ! $kny_public || is_preview(),
		'page_id' => $kny_page_id,
	)
);

if ( ! $kny_ok && ! empty( $GLOBALS['kny_fallback_template'] ) && file_exists( $GLOBALS['kny_fallback_template'] ) ) {
	include $GLOBALS['kny_fallback_template'];
}
