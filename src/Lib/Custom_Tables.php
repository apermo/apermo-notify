<?php
/**
 * Vendored helper for creating and maintaining WordPress custom tables.
 *
 * Source: https://gist.github.com/apermo/0fb0cbca1f57625ba6753ef3b7f73ffa
 * Gist version: 1.0.1
 *
 * Keep the namespace unchanged (`apermo\WPTools`) so future syncs from the
 * gist are a copy-paste.
 *
 * @package Apermo\Notify
 */

declare(strict_types=1);

namespace apermo\WPTools;

defined( 'ABSPATH' ) || exit;

use Exception;

/**
 * Creates and updates custom WordPress tables tracked by an option-stored version key.
 */
class Custom_Tables {
	/**
	 * The option key to store the version.
	 *
	 * @var string
	 */
	private string $version_key;

	/**
	 * The version to update to.
	 *
	 * @var int
	 */
	private int $version;

	/**
	 * The debug mode flag.
	 *
	 * @var bool
	 */
	private bool $debug = false;

	/**
	 * The tables to create.
	 *
	 * @var array<string, string>
	 */
	private array $tables = [];

	/**
	 * The debug messages.
	 *
	 * @var array<int, string>
	 */
	private array $debug_messages = [];

	/**
	 * Constructs the helper with version-key tracking.
	 *
	 * @param string $version_key The option key that stores the current schema version.
	 * @param int    $version     The target schema version.
	 * @param bool   $debug       Whether to record debug output to a timestamped option.
	 */
	public function __construct( string $version_key, int $version, bool $debug = false ) {
		$this->version_key = $version_key;
		$this->version     = $version;
		$this->debug       = $debug;
	}

	/**
	 * Registers a table for creation.
	 *
	 * The table name is prefixed with the WordPress table prefix. The SQL must
	 * use %1$s for the prefixed table name and may use %2$s for the collate clause.
	 *
	 * @param string $table_name The table name without prefix.
	 * @param string $create_sql The SQL to create the table.
	 *
	 * @throws Exception If the table name is invalid or the SQL is missing placeholders.
	 */
	public function add( string $table_name, string $create_sql ): void {
		if ( $table_name !== sanitize_key( $table_name ) ) {
			throw new Exception( 'Invalid table name' );
		}

		global $wpdb;
		$wpdb->$table_name = $wpdb->prefix . $table_name;
		$wpdb->tables[]    = $table_name;

		if ( false === str_contains( $create_sql, '%s' ) ) {
			throw new Exception( 'Invalid SQL, add %1$s for the table name and optionally %2$s for the SQL collation' );
		}

		$create_sql = sprintf( $create_sql, $wpdb->prefix . $table_name, 'COLLATE ' . $wpdb->collate );

		$this->tables[ $table_name ] = $create_sql;
	}

	/**
	 * Creates and updates the registered tables when the stored version is below the target.
	 *
	 * @param bool $force Whether to run dbDelta regardless of the stored version.
	 */
	public function create_and_update_tables( bool $force = false ): void {
		$current_version = (int) get_option( $this->version_key, 0 );

		$this->debug_messages[] = 'Creating tables...';
		$this->debug_messages[] = 'Current version: ' . $current_version;
		$this->debug_messages[] = 'Target version: ' . $this->version;

		if ( $current_version >= $this->version && true !== $force ) {
			$this->debug_messages[] = 'Tables up to date...';
			$this->store_debug_messages();
			return;
		}

		$this->debug_messages[] = 'Creating tables...';

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		foreach ( $this->tables as $table_name => $create_sql ) {
			$this->debug_messages[] = 'Creating table ' . $table_name . '...';
			array_push( $this->debug_messages, ...dbDelta( $create_sql ) );
			$this->debug_messages[] = 'Table ' . $table_name . ' created.';
		}

		update_option( $this->version_key, $this->version, false );

		$this->store_debug_messages();
	}

	/**
	 * Stores the collected debug messages to a timestamped option when debug mode is on.
	 */
	private function store_debug_messages(): void {
		if ( true === $this->debug ) {
			update_option( $this->version_key . '_debug_' . gmdate( 'Ymd-His' ), $this->debug_messages, false );
		}
	}

	/**
	 * Deletes every stored debug-message option for this helper instance.
	 *
	 * @throws Exception If the delete query fails.
	 * @return int The number of deleted rows.
	 */
	public function clear_debug_messages(): int {
		global $wpdb;
		$num_rows = $wpdb->delete( $wpdb->options, [ 'option_name' => $this->version_key . '_debug_%' ] );
		if ( false === $num_rows ) {
			throw new Exception( 'Something went wrong while deleting debug messages.' );
		}

		return $num_rows;
	}

	/**
	 * Drops a registered custom table.
	 *
	 * @param string $table_name The table name without prefix.
	 *
	 * @throws Exception If the drop query fails.
	 * @return bool Whether the table was actually dropped (true) or did not exist (false).
	 */
	public function drop_table( string $table_name ): bool {
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table_name ) );

		if ( false === $result ) {
			throw new Exception( 'Something went wrong while dropping table ' . $table_name . '.' );
		}

		return (bool) $wpdb->rows_affected;
	}
}
