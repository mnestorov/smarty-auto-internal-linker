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
if (!defined('WPINC')) {
	die;
}

/* -------------------------------------------------------------------------
 * Constants
 * ---------------------------------------------------------------------- */

define('SMARTY_AUL_TABLE', 'cail_keywords');
define('SMARTY_AUL_CACHE_KEY', 'smarty_aul_dictionary_cache');
define('SMARTY_AUL_CACHE_TTL', DAY_IN_SECONDS);
define('SMARTY_AUL_MAX_LINKS_PER_POST', 3);
define('SMARTY_AUL_CRON_HOOK', 'smarty_aul_process_old_posts');

/* -------------------------------------------------------------------------
 * Activation / Deactivation Hooks
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'smarty_aul_activate' ) ) {
    /**
     * Creates DB table and schedules cron on plugin activation.
     *
     * @since 1.1.0
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
     * Clears cron and caches on plugin deactivation.
     *
     * @since 1.1.0
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
     * Returns the fully‑qualified keywords table name.
     *
     * @since 1.1.0
     * @return string
     */
    function smarty_aul_get_table_name() {
        global $wpdb;
        return $wpdb->prefix . SMARTY_AUL_TABLE;
    }
}

if ( ! function_exists( 'smarty_aul_register_table' ) ) {
    /**
     * Exposes custom table to $wpdb for safe queries.
     *
     * @since 1.1.0
     * @return void
     */
    function smarty_aul_register_table() {
        global $wpdb;
        $wpdb->smarty_aul_keywords = smarty_aul_get_table_name();
    }
}
add_action( 'init', 'smarty_aul_register_table' );

if ( ! function_exists( 'smarty_aul_get_dictionary' ) ) {
    /**
     * Retrieves the keyword dictionary from cache or database.
     * Array structure: [ 'Keyword' => [ 'url' => '...', 'max' => 1, 'rel' => 'nofollow' ] ]
     *
     * @since 1.1.0
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
     * Determines whether a DOMText node is inside a disallowed ancestor (heading, blockquote, link).
     *
     * @since 1.1.0
     * @param DOMNode $node The text node.
     * @param array   $disallowed Tag names to skip.
     * @return bool
     */
    function smarty_aul_has_disallowed_ancestor( $node, $disallowed ) {
        while ( $node && $node->parentNode ) {
            $node = $node->parentNode;
            if ( in_array( strtolower( $node->nodeName ), $disallowed, true ) ) {
                return true;
            }
        }
        return false;
    }
}

/* -------------------------------------------------------------------------
 * Content Filter
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'smarty_aul_filter_content' ) ) {
    /**
     * Filters post content, inserting automatic internal links.
     *
     * @since 1.1.0
     * @param string $content Original post content.
     * @return string Modified content.
     */
    function smarty_aul_filter_content( $content ) {
        if ( is_admin() || ! is_singular() ) {
            return $content;
        }

        $dictionary = smarty_aul_get_dictionary();
        if ( empty( $dictionary ) ) {
            return $content;
        }

        libxml_use_internal_errors( true );
        $encoding = get_bloginfo( 'charset' );
        $dom      = new DOMDocument();
        $dom->loadHTML( '<?xml encoding="' . $encoding . '"?>' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

        $xpath   = new DOMXPath( $dom );
        $text_nodes = $xpath->query( '//text()' );

        $links_added    = 0;
        $links_per_word = [];
        $disallowed     = [ 'a', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ];

        foreach ( $text_nodes as $text_node ) {
            if ( smarty_aul_has_disallowed_ancestor( $text_node, $disallowed ) ) {
                continue;
            }

            $node_content = $text_node->nodeValue;
            foreach ( $dictionary as $keyword => $meta ) {
                if ( $links_added >= SMARTY_AUL_MAX_LINKS_PER_POST ) {
                    break 2; // Reached global limit
                }

                if ( isset( $links_per_word[ $keyword ] ) && $links_per_word[ $keyword ] >= $meta['max'] ) {
                    continue;
                }

                $escaped = preg_quote( $keyword, '/' );
                $regex   = '/\b(' . $escaped . ')\b/u';

                if ( preg_match( $regex, $node_content ) ) {
                    $parts = preg_split( $regex, $node_content, 2, PREG_SPLIT_DELIM_CAPTURE );
                    if ( count( $parts ) === 3 ) {
                        $before = $dom->createTextNode( $parts[0] );
                        $after  = $dom->createTextNode( $parts[2] );
                        $anchor = $dom->createElement( 'a', $keyword );
                        $anchor->setAttribute( 'href', esc_url_raw( $meta['url'] ) );
                        $anchor->setAttribute( 'title', $keyword );
                        if ( 'nofollow' === $meta['rel'] ) {
                            $anchor->setAttribute( 'rel', 'nofollow' );
                        }

                        $parent = $text_node->parentNode;
                        $parent->replaceChild( $after, $text_node );
                        $parent->insertBefore( $anchor, $after );
                        $parent->insertBefore( $before, $anchor );

                        $links_added++;
                        $links_per_word[ $keyword ] = ( $links_per_word[ $keyword ] ?? 0 ) + 1;
                        break; // Move to next text node.
                    }
                }
            }
        }

        return $dom->saveHTML();
    }
}
add_filter( 'the_content', 'smarty_aul_filter_content', 20 );

