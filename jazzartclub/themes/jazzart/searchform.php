<?php $jac_search_id = wp_unique_id( 'search-' ); ?>
<form class="site-search" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
	<label class="visually-hidden" for="<?php echo esc_attr( $jac_search_id ); ?>">Поиск по сайту</label>
	<input type="search" id="<?php echo esc_attr( $jac_search_id ); ?>" name="s" value="<?php echo esc_attr( get_search_query() ); ?>" placeholder="Поиск по сайту">
	<button type="submit">Найти</button>
</form>
