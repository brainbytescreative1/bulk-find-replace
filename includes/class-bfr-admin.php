<?php
/**
 * Tools > Bulk Find Replace screen, CSV upload and batch AJAX endpoint.
 *
 * @package Bulk_Find_Replace
 */

defined( 'ABSPATH' ) || exit;

class BFR_Admin {

	const SLUG = 'bulk-find-replace';

	/** Most rows accepted from one CSV. */
	const MAX_PAIRS = 5000;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_bfr_upload', array( __CLASS__, 'handle_upload' ) );
		add_action( 'wp_ajax_bfr_batch', array( __CLASS__, 'ajax_batch' ) );
	}

	/**
	 * Admins only; on multisite, super admins only, since tables like users are shared.
	 *
	 * @return bool
	 */
	public static function can() {
		return current_user_can( 'manage_options' ) && ( ! is_multisite() || is_super_admin() );
	}

	public static function menu() {
		if ( ! self::can() ) {
			return;
		}
		$hook = add_management_page(
			__( 'Bulk Find Replace', 'bulk-find-replace' ),
			__( 'Bulk Find Replace', 'bulk-find-replace' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
		add_action(
			'admin_enqueue_scripts',
			function ( $current ) use ( $hook ) {
				if ( $current === $hook ) {
					self::assets();
				}
			}
		);
	}

	public static function assets() {
		wp_enqueue_style( 'bfr-admin', BFR_URL . 'assets/admin.css', array(), BFR_VERSION );
		wp_enqueue_script( 'bfr-admin', BFR_URL . 'assets/admin.js', array(), BFR_VERSION, true );
		wp_localize_script(
			'bfr-admin',
			'bfrData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'bfr_batch' ),
				'i18n'    => array(
					'confirm'      => __( 'This will permanently change your database. Make sure you have a backup, then click the button again within 10 seconds to run the replacements.', 'bulk-find-replace' ),
					'confirmAgain' => __( 'Click again to confirm', 'bulk-find-replace' ),
					'running'      => __( 'Working on', 'bulk-find-replace' ),
					'dryDone'      => __( 'Dry run finished. Nothing was changed.', 'bulk-find-replace' ),
					'liveDone'     => __( 'Done. Your database has been updated.', 'bulk-find-replace' ),
					'failed'       => __( 'Stopped because of an error:', 'bulk-find-replace' ),
					/* translators: 1: number of tables, 2: table names, 3: number of selected tables. */
					'scopeOnly'    => __( 'Only run on the %1$d tables where the dry run found changes (%2$s). Untick to scan all %3$d selected tables again.', 'bulk-find-replace' ),
				),
			)
		);
	}

	public static function page_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'tools.php' ) );
	}

	public static function render() {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'bulk-find-replace' ) );
		}
		$job = isset( $_GET['job'] ) ? BFR_Job::get( sanitize_text_field( wp_unslash( $_GET['job'] ) ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="wrap bfr">';
		echo '<h1>' . esc_html__( 'Bulk Find Replace', 'bulk-find-replace' ) . '</h1>';
		if ( isset( $_GET['bfr_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$error = get_transient( 'bfr_error_' . get_current_user_id() );
			delete_transient( 'bfr_error_' . get_current_user_id() );
			if ( $error ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
			}
		}
		if ( $job ) {
			self::render_job( $job );
		} else {
			self::render_form();
		}
		echo '</div>';
	}

	private static function render_form() {
		global $wpdb;
		$tables = BFR_Job::site_tables();
		?>
		<div class="notice notice-warning inline"><p>
			<?php esc_html_e( 'This changes your database directly. Take a backup before running replacements for real.', 'bulk-find-replace' ); ?>
		</p></div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="bfr_upload">
			<?php wp_nonce_field( 'bfr_upload' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bfr-csv"><?php esc_html_e( 'CSV file', 'bulk-find-replace' ); ?></label></th>
					<td>
						<input type="file" id="bfr-csv" name="bfr_csv" accept=".csv,text/csv,text/plain" required>
						<p class="description">
							<?php esc_html_e( 'Two columns: the text to find, then its replacement. One pair per row. A header row named "find,replace" is detected and skipped. Pairs run in file order, top to bottom.', 'bulk-find-replace' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Options', 'bulk-find-replace' ); ?></th>
					<td>
						<p><label><input type="checkbox" name="bfr_whole_urls" value="1" checked> <?php esc_html_e( 'Match whole URLs only', 'bulk-find-replace' ); ?></label></p>
						<p class="description"><?php esc_html_e( 'Skips matches that are just the start of a longer URL. With this on, /houston replaces links to /houston but leaves /houston/ and /houston/car-accident-lawyer/ alone. Turn it off to replace plain text anywhere it appears.', 'bulk-find-replace' ); ?></p>
						<p><label><input type="checkbox" name="bfr_case_insensitive" value="1"> <?php esc_html_e( 'Ignore letter case when matching', 'bulk-find-replace' ); ?></label></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bfr-skip"><?php esc_html_e( 'Skip columns', 'bulk-find-replace' ); ?></label></th>
					<td>
						<input type="text" id="bfr-skip" name="bfr_skip_columns" value="guid" class="regular-text">
						<p class="description"><?php esc_html_e( 'Comma-separated column names to leave untouched. Post GUIDs should normally never change.', 'bulk-find-replace' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Tables', 'bulk-find-replace' ); ?></th>
					<td>
						<p><label><input type="checkbox" class="bfr-toggle-all" checked> <strong><?php esc_html_e( 'All tables', 'bulk-find-replace' ); ?></strong></label></p>
						<div class="bfr-tables">
							<?php foreach ( $tables as $table ) : ?>
								<label><input type="checkbox" name="bfr_tables[]" value="<?php echo esc_attr( $table ); ?>" checked> <?php echo esc_html( $table ); ?></label>
							<?php endforeach; ?>
						</div>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Upload and preview', 'bulk-find-replace' ) ); ?>
		</form>
		<?php
	}

	private static function render_job( array $job ) {
		$pairs    = $job['pairs'];
		$warnings = isset( $job['warnings'] ) ? $job['warnings'] : array();
		$locked   = ! empty( $job['live_started'] );
		?>
		<p><a href="<?php echo esc_url( self::page_url() ); ?>">&larr; <?php esc_html_e( 'Upload a different CSV', 'bulk-find-replace' ); ?></a></p>

		<?php foreach ( $warnings as $warning ) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html( $warning ); ?></p></div>
		<?php endforeach; ?>

		<h2>
			<?php
			/* translators: %d: number of find/replace pairs. */
			echo esc_html( sprintf( _n( '%d replacement pair', '%d replacement pairs', count( $pairs ), 'bulk-find-replace' ), count( $pairs ) ) );
			?>
		</h2>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: number of tables, 2: yes or no, 3: yes or no, 4: column names. */
					__( '%1$d tables selected. Whole URLs only: %2$s. Ignore case: %3$s. Skipped columns: %4$s.', 'bulk-find-replace' ),
					count( $job['tables'] ),
					empty( $job['options']['whole_urls'] ) ? __( 'no', 'bulk-find-replace' ) : __( 'yes', 'bulk-find-replace' ),
					empty( $job['options']['case_insensitive'] ) ? __( 'no', 'bulk-find-replace' ) : __( 'yes', 'bulk-find-replace' ),
					$job['options']['skip_columns'] ? implode( ', ', $job['options']['skip_columns'] ) : __( 'none', 'bulk-find-replace' )
				)
			);
			?>
		</p>
		<div class="bfr-pairs">
			<table class="widefat striped">
				<thead><tr><th>#</th><th><?php esc_html_e( 'Find', 'bulk-find-replace' ); ?></th><th><?php esc_html_e( 'Replace with', 'bulk-find-replace' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( array_slice( $pairs, 0, 200 ) as $i => $pair ) : ?>
					<tr><td><?php echo (int) $i + 1; ?></td><td><code><?php echo esc_html( $pair[0] ); ?></code></td><td><code><?php echo esc_html( $pair[1] ); ?></code></td></tr>
				<?php endforeach; ?>
				<?php if ( count( $pairs ) > 200 ) : ?>
					<tr><td colspan="3">
						<?php
						/* translators: %d: number of pairs not shown. */
						echo esc_html( sprintf( __( '…and %d more', 'bulk-find-replace' ), count( $pairs ) - 200 ) );
						?>
					</td></tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>

		<div id="bfr-runner"
			data-job="<?php echo esc_attr( $job['id'] ); ?>"
			data-tables="<?php echo esc_attr( wp_json_encode( $job['tables'] ) ); ?>"
			data-locked="<?php echo $locked ? '1' : '0'; ?>">
			<p class="bfr-actions">
				<button type="button" class="button button-secondary" data-mode="dry"><?php esc_html_e( 'Run dry run', 'bulk-find-replace' ); ?></button>
				<button type="button" class="button button-primary" data-mode="live" disabled><?php esc_html_e( 'Run replacements for real', 'bulk-find-replace' ); ?></button>
			</p>
			<?php if ( $locked ) : ?>
				<div class="notice notice-info inline"><p>
					<?php
					echo esc_html(
						! empty( $job['live_run'] )
							/* translators: %s: date and time. */
							? sprintf( __( 'These replacements were already run on %s. Upload the CSV again if you really want to run it twice.', 'bulk-find-replace' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $job['live_run'] ) )
							: __( 'A live run of this CSV was started but did not finish. Run a dry run to see what is left, then upload the CSV again to continue.', 'bulk-find-replace' )
					);
					?>
				</p></div>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'The dry run shows what would change without writing anything. The real run unlocks after a dry run finishes, and by default only goes through the tables where the dry run found changes.', 'bulk-find-replace' ); ?></p>
				<p class="bfr-scope" hidden>
					<label><input type="checkbox" checked> <span></span></label>
				</p>
			<?php endif; ?>
			<p class="bfr-progress" aria-live="polite"></p>
			<div class="bfr-results"></div>
		</div>
		<?php
	}

	public static function handle_upload() {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'bulk-find-replace' ) );
		}
		check_admin_referer( 'bfr_upload' );

		$file = isset( $_FILES['bfr_csv'] ) ? $_FILES['bfr_csv'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $file || UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::fail( __( 'The CSV did not upload. Check the file and try again.', 'bulk-find-replace' ) );
		}
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'csv', 'txt', 'tsv' ), true ) ) {
			self::fail( __( 'Please upload a .csv file.', 'bulk-find-replace' ) );
		}

		$parsed = self::parse_csv( $file['tmp_name'], ! empty( $_POST['bfr_whole_urls'] ) );
		if ( is_wp_error( $parsed ) ) {
			self::fail( $parsed->get_error_message() );
		}

		$all    = BFR_Job::site_tables();
		$tables = isset( $_POST['bfr_tables'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['bfr_tables'] ) ) : array();
		$tables = array_values( array_intersect( $all, $tables ) );
		if ( ! $tables ) {
			self::fail( __( 'Select at least one table.', 'bulk-find-replace' ) );
		}

		$skip = isset( $_POST['bfr_skip_columns'] ) ? sanitize_text_field( wp_unslash( $_POST['bfr_skip_columns'] ) ) : '';
		$skip = array_values( array_filter( array_map( 'trim', explode( ',', $skip ) ), 'strlen' ) );

		$id  = BFR_Job::create(
			$parsed['pairs'],
			$tables,
			array(
				'case_insensitive' => ! empty( $_POST['bfr_case_insensitive'] ),
				'whole_urls'       => ! empty( $_POST['bfr_whole_urls'] ),
				'skip_columns'     => $skip,
			)
		);
		$job = BFR_Job::get( $id );
		$job['warnings'] = $parsed['warnings'];
		BFR_Job::save( $job );

		wp_safe_redirect( self::page_url( array( 'job' => $id ) ) );
		exit;
	}

	/**
	 * Read find/replace pairs from a CSV file.
	 *
	 * @param string $path
	 * @param bool   $whole_urls Whether pairs will match whole URLs only (affects chaining warnings).
	 * @return array{pairs: array, warnings: string[]}|WP_Error
	 */
	public static function parse_csv( $path, $whole_urls = false ) {
		$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			return new WP_Error( 'bfr_csv', __( 'Could not read the CSV file.', 'bulk-find-replace' ) );
		}

		// Pick the delimiter that appears most in the first line (Excel in some locales uses semicolons).
		$first = (string) fgets( $handle );
		rewind( $handle );
		$delimiter = ',';
		$best      = 0;
		foreach ( array( ',', ';', "\t" ) as $candidate ) {
			$n = substr_count( $first, $candidate );
			if ( $n > $best ) {
				$best      = $n;
				$delimiter = $candidate;
			}
		}

		$pairs    = array();
		$seen     = array();
		$warnings = array();
		$line     = 0;
		$empty    = 0;
		$extra    = 0;
		while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			++$line;
			if ( 1 === $line && isset( $row[0] ) ) {
				$row[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $row[0] ); // UTF-8 BOM.
				$head   = strtolower( trim( $row[0] ) );
				if ( in_array( $head, array( 'find', 'search', 'old', 'from' ), true ) ) {
					continue;
				}
			}
			if ( array( null ) === $row ) {
				continue; // Blank line.
			}
			$find    = isset( $row[0] ) ? (string) $row[0] : '';
			$replace = isset( $row[1] ) ? (string) $row[1] : '';
			if ( '' === $find ) {
				++$empty;
				continue;
			}
			if ( count( $row ) > 2 && '' !== implode( '', array_slice( $row, 2 ) ) ) {
				++$extra;
			}
			if ( $find === $replace ) {
				continue;
			}
			if ( isset( $seen[ $find ] ) ) {
				/* translators: 1: find text, 2: line number. */
				$warnings[] = sprintf( __( '"%1$s" appears more than once (again on line %2$d). Only the first replacement can match.', 'bulk-find-replace' ), $find, $line );
			}
			$seen[ $find ] = true;
			$pairs[]       = array( $find, $replace );
			if ( count( $pairs ) > self::MAX_PAIRS ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				/* translators: %d: maximum number of rows. */
				return new WP_Error( 'bfr_csv', sprintf( __( 'The CSV has more than %d rows. Split it into smaller files.', 'bulk-find-replace' ), self::MAX_PAIRS ) );
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $pairs ) {
			return new WP_Error( 'bfr_csv', __( 'No find/replace pairs were found in the CSV.', 'bulk-find-replace' ) );
		}
		if ( $empty ) {
			/* translators: %d: number of rows. */
			$warnings[] = sprintf( _n( '%d row had an empty find value and was skipped.', '%d rows had an empty find value and were skipped.', $empty, 'bulk-find-replace' ), $empty );
		}
		if ( $extra ) {
			/* translators: %d: number of rows. */
			$warnings[] = sprintf( _n( '%d row had more than two columns. Only the first two were used.', '%d rows had more than two columns. Only the first two were used.', $extra, 'bulk-find-replace' ), $extra );
		}

		// Warn when one pair's output feeds a later pair's input, since pairs run in order.
		foreach ( $pairs as $i => $earlier ) {
			foreach ( array_slice( $pairs, $i + 1, null, true ) as $j => $later ) {
				$probe = new BFR_Replacer( array( $later ), false, $whole_urls );
				if ( '' !== $earlier[1] && $probe->run( $earlier[1] ) !== $earlier[1] ) {
					/* translators: 1: row number, 2: row number. */
					$warnings[] = sprintf( __( 'The replacement in pair %1$d contains the find text of pair %2$d, so pair %2$d will also change text produced by pair %1$d.', 'bulk-find-replace' ), $i + 1, $j + 1 );
					if ( count( $warnings ) > 20 ) {
						break 2;
					}
				}
			}
		}

		return array(
			'pairs'    => $pairs,
			'warnings' => $warnings,
		);
	}

	private static function fail( $message ) {
		set_transient( 'bfr_error_' . get_current_user_id(), $message, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::page_url( array( 'bfr_error' => 1 ) ) );
		exit;
	}

	public static function ajax_batch() {
		if ( ! self::can() ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'bulk-find-replace' ) ), 403 );
		}
		check_ajax_referer( 'bfr_batch', 'nonce' );

		$job = BFR_Job::get( isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : '' );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'This job no longer exists. Upload the CSV again.', 'bulk-find-replace' ) ) );
		}

		$live     = isset( $_POST['mode'] ) && 'live' === $_POST['mode'];
		$index    = isset( $_POST['table_index'] ) ? absint( $_POST['table_index'] ) : 0;
		$after_pk = isset( $_POST['after_pk'] ) && '' !== $_POST['after_pk'] ? (string) wp_unslash( $_POST['after_pk'] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- passed to $wpdb->prepare().

		$starting = 0 === $index && null === $after_pk;
		if ( $live ) {
			if ( $starting && ! empty( $job['live_started'] ) ) {
				wp_send_json_error( array( 'message' => __( 'This CSV has already been run. Upload it again to run it a second time.', 'bulk-find-replace' ) ) );
			}
			if ( ! $starting && empty( $job['live_started'] ) ) {
				wp_send_json_error( array( 'message' => __( 'The live run was not started properly.', 'bulk-find-replace' ) ) );
			}
			if ( $starting ) {
				// After a finished dry run, only visit the tables where it found changes, unless asked to scan them all.
				$scan_all            = isset( $_POST['scope'] ) && 'all' === $_POST['scope'];
				$job['live_started'] = time();
				$job['live_tables']  = ! $scan_all && isset( $job['dry_matches'] ) ? $job['dry_matches'] : $job['tables'];
				BFR_Job::save( $job );
			}
			$tables = isset( $job['live_tables'] ) ? $job['live_tables'] : $job['tables'];
		} else {
			if ( $starting ) {
				$job['dry_found'] = array();
				unset( $job['dry_matches'] );
				BFR_Job::save( $job );
			}
			$tables = $job['tables'];
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore
		}

		$result = BFR_Job::process_batch( array_merge( $job, array( 'tables' => $tables ) ), $index, $after_pk, $live );
		if ( $result['db_error'] ) {
			wp_send_json_error(
				array(
					/* translators: 1: table name, 2: database error. */
					'message' => sprintf( __( 'Database error while reading %1$s: %2$s', 'bulk-find-replace' ), $result['table'], $result['db_error'] ),
				)
			);
		}

		$result['all_done'] = $result['table_done'] && $index >= count( $tables ) - 1;

		if ( ! $live ) {
			$found = isset( $job['dry_found'] ) ? $job['dry_found'] : array();
			if ( $result['rows_changed'] && ! in_array( $result['table'], $found, true ) ) {
				$found[]          = $result['table'];
				$job['dry_found'] = $found;
				BFR_Job::save( $job );
			}
			if ( $result['all_done'] ) {
				$job['dry_matches'] = array_values( array_intersect( $job['tables'], $found ) );
				BFR_Job::save( $job );
				$result['matched_tables'] = $job['dry_matches'];
			}
		}
		if ( $live && $result['all_done'] ) {
			$job['live_run'] = time();
			BFR_Job::save( $job );
			wp_cache_flush();
		}

		wp_send_json_success( $result );
	}
}
