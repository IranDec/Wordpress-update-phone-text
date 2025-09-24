<?php
/**
 * Plugin Name:       Adschi Search and Replace
 * Description:       A plugin to search and replace text throughout the entire WordPress database.
 * Version:           1.3.0
 * Author:            Mohammad Babaei
 * Author URI:        https://adschi.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       adschi-search-replace
 *
 * @package           AdschiSearchReplace
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Create the history table upon plugin activation.
 */
function asr_create_history_table() {
	global $wpdb;
	$table_name      = $wpdb->prefix . 'asr_history';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		search_term text NOT NULL,
		replace_term text NOT NULL,
		rows_affected int(11) NOT NULL,
		tables_processed int(11) NOT NULL,
		timestamp datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
		user_id bigint(20) UNSIGNED NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}
register_activation_hook( __FILE__, 'asr_create_history_table' );

/**
 * Check if a string is serialized.
 *
 * @param string $data The string to check.
 * @param bool   $strict Whether to be strict about the check.
 * @return bool
 */
function asr_is_serialized( $data, $strict = true ) {
	// If it isn't a string, it isn't serialized.
	if ( ! is_string( $data ) ) {
		return false;
	}
	$data = trim( $data );
	if ( 'N;' === $data ) {
		return true;
	}
	if ( strlen( $data ) < 4 ) {
		return false;
	}
	if ( ':' !== $data[1] ) {
		return false;
	}
	if ( $strict ) {
		$lastc = substr( $data, -1 );
		if ( ';' !== $lastc && '}' !== $lastc ) {
			return false;
		}
	} else {
		$semicolon = strpos( $data, ';' );
		$brace     = strpos( $data, '}' );
		// Either ; or } must exist.
		if ( false === $semicolon && false === $brace ) {
			return false;
		}
		// But neither must be in the first X characters.
		if ( false !== $semicolon && $semicolon < 3 ) {
			return false;
		}
		if ( false !== $brace && $brace < 4 ) {
			return false;
		}
	}
	$token = $data[0];
	switch ( $token ) {
		case 's':
			if ( $strict ) {
				if ( '"' !== substr( $data, -2, 1 ) ) {
					return false;
				}
			} elseif ( false === strpos( $data, '"' ) ) {
				return false;
			}
			// Or else fall through.
		case 'a':
		case 'O':
			return (bool) preg_match( "/^{$token}:[0-9]+:.*[;}]\$/s", $data );
		case 'b':
		case 'i':
		case 'd':
			$end = $strict ? '$' : '';
			return (bool) preg_match( "/^{$token}:[0-9.E-]+;$end/", $data );
	}
	return false;
}

/**
 * Recursively search and replace data.
 *
 * @param mixed  $data    The data to search and replace.
 * @param string $search  The value to search for.
 * @param string $replace The value to replace it with.
 * @return mixed
 */
function asr_recursive_replace( $data, $search, $replace, $case_sensitive = false ) {
	if ( is_string( $data ) ) {
		if ( asr_is_serialized( $data ) ) {
			$unserialized = @unserialize( $data );
			if ( false !== $unserialized ) {
				$unserialized = asr_recursive_replace( $unserialized, $search, $replace, $case_sensitive );
				return serialize( $unserialized );
			}
		}
		$replace_function = $case_sensitive ? 'str_replace' : 'str_ireplace';
		return $replace_function( $search, $replace, $data );
	}

	if ( is_array( $data ) ) {
		foreach ( $data as $key => &$value ) {
			$value = asr_recursive_replace( $value, $search, $replace, $case_sensitive );
		}
		return $data;
	}

	if ( is_object( $data ) ) {
		$new_object = new stdClass();
		foreach ( $data as $key => $value ) {
			$new_object->$key = asr_recursive_replace( $value, $search, $replace, $case_sensitive );
		}
		return $new_object;
	}

	return $data;
}

/**
 * Handle the search and replace form submission.
 */
