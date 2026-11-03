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

	/** Счётчик номеров Джазеты: номер последнего опубликованного выпуска. */
	public static function jazzeta_number( $n ) {
		global $wpdb;
		$table = $wpdb->prefix . 'es_state';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array( 'done' => true, 'status' => 'таблицы Джазеты нет', 'log' => 'Джазета: плагин не установлен.' );
		}
		$n = max( 0, (int) $n );
		$wpdb->query( $wpdb->prepare( "REPLACE INTO `$table` (name, value) VALUES ('number', %d)", $n ) ); // phpcs:ignore
		return array( 'done' => true, 'status' => "последний номер: $n", 'log' => "Джазета: счётчик номеров установлен на $n, следующий выпуск получит № " . ( $n + 1 ) . '.' );
	}

	/**
	 * Восстановление подшивки Джазеты из файлов data/jazzeta/ГГГГ-ММ-ДД.json: по датам, с прежними номерами
	 * (№ = дням с 21.10.2026 + 1), с кроссвордом из crossword-ГГГГ-ММ-ДД.json, с публикацией от имени владельца.
	 */
	public static function jazzeta_restore() {
		global $wpdb;
		if ( ! class_exists( '\Kozlov\Gazeta\Data\Issues' ) ) {
			return array( 'done' => true, 'status' => 'плагин Джазеты не активен', 'log' => 'Джазета: активируйте Kozlov Jazzeta.' );
		}
		$dir   = KI_DIR . 'data/jazzeta/';
		$files = glob( $dir . '2026-*.json' );
		sort( $files );
		$table = $wpdb->prefix . 'es_issues';
		$state = $wpdb->prefix . 'es_state';
		$log   = array();
		$owner = class_exists( '\Kozlov\Gazeta\Runtime\Mode' ) && \Kozlov\Gazeta\Runtime\Mode::isOwner();
		if ( ! $owner && current_user_can( 'manage_options' ) ) {
			// на новом сайте издатель — тот, кто запускает восстановление (администратор)
			$sec = get_option( 'es_security', array() );
			$sec = is_array( $sec ) ? $sec : array();
			$log[] = 'издатель был id ' . (int) ( $sec['publisher_id'] ?? 0 ) . ', назначен вы (id ' . get_current_user_id() . ')';
			$sec['publisher_id'] = get_current_user_id();
			update_option( 'es_security', $sec, false );
			$owner = \Kozlov\Gazeta\Runtime\Mode::isOwner();
		}
		if ( ! $owner ) {
			$log[] = 'Вы не издатель газеты (es_security.publisher_id): выпуски будут созданы черновиками без публикации.';
		}
		foreach ( $files as $file ) {
			$date = basename( $file, '.json' );
			$num  = (int) floor( ( strtotime( $date ) - strtotime( '2026-10-21' ) ) / DAY_IN_SECONDS ) + 1;
			$row  = $wpdb->get_row( $wpdb->prepare( "SELECT id, state, number, version FROM `$table` WHERE edition_date = %s", $date ), ARRAY_A ); // phpcs:ignore
			if ( $row && 'published' === $row['state'] ) {
				$log[] = "$date: уже опубликован (№ {$row['number']}), пропуск."; continue;
			}
			$raw = (string) file_get_contents( $file );
			$res = \Kozlov\Gazeta\Data\Issues::import( $raw, $row ? (int) $row['version'] : null );
			if ( is_wp_error( $res ) ) {
				$log[] = "$date: импорт отклонён: " . $res->get_error_message(); continue;
			}
			$id = (int) $res['id']; $ver = (int) $res['version'];
			$log[] = "$date: черновик id $id, версия $ver" . ( ! empty( $res['unchanged'] ) ? ' (без изменений)' : '' ) . '.';
			// кроссворд выпуска
			$cw = $dir . 'crossword-' . $date . '.json';
			if ( file_exists( $cw ) && class_exists( '\Kozlov\Gazeta\Domain\Crossword' ) ) {
				$parsed = \Kozlov\Gazeta\Domain\Crossword::parse( (string) file_get_contents( $cw ) );
				$built  = is_wp_error( $parsed ) ? $parsed : \Kozlov\Gazeta\Domain\Crossword::assemble( $parsed, (int) wp_rand( 1, 999999 ), '' );
				$saved  = is_wp_error( $built ) ? $built : \Kozlov\Gazeta\Data\Issues::saveCrossword( $id, $ver, $built );
				if ( is_wp_error( $saved ) ) {
					$log[] = "  кроссворд не принят: " . $saved->get_error_message();
				} else {
					$ver++; $log[] = '  кроссворд «' . ( $parsed['title'] ?? '' ) . '» добавлен.';
				}
			}
			if ( ! $owner ) { continue; }
			// материалы были проверены и опубликованы на старом сайте: отметка проверки восстанавливается, иначе публикация заблокирована
			$atable = $wpdb->prefix . 'es_articles';
			foreach ( \Kozlov\Gazeta\Data\Issues::articleRows( $id ) as $arow ) {
				$article = \Kozlov\Gazeta\Data\Db::decode( $arow['draft_json'] );
				$wpdb->update( $atable, array( 'reviewed_hash' => \Kozlov\Gazeta\Data\Issues::articleHash( $article ), 'reviewed_by' => get_current_user_id(), 'reviewed_at' => \Kozlov\Gazeta\Data\Db::now(), 'review_note' => 'Восстановление номера, опубликованного на kozlovclub.ru до 02.11.2026.' ), array( 'id' => (int) $arow['id'] ) ); // phpcs:ignore
			}
			$blockers = \Kozlov\Gazeta\Data\Issues::blockers( \Kozlov\Gazeta\Data\Issues::find( $id ) );
			if ( $blockers ) {
				$log[] = '  не опубликован, остался черновиком: ' . implode( ' ', array_slice( $blockers, 0, 4 ) ); continue;
			}
			// номер: счётчик на единицу меньше ожидаемого
			$wpdb->query( $wpdb->prepare( "REPLACE INTO `$state` (name, value) VALUES ('number', %d)", $num - 1 ) ); // phpcs:ignore
			\Kozlov\Gazeta\Data\Permit::grant( 'publish', array( $id, $ver ) );
			try {
				$pub = \Kozlov\Gazeta\Data\Issues::publish( $id, $ver );
			} finally {
				\Kozlov\Gazeta\Data\Permit::revoke( 'publish' );
			}
			if ( is_wp_error( $pub ) ) {
				$log[] = "  публикация не удалась: " . $pub->get_error_message();
			} else {
				$got = $wpdb->get_var( $wpdb->prepare( "SELECT number FROM `$table` WHERE id = %d", $id ) ); // phpcs:ignore
				$log[] = "  опубликован как № " . str_pad( (string) $got, 3, '0', STR_PAD_LEFT ) . '.';
				if ( class_exists( '\Kozlov\Gazeta\Runtime\Cache' ) ) { try { \Kozlov\Gazeta\Runtime\Cache::purgeIssue( $id ); } catch ( \Throwable $e ) { /* не критично */ } }
			}
		}
		// счётчик на последний опубликованный номер
		$max = (int) $wpdb->get_var( "SELECT MAX(number) FROM `$table` WHERE state = 'published'" ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( "REPLACE INTO `$state` (name, value) VALUES ('number', %d)", $max ) ); // phpcs:ignore
		if ( class_exists( '\Kozlov\Gazeta\Data\Search' ) ) { try { \Kozlov\Gazeta\Data\Search::ensureFresh( true ); } catch ( \Throwable $e ) { /* не критично */ } }
		return array( 'done' => true, 'status' => "последний номер: $max", 'log' => "Джазета, подшивка:\n  " . implode( "\n  ", $log ) );
	}
}
