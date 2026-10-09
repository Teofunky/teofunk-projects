<?php
// Тестовая заглушка All-in-One Event Calendar: тип записи и таблица как в оригинале.
add_action( 'init', function () {
	register_post_type( 'ai1ec_event', array( 'public' => true, 'label' => 'События', 'rewrite' => array( 'slug' => 'event' ), 'supports' => array( 'title', 'editor', 'thumbnail', 'custom-fields' ) ) );
} );
