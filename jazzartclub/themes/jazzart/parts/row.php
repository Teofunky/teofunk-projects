<?php
/** Одна статья в списке. */
$jac_image = jac_row_image();
$jac_meta  = jac_meta();
$jac_text  = get_the_excerpt();
?>
<li class="row<?php echo $jac_image ? ' has-image' : ''; ?>">
	<div>
		<?php if ( $jac_meta ) : ?>
			<p class="meta"><?php echo $jac_meta; ?></p>
		<?php endif; ?>
		<h3 class="row-title"><a href="<?php the_permalink(); ?>"><?php echo get_the_title() ? get_the_title() : 'Без названия'; ?></a></h3>
		<?php if ( $jac_text ) : ?>
			<p class="row-excerpt"><?php echo esc_html( $jac_text ); ?></p>
		<?php endif; ?>
	</div>
	<?php if ( $jac_image ) : ?>
		<a class="row-image" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true"><?php echo $jac_image; ?></a>
	<?php endif; ?>
</li>
