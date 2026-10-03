<?php
/**
 * Тема «Джаз Арт Клуб».
 * Крупный шрифт, высокий контраст, простое меню.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'JAC_VERSION', '1.0.0' );

if ( ! isset( $content_width ) ) {
	$content_width = 780;
}

/* ------------------------------------------------------------
 * Настройка темы
 * ------------------------------------------------------------ */
add_action( 'after_setup_theme', function () {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'custom-logo', array( 'height' => 180, 'width' => 600, 'flex-width' => true, 'flex-height' => true ) );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ) );
	add_theme_support( 'editor-styles' );
	add_editor_style( 'assets/editor.css' );

	add_image_size( 'jac-row', 440, 294, true );
	add_image_size( 'jac-wide', 1560, 0, false );

	register_nav_menus( array(
		'primary' => 'Главное меню (синяя полоса вверху)',
		'projects' => 'Проекты клуба (кнопки в боковой колонке)',
		'footer'   => 'Меню в подвале',
	) );
} );

add_action( 'widgets_init', function () {
	register_sidebar( array(
		'name'          => 'Боковая колонка (под рубриками)',
		'id'            => 'side',
		'before_widget' => '<section class="widget %2$s">',
		'after_widget'  => '</section>',
		'before_title'  => '<h2 class="side-title">',
		'after_title'   => '</h2>',
	) );
} );

// При включении темы боковая колонка пустая: стандартные виджеты WordPress
// («Свежие записи», «Архивы» и т.п.) только отвлекают. Нужные можно добавить вручную.
add_action( 'after_switch_theme', function () {
	$widgets = get_option( 'sidebars_widgets', array() );
	if ( ! empty( $widgets['side'] ) ) {
		$widgets['wp_inactive_widgets'] = array_merge( (array) ( $widgets['wp_inactive_widgets'] ?? array() ), (array) $widgets['side'] );
		$widgets['side']                = array();
		update_option( 'sidebars_widgets', $widgets );
	}
}, 20 );

/* ------------------------------------------------------------
 * Стили и скрипты
 * ------------------------------------------------------------ */
add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'jazzart', get_stylesheet_uri(), array(), JAC_VERSION );
	wp_enqueue_script( 'jazzart', get_template_directory_uri() . '/assets/site.js', array(), JAC_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
} );

// Размер текста применяется до отрисовки страницы, чтобы она не «прыгала».
add_action( 'wp_head', function () {
	$fonts = get_template_directory_uri() . '/assets/fonts/';
	echo '<link rel="preload" href="' . esc_url( $fonts . 'pt-serif-cyrillic-400-normal.woff2' ) . '" as="font" type="font/woff2" crossorigin>' . "\n";
	echo '<link rel="preload" href="' . esc_url( $fonts . 'pt-serif-cyrillic-700-normal.woff2' ) . '" as="font" type="font/woff2" crossorigin>' . "\n";
	echo '<link rel="preload" href="' . esc_url( $fonts . 'old-standard-tt-cyrillic-700-normal.woff2' ) . '" as="font" type="font/woff2" crossorigin>' . "\n";
	echo "<script>(function(d){d.classList.add('js');try{var s=localStorage.getItem('jac-size');if(s==='l'||s==='xl'){d.setAttribute('data-size',s);}}catch(e){}})(document.documentElement);</script>\n";
}, 1 );

// Значок сайта во вкладке браузера: логотип клуба, пока в «Свойства сайта» не загружен другой.
add_action( 'wp_head', function () {
	if ( has_site_icon() ) {
		return;
	}
	$dir = get_template_directory_uri() . '/assets/';
	echo '<link rel="icon" href="' . esc_url( $dir . 'icon-32.png' ) . '" sizes="32x32">' . "\n";
	echo '<link rel="icon" href="' . esc_url( $dir . 'icon-512.png' ) . '" sizes="512x512">' . "\n";
	echo '<link rel="apple-touch-icon" href="' . esc_url( $dir . 'icon-180.png' ) . '">' . "\n";
	echo '<meta name="theme-color" content="#5b050e">' . "\n";
} );

// Смайлики-картинки сайту не нужны, убираем лишний скрипт.
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );

/* ------------------------------------------------------------
 * Вспомогательные функции
 * ------------------------------------------------------------ */

