<?php
/**
 * A find/replace job: the uploaded pairs, the chosen tables, and batch processing.
 *
 * @package Bulk_Find_Replace
 */

defined( 'ABSPATH' ) || exit;

class BFR_Job {

	const OPTION_PREFIX = 'bfr_job_';

	/** Rows read per batch request. */
	const BATCH_SIZE = 250;

	/** Before/after examples kept per table. */
	const MAX_SAMPLES = 10;

	/**
	 * Above this many pairs x columns, skip the LIKE pre-filter and scan every row.
	 * A long OR list makes each batch query scan the whole table, which hosts like
	 * WP Engine kill on large tables such as wp_postmeta.
	 */
	const MAX_LIKE_TERMS = 40;

	/** Column types that can hold text worth replacing. */
	const TEXT_TYPES = array( 'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext' );

	/**
	 * Create and store a job.
	 *
	 * @param array $pairs   List of array( find, replace ).
	 * @param array $tables  Table names.
	 * @param array $options case_insensitive (bool), whole_urls (bool), skip_columns (string[]).
	 * @return string Job id.
	 */
	public static function create( array $pairs, array $tables, array $options ) {
		self::cleanup_old();
		$id  = strtolower( wp_generate_password( 12, false ) );
		$job = array(
			'id'       => $id,
			'created'  => time(),
			'user'     => get_current_user_id(),
			'pairs'    => array_values( $pairs ),
			'tables'   => array_values( $tables ),
			'options'  => $options,
			'live_run' => null,
		);
		add_option( self::OPTION_PREFIX . $id, $job, '', 'no' );
		return $id;
	}

