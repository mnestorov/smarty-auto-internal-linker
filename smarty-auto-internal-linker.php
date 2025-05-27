<?php
/**
 * Plugin Name:             SM - Auto Internal Linker
 * Plugin URI:              https://github.com/mnestorov/smarty-auto-internal-linker
 * Description:             Automatically links predefined keywords/phrases in news articles to related posts within the site.
 * Version:                 1.0.0
 * Author:                  Martin Nestorov
 * Author URI:              https://github.com/mnestorov
 * License:                 GPL-2.0+
 * License URI:             http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:             smarty-auto-internal-linker
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/* -------------------------------------------------------------------------
 * Constants
 * ---------------------------------------------------------------------- */

define( 'SMARTY_AUL_TABLE',               'smarty_aul_dictionary_keywords' );
define( 'SMARTY_AUL_CACHE_KEY',           'smarty_aul_dictionary_cache' );
define( 'SMARTY_AUL_CACHE_TTL',           DAY_IN_SECONDS );
define( 'SMARTY_AUL_MAX_LINKS_PER_POST',  3 );
define( 'SMARTY_AUL_PER_PAGE', 20 ); 
define( 'SMARTY_AUL_CRON_HOOK',           'smarty_aul_linking_old_posts' );
define( 'SMARTY_AUL_VERSION',             '1.2.1' );

/* -------------------------------------------------------------------------
 * Activation / Deactivation
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'smarty_aul_activate' ) ) {
	/**
	 * Creates DB table and schedules cron on plugin activation.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	function smarty_aul_activate() {
		global $wpdb;
		$table_name      = $wpdb->prefix . SMARTY_AUL_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			keyword VARCHAR(255) NOT NULL,
			target_url TEXT NOT NULL,
			max_per_post INT(3) UNSIGNED NOT NULL DEFAULT 1,
			rel_attribute ENUM('nofollow','dofollow') DEFAULT 'dofollow',
			PRIMARY KEY  (id),
			KEY keyword (keyword(191))
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		if ( ! wp_next_scheduled( SMARTY_AUL_CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', SMARTY_AUL_CRON_HOOK );
		}
	}
}
register_activation_hook( __FILE__, 'smarty_aul_activate' );

if ( ! function_exists( 'smarty_aul_deactivate' ) ) {
	/**
	 * Clears cron and transient cache on plugin deactivation.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	function smarty_aul_deactivate() {
		wp_clear_scheduled_hook( SMARTY_AUL_CRON_HOOK );
		delete_transient( SMARTY_AUL_CACHE_KEY );
	}
}
register_deactivation_hook( __FILE__, 'smarty_aul_deactivate' );

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'smarty_aul_get_table_name' ) ) {
	/**
	 * Returns the fully-qualified name of the keywords table.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	function smarty_aul_get_table_name() {
		global $wpdb;
		return $wpdb->prefix . SMARTY_AUL_TABLE;
	}
}

if ( ! function_exists( 'smarty_aul_register_table' ) ) {
	/**
	 * Exposes our custom table to $wpdb for safe queries.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	function smarty_aul_register_table() {
		global $wpdb;
		$wpdb->smarty_aul_keywords = smarty_aul_get_table_name();
	}
}
add_action( 'init', 'smarty_aul_register_table' );

if ( ! function_exists( 'smarty_aul_get_keyword_by_id' ) ) {
	/**
	 * Fetches one keyword row by ID.
	 *
	 * @since 1.0.0
	 * @param int $id Row ID.
	 * @return object|null
	 */
	function smarty_aul_get_keyword_by_id( $id ) {
		global $wpdb;
		$table = smarty_aul_get_table_name();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ) );
	}
}

