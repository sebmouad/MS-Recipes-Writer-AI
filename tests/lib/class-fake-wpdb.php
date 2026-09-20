<?php
/**
 * Recording stand-in for $wpdb.
 *
 * It never talks to MySQL: it renders prepared statements so a test can assert
 * the SQL a screen produces, and returns rows from handlers a test registers.
 */
class MSRWA_Fake_Wpdb {
	public $prefix = 'wp_';
	public $posts = 'wp_posts';
	public $insert_id = 0;
	public $queries = array();
	private $handlers = array();
	private $default_var = 0;

	/** Rows are returned by the first handler whose needle appears in the query. */
	public function on( $needle, $rows ) { $this->handlers[] = array( $needle, $rows ); return $this; }

	public function default_var( $value ) { $this->default_var = $value; return $this; }

	public function esc_like( $value ) { return addcslashes( (string) $value, '_%\\' ); }

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		foreach ( $args as $arg ) {
			$replacement = is_numeric( $arg ) ? (string) $arg : "'" . str_replace( "'", "\\'", (string) $arg ) . "'";
			$query = preg_replace( '/%[dfs]/', str_replace( '$', '\\$', $replacement ), $query, 1 );
		}
		return $query;
	}

	public function get_var( $query ) { $this->queries[] = $query; return $this->resolve( $query, $this->default_var ); }
	public function get_col( $query ) { $this->queries[] = $query; return (array) $this->resolve( $query, array() ); }
	public function get_row( $query, $output = null ) { $this->queries[] = $query; $rows = (array) $this->resolve( $query, array() ); return $rows ? reset( $rows ) : null; }
	public function get_results( $query, $output = null ) { $this->queries[] = $query; return (array) $this->resolve( $query, array() ); }
	public function query( $query ) { $this->queries[] = $query; return 1; }
	public function insert( $table, $data, $formats = null ) { $this->queries[] = 'INSERT ' . $table; $this->insert_id++; return 1; }
	public function update( $table, $data, $where, $formats = null, $where_formats = null ) { $this->queries[] = 'UPDATE ' . $table . ' ' . wp_json_encode( $data ); return 1; }

	private function resolve( $query, $fallback ) {
		foreach ( $this->handlers as $handler ) {
			if ( false !== strpos( $query, $handler[0] ) ) { return $handler[1]; }
		}
		return $fallback;
	}

	/** All queries recorded so far, newline separated, for assertions. */
	public function log() { return implode( "\n", $this->queries ); }

	public function matching( $needle ) {
		return array_values( array_filter( $this->queries, static function ( $query ) use ( $needle ) { return false !== strpos( $query, $needle ); } ) );
	}
}