/** Логотип: загруженный в «Свойства сайта» или встроенный в тему (кремовый, для бордовой шапки). */
function jac_logo_url() {
	$custom = get_theme_mod( 'custom_logo' );
	if ( $custom ) {
		$src = wp_get_attachment_image_url( $custom, 'medium' );
		if ( $src ) {
			return $src;
		}
	}
	return get_template_directory_uri() . '/assets/logo.svg';
}

/** Страница, которая показывается на главной (по умолчанию «Анонсы»). */
function jac_home_page() {
	$id   = (int) get_theme_mod( 'jac_home_page', 0 );
	$page = $id ? get_post( $id ) : null;
	return ( $page && 'page' === $page->post_type && 'publish' === $page->post_status ) ? $page : null;
}

/** Дата по-русски: «3 октября 2026». Не зависит от языка установки WordPress. */
function jac_date( $post = null ) {
	$months = array( 1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря' );
	$ts     = get_post_timestamp( $post );
	if ( ! $ts ) {
		return '';
	}
	return wp_date( 'j', $ts ) . ' ' . $months[ (int) wp_date( 'n', $ts ) ] . ' ' . wp_date( 'Y', $ts );
}

/** Склонение: jac_plural( 5, 'статья', 'статьи', 'статей' ) вернёт «5 статей». */
function jac_plural( $n, $one, $few, $many ) {
	$n    = absint( $n );
	$mod  = $n % 100;
	$last = $n % 10;
	if ( $mod >= 11 && $mod <= 14 ) {
		$word = $many;
	} elseif ( 1 === $last ) {
		$word = $one;
	} elseif ( $last >= 2 && $last <= 4 ) {
		$word = $few;
	} else {
		$word = $many;
	}
	return number_format_i18n( $n ) . ' ' . $word;
}

/** Основная рубрика статьи (первая по порядку). */
function jac_rubric( $post = null ) {
	$cats = get_the_category( get_post( $post )->ID ?? 0 );
	return $cats ? $cats[0] : null;
}

/** Строка над заголовком: рубрика и, если включено в настройках, дата. */
function jac_meta( $post = null ) {
	$rubric = jac_rubric( $post );
	$out    = '';
	if ( get_theme_mod( 'jac_show_dates', false ) ) {
		$out .= '<time datetime="' . esc_attr( get_the_date( 'c', $post ) ) . '">' . esc_html( jac_date( $post ) ) . '</time>';
	}
	// На странице самой рубрики повторять её название у каждой статьи незачем.
	if ( $rubric && ! is_category( $rubric->term_id ) ) {
		$out .= '<span>Рубрика: <a href="' . esc_url( get_category_link( $rubric ) ) . '">' . esc_html( $rubric->name ) . '</a></span>';
	}
	return $out;
}

/**
 * Картинки и файлы из статей лежат в wp-content/uploads. Пока эта папка не скопирована
 * на новый сервер, показываем файлы прямо со старого сайта. Как только файл появляется
 * на месте, берётся он. Адрес старого сайта: «Внешний вид → Настроить → Перенос».
 */
function jac_old_site() {
	$old = untrailingslashit( (string) get_theme_mod( 'jac_old_site', '' ) );
	$host = function ( $url ) {
		return preg_replace( '~^www\.~i', '', strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
	};
	// Как только сайт открыт на адресе старого сайта (с www или без), перенос считается завершённым.
	if ( ! $old || $host( $old ) === $host( home_url() ) ) {
		return '';
	}
	return $old;
}

function jac_upload_url( $path ) {
	if ( 0 !== strpos( $path, '/wp-content/uploads/' ) ) {
		return $path;
	}
	$old = jac_old_site();
	if ( ! $old ) {
		return $path;
	}
	$file = WP_CONTENT_DIR . substr( rawurldecode( strtok( $path, '?#' ) ), strlen( '/wp-content' ) );
	return file_exists( $file ) ? $path : $old . $path;
}

add_filter( 'the_content', function ( $html ) {
	if ( false === strpos( $html, '/wp-content/uploads/' ) || ! jac_old_site() ) {
		return $html;
	}
	return preg_replace_callback( '~(\b(?:src|href)=["\'])(/wp-content/uploads/[^"\']+)~i', function ( $m ) {
		return $m[1] . jac_upload_url( $m[2] );
	}, $html );
}, 20 );

// Пробная копия на другом адресе не должна попадать в поисковики вместо настоящего сайта.
add_filter( 'wp_robots', function ( $robots ) {
	if ( jac_old_site() ) {
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
		unset( $robots['max-image-preview'] );
	}
	return $robots;
} );

/** Картинка для списка статей: «изображение записи», а если его нет, первая картинка из текста. */
function jac_row_image( $post = null ) {
	$post = get_post( $post );
	if ( has_post_thumbnail( $post ) ) {
		return get_the_post_thumbnail( $post, 'jac-row', array( 'alt' => '', 'loading' => 'lazy' ) );
	}
	if ( preg_match( '~<img\b[^>]*\bsrc=["\']([^"\']+)["\']~i', $post->post_content, $m ) ) {
		return '<img src="' . esc_url( jac_upload_url( $m[1] ) ) . '" alt="" loading="lazy">';
	}
	return '';
}

/** «Вы здесь»: путь от главной до текущей страницы. */
function jac_crumbs() {
	if ( is_front_page() ) {
		return;
	}
	$items   = array( '<a href="' . esc_url( home_url( '/' ) ) . '">Главная</a>' );
	$current = '';

	if ( is_singular( 'post' ) ) {
		$rubric = jac_rubric();
		if ( $rubric ) {
			foreach ( array_reverse( get_ancestors( $rubric->term_id, 'category' ) ) as $id ) {
				$items[] = '<a href="' . esc_url( get_category_link( $id ) ) . '">' . esc_html( get_cat_name( $id ) ) . '</a>';
			}
			$items[] = '<a href="' . esc_url( get_category_link( $rubric ) ) . '">' . esc_html( $rubric->name ) . '</a>';
		}
	} elseif ( is_page() ) {
		foreach ( array_reverse( get_post_ancestors( get_the_ID() ) ) as $id ) {
			$items[] = '<a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a>';
		}
		$current = get_the_title();
	} elseif ( is_category() ) {
		$term = get_queried_object();
		foreach ( array_reverse( get_ancestors( $term->term_id, 'category' ) ) as $id ) {
			$items[] = '<a href="' . esc_url( get_category_link( $id ) ) . '">' . esc_html( get_cat_name( $id ) ) . '</a>';
		}
		$current = $term->name;
	} elseif ( is_search() ) {
		$current = 'Поиск';
	} elseif ( is_404() ) {
		$current = 'Страница не найдена';
	} elseif ( is_archive() ) {
		$current = wp_strip_all_tags( get_the_archive_title() );
	} elseif ( is_home() ) {
		$current = 'Все статьи';
	}

	if ( $current ) {
		$items[] = '<span aria-current="page">' . esc_html( $current ) . '</span>';
	}
	echo '<nav class="crumbs" aria-label="Вы здесь">' . implode( '<span class="sep" aria-hidden="true">›</span>', $items ) . '</nav>';
}

/** Кнопки «Проекты клуба»: главные разделы, как на старом сайте. */
function jac_projects() {
	if ( ! has_nav_menu( 'projects' ) ) {
		return;
	}
	echo '<nav class="projects" aria-labelledby="projects-title"><h2 class="side-title" id="projects-title">Проекты клуба</h2>';
	wp_nav_menu( array( 'theme_location' => 'projects', 'container' => false, 'menu_class' => 'projects-list', 'depth' => 1, 'fallback_cb' => false ) );
	echo '</nav>';
}

/** Карточка «Где проходят концерты». */
function jac_venue() {
	$address = get_theme_mod( 'jac_address' );
	$phone   = get_theme_mod( 'jac_phone' );
	if ( ! $address && ! $phone ) {
		return;
	}
	echo '<section class="venue" aria-labelledby="venue-title"><h2 class="side-title" id="venue-title">Где проходят концерты</h2>';
	if ( $address ) {
		echo '<p>' . esc_html( $address ) . '</p>';
	}
	if ( $phone ) {
		echo '<p>Телефон: <a href="tel:' . esc_attr( preg_replace( '~[^\d+]~', '', $phone ) ) . '">' . esc_html( $phone ) . '</a></p>';
	}
	$contacts = get_page_by_path( 'contacts' );
	if ( $contacts && 'publish' === $contacts->post_status && ! is_page( $contacts->ID ) ) {
		echo '<p><a class="button is-quiet" href="' . esc_url( get_permalink( $contacts ) ) . '">Все контакты</a></p>';
	}
	echo '</section>';
}

/** Список рубрик для боковой колонки. */
function jac_rubrics() {
	$current = 0;
	if ( is_category() ) {
		$current = get_queried_object_id();
	} elseif ( is_singular( 'post' ) && jac_rubric() ) {
		$current = jac_rubric()->term_id;
	}
	$terms = get_categories( array( 'hide_empty' => true, 'parent' => 0, 'orderby' => 'name' ) );
	if ( ! $terms ) {
		return;
	}
	echo '<nav aria-labelledby="rubrics-title"><h2 class="side-title" id="rubrics-title">Рубрики</h2><ul>';
	foreach ( $terms as $term ) {
		jac_rubric_item( $term, $current );
	}
	echo '</ul></nav>';
}

function jac_rubric_item( $term, $current ) {
	$class = ( $term->term_id === $current ) ? ' class="is-current"' : '';
	$aria  = ( $term->term_id === $current && is_category() ) ? ' aria-current="page"' : '';
	echo '<li' . $class . '><a href="' . esc_url( get_category_link( $term ) ) . '"' . $aria . '><span>' . esc_html( $term->name ) . '</span><span class="count">' . (int) $term->count . '</span></a>';
	$children = get_categories( array( 'hide_empty' => true, 'parent' => $term->term_id, 'orderby' => 'name' ) );
	if ( $children ) {
		echo '<ul class="children">';
		foreach ( $children as $child ) {
			jac_rubric_item( $child, $current );
		}
		echo '</ul>';
	}
	echo '</li>';
}

/** Постраничная навигация с понятными подписями. */
function jac_pagination() {
	the_posts_pagination( array(
		'mid_size'           => 1,
		'prev_text'          => 'Предыдущая страница',
		'next_text'          => 'Следующая страница',
		'screen_reader_text' => 'Страницы',
		'aria_label'         => 'Страницы',
		'before_page_number' => '<span class="visually-hidden">Страница </span>',
	) );
}

/** Меню, если его ещё не собрали в админке: «Главная» и страницы верхнего уровня. */
function jac_menu_fallback( $args ) {
	$class = is_front_page() ? ' class="current-menu-item"' : '';
	echo '<ul id="primary-menu" class="' . esc_attr( $args['menu_class'] ) . '"><li' . $class . '><a href="' . esc_url( home_url( '/' ) ) . '">Главная</a></li>';
	wp_list_pages( array( 'title_li' => '', 'depth' => (int) $args['depth'], 'sort_column' => 'menu_order,post_title' ) );
	echo '</ul>';
}
// У wp_list_pages свои классы, приводим их к общим.
add_filter( 'page_css_class', function ( $classes ) {
	if ( in_array( 'page_item_has_children', $classes, true ) ) {
		$classes[] = 'menu-item-has-children';
	}
	return $classes;
} );
add_filter( 'wp_list_pages', function ( $html ) {
	return str_replace( "class='children'", 'class="sub-menu"', $html );
} );

/* ------------------------------------------------------------
 * Анонсы статей
 * ------------------------------------------------------------ */
add_filter( 'excerpt_length', function () {
	return 34;
} );
add_filter( 'excerpt_more', function () {
	return '…';
} );

// Ссылка на YouTube отдельной строкой превращается в проигрыватель. Делаем это сами,
// без обращения сервера к YouTube: так вставка не зависит от того, доступен ли он с хостинга.
add_filter( 'the_content', function ( $html ) {
	if ( false === stripos( $html, 'youtu' ) ) {
		return $html;
	}
	return preg_replace_callback(
		'~^[ \t]*(?:<p>\s*)?https?://(?:www\.|m\.)?(?:youtube\.com/watch\?v=|youtu\.be/)([\w-]{11})[^\s<]*(?:\s*</p>)?[ \t]*$~mi',
		function ( $m ) {
			return '<div class="video"><iframe src="https://www.youtube.com/embed/' . esc_attr( $m[1] ) . '" title="Видео на YouTube" loading="lazy" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>';
		},
		$html
	);
}, 7 );

// Таблицы из старых статей прокручиваются вбок, а не ломают страницу.
add_filter( 'the_content', function ( $html ) {
	if ( false === strpos( $html, '<table' ) || is_admin() ) {
		return $html;
	}
	return preg_replace( '~(?<!<figure class="wp-block-table">)(<table\b.*?</table>)~is', '<div class="table-scroll">$1</div>', $html );
}, 20 );

/* ------------------------------------------------------------
 * Настройки сайта: контакты в подвале
 * (Внешний вид → Настроить → Контакты в подвале)
 * ------------------------------------------------------------ */
add_action( 'customize_register', function ( $wp_customize ) {
	$wp_customize->add_section( 'jac_contacts', array( 'title' => 'Контакты в подвале', 'priority' => 30 ) );
	$fields = array(
		'jac_address' => 'Адрес',
		'jac_phone'   => 'Телефон (показывается также в шапке)',
		'jac_email'   => 'Электронная почта',
		'jac_note'    => 'Дополнительная строка (например, часы работы)',
	);
	foreach ( $fields as $id => $label ) {
		$wp_customize->add_setting( $id, array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ) );
		$wp_customize->add_control( $id, array( 'label' => $label, 'section' => 'jac_contacts', 'type' => 'text' ) );
	}

	$wp_customize->add_setting( 'jac_credit', array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ) );
	$wp_customize->add_control( 'jac_credit', array( 'label' => 'Подпись в самом низу (после года и названия)', 'section' => 'jac_contacts', 'type' => 'text' ) );

	$wp_customize->add_section( 'jac_home', array( 'title' => 'Главная страница', 'priority' => 29 ) );
	$wp_customize->add_setting( 'jac_home_page', array( 'default' => 0, 'sanitize_callback' => 'absint' ) );
	$wp_customize->add_control( 'jac_home_page', array(
		'label'       => 'Что показывать на главной',
		'description' => 'Текст выбранной страницы целиком. Обычно это «Анонсы»: программа концертов. Если не выбрано, на главной будет список статей.',
		'section'     => 'jac_home',
		'type'        => 'dropdown-pages',
	) );

	$wp_customize->add_section( 'jac_move', array( 'title' => 'Перенос со старого сайта', 'priority' => 32 ) );
	$wp_customize->add_setting( 'jac_old_site', array( 'default' => '', 'sanitize_callback' => 'esc_url_raw' ) );
	$wp_customize->add_control( 'jac_old_site', array(
		'label'       => 'Адрес старого сайта',
		'description' => 'Пока папка с картинками не скопирована на этот сервер, картинки берутся отсюда, а сама копия закрыта от поисковиков. Когда сайт заработает на основном адресе, это отключится само.',
		'section'     => 'jac_move',
		'type'        => 'url',
	) );

	$wp_customize->add_section( 'jac_lists', array( 'title' => 'Списки статей', 'priority' => 31 ) );
	$wp_customize->add_setting( 'jac_show_dates', array( 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ) );
	$wp_customize->add_control( 'jac_show_dates', array(
		'label'       => 'Показывать даты статей',
		'description' => 'На старом сайте даты служили для порядка статей и не совпадают с датами событий, поэтому по умолчанию они скрыты.',
		'section'     => 'jac_lists',
		'type'        => 'checkbox',
	) );
} );

