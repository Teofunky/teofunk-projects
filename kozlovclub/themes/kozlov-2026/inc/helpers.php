<?php
/** Хелперы вывода, перенесённые из старой темы. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function kz_month( $month, $short = true ) {
	$s = array( 'Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек' );
	$l = array( 'Январе', 'Феврале', 'Марте', 'Апреле', 'Мае', 'Июне', 'Июле', 'Августе', 'Сентябре', 'Октябре', 'Ноябре', 'Декабре' );
	return ( $short ? $s : $l )[ (int) $month - 1 ] ?? '';
}

function kz_month_genitive( $month ) {
	$g = array( 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря' );
	return $g[ (int) $month - 1 ] ?? '';
}

/** Разделитель месяцев в афише. */
function kz_month_divider( $month, $label = 'Концерты в ' ) {
	return '<div class="event_month"><span>' . esc_html( $label . kz_month( $month, false ) ) . '</span></div>';
}

/** Строка сцены и «Концерт сегодня» над карточкой. */
function kz_card_top( $c ) {
	$parts = array();
	if ( $c['is_today'] ) {
		$parts[] = 'Концерт сегодня';
	}
	if ( $c['stage'] ) {
		$parts[] = '<a href="' . esc_url( $c['stage']['url'] ) . '">' . esc_html( $c['stage']['name'] ) . '</a>';
	}
	if ( ! $parts ) {
		return '';
	}
	$style = '';
	if ( ! $c['is_today'] && $c['stage'] ) {
		if ( 'the-beat' === $c['stage']['slug'] ) {
			$style = ' style="background-image:url(\'' . esc_url( get_template_directory_uri() . '/assets/icons/yellow_circle.png' ) . '\')"';
		} elseif ( 'main-scene' === $c['stage']['slug'] ) {
			$style = ' style="background-image:none;padding-left:0"';
		}
	}
	return '<div class="top_info"' . $style . '>' . implode( ' | ', $parts ) . '</div>';
}

/** Кнопка покупки / брони / подробнее в карточке и слайдере. */
function kz_buy_button( $c, $more_link = true ) {
	$label = get_post_meta( $c['id'], 'kc_register_btn', true ) ? 'Регистрация' : 'Купить билет';
	if ( $c['cancelled'] ) {
		return '<a class="btn-widget-buy btn-cancelled" href="' . esc_url( $c['url'] ) . '">отменён</a>';
	}
	if ( $c['sold_out'] ) {
		return '<a class="btn-widget-buy btn-soldout" href="' . esc_url( $c['url'] ) . '">билетов нет</a>';
	}
	if ( $c['can_buy'] ) {
		return '<div class="btn-widget"><button class="tc-background-tomato" type="button" tc-event="' . esc_attr( $c['tc_event'] ) . '">' . esc_html( $label ) . '</button></div><a class="btn-widget-buy">' . esc_html( mb_strtolower( $label ) ) . '</a>';
	}
	if ( $c['booking'] ) {
		return '<a class="btn-widget-read open__primary__widget" role="button">Забронировать столик</a>';
	}
	return $more_link ? '<a class="btn-widget-buy" href="' . esc_url( $c['url'] ) . '">подробнее</a>' : '';
}

