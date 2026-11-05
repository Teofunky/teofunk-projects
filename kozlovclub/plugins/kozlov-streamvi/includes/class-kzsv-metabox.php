<?php
/**
 * Блок «Трансляция StreamVi» в карточке концерта: не транслируется / своё название / своё описание.
 * Хранится в собственных полях плагина (_kzsv_*), поля календаря и темы не трогаются.
 */

defined( 'ABSPATH' ) || exit;

final class KZSV_Metabox {

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ), 20, 2 );
		add_action( 'save_post', array( __CLASS__, 'save' ), 20, 2 );
	}

	public static function add( $post_type, $post = null ) {
		try {
			if ( ! in_array( $post_type, KZSV_Source::all_post_types(), true ) ) {
				return;
			}
			add_meta_box( 'kzsv_box', 'Трансляция StreamVi', array( __CLASS__, 'render' ), $post_type, 'side', 'default' );
		} catch ( \Throwable $e ) {
			kzsv_log_exception( $e );
		}
	}

	public static function render( $post ) {
		try {
			$skip  = (bool) get_post_meta( $post->ID, KZSV_Source::META_SKIP, true );
			$title = (string) get_post_meta( $post->ID, KZSV_Source::META_TITLE, true );
			$desc  = (string) get_post_meta( $post->ID, KZSV_Source::META_DESC, true );
			$src   = KZSV_Source::get();
			$main  = $src ? $src->is_main_stage( $post->ID ) : false;
			$max   = (int) KZSV_Settings::get( 'title_max' );
			wp_nonce_field( 'kzsv_box', 'kzsv_box_nonce' );
			?>
			<div class="kzsv-box">
				<?php if ( KZSV_Source::excluded( $post->post_title ) ) : ?>
					<p class="description"><strong>Не транслируется по правилу</strong> на странице «Трансляции» (список «Не транслируются никогда»). Название трансляции под этот концерт не меняется.</p>
				<?php endif; ?>
				<?php if ( ! $main ) : ?>
					<p class="description">Название трансляции меняется только для концертов главной сцены. Этот концерт сейчас не на главной сцене.</p>
				<?php endif; ?>
				<p><label><input type="checkbox" name="kzsv_skip" value="1" <?php checked( $skip ); ?>> Не транслируется: не менять название трансляции под этот концерт</label></p>
				<p><label for="kzsv_title"><strong>Название для трансляции</strong></label><br>
					<input type="text" id="kzsv_title" name="kzsv_title" class="widefat" maxlength="<?php echo esc_attr( (string) $max ); ?>" value="<?php echo esc_attr( $title ); ?>" placeholder="Пусто: название концерта">
				</p>
				<p><label for="kzsv_desc"><strong>Описание для трансляции</strong></label><br>
					<textarea id="kzsv_desc" name="kzsv_desc" class="widefat" rows="4" placeholder="Пусто: первый абзац анонса"><?php echo esc_textarea( $desc ); ?></textarea>
				</p>
				<?php if ( $src && $main && ! $skip ) : ?>
					<?php
					$start = 0;
					$plan  = KZSV_Runner::plan();
					if ( ! is_wp_error( $plan ) ) {
						foreach ( $plan['timeline'] as $e ) {
							if ( (int) $e['post_id'] === (int) $post->ID && $e['start'] >= KZSV_Runner::now() - 6 * HOUR_IN_SECONDS ) {
								$start = (int) $e['start'];
								break;
							}
						}
					}
					list( $t, $d ) = $src->compose( $post->ID, $post->post_title, get_permalink( $post->ID ), $start ? $start : KZSV_Runner::now() );
					?>
					<p class="description"><strong>Уйдёт в StreamVi:</strong><br>«<?php echo esc_html( $t ); ?>»<br><?php echo esc_html( KZSV_Text::clip( $d, 220 ) ); ?></p>
				<?php endif; ?>
			</div>
			<?php
		} catch ( \Throwable $e ) {
			kzsv_log_exception( $e );
		}
	}

	public static function save( $post_id, $post ) {
		try {
			if ( ! isset( $_POST['kzsv_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kzsv_box_nonce'] ) ), 'kzsv_box' ) ) {
				return;
			}
			if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}
			if ( ! is_object( $post ) || ! in_array( $post->post_type, KZSV_Source::all_post_types(), true ) ) {
				return;
			}
			$skip  = ! empty( $_POST['kzsv_skip'] );
			$title = isset( $_POST['kzsv_title'] ) ? sanitize_text_field( wp_unslash( $_POST['kzsv_title'] ) ) : '';
			$desc  = isset( $_POST['kzsv_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['kzsv_desc'] ) ) : '';

			if ( $skip ) {
				update_post_meta( $post_id, KZSV_Source::META_SKIP, 1 );
			} else {
				delete_post_meta( $post_id, KZSV_Source::META_SKIP );
			}
			foreach ( array( KZSV_Source::META_TITLE => $title, KZSV_Source::META_DESC => $desc ) as $key => $value ) {
				if ( '' === trim( $value ) ) {
					delete_post_meta( $post_id, $key );
				} else {
					update_post_meta( $post_id, $key, $value );
				}
			}
		} catch ( \Throwable $e ) {
			kzsv_log_exception( $e );
		}
	}
}
