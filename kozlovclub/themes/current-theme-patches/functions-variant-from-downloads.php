<?php


function kozlov_styles() {


    wp_register_style('google', 'https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&amp;subset=cyrillic', array(), null, 'all');
    wp_enqueue_style('google');

    wp_register_style('google2', 'https://fonts.googleapis.com/css?family=Lora&amp;subset=cyrillic', array(), null, 'all');
    wp_enqueue_style('google2');

    wp_enqueue_style( 'kozlov-style', get_stylesheet_uri() );

}
add_action( 'wp_enqueue_scripts', 'kozlov_styles' );


/**
 * Enqueue scripts.
 */
function kozlov_scripts() {

    wp_deregister_script('jquery');
    wp_register_script('jquery', get_template_directory_uri() . '/js/libs/jquery-3.2.1.min.js', false, false, true);
    wp_enqueue_script('jquery');

    wp_enqueue_script( 'kozlov-formstyler', get_template_directory_uri() . '/js/libs/owl.carousel.min.js', array(), '20151215', true );
    wp_register_script( 'tcwidget', ( 'https://ticketscloud.com:443/static/scripts/widget/tcwidget.js' ), false, null, true );

    wp_enqueue_script( 'kozlov-scripts', get_template_directory_uri() . '/js/script.js', array(), '20151215', true );

    wp_enqueue_script( 'tcwidget' );

    if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
        wp_enqueue_script( 'comment-reply' );
    }
}
add_action( 'wp_enqueue_scripts', 'kozlov_scripts' );

function pre () {
	$args = func_get_args();
	echo "<pre class=\"gsf-debug\">";
	foreach ($args as $arg) {
		echo htmlspecialchars(print_r($arg, true));
        print_r("\n");
        // debug_log($arg);
	}
	echo "</pre>";
}

function debug_log () {
    $args = func_get_args();
    foreach ($args as $arg) {
        $res = date("d.m.Y H:i:s");
        $res .= ": ";
        $res .= print_r($arg, true);
        $res .= "\n";
        file_put_contents('debug.log', $res, FILE_APPEND);
    }
}

register_nav_menus(array(
  'main-menu' => __( 'main-menu' ),
));

add_theme_support( 'post-thumbnails' );