if ( ! function_exists( 'smarty_aul_get_dictionary' ) ) {
	/**
	 * Retrieves the keyword dictionary (cached).
	 *
	 * @since 1.0.0
	 * @return array
	 */
	function smarty_aul_get_dictionary() {
		$dict = get_transient( SMARTY_AUL_CACHE_KEY );
		if ( false !== $dict ) {
			return $dict;
		}

		global $wpdb;
		$table = smarty_aul_get_table_name();
		$rows  = $wpdb->get_results( "SELECT keyword, target_url, max_per_post, rel_attribute FROM {$table}" );

		$dict = [];
		foreach ( $rows as $row ) {
			$dict[ $row->keyword ] = [
				'url' => $row->target_url,
				'max' => (int) $row->max_per_post,
				'rel' => $row->rel_attribute,
			];
		}
		set_transient( SMARTY_AUL_CACHE_KEY, $dict, SMARTY_AUL_CACHE_TTL );
		return $dict;
	}
}

if ( ! function_exists( 'smarty_aul_has_disallowed_ancestor' ) ) {
	/**
	 * Determines whether a DOMText node is inside a disallowed element.
	 *
	 * @since 1.0.0
	 * @param DOMNode $node Node to check.
	 * @param array   $tags Disallowed tag names.
	 * @return bool
	 */
	function smarty_aul_has_disallowed_ancestor( $node, $tags ) {
		while ( $node && $node->parentNode ) {
			$node = $node->parentNode;
			if ( in_array( strtolower( $node->nodeName ), $tags, true ) ) {
				return true;
			}
		}
		return false;
	}
}

if (!function_exists('smarty_aul_enqueue_admin_scripts')) {
    /**
     * Enqueue admin-specific styles and scripts for the City Autocomplete plugin.
     *
     * This function enqueues the admin CSS and JS files only for admin pages.
     * It also localizes the JS script with nonce and AJAX URL.
     *
     * @since 1.0.0
     *
     * @param string $hook The current admin page hook suffix.
     * @return void
     */
    function smarty_aul_enqueue_admin_scripts($hook) {
     
        wp_enqueue_style('smarty-aul-admin-css', plugin_dir_url(__FILE__) . 'css/smarty-aul-admin.css', array(), '1.0.0');
        wp_enqueue_script('smarty-aul-admin-js', plugin_dir_url(__FILE__) . 'js/smarty-aul-admin.js', array('jquery'), '1.0.0', true);

        wp_localize_script(
            'smarty-aul-admin-js',
            'smartyAutoInternalLinker',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('smarty_aul_nonce'),
            ]
        );
    }
    add_action('admin_enqueue_scripts', 'smarty_aul_enqueue_admin_scripts');
}

