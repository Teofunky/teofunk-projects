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

	/**
	 * 6в. Все выпуски Джазеты с kozlovclub.ru (поля из админки старого сайта, 04.11.2026): 21.10–01.11 опубликованы, 02.11 черновик.
	 * Выпуск, который уже есть, переписывается тем, что было на старом сайте (опубликованный сначала снимается, потом публикуется снова с тем же номером).
	 * Один выпуск за запуск шага: offset = номер в списке.
	 */
	public static function jazzeta_live( $offset ) {
		global $wpdb;
		if ( ! class_exists( '\Kozlov\Gazeta\Data\Issues' ) || ! class_exists( '\Kozlov\Gazeta\Admin\ArticleForm' ) ) {
			return array( 'done' => true, 'status' => 'плагин Джазеты не активен', 'log' => 'Джазета: активируйте Kozlov Jazzeta.' );
		}
		$list = json_decode( (string) file_get_contents( KI_DIR . 'data/jazzeta-live.json' ), true );
		$i    = (int) $offset;
		if ( ! is_array( $list ) || $i >= count( $list ) ) {
			$max = (int) $wpdb->get_var( "SELECT MAX(number) FROM `{$wpdb->prefix}es_issues` WHERE state = 'published'" ); // phpcs:ignore
			$wpdb->query( $wpdb->prepare( "REPLACE INTO `{$wpdb->prefix}es_state` (name, value) VALUES ('number', %d)", $max ) ); // phpcs:ignore
			if ( class_exists( '\Kozlov\Gazeta\Data\Search' ) ) { try { \Kozlov\Gazeta\Data\Search::ensureFresh( true ); } catch ( \Throwable $e ) { /* не критично */ } }
			return array( 'done' => true, 'offset' => $i, 'status' => "готово, последний номер № $max", 'log' => "Джазета: готово, последний опубликованный № $max." );
		}
		$I   = '\Kozlov\Gazeta\Data\Issues';
		$sec = get_option( 'es_security', array() );
		$sec = is_array( $sec ) ? $sec : array();
		if ( (int) ( $sec['publisher_id'] ?? 0 ) !== get_current_user_id() && current_user_can( 'manage_options' ) ) {
			$sec['publisher_id'] = get_current_user_id();
			update_option( 'es_security', $sec, false );
		}
		$it   = $list[ $i ];
		$date = $it['date'];
		$log  = array();
		$fail = function ( $msg ) use ( $i, $list, $date ) {
			return array( 'done' => $i + 1 >= count( $list ), 'offset' => $i + 1, 'status' => "$date: $msg", 'log' => "Джазета $date: $msg" );
		};
		$row = $I::findByDate( $date );
		if ( ! $row ) {
			$id = $I::create( $date );
			if ( is_wp_error( $id ) ) { return $fail( 'не создан: ' . $id->get_error_message() ); }
			$row = $I::find( (int) $id );
			$log[] = 'создан';
		}
		$id  = (int) $row['id'];
		$ver = (int) $row['version'];
		if ( 'published' === $row['state'] ) {
			$r = $I::withdraw( $id, $ver );
			if ( is_wp_error( $r ) ) { return $fail( 'не снят для обновления: ' . $r->get_error_message() ); }
			$ver++;
			$log[] = 'снят для обновления';
		}
		// материалы, которых не было на старом сайте, убираются
		$want = array();
		foreach ( $it['articles'] as $pairs ) {
			foreach ( $pairs as $p ) { if ( 'article[id]' === $p[0] ) { $want[] = $p[1]; } }
		}
		$doc = $I::document( $I::find( $id ) );
		foreach ( $doc['articles'] as $a ) {
			if ( ! in_array( $a['id'], $want, true ) ) {
				$r = $I::saveArticle( $id, $ver, array( 'id' => $a['id'] ), false, '', '', null, true );
				if ( ! is_wp_error( $r ) ) { $ver++; $log[] = 'убран ' . $a['id']; }
			}
		}
		$up = wp_upload_dir();
		foreach ( $it['articles'] as $pairs ) {
			$post = self::form( $pairs );
			$in   = isset( $post['article'] ) && is_array( $post['article'] ) ? $post['article'] : array();
			// фото материала: файл старого сайта переносится к нам
			$img = (string) ( $in['media_rights']['image_url'] ?? '' );
			if ( $img && preg_match( '#^https?://(www\.)?kozlovclub\.ru/wp-content/uploads/(.+)$#', $img, $m ) ) {
				$rel = rawurldecode( $m[2] );
				$aid = KI_Media::register( $rel, 0, '' );
				if ( $aid ) {
					KI_Media::fetch_now( $aid ); // файл ложится к нам по тому же пути; адрес остаётся адресом kozlovclub.ru (после переезда он наш)
				}
			}
			try {
				$article = \Kozlov\Gazeta\Admin\ArticleForm::fromPost( $in, (string) ( $post['body'] ?? '' ), (string) ( $post['editorial_notes'] ?? '' ), (string) ( $post['previous_article_ids'] ?? '' ), (string) ( $post['new_fact'] ?? '' ) );
			} catch ( \Throwable $e ) {
				$log[] = 'материал не разобран: ' . $e->getMessage(); continue;
			}
			$social = null;
			if ( '1' === (string) ( $post['social_enabled'] ?? '' ) ) {
				$social = array( 'article_id' => $article['id'], 'telegram_sentence' => (string) ( $post['telegram_sentence'] ?? '' ), 'instagram_sentence' => (string) ( $post['instagram_sentence'] ?? '' ), 'visual_format' => '1080x1350' );
			}
			$meta = array( 'brief' => array_intersect_key( (array) ( $post['brief'] ?? array() ), array_flip( array( 'why', 'context', 'next' ) ) ), 'facts_visible' => '1' === (string) ( $post['facts_visible'] ?? '' ) );
			if ( isset( $post['focus'] ) && is_array( $post['focus'] ) ) {
				$meta['focus'] = array( 'x' => (int) ( $post['focus']['x'] ?? 50 ), 'y' => (int) ( $post['focus']['y'] ?? 50 ) );
			}
			if ( '1' === (string) ( $post['tags_form'] ?? '' ) ) {
				$meta['tags'] = '1' === (string) ( $post['tags_manual'] ?? '' ) ? array_values( array_map( 'strval', (array) ( $post['tags'] ?? array() ) ) ) : null;
			}
			$r = $I::saveArticle( $id, $ver, $article, false, (string) ( $post['review_note'] ?? '' ), (string) ( $post['new_fact'] ?? '' ), $social, false, $meta );
			if ( is_wp_error( $r ) ) { $log[] = $article['id'] . ': ' . $r->get_error_message(); continue; }
			$ver++;
		}
		// выпуск: порядок, обложка, служебные поля
		$f   = self::form( $it['issue'] );
		$cur = $I::document( $I::find( $id ) );
		$ed  = $cur['editorial'];
		$ed['ready_for_human_review'] = '1' === (string) ( $f['ready'] ?? '' );
		$lines = function ( $v ) { return array_values( array_filter( array_map( 'trim', preg_split( '/\R/u', (string) $v ) ?: array() ), 'strlen' ) ); };
		$ed['blocking_issues']  = $lines( $f['blocking_issues'] ?? '' );
		$ed['generation_notes'] = $lines( $f['generation_notes'] ?? '' );
		foreach ( array( 'scan_start' => 'source_scan_window_start', 'scan_end' => 'source_scan_window_end' ) as $k => $key ) {
			$v = trim( (string) ( $f[ $k ] ?? '' ) );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/', $v ) ) {
				$ed[ $key ] = ( new \DateTimeImmutable( $v, new \DateTimeZone( 'Europe/Moscow' ) ) )->format( 'c' );
			}
		}
		$order = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $f['order'] ?? '' ) ) ) ) );
		$kind  = (string) ( $f['cover_kind'] ?? '' );
		$cover = null;
		if ( 'plate' === $kind ) { $cover = array( 'kind' => 'plate', 'plate' => (string) ( $f['cover_plate'] ?? '' ) ); }
		elseif ( in_array( $kind, array( 'lead', 'none' ), true ) ) { $cover = array( 'kind' => $kind ); }
		elseif ( 'photo' === $kind ) { $cover = array( 'kind' => 'lead' ); $log[] = 'обложка-фото заменена на главный материал'; }
		$r = $I::saveIssue( $id, $ver, $ed, $order, $cover );
		if ( is_wp_error( $r ) ) { $log[] = 'выпуск: ' . $r->get_error_message(); } else { $ver++; }
		// кроссворд: как на старом сайте (есть только у 23.10 и 30.10)
		$cwf = KI_DIR . 'data/jazzeta-cw/' . $date . '.json';
		$has = \Kozlov\Gazeta\Data\Meta::forIssue( $id, 'draft' );
		if ( file_exists( $cwf ) && class_exists( '\Kozlov\Gazeta\Domain\Crossword' ) ) {
			$parsed = \Kozlov\Gazeta\Domain\Crossword::parse( (string) file_get_contents( $cwf ) );
			$built  = is_wp_error( $parsed ) ? $parsed : \Kozlov\Gazeta\Domain\Crossword::assemble( $parsed, (int) wp_rand( 1, 999999 ), '' );
			$r      = is_wp_error( $built ) ? $built : $I::saveCrossword( $id, $ver, $built );
			if ( is_wp_error( $r ) ) { $log[] = 'кроссворд: ' . $r->get_error_message(); } else { $ver++; $log[] = 'кроссворд «' . ( $parsed['title'] ?? '' ) . '»'; }
		} elseif ( ! empty( $has['issue'][ $id ]['crossword'] ) || ! empty( $has['crossword'] ) ) {
			$r = $I::saveCrossword( $id, $ver, null );
			if ( ! is_wp_error( $r ) ) { $ver++; $log[] = 'лишний кроссворд убран'; }
		}
		if ( 'published' === $it['state'] ) {
			foreach ( $I::articleRows( $id ) as $arow ) {
				$a = \Kozlov\Gazeta\Data\Db::decode( $arow['draft_json'] );
				$wpdb->update( $wpdb->prefix . 'es_articles', array( 'reviewed_hash' => $I::articleHash( $a ), 'reviewed_by' => get_current_user_id(), 'reviewed_at' => \Kozlov\Gazeta\Data\Db::now(), 'review_note' => 'Выпуск перенесён с kozlovclub.ru, где был проверен и опубликован.' ), array( 'id' => (int) $arow['id'] ) ); // phpcs:ignore
			}
			// фото материалов записаны адресами kozlovclub.ru: на время проверки и публикации сайт считает этот домен своим
			$host  = wp_parse_url( home_url(), PHP_URL_HOST );
			$swap  = function ( $url ) use ( $host ) { return is_string( $url ) ? preg_replace( '#^https?://' . preg_quote( (string) $host, '#' ) . '#', 'https://kozlovclub.ru', $url ) : $url; };
			$updir = function ( $d ) use ( $swap ) { $d['baseurl'] = $swap( $d['baseurl'] ); $d['url'] = $swap( $d['url'] ); return $d; };
			if ( 'kozlovclub.ru' !== $host ) { add_filter( 'home_url', $swap, 99 ); add_filter( 'upload_dir', $updir, 99 ); }
			$fresh = $I::find( $id );
			$blk   = $I::blockers( $fresh );
			if ( $blk ) {
				$log[] = 'не опубликован: ' . implode( ' ', array_slice( $blk, 0, 3 ) );
			} else {
				if ( ! (int) $fresh['number'] ) {
					$wpdb->query( $wpdb->prepare( "REPLACE INTO `{$wpdb->prefix}es_state` (name, value) VALUES ('number', %d)", (int) $it['number'] - 1 ) ); // phpcs:ignore
				}
				\Kozlov\Gazeta\Data\Permit::grant( 'publish', array( $id, (int) $fresh['version'] ) );
				try { $pub = $I::publish( $id, (int) $fresh['version'] ); } finally { \Kozlov\Gazeta\Data\Permit::revoke( 'publish' ); }
				if ( is_wp_error( $pub ) ) { $log[] = 'публикация: ' . $pub->get_error_message(); }
				else {
					$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT number FROM `{$wpdb->prefix}es_issues` WHERE id = %d", $id ) ); // phpcs:ignore
					$log[] = 'опубликован № ' . str_pad( (string) $n, 3, '0', STR_PAD_LEFT );
					if ( class_exists( '\Kozlov\Gazeta\Runtime\Cache' ) ) { try { \Kozlov\Gazeta\Runtime\Cache::purgeIssue( $id ); } catch ( \Throwable $e ) { /* не критично */ } }
				}
			}
			remove_filter( 'home_url', $swap, 99 ); remove_filter( 'upload_dir', $updir, 99 );
		} else {
			$log[] = 'оставлен черновиком, как на старом сайте';
		}
		return array( 'done' => false, 'offset' => $i + 1, 'status' => "$date: " . implode( ', ', $log ), 'log' => "Джазета $date: " . implode( '; ', $log ) );
	}

	/** Пары [имя, значение] из формы в массив, как $_POST. */
	private static function form( $pairs ) {
		$q = array();
		foreach ( $pairs as $p ) {
			$q[] = rawurlencode( $p[0] ) . '=' . rawurlencode( (string) $p[1] );
		}
		$out = array();
		parse_str( implode( '&', $q ), $out );
		return $out;
	}
}