function asr_handle_search_replace() {
	if ( ! isset( $_POST['asr_submit'] ) ) {
		return;
	}

	// Security checks.
	if ( ! isset( $_POST['asr_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['asr_nonce'] ) ), 'asr_do_search_replace' ) ) {
		add_settings_error( 'asr_messages', 'asr_nonce_fail', __( 'Security check failed.', 'adschi-search-replace' ), 'error' );
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		add_settings_error( 'asr_messages', 'asr_capability_fail', __( 'You do not have permission to perform this action.', 'adschi-search-replace' ), 'error' );
		return;
	}
	if ( ! isset( $_POST['asr_search'] ) || empty( $_POST['asr_search'] ) ) {
		add_settings_error( 'asr_messages', 'asr_search_empty', __( 'The "Find" field cannot be empty.', 'adschi-search-replace' ), 'error' );
		return;
	}

	global $wpdb;

	$search         = sanitize_text_field( wp_unslash( $_POST['asr_search'] ) );
	$replace        = isset( $_POST['asr_replace'] ) ? sanitize_text_field( wp_unslash( $_POST['asr_replace'] ) ) : '';
	$case_sensitive = isset( $_POST['asr_case_sensitive'] ) && '1' === $_POST['asr_case_sensitive'];
	$whole_word     = isset( $_POST['asr_whole_word'] ) && '1' === $_POST['asr_whole_word'];

	// Get all tables.
	$tables = $wpdb->get_results( 'SHOW TABLES' );
	if ( ! $tables ) {
		add_settings_error( 'asr_messages', 'asr_no_tables', __( 'Could not retrieve database tables.', 'adschi-search-replace' ), 'error' );
		return;
	}
	$tables_in_db = 'Tables_in_' . DB_NAME;

	$total_rows_affected = 0;
	$tables_processed    = 0;

	// WordPress tables that should be skipped for safety.
	$excluded_tables = array( $wpdb->prefix . 'users', $wpdb->prefix . 'usermeta' );

	foreach ( $tables as $table ) {
		$table_name = $table->$tables_in_db;

		if ( in_array( $table_name, $excluded_tables, true ) ) {
			continue;
		}

		// Find the primary key(s) for the current table.
		$primary_keys_results = $wpdb->get_results( "SHOW KEYS FROM `{$table_name}` WHERE Key_name = 'PRIMARY'" );
		if ( empty( $primary_keys_results ) ) {
			// Cannot safely update rows without a primary key.
			continue;
		}
		$primary_keys = wp_list_pluck( $primary_keys_results, 'Column_name' );

		// Get text-based columns for the current table.
		$columns = $wpdb->get_results( "DESCRIBE `{$table_name}`" );
		if ( ! $columns ) {
			continue;
		}

		$text_columns = array();
		foreach ( $columns as $column ) {
			if ( ! in_array( $column->Field, $primary_keys, true ) && preg_match( '/(char|text|enum|set)/i', $column->Type ) ) {
				$text_columns[] = $column->Field;
			}
		}

		if ( empty( $text_columns ) ) {
			continue;
		}

		$tables_processed++;

		// Select the primary keys and all text columns.
		$select_columns = array_merge( $primary_keys, $text_columns );
		$select_query   = "SELECT `" . implode( '`, `', $select_columns ) . "` FROM `{$table_name}` WHERE ";

		$where_clauses = array();
		foreach ( $text_columns as $column_name ) {
			$where_clauses[] = $wpdb->prepare( "`{$column_name}` LIKE %s", '%' . $wpdb->esc_like( $search ) . '%' );
		}
		$select_query .= implode( ' OR ', $where_clauses );

		// Fetch all candidate rows.
		$candidate_rows = $wpdb->get_results( $select_query );

		foreach ( $candidate_rows as $row ) {
			$row_changed = false;
			$update_data = array();
			$where_data  = array();

			// Populate the WHERE clause data for the update.
			foreach ( $primary_keys as $pk_col ) {
				$where_data[ $pk_col ] = $row->$pk_col;
			}

			// Check each text column in the row for a match.
			foreach ( $text_columns as $column_name ) {
				$original_value = $row->$column_name;
				$new_value      = $original_value;

				if ( $whole_word ) {
					$comparison_function = $case_sensitive ? 'strcmp' : 'strcasecmp';
					if ( 0 === $comparison_function( $original_value, $search ) ) {
						$new_value = $replace;
					}
				} else {
					$new_value = asr_recursive_replace( $original_value, $search, $replace, $case_sensitive );
				}

				if ( $new_value !== $original_value ) {
					$update_data[ $column_name ] = $new_value;
					$row_changed = true;
				}
			}

			// If any column in the row was changed, perform the update.
			if ( $row_changed ) {
				$result = $wpdb->update( $table_name, $update_data, $where_data );
				if ( false !== $result ) {
					$total_rows_affected++;
				}
			}
		}
	}

	$message = sprintf(
		// translators: 1: Number of rows, 2: Number of tables.
		__( 'Search and replace completed successfully. %1$d rows in %2$d tables were updated.', 'adschi-search-replace' ),
		absint( $total_rows_affected ),
		absint( $tables_processed )
	);
	add_settings_error( 'asr_messages', 'asr_success', $message, 'success' );

	// Log the action to the history table.
	if ( $total_rows_affected > 0 ) {
		$history_table = $wpdb->prefix . 'asr_history';
		$wpdb->insert(
			$history_table,
			array(
				'search_term'      => $search,
				'replace_term'     => $replace,
				'rows_affected'    => $total_rows_affected,
				'tables_processed' => $tables_processed,
				'timestamp'        => current_time( 'mysql' ),
				'user_id'          => get_current_user_id(),
			)
		);
	}
}

add_action( 'admin_init', 'asr_handle_search_replace' );

/**
 * Add a new top-level menu item to the WordPress admin.
 */
function asr_options_page() {
	add_menu_page(
		__( 'Adschi Search and Replace', 'adschi-search-replace' ),
		__( 'Adschi S&R', 'adschi-search-replace' ),
		'manage_options',
		'adschi-search-replace',
		'asr_options_page_html',
		'dashicons-search'
	);

	add_submenu_page(
		'adschi-search-replace',
		__( 'Search & Replace', 'adschi-search-replace' ),
		__( 'Search & Replace', 'adschi-search-replace' ),
		'manage_options',
		'adschi-search-replace',
		'asr_options_page_html'
	);

	add_submenu_page(
		'adschi-search-replace',
		__( 'History', 'adschi-search-replace' ),
		__( 'History', 'adschi-search-replace' ),
		'manage_options',
		'adschi-search-replace-history',
		'asr_history_page_html'
	);
}
add_action( 'admin_menu', 'asr_options_page' );

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class for displaying the history log in a list table.
 */
class ASR_History_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => __( 'Log', 'adschi-search-replace' ),
				'plural'   => __( 'Logs', 'adschi-search-replace' ),
				'ajax'     => false,
			)
		);
	}

	/**
	 * Get the list of columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'search_term'      => __( 'Find', 'adschi-search-replace' ),
			'replace_term'     => __( 'Replace with', 'adschi-search-replace' ),
			'rows_affected'    => __( 'Rows Affected', 'adschi-search-replace' ),
			'tables_processed' => __( 'Tables Processed', 'adschi-search-replace' ),
			'timestamp'        => __( 'Date', 'adschi-search-replace' ),
			'user_id'          => __( 'User', 'adschi-search-replace' ),
		);
	}

	/**
	 * Prepare the items for the table.
	 */
	public function prepare_items() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'asr_history';

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$total_items  = $wpdb->get_var( "SELECT COUNT(id) FROM $table_name" );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
			)
		);

		$offset = ( $current_page - 1 ) * $per_page;

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->items           = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name ORDER BY timestamp DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);
	}

	/**
	 * Define what data to show on each column of the table.
	 *
	 * @param  array  $item        Data.
	 * @param  string $column_name - Current column name.
	 * @return mixed
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'search_term':
			case 'replace_term':
			case 'rows_affected':
			case 'tables_processed':
			case 'timestamp':
				return $item[ $column_name ];
			case 'user_id':
				$user = get_userdata( $item[ $column_name ] );
				return $user ? $user->display_name : __( 'Unknown', 'adschi-search-replace' );
			default:
				return print_r( $item, true );
		}
	}
}

/**
 * Display the history page HTML.
 */
