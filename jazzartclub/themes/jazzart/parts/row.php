<?php
/** Одна статья в списке. */
$jac_image = jac_row_image();
$jac_meta  = jac_meta();
$jac_text  = get_the_excerpt();
// Анонс, который лишь повторяет заголовок, не показываем.
if ( mb_strtolower( trim( $jac_text, " .…\n" ) ) === mb_strtolower( trim( get_the_title() ) ) ) {
	$jac_text = '';
}
?>
<li class="row<?php echo $jac_image ? '' : ' no-image'; ?>">
	<?php if ( $jac_image ) : ?>
		<a class="row-image" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true"><?php echo $jac_image; ?></a>
	<?php endif; ?>
	<div>
		<?php if ( $jac_meta ) : ?>
			<p class="meta"><?php echo $jac_meta; ?></p>
		<?php endif; ?>
		<h3 class="row-title"><a href="<?php the_permalink(); ?>"><?php echo get_the_title() ? get_the_title() : 'Без названия'; ?></a></h3>
		<?php if ( $jac_text ) : ?>
			<p class="row-excerpt"><?php echo esc_html( wp_trim_words( jac_soften_caps( $jac_text ), 22, '…' ) ); ?></p>
		<?php endif; ?>
	</div>
</li>