function _month ( $month, $lang, $short = true ) {
	if ( $short ) {
		$months = array (
			'ru' => array( 'Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек' ),
			'en' => array( 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ),
		);
	} else {
		$months = array (
			'ru' => array( 'Январе', 'Феврале', 'Марте', 'Апреле', 'Мае', 'Июне', 'Июле', 'Августе', 'Сентябре', 'Октябре', 'Ноябре', 'Декабре' ),
			'en' => array( 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December' ),
		);
	}

	return $months[$lang][$month - 1];
}

function _day ( $day, $lang ) {
	$days = array (
		'ru' => array ( 'Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', ),
		'en' => array ( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ),
	);
	return $days[$lang][$day];
}

/*this function controls the meta titles display*/
if ( ! function_exists( 'elegant_titles' ) ){
	function elegant_titles() {
		global $shortname;

		$sitename = get_bloginfo('name');
		$site_description = get_bloginfo('description');

		#if the title is being displayed on the homepage
		if (is_home() || is_front_page()) {
			if (get_option($shortname.'_seo_home_title') == 'on') echo get_option($shortname.'_seo_home_titletext');
			else {
				$seo_home_type = get_option( $shortname . '_seo_home_type' );
				$seo_home_separate = get_option($shortname.'_seo_home_separate');

				if ( $seo_home_type == 'BlogName | Blog description' ) echo $sitename . $seo_home_separate . $site_description;
				if ( $seo_home_type == 'Blog description | BlogName') echo $site_description. $seo_home_separate . $sitename;
				if ( $seo_home_type == 'BlogName only') echo $sitename;
			}
		}
		#if the title is being displayed on single posts/pages
		if (is_single() || is_page()) {
			global $wp_query;
			$postid = $wp_query->post->ID;
			$key = get_option($shortname.'_seo_single_field_title');
			$exists3 = get_post_meta($postid, ''.$key.'', true);
					if (get_option($shortname.'_seo_single_title') == 'on' && $exists3 !== '' ) echo $exists3;
					else {
						$seo_single_type = get_option($shortname.'_seo_single_type');
						$seo_single_separate = get_option($shortname.'_seo_single_separate');
						if ( $seo_single_type == 'BlogName | Post title' ) echo $sitename . $seo_single_separate . wp_title('',false,'');
						if ( $seo_single_type == 'Post title | BlogName' ) echo wp_title('',false,'') . $seo_single_separate . $sitename;
						if ( $seo_single_type == 'Post title only' ) echo wp_title('',false,'');
					}

		}
		#if the title is being displayed on index pages (categories/archives/search results)
		if (is_category() || is_archive() || is_search()) {
			$seo_index_type = get_option($shortname.'_seo_index_type');
			$seo_index_separate = get_option($shortname.'_seo_index_separate');
			if ( $seo_index_type == 'BlogName | Category name' ) echo $sitename . $seo_index_separate . wp_title('',false,'');
			if ( $seo_index_type == 'Category name | BlogName') echo wp_title('',false,'') . $seo_index_separate . $sitename;
			if ( $seo_index_type == 'Category name only') echo wp_title('',false,'');
		}
	}
}

/*this function controls the meta description display*/
if ( ! function_exists( 'elegant_description' ) ){
	function elegant_description() {
		global $shortname;

		#homepage descriptions
		if ( is_home() && get_option($shortname.'_seo_home_description') == 'on' ) echo '<meta name="description" content="' . esc_attr( get_option($shortname.'_seo_home_descriptiontext') ) .'" />';

		#single page descriptions
		global $wp_query;
		if ( isset($wp_query->post->ID) ) $postid = $wp_query->post->ID;
		$key2 = get_option($shortname.'_seo_single_field_description');
		if ( isset($postid) ) $exists = get_post_meta($postid, ''.$key2.'', true);
		if (get_option($shortname.'_seo_single_description') == 'on' && $exists !== '') {
			if (is_single() || is_page()) echo '<meta name="description" content="' . esc_attr( $exists ) . '" />';
		}

		#index descriptions
		//remove_filter('term_description','wpautop');
		$cat = get_query_var('cat');
		$exists2 = category_description($cat);

		$seo_index_description = get_option($shortname.'_seo_index_description');

		if ($exists2 !== '' && $seo_index_description == 'on') {
			if (is_category()) echo '<meta name="description" content="'. esc_attr( $exists2 ) .'" />';
		}
		if (is_archive() && $seo_index_description == 'on') echo '<meta name="description" content="Currently viewing archives from'. esc_attr( wp_title('',false,'') ) .'" />';
		if (is_search() && $seo_index_description == 'on') echo '<meta name="description" content="'. esc_attr( wp_title('',false,'') ) .'" />';
	}
}

/*this function controls the meta keywords display*/
if ( ! function_exists( 'elegant_keywords' ) ){
	function elegant_keywords() {
		global $shortname;

		#homepage keywords
		if (is_home() && get_option($shortname.'_seo_home_keywords') == 'on') echo '<meta name="keywords" content="'.esc_attr( get_option($shortname.'_seo_home_keywordstext') ).'" />';

		#single page keywords
		global $wp_query;
		if (isset($wp_query->post->ID)) $postid = $wp_query->post->ID;
		$key3 = get_option($shortname.'_seo_single_field_keywords');
		if (isset($postid)) $exists4 = get_post_meta($postid, ''.$key3.'', true);
		if (isset($exists4) && $exists4 !== '' && get_option($shortname.'_seo_single_keywords') == 'on') {
			if (is_single() || is_page()) echo '<meta name="keywords" content="' . esc_attr( $exists4 ) . '" />';
		}
	}
}

/*this function controls canonical urls*/
if ( ! function_exists( 'elegant_canonical' ) ){
	function elegant_canonical() {
		global $shortname;

		#homepage urls
		if (is_home() && get_option($shortname.'_seo_home_canonical') == 'on') echo '<link rel="canonical" href="'. esc_url( home_url() ).'" />';

		#single page urls
		global $wp_query;
		if (isset($wp_query->post->ID)) $postid = $wp_query->post->ID;
		if (get_option($shortname.'_seo_single_canonical') == 'on') {
			if (is_single() || is_page()) echo '<link rel="canonical" href="'.esc_url( get_permalink() ).'" />';
		}

		#index page urls
		if (get_option($shortname.'_seo_index_canonical') == 'on') {
			if (is_archive() || is_category() || is_search()) echo '<link rel="canonical" href="'. esc_url( get_permalink() ).'" />';
		}
	}
}



/**
* @link https://austin.passy.co/2013/all-in-one-event-calendar-wordpress-custom-permalink-slugs-archive/
*/
add_action( 'init', 'ai1ec_post_type_init', 99 );
function ai1ec_post_type_init () {

	if ( function_exists( 'ai1ec_initiate_constants' ) && defined( 'AI1EC_POST_TYPE' ) ) {

		global $wp_post_types, $ai1ec_settings, $ai1ec_app_helper;

		// unset the original post type created by the plugin
		unset( $wp_post_types[ AI1EC_POST_TYPE ] );

		$labels = array(
			'name'               	=> _x( 'Все события', 'События', AI1EC_PLUGIN_NAME ),
			'singular_name'      	=> _x( 'Событие', 'Событие', AI1EC_PLUGIN_NAME ),
			'add_new'            	=> __( 'Добавить', AI1EC_PLUGIN_NAME ),
			'add_new_item'       	=> __( 'Добавить новое событие', AI1EC_PLUGIN_NAME ),
			'edit_item'          	=> __( 'Редактировать событие', AI1EC_PLUGIN_NAME ),
			'new_item'           	=> __( 'Новое событие', AI1EC_PLUGIN_NAME ),
			'view_item'          	=> __( 'Просмотреть событие', AI1EC_PLUGIN_NAME ),
			'search_items'       	=> __( 'Поиск событий', AI1EC_PLUGIN_NAME ),
			'not_found'          	=> __( 'События отсутствуют', AI1EC_PLUGIN_NAME ),
			'not_found_in_trash' 	=> __( 'События отсутствуют в корзине', AI1EC_PLUGIN_NAME ),
			'parent_item_colon'  	=> __( 'Parent Session', AI1EC_PLUGIN_NAME ),
			'menu_name'          	=> __( 'События', AI1EC_PLUGIN_NAME ),
			// 'all_items'          	=> $ai1ec_app_helper->get_all_items_name(),
			'all_items'          	=> __( 'Все События', AI1EC_PLUGIN_NAME ),
		);

		$supports = array(
			'title',
			'editor',
			'comments',
			'custom-fields',
			'thumbnail'
		);

		$args = array(
			'labels'				=> $labels,
			'public' 				=> true,
			'publicly_queryable' 	=> true,
			'show_ui' 				=> true,
			'show_in_menu' 			=> true,
			'query_var' 			=> true,
			'rewrite' 				=> array(
				'slug' => 'event',
				'with_front' => false,
			),
			'capability_type'		=> array(
				'ai1ec_event',
				'ai1ec_events'
			),
			'capabilities'        	=> array(
				'read_post'             => 'read_ai1ec_event',
				'edit_post'             => 'edit_ai1ec_event',
				'edit_posts'            => 'edit_ai1ec_events',
				'edit_others_posts'     => 'edit_others_ai1ec_events',
				'edit_private_posts'    => 'edit_private_ai1ec_events',
				'edit_published_posts'  => 'edit_published_ai1ec_events',
				'delete_post'           => 'delete_ai1ec_event',
				'delete_posts'          => 'delete_ai1ec_events',
				'delete_others_posts'   => 'delete_others_ai1ec_events',
				'delete_published_posts'=> 'delete_published_ai1ec_events',
				'delete_private_posts'  => 'delete_private_ai1ec_events',
				'publish_posts'			=> 'publish_ai1ec_events',
				'read_private_posts'	=> 'read_private_ai1ec_events'
			),
			'has_archive' 			=> true,
			'hierarchical' 			=> true,
			'menu_position' 		=> 5,
			'supports'				=> $supports,
			'exclude_from_search' 	=> ( is_object( $ai1ec_settings ) && isset( $ai1ec_settings->exclude_from_search ) ) ? (bool) $ai1ec_settings->exclude_from_search : false,
		);

		register_post_type( AI1EC_POST_TYPE, $args );

	}
	// AI1EC_POST_TYPE // ai1ec_initiate_constants()
}

if ( function_exists( 'add_image_size' ) ) {
    add_image_size( 'category-thumb', 320, 9999 ); // 300 в ширину и без ограничения в высоту
    add_image_size( 'category-thumb2', 450, 9999 ); // 300 в ширину и без ограничения в высоту
    add_image_size( 'slider-thumb', 300, 400 ); // 300 в ширину и без ограничения в высоту
}
//remove_action('template_redirect', 'redirect_canonical');



add_filter( 'redirect_canonical', 'remove_redirect_guess_404_permalink' );
/**
 * Отключает «угадывание» адреса для 404: WordPress не будет перекидывать с несуществующего
 * адреса на похожий концерт. Для обычных адресов возвращает канонический URL как есть
 * (например, /event/slug -> /event/slug/). Раньше хук был, а функции не было: запрос падал
 * и страница без слеша на конце открывалась пустой.
 */
function remove_redirect_guess_404_permalink( $redirect_url ) {
	if ( is_404() ) {
		return false;
	}
	return $redirect_url;
}

add_action('init', function() {
    add_rewrite_rule('^(archive)/', 'index.php?pagename=$matches[1]&paged=$matches[2]', 'top');
});
add_filter( 'template_include', 'my_template' );
function my_template( $template ) {

    # шаблон для страниц для выводо таксономий "Календари"
    if ( is_tax( 'events_categories' ) ) {
        return get_stylesheet_directory() . '/taxonomy-events_categories.php';
    }

    return $template;
}


// Хук для главной страницы с сортировкой по жанрам
if (isset($_GET['genre'])) {
  add_filter('wpseo_title', function ($title) {
    $lang = WPGlobus::Config()->language;

    if ($lang == 'ru') {
      switch ($_GET['genre']) {
        case 'jazz':
          $genre_title = 'Джаз';
        break;
        case 'blues':
          $genre_title = 'Блюз';
        break;
        case 'funk':
          $genre_title = 'Фанк';
        break;
        case 'fusion':
          $genre_title = 'Фьюжн';
        break;
        case 'rock':
          $genre_title = 'Рок';
        break;
        case 'soul':
          $genre_title = 'Соул';
        break;
        case 'gospel':
          $genre_title = 'Госпел';
        break;
        case 'classics':
          $genre_title = 'Классика';
        break;
        case 'ethnics':
          $genre_title = 'Этника';
        break;
        case 'folk':
          $genre_title = 'Фолк';
        break;
        case 'pop':
          $genre_title = 'Поп';
        break;
        case 'dance':
          $genre_title = 'Танцы';
        break;
        case 'jam':
          $genre_title = 'Джем-сэшн';
        break;
        case 'latin':
          $genre_title = 'Латино';
        break;
        case 'hiphop':
          $genre_title = 'Хипхоп';
        break;
        case 'dance':
          $genre_title = 'Танцы';
        break;
        case 'manush':
          $genre_title = 'Мануш';
        break;
        case 'indi':
          $genre_title = 'Инди';
        break;
        case 'jazz-rock':
          $genre_title = 'Джаз-рок';
        break;
        case 'flamenko':
          $genre_title = 'Фламенко';
        break;
        case 'avangard':
          $genre_title = 'Авангард';
        break;
        case 'festival':
          $genre_title = 'Фестиваль';
        break;
        case 'master':
          $genre_title = 'Мастер-класс';
        break;
        case 'lecture':
          $genre_title = 'Лекция';
        break;
        case 'alexey kozlov':
          $genre_title = 'Алексей Козлов';
        break;
        case 'free':
          $genre_title = 'Вход свободный';
        break;
        default:
          $genre_title = '';
        break;
      }
      $title = $genre_title . ' концерт в Москве | ' . $title;
    } else if ($lang == 'en') {
      $genre_title = ucwords($_GET['genre']);
      $title = $genre_title . ' concerts in Moscow | ' . $title;
    }


    return $title;
  });
}

// Capabilities для Ресторана
function menu_post_type() {
    $args = array(
        'public'          => true,
        'label'           => __( 'Ресторан', 'textdomain' ),
        'menu_icon'       => 'dashicons-food',
        'show_in_rest'    => true,
        // 'taxonomies'      => array( 'category' ),
        'capability_type' => array('menu', 'menus'), //custom capability type
        'map_meta_cap'    => true, //map_meta_cap must be true
    );
    register_post_type( 'menu', $args );
}
add_action( 'init', 'menu_post_type' ); //action hook

// Capabilities для Баннеров ресторана
function menu_banner_post_type() {
    $args = array(
        'public'          => true,
        'label'           => __( 'Ресторан [Баннеры]', 'textdomain' ),
        'menu_icon'       => 'dashicons-format-gallery',
        'show_in_rest'    => true,
        // 'taxonomies'      => array( 'category' ),
        'capability_type' => array('banner', 'banners'), //custom capability type
        'map_meta_cap'    => true, //map_meta_cap must be true
    );
    register_post_type( 'banner', $args );
}
add_action( 'init', 'menu_banner_post_type' ); //action hook

// Отключить автосохранение
function disable_autosave() {
    wp_deregister_script( 'autosave' );
}
add_action( 'admin_init', 'disable_autosave' );




// Добавляем пункт меню
function custom_options_menu() {
  add_menu_page(
    'Настройки депозита', // Page title
    'Настройки депозита', // Menu title
    'edit_pages', // Capability (права доступа)
    'custom-options', // Menu slug
    'custom_options_page', // Callback function
    'dashicons-admin-generic', // Icon (можно изменить)
    6 // Position in menu
  );
}

add_action( 'admin_menu', 'custom_options_menu' );

// Функция, которая выводит страницу настроек
function custom_options_page() {
  ?>
  <div class="wrap">
      <h1>Настройки депозита</h1>
      <form method="post" action="options.php">
          <?php
          settings_fields( 'custom_options_group' );
          do_settings_sections( 'custom-options' );
          submit_button();
          ?>
      </form>
  </div>
  <?php
}

// Регистрируем настройки
function custom_register_settings() {
    register_setting( 'custom_options_group', 'custom_deposit_text', 'wp_kses_post' );
    register_setting( 'custom_options_group', 'custom_no_deposit_text', 'wp_kses_post' );

    add_settings_section(
        'custom_settings_section', // ID section
        '', // Title section
        '', // Callback section (пустая строка, если не нужно описание)
        'custom-options' // Page
    );

    add_settings_field(
        'custom_deposit_text', // ID field
        'Событие по брони и ТРЕБУЕТСЯ депозит:', // Title field
        'custom_deposit_text_callback', // Callback field
        'custom-options', // Page
        'custom_settings_section' // Section
    );

    add_settings_field(
        'custom_no_deposit_text', // ID field
        'Событие по брони и НЕ ТРЕБУЕТСЯ депозит:', // Title field
        'custom_no_deposit_text_callback', // Callback field
        'custom-options', // Page
        'custom_settings_section' // Section
    );
}
add_action( 'admin_init', 'custom_register_settings' );

// Callback для вывода поля ввода
function custom_deposit_text_callback() {

    $value = get_option( 'custom_deposit_text' );
    $editor_id = 'custom_deposit_text'; // Уникальный ID для редактора

    $settings = array(
        'wpautop'           => false,  // Включает автоматическую расстановку параграфов
        'media_buttons'     => false,  // Включает кнопки загрузки медиафайлов
        'textarea_name'     => 'custom_deposit_text', // Важно: Имя поля textarea
        'textarea_rows'     => 6,   // Количество строк
        'tabindex'          => '',
        'editor_css'        => '',
        'editor_class'      => '',
        'teeny'             => false,  // Упрощенный редактор
        'dfw'               => false,
        'tinymce'           => true,  // Включаем TinyMCE
        'quicktags'         => true   // Включаем Quicktags
    );

    wp_editor( $value, $editor_id, $settings );
}

function custom_no_deposit_text_callback() {

    $value = get_option( 'custom_no_deposit_text' );
    $editor_id = 'custom_no_deposit_text'; // Уникальный ID для редактора

    $settings = array(
        'wpautop'           => false,  // Включает автоматическую расстановку параграфов
        'media_buttons'     => false,  // Включает кнопки загрузки медиафайлов
        'textarea_name'     => 'custom_no_deposit_text', // Важно: Имя поля textarea
        'textarea_rows'     => 6,   // Количество строк
        'tabindex'          => '',
        'editor_css'        => '',
        'editor_class'      => '',
        'teeny'             => false,  // Упрощенный редактор
        'dfw'               => false,
        'tinymce'           => true,  // Включаем TinyMCE
        'quicktags'         => true   // Включаем Quicktags
    );

    wp_editor( $value, $editor_id, $settings );
}


// function rem_wp_ver_css_js ($src) {
//     if (strpos($src, 'ver=')) {
//       $src = remove_query_arg( 'ver', $src );
//     }
//     return $src;
// }
//
// add_filter('style_loader_src', 'rem_wp_ver_css_js', 9999 );
// add_filter('script_loader_src', 'rem_wp_ver_css_js', 9999 );
// Kozlov Chat Widget v2
function kozlov_chat_widget_script() {
    // Не показываем виджет на страницах концертов
    if ( is_singular('event') || strpos($_SERVER['REQUEST_URI'], '/event/') !== false ) {
        return;
    }

    wp_register_script('kozlov-chat-widget-v2', home_url('/kozlov-chat-widget-v2.js?ver=20260530-3'), array(), '20260530-3', true);
    wp_add_inline_script('kozlov-chat-widget-v2', 'window.process=window.process||{env:{NODE_ENV:"production"}};', 'before');
    wp_enqueue_script('kozlov-chat-widget-v2');
}
add_action('wp_enqueue_scripts', 'kozlov_chat_widget_script');