function asr_history_page_html() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Search and Replace History', 'adschi-search-replace' ); ?></h1>
		<?php
		$list_table = new ASR_History_List_Table();
		$list_table->prepare_items();
		$list_table->display();
		?>
	</div>
	<?php
}

/**
 * Display the plugin's options page HTML.
 */
function asr_options_page_html() {
	// Check user capabilities.
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<?php settings_errors( 'asr_messages' ); ?>
		<p><?php esc_html_e( 'Use this page to search for and replace text across your entire database.', 'adschi-search-replace' ); ?></p>

		<div style="border: 1px solid #ff0000; padding: 10px; margin-bottom: 20px;">
			<h3 style="margin-top: 0; color: #ff0000;"><?php esc_html_e( 'IMPORTANT: Please backup your database before using this tool.', 'adschi-search-replace' ); ?></h3>
			<p><?php esc_html_e( 'This tool will directly modify your database. We are not responsible for any data loss. Always take a full backup before proceeding.', 'adschi-search-replace' ); ?></p>
		</div>

		<form action="" method="post">
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><label for="asr_search"><?php esc_html_e( 'Find', 'adschi-search-replace' ); ?></label></th>
					<td><input type="text" id="asr_search" name="asr_search" value="" class="regular-text" required /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="asr_replace"><?php esc_html_e( 'Replace with', 'adschi-search-replace' ); ?></label></th>
					<td><input type="text" id="asr_replace" name="asr_replace" value="" class="regular-text" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'Options', 'adschi-search-replace' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><span><?php esc_html_e( 'Search Options', 'adschi-search-replace' ); ?></span></legend>
							<label for="asr_case_sensitive">
								<input type="checkbox" name="asr_case_sensitive" id="asr_case_sensitive" value="1" />
								<?php esc_html_e( 'Case-sensitive', 'adschi-search-replace' ); ?>
							</label>
							<br />
							<label for="asr_whole_word">
								<input type="checkbox" name="asr_whole_word" id="asr_whole_word" value="1" />
								<?php esc_html_e( 'Match whole value only', 'adschi-search-replace' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'By default, the search is case-insensitive and replaces all occurrences.', 'adschi-search-replace' ); ?>
							</p>
						</fieldset>
					</td>
				</tr>
			</table>
			<?php wp_nonce_field( 'asr_do_search_replace', 'asr_nonce' ); ?>
			<?php submit_button( __( 'Run Search/Replace', 'adschi-search-replace' ), 'primary', 'asr_submit' ); ?>
		</form>

		<div style="margin-top: 20px; padding-top: 10px; border-top: 1px solid #ccc; text-align: center;">
			<p>
				<?php
				echo wp_kses(
					'مشاوره حرفه ای راه اندازی کمپین های تبلیغاتی و طراحی تخصصی سایت <a href="https://adschi.com/" target="_blank" rel="noopener noreferrer">ادزچی</a>',
					array(
						'a' => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
					)
				);
				?>
			</p>
			<p>
				<em>
					<?php esc_html_e( 'Developed by', 'adschi-search-replace' ); ?>
					<a href="https://adschi.com" target="_blank" rel="noopener noreferrer">Mohammad Babaei</a>
				</em>
			</p>
		</div>

	</div>
	<?php
}
