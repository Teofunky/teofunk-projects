<?php
/** Карточка концерта. Данные приходят в $args['event']. */
$jac_e = $args['event'];
?>
<li class="card">
	<a class="card-link" href="<?php echo esc_url( jac_afisha_url( $jac_e ) ); ?>">
		<span class="card-photo">
			<?php if ( $jac_e['image_full'] ) : ?>
				<img src="<?php echo esc_url( jac_img( $jac_e['image_full'], 960 ) ); ?>" alt="" loading="lazy">
			<?php endif; ?>
			<?php echo jac_date_badge( $jac_e ); ?>
		</span>
		<span class="card-body">
			<span class="card-title"><?php echo esc_html( $jac_e['title'] ); ?></span>
			<?php if ( $jac_e['time'] ) : ?>
				<span class="card-meta">Начало в <?php echo esc_html( $jac_e['time'] ); ?></span>
			<?php endif; ?>
			<span class="card-cta more-link">Подробнее</span>
		</span>
	</a>
</li>