/* -------------------------------------------------------------------------
 * Front-end CSS (bold links)
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'smarty_aul_enqueue_assets' ) ) {
	/**
	 * Injects a tiny inline stylesheet that makes auto-links bold.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	function smarty_aul_enqueue_assets() {
		wp_register_style( 'smarty-aul-inline', false, [], SMARTY_AUL_VERSION );
		wp_enqueue_style( 'smarty-aul-inline' );
		wp_add_inline_style( 'smarty-aul-inline', '.smarty-aul-link{font-weight:bold;}' );
	}
}
add_action( 'wp_enqueue_scripts', 'smarty_aul_enqueue_assets' );

/* -------------------------------------------------------------------------
 * Content filter
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'smarty_aul_filter_content' ) ) {
	/**
	 * Inserts automatic internal links into post content (case-insensitive).
	 *
	 * @since 1.0.0
	 * @param string $content Original HTML.
	 * @return string Modified HTML with links.
	 */
	function smarty_aul_filter_content( $content ) {
		/* Run only on the front end of singular posts/pages */
		if ( is_admin() || ! is_singular() ) {
			return $content;
		}

		$dictionary = smarty_aul_get_dictionary();
		if ( empty( $dictionary ) ) {
			return $content;
		}

		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML(
			'<?xml encoding="' . get_bloginfo( 'charset' ) . '"?>' . $content,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);

		$xpath      = new DOMXPath( $dom );
		$text_nodes = $xpath->query( '//text()' );

		$links_added = 0;
		$per_word    = [];                                           // counts per keyword (lower-case)
		$skip_tags   = [ 'a', 'blockquote', 'h1', 'h2', 'h3',
						 'h4', 'h5', 'h6' ];

		foreach ( $text_nodes as $text_node ) {
			if ( smarty_aul_has_disallowed_ancestor( $text_node, $skip_tags ) ) {
				continue;
			}

			$node_content = $text_node->nodeValue;

			foreach ( $dictionary as $keyword => $data ) {

				/* Global cap reached? */
				if ( $links_added >= SMARTY_AUL_MAX_LINKS_PER_POST ) {
					break 2;
				}

				/* Per-word cap (case-insensitive) */
				$key_lower = strtolower( $keyword );
				if (
					isset( $per_word[ $key_lower ] )
					&& $per_word[ $key_lower ] >= $data['max']
				) {
					continue;
				}

				/* Case-insensitive, Unicode-safe regex */
				$regex = '/\b(' . preg_quote( $keyword, '/' ) . ')\b/ui';

				if ( preg_match( $regex, $node_content ) ) {

					/* Split once, keep the matched word ($parts[1]) */
					$parts = preg_split(
						$regex,
						$node_content,
						2,
						PREG_SPLIT_DELIM_CAPTURE
					);

					if ( 3 === count( $parts ) ) {
						$before = $dom->createTextNode( $parts[0] );
						$after  = $dom->createTextNode( $parts[2] );

						/* Use the encountered form (NFT / nft / Nft …) */
						$anchor = $dom->createElement( 'a', $parts[1] );
						$anchor->setAttribute( 'href', esc_url_raw( $data['url'] ) );
						$anchor->setAttribute( 'title', $keyword );
						$anchor->setAttribute( 'class', 'smarty-aul-link' );
						if ( 'nofollow' === $data['rel'] ) {
							$anchor->setAttribute( 'rel', 'nofollow' );
						}

						$parent = $text_node->parentNode;
						$parent->replaceChild( $after, $text_node );
						$parent->insertBefore( $anchor, $after );
						$parent->insertBefore( $before, $anchor );

						$links_added++;
						$per_word[ $key_lower ] = ( $per_word[ $key_lower ] ?? 0 ) + 1;
						break;   // next text node
					}
				}
			}
		}

		return $dom->saveHTML();
	}
}
add_filter( 'the_content', 'smarty_aul_filter_content', 20 );

/* -------------------------------------------------------------------------
 * Cron task
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'smarty_aul_linking_process' ) ) {
	/**
	 * Inserts internal links into older posts (hourly).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	function smarty_aul_linking_process() {
		$posts = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'date_query'     => [
				'column' => 'post_date_gmt',
				'before' => '-1 week',
			],
			'fields' => 'ids',
		] );

		foreach ( $posts as $id ) {
			$original = get_post_field( 'post_content', $id );
			$updated  = smarty_aul_filter_content( $original );
			if ( $updated !== $original ) {
				remove_filter( 'content_save_pre', 'wp_filter_post_kses' ); // Allow saving HTML we just generated.
				wp_update_post( [ 'ID' => $id, 'post_content' => $updated ] );
			}
		}
	}
}
add_action( SMARTY_AUL_CRON_HOOK, 'smarty_aul_linking_process' );

/* -------------------------------------------------------------------------
 * Admin UI
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'smarty_aul_admin_notice' ) ) {
	/**
	 * Displays an admin-notice message once.
	 *
	 * @since 1.0.0
	 * @param string $msg  Message text.
	 * @param string $type notice-info|notice-success|notice-error|notice-warning.
	 * @return void
	 */
	function smarty_aul_admin_notice( $msg, $type = 'notice-info' ) {
		add_action( 'admin_notices', function() use ( $msg, $type ) {
			printf(
				'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $type ),
				esc_html( $msg )
			);
		} );
	}
}