/** Карточка концерта в сетке (view=cell) и в списке (view=line). */
function kz_event_card( $c, $view = 'cell' ) {
	$classes = array( 'event', 'today_' . ( $c['is_today'] ? '1' : '' ) );
	if ( $c['stage'] ) {
		$classes[] = 'stage-' . $c['stage']['slug'];
	}
	ob_start();
	?>
	<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
		<?php echo kz_card_top( $c ); // phpcs:ignore ?>
		<div class="wrap">
			<?php if ( 'cell' === $view ) : ?>
				<div class="thumbnail">
					<a href="<?php echo esc_url( $c['url'] ); ?>"><?php echo $c['image']['id'] ? wp_get_attachment_image( $c['image']['id'], 'category-thumb2', false, array( 'loading' => 'lazy', 'class' => 'attachment-category-thumb2 size-category-thumb2 wp-post-image' ) ) : ''; ?></a>
				</div>
			<?php endif; ?>
			<div class="desc">
				<?php if ( 'line' === $view ) : ?>
					<div class="datetime">
						<div class="cell cell_1"><?php echo esc_html( $c['day'] ); ?></div>
						<div class="cell cell_2">
							<div class="row row_1" data-time="<?php echo esc_attr( $c['time'] ); ?>" data-wd="<?php echo (int) date( 'N', strtotime( $c['date'] ) ); ?>"><?php echo esc_html( $c['month_short'] . ' | ' . $c['time'] ); ?></div>
							<div class="row row_2"><?php echo esc_html( $c['weekday'] ); ?></div>
						</div>
					</div>
				<?php endif; ?>
				<a class="title set_height" href="<?php echo esc_url( $c['url'] ); ?>"><?php echo esc_html( $c['title'] ); ?></a>
				<?php if ( 'cell' === $view ) : ?>
					<div class="datetime">
						<div class="wrap">
							<div class="cell cell_1"><?php echo esc_html( $c['day'] ); ?></div>
							<div class="cell cell_2">
								<div class="row row_1" data-time="<?php echo esc_attr( $c['time'] ); ?>" data-wd="<?php echo (int) date( 'N', strtotime( $c['date'] ) ); ?>"><?php echo esc_html( $c['month_short'] . ' | ' . $c['time'] . ' | ' . $c['weekday'] ); ?></div>
								<div class="row row_2"><?php echo esc_html( $c['price']['text'] ); ?></div>
							</div>
						</div>
					</div>
				<?php else : ?>
					<div class="price"><?php echo esc_html( $c['price']['text'] ); ?></div>
				<?php endif; ?>
			</div>
			<div class="readmore"><?php echo kz_buy_button( $c ); // phpcs:ignore ?></div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/** Афиша: список карточек с разделителями по месяцам. $concerts из kozlov_concerts(). */
function kz_afisha( $concerts, $view = 'cell', $divider_label = 'Концерты в ' ) {
	if ( ! $concerts ) {
		return '<div class="afisha-empty">Концертов не найдено. <a href="' . esc_url( home_url( '/' ) ) . '">Вся афиша</a></div>';
	}
	$out = ''; $month = null;
	foreach ( $concerts as $c ) {
		$m = (int) substr( $c['date'], 5, 2 );
		if ( $m !== $month ) {
			if ( null !== $month ) {
				$out .= '</div>';
			}
			$out .= kz_month_divider( $m, $divider_label ) . '<div class="events view_' . esc_attr( $view ) . '">';
			$month = $m;
		}
		$out .= kz_event_card( $c, $view );
	}
	return $out . '</div>';
}

/** Переключатели вида и календарь над афишей. */
function kz_viewtabs( $view ) {
	return '<div class="viewtabs" id="events">'
		. '<a href="#" class="tab_item tab_calendar active calendar-toggler" title="Календарь"></a>'
		. '<a href="' . esc_url( add_query_arg( 'view', 'line' ) ) . '" class="tab_item tab_line ' . ( 'line' === $view ? 'active' : '' ) . '"></a>'
		. '<a href="' . esc_url( add_query_arg( 'view', 'cell' ) ) . '" class="tab_item tab_cell ' . ( 'cell' === $view ? 'active' : '' ) . '"></a>'
		. '</div>';
}

function kz_view() {
	return ( isset( $_GET['view'] ) && 'line' === $_GET['view'] ) ? 'line' : 'cell';
}

/** Ссылки жанров концерта: «Жанры: Джаз, Этника». */
function kz_genre_links( $c ) {
	$a = array();
	foreach ( $c['genres'] as $g ) {
		$a[] = '<a href="' . esc_url( get_term_link( $g['slug'], 'kc_genre' ) ) . '">' . esc_html( $g['name'] ) . '</a>';
	}
	return implode( ', ', $a );
}

/** Видео: ссылка или iframe → iframe. */
function kz_video_embed( $src ) {
	$src = trim( (string) $src );
	if ( '' === $src ) {
		return '';
	}
	if ( preg_match( '#youtu\.be/([\w-]+)|youtube\.com/(?:watch\?v=|embed/|shorts/)([\w-]+)#', $src, $m ) ) {
		$src = 'https://www.youtube.com/embed/' . ( $m[1] ?: $m[2] );
	} elseif ( preg_match( '#rutube\.ru/video/([\w-]+)#', $src, $m ) ) {
		$src = 'https://rutube.ru/play/embed/' . $m[1] . '/';
	} elseif ( preg_match( '#vk\.com/video(-?\d+)_(\d+)#', $src, $m ) ) {
		$src = 'https://vk.com/video_ext.php?oid=' . $m[1] . '&id=' . $m[2];
	}
	return '<iframe src="' . esc_url( $src ) . '" width="853" height="480" frameborder="0" allowfullscreen loading="lazy" title="Видео"></iframe>';
}

/** Подпись периода: «5 ноября», «5–12 ноября», «28 ноября – 3 декабря». */
function kz_range_label( $a, $b = '' ) {
	$da = (int) substr( $a, 8, 2 ); $ma = (int) substr( $a, 5, 2 ); $ya = (int) substr( $a, 0, 4 );
	$cur = (int) wp_date( 'Y' );
	$year = $cur !== $ya ? ' ' . $ya : '';
	if ( ! $b || $b <= $a ) {
		return $da . ' ' . kz_month_genitive( $ma ) . $year;
	}
	$db = (int) substr( $b, 8, 2 ); $mb = (int) substr( $b, 5, 2 ); $yb = (int) substr( $b, 0, 4 );
	if ( $ma === $mb && $ya === $yb ) {
		return $da . "\u{2009}\u{2013}\u{2009}" . $db . ' ' . kz_month_genitive( $mb ) . $year;
	}
	return $da . ' ' . kz_month_genitive( $ma ) . ( $ya !== $yb ? ' ' . $ya : '' ) . " \u{2013} " . $db . ' ' . kz_month_genitive( $mb ) . ( $yb !== $cur ? ' ' . $yb : '' );
}
