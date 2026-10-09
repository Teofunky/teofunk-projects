<?php
global $wpdb; $t = $wpdb->prefix . 'ai1ec_events';
for ( $i = 0; $i < 320; $i++ ) {
	$id = wp_insert_post( array( 'post_type' => 'ai1ec_event', 'post_title' => 'НАГРУЗКА ' . $i, 'post_status' => 'publish', 'post_name' => 'bulk-' . $i ) );
	update_post_meta( $id, 'location_the_beat', '1' ); update_post_meta( $id, 'textblock_1_description', '<p>Текст ' . $i . '</p>' ); update_post_meta( $id, 'ticketscloud_link', 'x' ); set_post_thumbnail( $id, 4 );
	$st = ( new DateTime( '2026-10-15 20:00', new DateTimeZone( 'UTC' ) ) )->getTimestamp() + $i * 3600;
	$wpdb->insert( $t, array( 'post_id' => $id, 'start' => $st, 'end' => $st + 7200, 'timezone_name' => 'UTC', 'allday' => 0, 'instant_event' => 0, 'recurrence_rules' => '', 'cost' => '[:ru]от 1000 руб.[:en]from 1000' ) );
}
echo "bulk ok\n";