/* -------------------------------------------------------------------------
 * Cron Job
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'smarty_aul_cron_process' ) ) {
    /**
     * Processes older posts in batches, injecting links based on new keywords.
     *
     * @since 1.1.0
     * @return void
     */
    function smarty_aul_cron_process() {
        $args = [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'date_query'     => [
                'column' => 'post_date_gmt',
                'before' => '-1 week',
            ],
            'fields'         => 'ids',
        ];

        $posts = get_posts( $args );
        foreach ( $posts as $post_id ) {
            $content = get_post_field( 'post_content', $post_id );
            $updated = smarty_aul_filter_content( $content );
            if ( $updated !== $content ) {
                remove_filter( 'content_save_pre', 'wp_filter_post_kses' );
                wp_update_post( [ 'ID' => $post_id, 'post_content' => $updated ] );
            }
        }
    }
}
add_action( SMARTY_AUL_CRON_HOOK, 'smarty_aul_cron_process' );

/* -------------------------------------------------------------------------
 * Admin UI
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'smarty_aul_register_admin_menu' ) ) {
    /**
     * Registers admin menu entry.
     *
     * @since 1.1.0
     * @return void
     */
    function smarty_aul_register_admin_menu() {
        add_menu_page(
            __( 'Auto Internal Links', 'smarty-auto-internal-linker' ),
            __( 'Auto Links', 'smarty-auto-internal-linker' ),
            'manage_options',
            'smarty_aul_admin',
            'smarty_aul_admin_page',
            'dashicons-admin-links'
        );
    }
}
add_action( 'admin_menu', 'smarty_aul_register_admin_menu' );

if ( ! function_exists( 'smarty_aul_admin_page' ) ) {
    /**
     * Renders the admin page for managing keywords.
     *
     * @since 1.1.0
     * @return void
     */
    function smarty_aul_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        global $wpdb;
        $table = smarty_aul_get_table_name();

        // Handle form submissions.
        if ( isset( $_POST['smarty_aul_action'] ) && check_admin_referer( 'smarty_aul_nonce' ) ) {
            $action = sanitize_text_field( $_POST['smarty_aul_action'] );

            if ( 'add' === $action ) {
                $wpdb->insert( $table, [
                    'keyword'       => sanitize_text_field( $_POST['keyword'] ),
                    'target_url'    => esc_url_raw( $_POST['target_url'] ),
                    'max_per_post'  => (int) $_POST['max_per_post'],
                    'rel_attribute' => in_array( $_POST['rel_attribute'], [ 'nofollow', 'dofollow' ], true ) ? $_POST['rel_attribute'] : 'dofollow',
                ] );
                delete_transient( SMARTY_AUL_CACHE_KEY );
            }

            if ( 'delete' === $action ) {
                $wpdb->delete( $table, [ 'id' => (int) $_POST['id'] ] );
                delete_transient( SMARTY_AUL_CACHE_KEY );
            }
        }

        $keywords = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC" );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Automatic Internal Linking', 'smarty-auto-internal-linker' ); ?></h1>

            <h2><?php esc_html_e( 'Add New Keyword', 'smarty-auto-internal-linker' ); ?></h2>
            <form method="post">
                <?php wp_nonce_field( 'smarty_aul_nonce' ); ?>
                <input type="hidden" name="smarty_aul_action" value="add" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="keyword"><?php esc_html_e( 'Keyword / Phrase', 'smarty-auto-internal-linker' ); ?></label></th>
                        <td><input name="keyword" type="text" id="keyword" value="" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="target_url"><?php esc_html_e( 'Target URL', 'smarty-auto-internal-linker' ); ?></label></th>
                        <td><input name="target_url" type="url" id="target_url" value="" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="max_per_post"><?php esc_html_e( 'Max links per post', 'smarty-auto-internal-linker' ); ?></label></th>
                        <td><input name="max_per_post" type="number" min="1" max="3" id="max_per_post" value="1" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rel_attribute"><?php esc_html_e( 'Rel attribute', 'smarty-auto-internal-linker' ); ?></label></th>
                        <td>
                            <select name="rel_attribute" id="rel_attribute">
                                <option value="dofollow">dofollow</option>
                                <option value="nofollow">nofollow</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Save Keyword', 'smarty-auto-internal-linker' ) ); ?>
            </form>

            <hr />

            <h2><?php esc_html_e( 'Existing Keywords', 'smarty-auto-internal-linker' ); ?></h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Keyword', 'smarty-auto-internal-linker' ); ?></th>
                        <th><?php esc_html_e( 'Target URL', 'smarty-auto-internal-linker' ); ?></th>
                        <th><?php esc_html_e( 'Max/Post', 'smarty-auto-internal-linker' ); ?></th>
                        <th><?php esc_html_e( 'Rel', 'smarty-auto-internal-linker' ); ?></th>
                        <th><?php esc_html_e( 'Action', 'smarty-auto-internal-linker' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $keywords as $kw ) : ?>
                    <tr>
                        <td><?php echo esc_html( $kw->keyword ); ?></td>
                        <td><a href="<?php echo esc_url( $kw->target_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_url( $kw->target_url ); ?></a></td>
                        <td><?php echo (int) $kw->max_per_post; ?></td>
                        <td><?php echo esc_html( $kw->rel_attribute ); ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'smarty_aul_nonce' ); ?>
                                <input type="hidden" name="smarty_aul_action" value="delete" />
                                <input type="hidden" name="id" value="<?php echo (int) $kw->id; ?>" />
                                <?php submit_button( __( 'Delete', 'smarty-auto-internal-linker' ), 'delete', '', false, [ 'onclick' => 'return confirm(\'Are you sure?\')' ] ); ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
