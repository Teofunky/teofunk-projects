<?php
/** Колонка справа на внутренних страницах. */
?>
<aside class="side" aria-label="Афиша, проекты и контакты">
	<?php
	jac_next_concert();
	jac_projects();
	jac_venue();
	if ( is_active_sidebar( 'side' ) ) {
		dynamic_sidebar( 'side' );
	}
	?>
</aside>
