<?php
/**
 * Список концертов с заголовком: архивы сцен, жанров, афиша, архив прошедших.
 * Ожидает $kz_title, $kz_sub (необязательно), $kz_concerts, $kz_view.
 */
?>
<div class="content content_home">
	<div class="inner content_inner events_wrap view_<?php echo esc_attr( $kz_view ); ?>">
		<div class="wrap">
			<?php echo kz_viewtabs( $kz_view ); // phpcs:ignore ?>
			<h1 class="afisha-heading"><?php echo esc_html( $kz_title ); ?><?php if ( ! empty( $kz_sub ) ) : ?><small><?php echo wp_kses_post( $kz_sub ); ?></small><?php endif; ?></h1>
			<?php echo kz_afisha( $kz_concerts, $kz_view, $kz_divider ?? 'Концерты в ' ); // phpcs:ignore ?>
		</div>
	</div>
</div>
<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="go-to-home-mb"></a>
