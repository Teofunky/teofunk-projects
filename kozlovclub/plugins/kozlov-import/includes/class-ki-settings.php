<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Настройки сайта, Yoast и наших плагинов со старого сайта; таблицы Джазеты. */
class KI_Settings {

	private static function data() {
		return KI_Importer::load( 'settings' );
	}

	/** Общие настройки и Yoast. */
	public static function site() {
		$d = self::data();
		$o = $d['options'];
		$log = array();
		foreach ( array( 'blogname', 'blogdescription', 'timezone_string', 'date_format', 'time_format', 'start_of_week' ) as $k ) {
			if ( isset( $o[ $k ] ) && '' !== $o[ $k ] ) {
				update_option( $k, $o[ $k ] );
			}
		}
		$log[] = 'Название сайта, описание, пояс Europe/Moscow, форматы даты.';
		// Постоянные ссылки: /event/slug/ работает только с ЧПУ
		if ( ! get_option( 'permalink_structure' ) ) {
			update_option( 'permalink_structure', '/%postname%/' );
			flush_rewrite_rules();
			$log[] = 'Включены ЧПУ (/%postname%/).';
		}
		// Yoast: заголовки, описания, соцсети. Ключи старого типа ai1ec_event переименованы в concert.
		if ( defined( 'WPSEO_VERSION' ) ) {
			$titles = maybe_unserialize( $o['wpseo_titles'] ?? '' );
			if ( is_array( $titles ) ) {
				$cur = get_option( 'wpseo_titles', array() );
				foreach ( $titles as $k => $v ) {
					$nk = str_replace( array( 'ai1ec_event', 'events_categories', 'events_tags' ), array( 'concert', 'kc_genre', 'kc_tag' ), $k );
					if ( preg_match( '/^(title|metadesc|noindex|display-metabox|social-title|social-description|social-image|schema-|breadcrumbs|separator|forcerewritetitle|stripcategorybase|disable-|company_|person_|website_name|alternate_website_name)/', $nk ) ) {
						$cur[ $nk ] = is_string( $v ) ? str_replace( 'ai1ec_event', 'concert', $v ) : $v;
					}
				}
				update_option( 'wpseo_titles', $cur );
				$log[] = 'Yoast: шаблоны заголовков и описаний (' . count( $titles ) . ' ключей).';
			}
			$social = maybe_unserialize( $o['wpseo_social'] ?? '' );
			if ( is_array( $social ) ) {
				$cur = get_option( 'wpseo_social', array() );
				foreach ( $social as $k => $v ) {
					if ( '' !== $v && null !== $v ) {
						$cur[ $k ] = $v;
					}
				}
				update_option( 'wpseo_social', $cur );
				$log[] = 'Yoast: соцсети и OpenGraph.';
			}
			$wpseo = maybe_unserialize( $o['wpseo'] ?? '' );
			if ( is_array( $wpseo ) ) {
				$cur = get_option( 'wpseo', array() );
				foreach ( array( 'company_or_person', 'company_name', 'company_logo', 'company_logo_id', 'person_name', 'website_name', 'alternate_website_name', 'enable_xml_sitemap', 'disable-author', 'disable-date', 'disable-post_format', 'disable-attachment', 'breadcrumbs-enable', 'enable_enhanced_slack_sharing', 'enable_metabox_insights', 'enable_link_suggestions', 'enable_text_link_counter', 'enable_headless_rest_endpoints', 'remove_feed_global_comments', 'remove_feed_post_comments', 'remove_shortlinks', 'remove_rest_api_links', 'remove_rsd_wlw_links', 'remove_oembed_links', 'remove_generator', 'remove_emoji_scripts', 'remove_powered_by_header', 'remove_pingback_header', 'clean_campaign_tracking_urls', 'clean_permalinks', 'search_cleanup', 'redirect_search_pretty_urls', 'deny_search_crawling' ) as $k ) {
					if ( array_key_exists( $k, $wpseo ) ) {
						$cur[ $k ] = $wpseo[ $k ];
					}
				}
				update_option( 'wpseo', $cur );
				$log[] = 'Yoast: общие настройки (компания, карта сайта, чистка).';
			}
		} else {
			$log[] = 'Yoast SEO не активен: его настройки пропущены. Активируйте Yoast и запустите шаг ещё раз.';
		}
		// настройки наших плагинов, если они установлены (иначе лягут в опции и подхватятся при установке)
		foreach ( array( 'kss_settings', 'kaf_settings', 'kep_settings', 'kozlov_event_schema_settings' ) as $k ) {
			if ( ! empty( $o[ $k ] ) && false === get_option( $k ) ) {
				update_option( $k, maybe_unserialize( $o[ $k ] ) );
			}
		}
		$log[] = 'Настройки Smart Search, Afisha Filters, Event Page, Event Schema сохранены (подхватятся при установке плагинов).';
		return array( 'done' => true, 'status' => 'перенесены', 'log' => "Настройки:\n  " . implode( "\n  ", $log ) );
	}