	/**
	 * @param string $id
	 * @return array|null
	 */
	public static function get( $id ) {
		$id = preg_replace( '/[^a-z0-9]/', '', (string) $id );
		if ( '' === $id ) {
			return null;
		}
		$job = get_option( self::OPTION_PREFIX . $id );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * @param array $job
	 */
	public static function save( array $job ) {
		update_option( self::OPTION_PREFIX . $job['id'], $job, 'no' );
	}

	/**
	 * Delete jobs older than a week.
	 */
	public static function cleanup_old() {
		global $wpdb;
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%'
			)
		);
		foreach ( $names as $name ) {
			$job = get_option( $name );
			if ( ! is_array( $job ) || empty( $job['created'] ) || $job['created'] < time() - WEEK_IN_SECONDS ) {
				delete_option( $name );
			}
		}
	}

	/**
	 * Tables this site owns, in database order.
	 *
	 * @return string[]
	 */
	public static function site_tables() {
		global $wpdb;
		$like = $wpdb->esc_like( $wpdb->prefix ) . '%';
		return $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
	}

	/**
	 * Primary key and text columns of a table.
	 *
	 * @param string $table Validated table name.
	 * @param array  $skip  Column names to leave alone.
	 * @return array{pk: string|null, columns: string[], reason: string}
	 */
	public static function table_info( $table, array $skip ) {
		global $wpdb;
		$rows    = $wpdb->get_results( 'DESCRIBE ' . self::quote( $table ), ARRAY_A );
		$pks     = array();
		$columns = array();
		foreach ( (array) $rows as $row ) {
			if ( 'PRI' === $row['Key'] ) {
				$pks[] = $row['Field'];
			}
			$base = strtolower( preg_replace( '/\(.*$/', '', $row['Type'] ) );
			if ( in_array( $base, self::TEXT_TYPES, true ) && ! in_array( $row['Field'], $skip, true ) ) {
				$columns[] = $row['Field'];
			}
		}
		$reason = '';
		if ( 1 !== count( $pks ) ) {
			$reason = __( 'No single-column primary key', 'bulk-find-replace' );
		} elseif ( ! $columns ) {
			$reason = __( 'No text columns', 'bulk-find-replace' );
		}
		return array(
			'pk'      => 1 === count( $pks ) ? $pks[0] : null,
			'columns' => array_values( array_diff( $columns, $pks ) ),
			'reason'  => $reason,
		);
	}

	/**
	 * Process one batch of one table.
	 *
	 * @param array      $job
	 * @param int        $table_index Index into $job['tables'].
	 * @param string|null $after_pk   Last primary key handled, or null to start the table.
	 * @param bool       $live        Write changes (true) or only count them (false).
	 * @return array Batch result for the browser.
	 */
	public static function process_batch( array $job, $table_index, $after_pk, $live ) {
		global $wpdb;

		$table  = isset( $job['tables'][ $table_index ] ) ? $job['tables'][ $table_index ] : null;
		$result = array(
			'table'        => $table,
			'table_index'  => $table_index,
			'next_pk'      => null,
			'table_done'   => true,
			'rows'         => 0,
			'rows_changed' => 0,
			'cells'        => 0,
			'replacements' => 0,
			'skipped_objs' => 0,
			'errors'       => 0,
			'skipped'      => '',
			'samples'      => array(),
			'db_error'     => '',
		);
		if ( null === $table || ! in_array( $table, self::site_tables(), true ) ) {
			$result['skipped'] = __( 'Table not found', 'bulk-find-replace' );
			return $result;
		}

		$skip = isset( $job['options']['skip_columns'] ) ? (array) $job['options']['skip_columns'] : array();
		$info = self::table_info( $table, $skip );
		if ( $info['reason'] ) {
			$result['skipped'] = $info['reason'];
			return $result;
		}

		$pk      = $info['pk'];
		$cols    = $info['columns'];
		$where   = array();
		$params  = array();
		$ci      = ! empty( $job['options']['case_insensitive'] );
		$pairs   = $job['pairs'];

		if ( null !== $after_pk ) {
			$where[]  = self::quote( $pk ) . ' > %s';
			$params[] = $after_pk;
		}

		// Never rewrite the job records themselves.
		if ( $table === $wpdb->options ) {
			$where[]  = 'option_name NOT LIKE %s';
			$params[] = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';
		}

		if ( count( $pairs ) * count( $cols ) <= self::MAX_LIKE_TERMS ) {
			$ors = array();
			foreach ( $cols as $col ) {
				foreach ( $pairs as $pair ) {
					$ors[]    = self::quote( $col ) . ' LIKE %s';
					$params[] = '%' . $wpdb->esc_like( $pair[0] ) . '%';
				}
			}
			$where[] = '(' . implode( ' OR ', $ors ) . ')';
		}

		$select = implode( ', ', array_map( array( __CLASS__, 'quote' ), array_merge( array( $pk ), $cols ) ) );
		$sql    = "SELECT {$select} FROM " . self::quote( $table )
			. ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' )
			. ' ORDER BY ' . self::quote( $pk ) . ' LIMIT ' . self::BATCH_SIZE;
		$rows   = $params
			? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) // phpcs:ignore
			: $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore

		// A failed query returns no rows; report it instead of treating the table as clean.
		if ( $wpdb->last_error ) {
			$result['db_error'] = $wpdb->last_error;
			return $result;
		}

		$replacer = new BFR_Replacer( $pairs, $ci, ! empty( $job['options']['whole_urls'] ) );

		foreach ( (array) $rows as $row ) {
			++$result['rows'];
			$changed = array();
			foreach ( $cols as $col ) {
				$old = $row[ $col ];
				if ( null === $old || '' === $old ) {
					continue;
				}
				$new                     = $replacer->run( $old );
				$result['skipped_objs'] += $replacer->get_skipped_objects();
				if ( $new === $old ) {
					continue;
				}
				$changed[ $col ]          = $new;
				$result['replacements'] += $replacer->get_count();
				++$result['cells'];
				if ( count( $result['samples'] ) < self::MAX_SAMPLES ) {
					$result['samples'][] = array_merge(
						array(
							'pk'     => $row[ $pk ],
							'column' => $col,
						),
						self::snippet( $old, $new )
					);
				}
			}
			if ( $changed ) {
				++$result['rows_changed'];
				if ( $live && false === $wpdb->update( $table, $changed, array( $pk => $row[ $pk ] ) ) ) {
					++$result['errors'];
				}
			}
		}

		if ( count( (array) $rows ) === self::BATCH_SIZE ) {
			$last                 = end( $rows );
			$result['next_pk']    = (string) $last[ $pk ];
			$result['table_done'] = false;
		}

		return $result;
	}

	/**
	 * Short before/after excerpt around the first difference.
	 *
	 * @param string $old
	 * @param string $new
	 * @return array{before: string, after: string}
	 */
	public static function snippet( $old, $new ) {
		$len   = min( strlen( $old ), strlen( $new ) );
		$start = 0;
		while ( $start < $len && $old[ $start ] === $new[ $start ] ) {
			++$start;
		}
		$from = max( 0, $start - 40 );
		$cut  = function ( $s ) use ( $from ) {
			$part = substr( $s, $from, 140 );
			// Avoid splitting a multibyte character at either end.
			$part = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $part, 'UTF-8', 'UTF-8' ) : $part;
			return ( $from > 0 ? '…' : '' ) . $part . ( strlen( $s ) > $from + 140 ? '…' : '' );
		};
		return array(
			'before' => $cut( $old ),
			'after'  => $cut( $new ),
		);
	}

	/**
	 * Backtick-quote an identifier.
	 *
	 * @param string $name
	 * @return string
	 */
	public static function quote( $name ) {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}
}