if ( ! function_exists( 'smarty_aul_register_admin_menu' ) ) {
	/**
	 * Registers the top-level “Auto Links” menu.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	function smarty_aul_register_admin_menu() {
		add_menu_page(
			__( 'Auto Internal Links', 'smarty-auto-internal-linker' ),
			__( 'Auto Links',         'smarty-auto-internal-linker' ),
			'manage_options',
			'smarty_aul_settings',
			'smarty_aul_settings_page',
			'dashicons-admin-links'
		);
	}
}
add_action( 'admin_menu', 'smarty_aul_register_admin_menu' );

if ( ! function_exists( 'smarty_aul_settings_page' ) ) {
	/**
	 * Renders the settings screen.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	function smarty_aul_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		$table = smarty_aul_get_table_name();

		/* ---------- Handle POST ---------- */
		if (
			isset( $_POST['smarty_aul_action'] ) &&
			check_admin_referer( 'smarty_aul_nonce' )
		) {
			$action = sanitize_text_field( $_POST['smarty_aul_action'] );
			$data   = [
				'keyword'       => sanitize_text_field( $_POST['keyword'] ),
				'target_url'    => esc_url_raw( $_POST['target_url'] ),
				'max_per_post'  => (int) $_POST['max_per_post'],
				'rel_attribute' => in_array( $_POST['rel_attribute'], [ 'nofollow', 'dofollow' ], true )
					? $_POST['rel_attribute']
					: 'dofollow',
			];

			if ( 'add' === $action ) {
				$wpdb->insert( $table, $data );
			}

			if ( 'update' === $action && isset( $_POST['id'] ) ) {
				$wpdb->update( $table, $data, [ 'id' => (int) $_POST['id'] ], [ '%s', '%s', '%d', '%s' ], [ '%d' ] );
			}

			if ( 'delete' === $action && isset( $_POST['id'] ) ) {
				$wpdb->delete( $table, [ 'id' => (int) $_POST['id'] ], [ '%d' ] );
			}

			delete_transient( SMARTY_AUL_CACHE_KEY );

			if ( $wpdb->last_error ) {
				/* SQL error → stay on page, show notice */
				smarty_aul_admin_notice(
					__( 'Database error: ', 'smarty-auto-internal-linker' ) . $wpdb->last_error,
					'notice-error'
				);
			} else {
				/* Success → redirect to avoid resubmission */
				$redirect = admin_url( 'admin.php?page=smarty_aul_settings' );
				if ( ! headers_sent() ) {
					wp_safe_redirect( $redirect );
					exit;
				}
				/* Fallback when headers already sent */
				echo '<script>location.href="' . esc_url_raw( $redirect ) . '";</script>';
				exit;
			}
		}

		/* ---------- Edit mode ---------- */
		$editing     = false;
		$edit_record = null;
		if ( isset( $_GET['edit'] ) ) {
			$edit_record = smarty_aul_get_keyword_by_id( (int) $_GET['edit'] );
			$editing     = (bool) $edit_record;
		}

		/* ---------- Pagination setup ---------- */
		$per_page = SMARTY_AUL_PER_PAGE;
		$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$offset   = ( $paged - 1 ) * $per_page;

		$total_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$total_pages = max( 1, ceil( $total_items / $per_page ) );

		$keywords = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Automatic Internal Linking | Settings', 'smarty-auto-internal-linker' ); ?></h1>
			<div id="smarty-aul-settings-container">
				<div>
					<h2>
						<?php
						echo $editing
							? esc_html__( 'Edit Keyword', 'smarty-auto-internal-linker' )
							: esc_html__( 'Add Keyword',  'smarty-auto-internal-linker' );
						?>
					</h2>

					<form id="smarty-aul-form" method="post">
						<?php wp_nonce_field( 'smarty_aul_nonce' ); ?>
						<input type="hidden" name="smarty_aul_action" value="<?php echo $editing ? 'update' : 'add'; ?>">
						<?php if ( $editing ) : ?>
							<input type="hidden" name="id" value="<?php echo (int) $edit_record->id; ?>">
						<?php endif; ?>

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">
									<label for="keyword"><?php esc_html_e( 'Keyword / Phrase', 'smarty-auto-internal-linker' ); ?></label>
								</th>
								<td>
									<input name="keyword" type="text" id="keyword" value="<?php echo esc_attr( $editing ? $edit_record->keyword : '' ); ?>" class="regular-text" required>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="target_url"><?php esc_html_e( 'Target URL', 'smarty-auto-internal-linker' ); ?></label>
								</th>
								<td>
									<input name="target_url" type="url" id="target_url" value="<?php echo esc_url( $editing ? $edit_record->target_url : '' ); ?>" class="regular-text" required>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="max_per_post"><?php esc_html_e( 'Max links per post', 'smarty-auto-internal-linker' ); ?></label>
								</th>
								<td>
									<input name="max_per_post" type="number" id="max_per_post" min="1" max="3" value="<?php echo esc_attr( $editing ? (int) $edit_record->max_per_post : 1 ); ?>" required>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="rel_attribute"><?php esc_html_e( 'Rel attribute', 'smarty-auto-internal-linker' ); ?></label>
								</th>
								<td>
									<select name="rel_attribute" id="rel_attribute">
										<option value="dofollow" <?php selected( $editing ? $edit_record->rel_attribute : 'dofollow', 'dofollow' ); ?>>dofollow</option>
										<option value="nofollow" <?php selected( $editing ? $edit_record->rel_attribute : '', 'nofollow' ); ?>>nofollow</option>
									</select>
								</td>
							</tr>
						</table>

						<?php
						submit_button(
							$editing
								? __( 'Update Keyword', 'smarty-auto-internal-linker' )
								: __( 'Save Keyword',   'smarty-auto-internal-linker' )
						);
						if ( $editing ) {
							echo '&nbsp;<a href="' . esc_url( admin_url( 'admin.php?page=smarty_aul_settings' ) ) . '" class="button-secondary">' . esc_html__( 'Cancel', 'smarty-auto-internal-linker' ) . '</a>';
						}
						?>
					</form>
					<hr>
					
					<h2><?php esc_html_e( 'Existing Keywords', 'smarty-auto-internal-linker' ); ?></h2>
					<table id="smarty-aul-keywords" class="widefat">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Keyword', 'smarty-auto-internal-linker' ); ?></th>
								<th><?php esc_html_e( 'Target URL', 'smarty-auto-internal-linker' ); ?></th>
								<th><?php esc_html_e( 'Max/Post', 'smarty-auto-internal-linker' ); ?></th>
								<th><?php esc_html_e( 'Rel', 'smarty-auto-internal-linker' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'smarty-auto-internal-linker' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $keywords as $kw ) : ?>
								<tr>
									<td><?php echo esc_html( $kw->keyword ); ?></td>
									<td>
										<a href="<?php echo esc_url( $kw->target_url ); ?>" target="_blank" rel="noopener noreferrer">
											<?php echo esc_url( $kw->target_url ); ?>
										</a>
									</td>
									<td><?php echo (int) $kw->max_per_post; ?></td>
									<td><?php echo esc_html( $kw->rel_attribute ); ?></td>
									<td style="white-space:nowrap;">
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=smarty_aul_settings&edit=' . $kw->id ) ); ?>" class="button">
											<?php esc_html_e( 'Edit', 'smarty-auto-internal-linker' ); ?>
										</a>

										<form method="post" style="display:inline;">
											<?php wp_nonce_field( 'smarty_aul_nonce' ); ?>
											<input type="hidden" name="smarty_aul_action" value="delete">
											<input type="hidden" name="id" value="<?php echo (int) $kw->id; ?>">
											<?php
											submit_button(
												__( 'Delete', 'smarty-auto-internal-linker' ),
												'delete',
												'',
												false,
												[ 'onclick' => 'return confirm("Are you sure?");' ]
											);
											?>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<?php
					/* ---------- Pagination links ---------- */
					if ( $total_pages > 1 ) :
						$page_links = paginate_links( [
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
							'total'     => $total_pages,
							'current'   => $paged,
							'type'      => 'array',
						] );

						if ( $page_links ) :
							echo '<div id="smarty-aul-pagination"><span class="pagination-links">';
							echo join( ' ', $page_links );
							echo '</span></div>';
						endif;
					endif;
					?>
				</div>
				<div id="smarty-aul-tabs-container">
					<div>
						<h2 class="smarty-aul-nav-tab-wrapper">
							<a href="#smarty-aul-documentation" class="smarty-aul-nav-tab smarty-aul-nav-tab-active"><?php esc_html_e('Documentation', 'smarty-auto-internal-linker'); ?></a>
							<a href="#smarty-aul-changelog" class="smarty-aul-nav-tab"><?php esc_html_e('Changelog', 'smarty-auto-internal-linker'); ?></a>
						</h2>
						<div id="smarty-aul-documentation" class="smarty-aul-tab-content active">
							<div class="smarty-aul-view-more-container">
								<p><?php esc_html_e('Click "View More" to load the plugin documentation.', 'smarty-auto-internal-linker'); ?></p>
								<button id="smarty-aul-load-readme-btn" class="button button-primary">
									<?php esc_html_e('View More', 'smarty-auto-internal-linker'); ?>
								</button>
							</div>
							<div id="smarty-aul-readme-content" style="margin-top: 20px;"></div>
						</div>
						<div id="smarty-aul-changelog" class="smarty-aul-tab-content">
							<div class="smarty-aul-view-more-container">
								<p><?php esc_html_e('Click "View More" to load the plugin changelog.', 'smarty-auto-internal-linker'); ?></p>
								<button id="smarty-aul-load-changelog-btn" class="button button-primary">
									<?php esc_html_e('View More', 'smarty-auto-internal-linker'); ?>
								</button>
							</div>
							<div id="smarty-aul-changelog-content" style="margin-top: 20px;"></div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}

