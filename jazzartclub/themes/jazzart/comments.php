<?php
if ( post_password_required() ) {
	return;
}
?>
<section class="comments" id="comments">
	<?php if ( have_comments() ) : ?>
		<h2 class="section-title"><?php echo esc_html( jac_plural( get_comments_number(), 'отзыв', 'отзыва', 'отзывов' ) ); ?></h2>
		<ol><?php wp_list_comments( array( 'style' => 'ol', 'avatar_size' => 0 ) ); ?></ol>
		<?php the_comments_pagination( array( 'prev_text' => 'Раньше', 'next_text' => 'Позже' ) ); ?>
	<?php endif; ?>
	<?php
	comment_form( array(
		'title_reply'        => 'Написать отзыв',
		'label_submit'       => 'Отправить отзыв',
		'title_reply_before' => '<h2 class="section-title" id="reply-title">',
		'title_reply_after'  => '</h2>',
	) );
	?>
</section>