/* ------------------------------------------------------------
 * Подсказка редактору на главной странице админки
 * ------------------------------------------------------------ */
add_action( 'wp_dashboard_setup', function () {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	wp_add_dashboard_widget( 'jac_help', 'Как добавить статью', function () {
		?>
		<ol style="font-size:15px;line-height:1.6;margin:0 0 14px 20px;">
			<li>Нажмите кнопку «Написать новую статью» ниже.</li>
			<li>Введите заголовок и текст. Подзаголовки внутри статьи оформляйте блоком «Заголовок».</li>
			<li>Справа, во вкладке «Запись», отметьте рубрику. Без рубрики статья попадёт в общую.</li>
			<li>По желанию добавьте «Изображение записи»: оно появится в списке статей и над текстом.</li>
			<li>Нажмите «Опубликовать». Статья сразу появится на главной и в своей рубрике.</li>
		</ol>
		<p>
			<a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'post-new.php' ) ); ?>">Написать новую статью</a>
			<a class="button button-hero" href="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">Все статьи</a>
		</p>
		<p style="color:#50575e;">Исправить готовую статью: откройте её на сайте и нажмите «Редактировать статью» под текстом. Программа концертов: «Страницы → АНОНСЫ», она же показывается на главной. Кнопки «Проекты клуба» и пункты меню: «Внешний вид → Меню».</p>
		<?php
	}, null, null, 'normal', 'high' );
} );

// Прежний адрес рубрики «Новости» (/category/uncategorized/) ведёт на новый, чтобы старые закладки работали.
add_action( 'template_redirect', function () {
	$path = trim( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
	if ( 0 === strpos( $path, 'category/uncategorized' ) ) {
		$news = get_term_by( 'slug', 'novosti', 'category' );
		if ( $news ) {
			wp_safe_redirect( get_category_link( $news ), 301 );
			exit;
		}
	}
}, 1 );

// Перенос содержимого старого сайта одной кнопкой и первичная настройка.
require get_template_directory() . '/inc/import.php';