if ( ! function_exists('smarty_aul_load_readme' ) ) {
    /**
     * AJAX handler to load and parse the README.md content.
     */
    function smarty_aul_load_readme() {
        check_ajax_referer('smarty_aul_nonce', 'nonce');
    
        if (!current_user_can('manage_options')) {
            wp_send_json_error('You do not have sufficient permissions.');
        }
    
        $readme_path = plugin_dir_path(__FILE__) . 'README.md';
        if (file_exists($readme_path)) {
            // Include Parsedown library
            if (!class_exists('Parsedown')) {
                require_once plugin_dir_path(__FILE__) . 'libs/Parsedown.php';
            }
    
            $parsedown = new Parsedown();
            $markdown_content = file_get_contents($readme_path);
            $html_content = $parsedown->text($markdown_content);
    
            // Remove <img> tags from the content
            $html_content = preg_replace('/<img[^>]*>/', '', $html_content);
    
            wp_send_json_success($html_content);
        } else {
            wp_send_json_error('README.md file not found.');
        }
    }    
}
add_action( 'wp_ajax_smarty_aul_load_readme', 'smarty_aul_load_readme' );

if ( ! function_exists( 'smarty_aul_load_changelog' ) ) {
    /**
     * AJAX handler to load and parse the CHANGELOG.md content.
     */
    function smarty_aul_load_changelog() {
        check_ajax_referer('smarty_aul_nonce', 'nonce');
    
        if (!current_user_can('manage_options')) {
            wp_send_json_error('You do not have sufficient permissions.');
        }
    
        $changelog_path = plugin_dir_path(__FILE__) . 'CHANGELOG.md';
        if (file_exists($changelog_path)) {
            if (!class_exists('Parsedown')) {
                require_once plugin_dir_path(__FILE__) . 'libs/Parsedown.php';
            }
    
            $parsedown = new Parsedown();
            $markdown_content = file_get_contents($changelog_path);
            $html_content = $parsedown->text($markdown_content);
    
            wp_send_json_success($html_content);
        } else {
            wp_send_json_error('CHANGELOG.md file not found.');
        }
    }
}
add_action( 'wp_ajax_smarty_aul_load_changelog', 'smarty_aul_load_changelog' );