	/** Джазета: выпуски, статьи, источники. Нужен активный плагин Kozlov Jazzeta (он создаёт таблицы). */
	public static function jazzeta() {
		global $wpdb;
		$d = self::data();
		$umap = get_option( 'ki_map_user', array() );
		$map_user = function ( $v ) use ( $umap ) {
			return ( is_numeric( $v ) && isset( $umap[ (int) $v ] ) ) ? (int) $umap[ (int) $v ] : $v;
		};
		$n = 0; $skipped = array();
		foreach ( $d['tables'] as $suffix => $t ) {
			$table = $wpdb->prefix . $suffix;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				$skipped[] = $suffix; continue;
			}
			$cols = $t['cols'];
			$existing = array_map( fn( $c ) => $c->Field, $wpdb->get_results( "SHOW COLUMNS FROM `$table`" ) ); // phpcs:ignore
			$use = array_values( array_intersect( $cols, $existing ) );
			if ( ! $use ) { continue; }
			foreach ( $t['rows'] as $row ) {
				$data = array();
				foreach ( $cols as $i => $c ) {
					if ( in_array( $c, $use, true ) ) {
						$data[ $c ] = in_array( $c, array( 'actor_id', 'reviewed_by', 'publisher_id', 'changed_by', 'user_id', 'created_by', 'approved_by' ), true ) ? $map_user( $row[ $i ] ) : $row[ $i ];
					}
				}
				$fields = '`' . implode( '`,`', array_keys( $data ) ) . '`';
				$ph = implode( ',', array_fill( 0, count( $data ), '%s' ) );
				// es_state: счётчики (номер выпуска, версия каталога) должны заменить те, что плагин создал при активации
				$verb = 'es_state' === $suffix ? 'REPLACE' : 'INSERT IGNORE';
				$sql = $wpdb->prepare( "$verb INTO `$table` ($fields) VALUES ($ph)", array_values( $data ) ); // phpcs:ignore
				if ( false !== $wpdb->query( $sql ) ) { $n += (int) $wpdb->rows_affected; } // phpcs:ignore
			}
		}
		if ( count( $skipped ) === count( $d['tables'] ) ) {
			return array( 'done' => true, 'status' => 'плагин Джазеты не установлен', 'log' => 'Джазета: таблиц нет. Установите и активируйте Kozlov Jazzeta, затем запустите шаг ещё раз.' );
		}
		$log = array( "добавлено строк $n" . ( $skipped ? ', нет таблиц: ' . implode( ', ', $skipped ) : '' ) );
		// режим газеты (публичная) и издатель, как на старом сайте
		$o = $d['options'];
		foreach ( array( 'es_runtime', 'es_security' ) as $k ) {
			$v = maybe_unserialize( $o[ $k ] ?? '' );
			if ( is_array( $v ) ) {
				foreach ( array( 'changed_by', 'publisher_id' ) as $uk ) {
					if ( isset( $v[ $uk ] ) ) { $v[ $uk ] = (int) $map_user( $v[ $uk ] ); }
				}
				update_option( $k, $v, false );
			}
		}
		$log[] = 'режим газеты: ' . ( maybe_unserialize( $o['es_runtime'] ?? '' )['mode'] ?? '?' );
		// поисковый индекс газеты: производные данные, пересобираются плагином
		if ( class_exists( '\\Kozlov\\Gazeta\\Data\\Search' ) && method_exists( '\\Kozlov\\Gazeta\\Data\\Search', 'ensureFresh' ) ) {
			try { \Kozlov\Gazeta\Data\Search::ensureFresh( true ); $log[] = 'поисковый индекс пересобран'; } catch ( \Throwable $e ) { $log[] = 'индекс не пересобран: ' . $e->getMessage(); }
		}
		return array( 'done' => true, 'status' => "добавлено строк: $n", 'log' => 'Джазета: ' . implode( '; ', $log ) . '.' );
	}
}
