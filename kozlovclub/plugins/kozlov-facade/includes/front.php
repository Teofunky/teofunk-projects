<?php
/**
 * Front end: the block is printed (hidden) on the home page for the allowed audience;
 * the script mounts it above the slider on phones only. Any failure leaves the page as it was.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Who sees the block right now. */
function kzf_should_render() {
	static $ok = null;
	if ( null !== $ok ) {
		return $ok;
	}
	$ok = false;
	if ( is_admin() || wp_doing_ajax() || ! is_front_page() || is_feed() ) {
		return $ok;
	}
	$mode = kzf_settings()['mode'];
	if ( 'all' === $mode ) {
		$ok = true;
	} elseif ( 'editors' === $mode ) {
		$ok = is_user_logged_in() && current_user_can( 'edit_others_posts' );
	}
	return $ok;
}

add_action( 'wp_enqueue_scripts', 'kzf_enqueue', 20 );
function kzf_enqueue() {
	try {
		if ( ! kzf_should_render() ) {
			return;
		}
		$meta = json_decode( (string) file_get_contents( KZF_DIR . 'assets/hero.json' ), true );
		if ( ! is_array( $meta ) ) {
			return;
		}
		wp_enqueue_style( 'kzf-front', KZF_URL . 'assets/front.css', array(), KZF_VER, 'all' );
		wp_enqueue_style( 'kzf-hero', KZF_URL . 'assets/hero.css', array(), KZF_VER, '(max-width: 767px)' );
		wp_enqueue_script( 'kzf-front', KZF_URL . 'assets/front.js', array(), KZF_VER, true );
		$cfg = array(
			'heroUrl'    => KZF_URL . 'assets/hero.svg?ver=' . KZF_VER,
			'assetsUrl'  => KZF_URL . 'assets/',
			'doorX'      => (float) $meta['door_x'],
			'viewW'      => (float) $meta['W'],
			'viewH'      => (float) $meta['H'],
			'streetSync' => (bool) kzf_settings()['street_sync'],
		);
		wp_add_inline_script( 'kzf-front', 'window.KZF_CONFIG=' . wp_json_encode( $cfg ) . ';', 'before' );
	} catch ( Throwable $e ) {
		return; // never break the page
	}
}

/**
 * In <head>: the drawing is preloaded, and on phones the old slider stays invisible from the very first paint while the block gets ready,
 * so it never flashes and jumps. Pure CSS, so it works even if scripts are deferred or moved by an optimizer.
 * Its space stays reserved. Safety: a tiny script printed with the block gives it back 2.5 s after the
 * page has been read unless the block has mounted; if no script runs at all, CSS gives it back after 4 s;
 * front.js gives it back at once (class kzf-off) whenever it decides not to mount.
 */
add_action( 'wp_head', 'kzf_head_early', 1 );
function kzf_head_early() {
	try {
		if ( ! kzf_should_render() || ! kzf_display_slides() ) {
			return;
		}
		echo '<style id="kzf-early">@media (max-width: 767px){html:not(.kzf-off):not(.kzf-on) .content_home .slider-wrapper{opacity:0;visibility:hidden;animation:kzf-back 0s linear 4s forwards}}@keyframes kzf-back{to{opacity:1;visibility:visible}}</style>' . "\n";
		// the drawing starts downloading with the page itself, not when the footer script runs
		// (same mode and credentials as the fetch() in front.js, so the browser reuses this download)
		echo '<link rel="preload" href="' . esc_url( KZF_URL . 'assets/hero.svg?ver=' . KZF_VER ) . '" as="fetch" crossorigin="anonymous" media="(max-width: 767px)">' . "\n";
	} catch ( Throwable $e ) {
		return;
	}
}

add_action( 'wp_footer', 'kzf_render_root', 5 );
function kzf_render_root() {
	$level = ob_get_level();
	try {
		if ( ! kzf_should_render() ) {
			return;
		}
		$slides = kzf_display_slides();
		if ( ! $slides ) {
			return; // no cards: the old slider stays, nothing is printed
		}
		ob_start();
		kzf_print_block( $slides );
		$html = ob_get_clean();
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside
	} catch ( Throwable $e ) {
		while ( ob_get_level() > $level ) { // close only the buffer this function opened
			ob_end_clean();
		}
	}
}

/** True for text typed in capitals («ПРИСТЕГНИТЕ РЕМНИ!»): such a subtitle is set upright and small. */
function kzf_all_caps( $text ) {
	return (bool) preg_match( '/\p{Lu}{3}/u', $text ) && mb_strtoupper( $text, 'UTF-8' ) === $text;
}

