<?php
/**
 * Небольшой блок «Для поисковиков» в правой колонке редактора анонса.
 *
 * Пишет только собственные скрытые поля плагина и только когда редактор сам их заполнил:
 * статус (_kozlov_event_status, прежняя дата _kozlov_event_previous_start),
 * исполнителей (_kozlov_event_performers), title и description для поисковиков
 * (_kozlov_event_seo_title, _kozlov_event_seo_desc). Пустое значение поле удаляет.
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

	const PERFORMERS_FIELD = 'kozlov_event_schema_performers';

	const SEO_TITLE_FIELD = 'kozlov_event_schema_seo_title';

	const SEO_DESC_FIELD = 'kozlov_event_schema_seo_desc';

	/** Ограничение длины ручных title и description (знаков): с запасом к рекомендуемым 65 и 160. */
	const SEO_TITLE_MAX = 100;
	const SEO_DESC_MAX  = 200;

	/** Ограничение длины поля исполнителей (знаков). */
	const PERFORMERS_MAX = 2000;

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
		add_meta_box( 'kozlov-event-schema-status', 'Для поисковиков', array( __CLASS__, 'render' ), Kozlov_Event_Schema_Builder::POST_TYPE, 'side', 'default' );
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
			echo '<p style="margin:0 0 4px;"><strong>Статус концерта</strong></p>';
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

			// Исполнители.
			$manual = (string) get_post_meta( $post->ID, Kozlov_Event_Schema_Builder::PERFORMERS_META, true );
			echo '<hr><p style="margin:8px 0 4px;"><label for="kozlov-event-schema-performers"><strong>Исполнители</strong></label></p>';
			echo '<textarea id="kozlov-event-schema-performers" name="' . esc_attr( self::PERFORMERS_FIELD ) . '" rows="4" style="width:100%" placeholder="Обычно не нужно: состав берётся из текста">' . esc_textarea( $manual ) . '</textarea>';
			if ( '' === trim( $manual ) ) {
				$auto  = Kozlov_Event_Schema_Builder::performers( $post->ID );
				$names = array();
				foreach ( $auto['list'] as $performer ) {
					$names[] = $performer['name'];
				}
				echo '<p class="description">' . ( empty( $names ) ? 'В тексте анонса состав не найден.' : 'Найдено в тексте: ' . esc_html( implode( ', ', $names ) ) . '.' ) . '</p>';
			}
			echo '<p class="description">Пусто: состав берётся из строки вида «На сцену выйдут: Имя Фамилия (инструмент), …». Чтобы указать вручную, впишите по одному исполнителю или коллективу на строке. «-»: не указывать исполнителей.</p>';

			// Title и description.
			$auto         = Kozlov_Event_Schema_Meta::build( $post );
			$manual_title = (string) get_post_meta( $post->ID, Kozlov_Event_Schema_Meta::TITLE_META, true );
			$manual_desc  = (string) get_post_meta( $post->ID, Kozlov_Event_Schema_Meta::DESC_META, true );
			echo '<hr><p style="margin:8px 0 4px;"><strong>Заголовок и описание в поиске</strong></p>';
			echo '<p class="description">Для прошедших, отменённых и отложенных концертов текст меняется сам: без цены, с пометкой о состоянии.</p>';
			if ( ! kozlov_event_schema_is_enabled( 'seo_meta' ) ) {
				echo '<p class="description">Сейчас выключено в «Настройки → Разметка концертов»: на сайте остаются значения Yoast.</p>';
			}
			echo '<p style="margin:6px 0 2px;"><label for="kozlov-event-schema-seo-title">Title</label></p>';
			echo '<input type="text" id="kozlov-event-schema-seo-title" name="' . esc_attr( self::SEO_TITLE_FIELD ) . '" value="' . esc_attr( $manual_title ) . '" style="width:100%" placeholder="' . esc_attr( $auto['title'] ) . '">';
			echo '<p style="margin:6px 0 2px;"><label for="kozlov-event-schema-seo-desc">Description</label></p>';
			echo '<textarea id="kozlov-event-schema-seo-desc" name="' . esc_attr( self::SEO_DESC_FIELD ) . '" rows="4" style="width:100%" placeholder="' . esc_attr( $auto['description'] ) . '">' . esc_textarea( $manual_desc ) . '</textarea>';
			echo '<p class="description">Пусто: собирается автоматически из названия, даты, сцены, цены и жанров (серым показано, что получится сейчас). Впишите свой текст, только если автоматический не годится. Title до 65 знаков, description до 160 (поля обрезаются на 100 и 200).</p>';
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

			// Исполнители: поле есть только в новой версии блока; если его нет в запросе, ничего не трогаем.
			if ( isset( $_POST[ self::PERFORMERS_FIELD ] ) ) {
				$performers = self::clean_performers( sanitize_textarea_field( wp_unslash( $_POST[ self::PERFORMERS_FIELD ] ) ) );
				if ( '' === $performers ) {
					delete_post_meta( $post_id, Kozlov_Event_Schema_Builder::PERFORMERS_META );
				} else {
					update_post_meta( $post_id, Kozlov_Event_Schema_Builder::PERFORMERS_META, wp_slash( $performers ) );
				}
			}

			// Title и description для поисковиков (то же правило: нет поля в запросе, ничего не трогаем).
			$seo_fields = array(
				self::SEO_TITLE_FIELD => Kozlov_Event_Schema_Meta::TITLE_META,
				self::SEO_DESC_FIELD  => Kozlov_Event_Schema_Meta::DESC_META,
			);
			foreach ( $seo_fields as $field => $meta_key ) {
				if ( ! isset( $_POST[ $field ] ) ) {
					continue;
				}
				$text = self::clean_seo_text( sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) ), self::SEO_TITLE_FIELD === $field ? self::SEO_TITLE_MAX : self::SEO_DESC_MAX );
				if ( '' === $text ) {
					delete_post_meta( $post_id, $meta_key );
				} else {
					update_post_meta( $post_id, $meta_key, wp_slash( $text ) );
				}
			}
		} catch ( Throwable $e ) {
			return;
		}
	}

	/**
	 * Ручные title и description: одна строка, без лишних пробелов, не длиннее $max.
	 *
	 * @param string $text Текст из формы (уже без тегов).
	 * @param int    $max  Максимум знаков.
	 * @return string
	 */
	public static function clean_seo_text( $text, $max ) {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( function_exists( 'mb_substr' ) ) {
			$text = mb_substr( $text, 0, (int) $max, 'UTF-8' );
		}
		return trim( $text );
	}

	/**
	 * Поле исполнителей: строки без лишних пробелов, без пустых строк, не длиннее PERFORMERS_MAX.
	 *
	 * @param string $text Текст из формы (уже без тегов).
	 * @return string
	 */
	public static function clean_performers( $text ) {
		$lines = array();
		foreach ( explode( "\n", str_replace( "\r", '', (string) $text ) ) as $line ) {
			$line = trim( (string) preg_replace( '/\s+/u', ' ', $line ) );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		$text = implode( "\n", $lines );
		if ( function_exists( 'mb_substr' ) ) {
			$text = mb_substr( $text, 0, self::PERFORMERS_MAX, 'UTF-8' );
		}
		return trim( $text );
	}
}
