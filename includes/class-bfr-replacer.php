<?php
/**
 * Serialization-safe find and replace on a single value.
 *
 * @package Bulk_Find_Replace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Applies an ordered list of find/replace pairs to a value, unpacking
 * serialized PHP data so string lengths stay correct after replacement.
 */
class BFR_Replacer {

	/** Nesting limit for arrays, objects and serialized-inside-serialized data. */
	const MAX_DEPTH = 64;

	/** @var string[] */
	private $finds;

	/** @var string[] */
	private $replaces;

	/** @var bool */
	private $case_insensitive;

	/** @var string[]|null Regex patterns when matching whole URLs only. */
	private $patterns = null;

	/** @var string[] Regex-safe replacements for $patterns. */
	private $pattern_replaces = array();

	/** @var int Replacements made by the last run(). */
	private $count = 0;

	/** @var int Serialized objects of unknown classes that were left untouched. */
	private $skipped_objects = 0;

	/**
	 * @param array $pairs            List of array( find, replace ), applied in order.
	 * @param bool  $case_insensitive Match regardless of letter case.
	 * @param bool  $whole_urls       Skip matches that are only the start or end of a longer URL,
	 *                                so /houston never matches inside /houston/ or /houston/katy/.
	 */
	public function __construct( array $pairs, $case_insensitive = false, $whole_urls = false ) {
		$this->finds            = array();
		$this->replaces         = array();
		$this->case_insensitive = (bool) $case_insensitive;
		foreach ( $pairs as $pair ) {
			$this->finds[]    = (string) $pair[0];
			$this->replaces[] = (string) $pair[1];
		}
		if ( $whole_urls ) {
			$this->patterns = array();
			foreach ( $this->finds as $i => $find ) {
				// Not followed by a character that would continue the URL path. A trailing "."
				// only counts as part of the URL when more URL text follows it, so a link at the
				// end of a sentence still matches. A full URL (https://...) needs no left boundary;
				// a bare path must not sit inside a longer path, but may follow an escaped quote
				// (\u0022 or \u0027), which is how block editor attributes store href values.
				// In JSON with escaped slashes, "\/" before or after the match continues the URL.
				$left             = preg_match( '~^[a-z][a-z0-9+.-]*://~i', $find )
					? ''
					: '(?:(?<![A-Za-z0-9_\-./%\\\\])|(?<=\\\\u0022)|(?<=\\\\u0027))';
				$this->patterns[] = '~' . $left . preg_quote( $find, '~' )
					. '(?![A-Za-z0-9_\-/%]|\.[A-Za-z0-9]|\\\\/)~' . ( $this->case_insensitive ? 'i' : '' );
				$this->pattern_replaces[] = addcslashes( $this->replaces[ $i ], '\\$' );
			}
		}
	}

	/**
	 * Replace inside a database value.
	 *
	 * @param mixed $value Raw column value.
	 * @return mixed The new value; identical to the input when nothing matched.
	 */
	public function run( $value ) {
		$this->count           = 0;
		$this->skipped_objects = 0;
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		return $this->walk( $value, 0 );
	}

	/** @return int */
	public function get_count() {
		return $this->count;
	}

	/** @return int */
	public function get_skipped_objects() {
		return $this->skipped_objects;
	}

	/**
	 * @param mixed $data
	 * @param int   $depth
	 * @return mixed
	 */
	private function walk( $data, $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			return $data;
		}

		if ( is_string( $data ) ) {
			if ( self::is_serialized( $data ) ) {
				$unserialized = @unserialize( $data ); // phpcs:ignore
				if ( false !== $unserialized || 'b:0;' === $data ) {
					$before = $this->count;
					$new    = $this->walk( $unserialized, $depth + 1 );
					// Only re-serialize when something changed, so untouched values stay byte-identical.
					return $this->count > $before ? serialize( $new ) : $data; // phpcs:ignore
				}
			}
			$n = 0;
			if ( null !== $this->patterns ) {
				$new = preg_replace( $this->patterns, $this->pattern_replaces, $data, -1, $n );
				if ( null === $new ) {
					return $data; // Regex failure (e.g. invalid UTF-8 with /u); leave untouched.
				}
				$this->count += $n;
				return $new;
			}
			$new = $this->case_insensitive
				? str_ireplace( $this->finds, $this->replaces, $data, $n )
				: str_replace( $this->finds, $this->replaces, $data, $n );
			$this->count += $n;
			return $new;
		}

		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = $this->walk( $value, $depth + 1 );
			}
			return $data;
		}

		if ( is_object( $data ) ) {
			// A class that isn't loaded can't be modified safely; leave it as stored.
			if ( $data instanceof __PHP_Incomplete_Class ) {
				++$this->skipped_objects;
				return $data;
			}
			foreach ( $data as $key => $value ) {
				$data->$key = $this->walk( $value, $depth + 1 );
			}
			return $data;
		}

		return $data;
	}

	/**
	 * Same rules as WordPress core's is_serialized(), strict mode.
	 *
	 * @param string $data
	 * @return bool
	 */
	public static function is_serialized( $data ) {
		if ( function_exists( 'is_serialized' ) ) {
			return is_serialized( $data );
		}
		$data = trim( $data );
		if ( 'N;' === $data ) {
			return true;
		}
		return (bool) preg_match( '/^(?:[aOC]:\d+:|s:\d+:"|[bid]:[^;]*;)/s', $data )
			&& in_array( substr( $data, -1 ), array( ';', '}' ), true );
	}
}