function kzf_print_block( $slides ) {
	$n = count( $slides );
	?>
<div id="kzf-root" class="kzf-root" data-kzf-ver="<?php echo esc_attr( KZF_VER ); ?>" hidden>
	<div class="kzf-hero"></div>
	<?php if ( $n ) : ?>
	<section class="kzf-card" aria-label="Афиша и новости клуба" style="--kzf-dots: <?php echo (int) ( $n > 1 ? $n : 0 ); ?>">
		<div class="kzf-frame" aria-hidden="true"><span class="kzf-f1"></span><span class="kzf-f2"></span><span class="kzf-f3"></span><span class="kzf-f4"></span><span class="kzf-f5"></span></div>
		<div class="kzf-track">
		<?php foreach ( $slides as $i => $s ) : ?>
			<article class="kzf-slide" aria-label="<?php echo esc_attr( ( $i + 1 ) . ' из ' . $n ); ?>"<?php echo ! empty( $s['until'] ) ? ' data-kzf-until="' . esc_attr( (int) $s['until'] ) . '"' : ''; ?>>
				<?php if ( $s['label'] ) : ?><div class="kzf-eyebrow"><?php echo esc_html( $s['label'] ); ?></div><?php endif; ?>
				<div class="kzf-row">
					<?php if ( $s['paper'] && ! $s['image'] ) : ?>
						<a class="kzf-paper" href="<?php echo esc_url( $s['link'] ); ?>" tabindex="-1" aria-hidden="true"><span class="kzf-pn">Ежедневная<br>Джазета</span><span class="kzf-pr"></span><span class="kzf-pd"><?php echo esc_html( trim( $s['paper']['no'] . ( $s['paper']['date'] ? ' · ' . $s['paper']['date'] : '' ) ) ); ?></span></a>
					<?php elseif ( $s['image'] ) : ?>
						<a class="kzf-photo" href="<?php echo esc_url( $s['link'] ? $s['link'] : '#' ); ?>" tabindex="-1" aria-hidden="true"><img src="<?php echo esc_url( $s['image'] ); ?>" alt="" loading="lazy" decoding="async"></a>
					<?php endif; ?>
					<div class="kzf-info">
						<?php if ( $s['when'] ) : ?><div class="kzf-when"><?php echo esc_html( $s['when'] ); ?></div><?php endif; ?>
						<?php if ( $s['title'] ) : ?>
						<h2 class="kzf-title<?php echo ( mb_strlen( $s['title'] ) > 48 ) ? ' kzf-long' : ''; ?>"><?php if ( $s['link'] ) : ?><a href="<?php echo esc_url( $s['link'] ); ?>"><?php echo esc_html( $s['title'] ); ?></a><?php else : ?><?php echo esc_html( $s['title'] ); ?><?php endif; ?></h2>
						<?php endif; ?>
						<?php if ( $s['subtitle'] ) : ?><div class="kzf-sub<?php echo kzf_all_caps( $s['subtitle'] ) ? ' kzf-caps' : ''; ?>"><?php echo esc_html( $s['subtitle'] ); ?></div><?php endif; ?>
						<?php if ( $s['meta'] ) : ?><div class="kzf-meta"><?php echo esc_html( $s['meta'] ); ?></div><?php endif; ?>
					</div>
				</div>
				<?php if ( 'event' === $s['kind'] && $s['tc_id'] ) : ?>
					<button class="kzf-buy tc-background-tomato" type="button" tc-event="<?php echo esc_attr( $s['tc_id'] ); ?>" data-href="<?php echo esc_url( $s['link'] ); ?>"><?php echo esc_html( $s['button'] ); ?></button>
				<?php elseif ( 'event' === $s['kind'] ) : ?>
					<a class="kzf-buy" href="<?php echo esc_url( $s['link'] ); ?>"><?php echo esc_html( $s['button'] ); ?></a>
				<?php elseif ( $s['link'] ) : ?>
					<a class="kzf-act" href="<?php echo esc_url( $s['link'] ); ?>"><?php echo esc_html( $s['button'] ); ?></a>
				<?php endif; ?>
			</article>
		<?php endforeach; ?>
		</div>
		<?php if ( $n > 1 ) : ?>
		<div class="kzf-dots" role="group" aria-label="Слайды">
			<?php for ( $i = 0; $i < $n; $i++ ) : ?>
			<button class="kzf-dot" type="button" aria-label="<?php echo esc_attr( 'Слайд ' . ( $i + 1 ) ); ?>" aria-current="<?php echo 0 === $i ? 'true' : 'false'; ?>"></button>
			<?php endfor; ?>
		</div>
		<?php endif; ?>
	</section>
	<?php endif; ?>
</div>
<script>(function(){setTimeout(function(){var d=document.documentElement;if(!d.classList.contains("kzf-on"))d.classList.add("kzf-off")},2500)})();</script>
	<?php
}
