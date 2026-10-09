<?php
/**
 * Небольшой блок «Статус для поисковиков» в правой колонке редактора анонса.
 *
 * Пишет только собственное скрытое поле _kozlov_event_status и только когда редактор
 * сам выбрал «Перенесён» или «Отменён». Возврат к «По расписанию» поле удаляет.
 *
 * @package Kozlov_Event_Schema
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Kozlov_Event_Schema_Status' ) ) {
	return;
}

/**
 * Status meta box.
 */
class Kozlov_Event_Schema_Status {

	const NONCE_ACTION = 'kozlov_event_schema_status';

	const NONCE_FIELD = 'kozlov_event_schema_status_nonce';

	const FIELD = 'kozlov_event_schema_status';

	const PREVIOUS_FIELD = 'kozlov_event_schema_previous';

	/**
	 * Хуки.
	 */
	public static function init() {
		add_action( 'add_meta_boxes_' . Kozlov_Event_Schema_Builder::POST_TYPE, array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_' . Kozlov_Event_Schema_Builder::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
	}

	/**
	 * Регистрирует блок.
	 */
	public static function add_box() {
		add_meta_box( 'kozlov-event-schema-status', 'Статус для поисковиков', array( __CLASS__, 'render' ), Kozlov_Event_Schema_Builder::POST_TYPE, 'side', 'default' );
	}

	/**
	 * Выводит выпадающий список.
	 *
	 * @param WP_Post $post Анонс.
	 */
	public static function render( $post ) {
		try {
			$current = Kozlov_Event_Schema_Builder::status( $post->ID );
			wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
			echo '<select name="' . esc_attr( self::FIELD ) . '" style="width:100%">';
			foreach ( Kozlov_Event_Schema_Builder::statuses() as $value => $status ) {
				echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current, $value, false ) . '>' . esc_html( $status[0] ) . '</option>';
			}
			echo '</select>';

			// Прежняя дата: сохранённая, иначе текущая дата из календаря (до того, как её поменяют).
			$previous = (string) get_post_meta( $post->ID, Kozlov_Event_Schema_Builder::PREVIOUS_META, true );
			$previous = str_replace( ' ', 'T', $previous );
			if ( '' === $previous ) {
				$row   = Kozlov_Event_Schema_Builder::get_row( $post->ID );
				$times = $row ? Kozlov_Event_Schema_Builder::event_times( $row ) : null;
				if ( $times && strlen( $times['start'] ) > 10 ) {
					$previous = substr( $times['start'], 0, 16 );
				}
			}
			echo '<p style="margin:10px 0 4px;"><label for="kozlov-event-schema-previous">Прежняя дата (только для «Перенесён на новую дату»):</label></p>';
			echo '<input type="datetime-local" id="kozlov-event-schema-previous" name="' . esc_attr( self::PREVIOUS_FIELD ) . '" value="' . esc_attr( $previous ) . '" style="width:100%">';

			echo '<p style="color:#b32d2e;font-weight:600;">Статус здесь виден только поисковикам. При отмене, отсрочке или переносе обязательно напишите то же самое в тексте анонса.</p>';
			echo '<p class="description">«Отложен»: новой даты нет, дату в календаре не меняйте.<br>«Перенесён на новую дату»: выберите этот статус, проверьте прежнюю дату и поставьте новую дату в календаре.<br>«Отменён»: дату не меняйте и анонс не удаляйте.</p>';
		} catch ( Throwable $e ) {
			return;
		}
	}

	/**
	 * Сохраняет выбор.
	 *
	 * @param int     $post_id ID записи.
	 * @param WP_Post $post    Запись.
	 */
	public static function save( $post_id, $post ) {
		try {
			if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST[ self::FIELD ] ) ) {
				return;
			}
			$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) );
			if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
				return;
			}
			if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
				return;
			}
			if ( ! ( $post instanceof WP_Post ) || Kozlov_Event_Schema_Builder::POST_TYPE !== $post->post_type ) {
				return;
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			$value = sanitize_key( wp_unslash( $_POST[ self::FIELD ] ) );
			if ( ! array_key_exists( $value, Kozlov_Event_Schema_Builder::statuses() ) ) {
				return;
			}
			if ( '' === $value ) {
				delete_post_meta( $post_id, Kozlov_Event_Schema_Builder::STATUS_META );
			} else {
				update_post_meta( $post_id, Kozlov_Event_Schema_Builder::STATUS_META, $value );
			}

			// Прежняя дата хранится только у перенесённых на новую дату.
			$previous = '';
			if ( 'rescheduled' === $value && isset( $_POST[ self::PREVIOUS_FIELD ] ) ) {
				$raw = str_replace( 'T', ' ', sanitize_text_field( wp_unslash( $_POST[ self::PREVIOUS_FIELD ] ) ) );
				if ( null !== Kozlov_Event_Schema_Builder::previous_start_iso( $raw ) ) {
					$previous = $raw;
				}
			}
			if ( '' === $previous ) {
				delete_post_meta( $post_id, Kozlov_Event_Schema_Builder::PREVIOUS_META );
			} else {
				update_post_meta( $post_id, Kozlov_Event_Schema_Builder::PREVIOUS_META, $previous );
			}
		} catch ( Throwable $e ) {
			return;
		}
	}
}
