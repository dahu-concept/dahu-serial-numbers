<?php
/**
 * What a serial number looks like coming in, and what can be read out of it.
 *
 * ## Why this is a class and not three lines in the manager
 *
 * In the plugin this was extracted from, the normaliser, the batch-key
 * normaliser and a letter-to-year table sat at the top of a 4,576-line
 * manager, and the letter table was one supplier's encoding written as a
 * class constant. On that shop it is exactly right. In a plugin somebody else
 * installs it is a guess about their supplier, made in code they cannot reach,
 * and the first serial that does not match it silently loses its date.
 *
 * So the plugin ships no opinion about what a serial means. It normalises —
 * trim, strip whitespace, uppercase, pull the value out of a scanned URL —
 * and then asks, through a filter, whether anybody can read a date out of it.
 * Nobody answers by default and both fields come back null, which is the
 * correct answer for a shop whose serials encode nothing.
 *
 * The supplier encoding that motivated all this ships too, as
 * `letter_year_month()`: a scheme a shop turns on with one line. It is not a
 * default, it is a worked example that happens to be in use.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalising serials, and reading what can be read out of them.
 */
class Dahu_Serial_Numbers_Format {

	/**
	 * Longest serial the column can hold.
	 */
	const MAX_LENGTH = 64;

	/**
	 * First letter to the first year of the cycle, for `letter_year_month()`.
	 *
	 * Twenty-six letters, twenty-six years, starting at 2001. Not used unless
	 * a shop turns that scheme on.
	 */
	const CYCLE_START = 2001;

	/**
	 * How many years the letter cycle covers before repeating.
	 */
	const CYCLE_LENGTH = 26;

	/**
	 * Cleans up a serial as it was typed, pasted or scanned.
	 *
	 * The scanned case is the one that shapes this method. A barcode reader is
	 * a keyboard: whatever the label encodes is typed into the focused field,
	 * and plenty of suppliers encode a URL rather than the number — the whole
	 * address lands in the box, query string and all. Refusing it would be
	 * correct and useless, because the person at the bench has no other way to
	 * enter that number. So a value carrying a known query parameter is
	 * reduced to that parameter.
	 *
	 * @param string $input Raw input.
	 * @return string Normalised serial, or '' when nothing usable is left.
	 */
	public static function normalise( $input ) {

		$raw   = trim( (string) $input );
		$input = $raw;

		if ( '' === $input ) {
			return '';
		}

		$input = self::from_url( $input );

		// Whitespace anywhere, not just at the ends: a reader configured with
		// a space as its suffix, or a number read off a packing list in two
		// halves, both arrive with one in the middle.
		$input = (string) preg_replace( '/\s+/', '', $input );
		$input = strtoupper( $input );

		/**
		 * Filters a normalised serial.
		 *
		 * The last word on what a serial looks like, for a shop whose
		 * supplier prefixes every label with a batch code, or pads with
		 * zeroes, or anything else nobody here can guess.
		 *
		 * @param string $serial Normalised serial.
		 * @param string $input  Raw input, before normalising.
		 */
		$serial = (string) apply_filters( 'dahu_serial_numbers_normalise', $input, $raw );

		return trim( $serial );
	}

	/**
	 * Pulls a serial out of a scanned URL.
	 *
	 * @param string $input Raw input.
	 * @return string The input, or the parameter value found in it.
	 */
	private static function from_url( $input ) {

		/**
		 * Filters the query parameters a scanned URL may carry the serial in.
		 *
		 * @param string[] $parameters Parameter names, compared case-insensitively.
		 */
		$parameters = (array) apply_filters( 'dahu_serial_numbers_url_parameters', array( 'series', 'serial', 'sn' ) );

		if ( ! $parameters ) {
			return $input;
		}

		// Cheap test before parsing: almost every input is already a plain
		// serial, and wp_parse_url() on each one of a thousand-line import is
		// a thousand parses for nothing.
		$looks_like_query = false;

		foreach ( $parameters as $parameter ) {
			if ( false !== stripos( $input, $parameter . '=' ) ) {
				$looks_like_query = true;
				break;
			}
		}

		if ( ! $looks_like_query ) {
			return $input;
		}

		$query = wp_parse_url( $input, PHP_URL_QUERY );

		if ( null === $query || false === $query ) {
			// A bare query string, with no scheme and no host: "?series=ABC".
			$query = ltrim( $input, '?' );
		}

		$args = array();
		wp_parse_str( (string) $query, $args );

		foreach ( $args as $key => $value ) {
			foreach ( $parameters as $parameter ) {
				if ( 0 === strcasecmp( (string) $key, (string) $parameter ) && '' !== (string) $value ) {
					return (string) $value;
				}
			}
		}

		return $input;
	}

	/**
	 * Cleans up a supplier batch reference into a key.
	 *
	 * Suppliers write the same batch three ways in one week — `SO-2605100-CN`,
	 * `SO 2605100 CN`, `SO2605100CN` — and a batch that groups by the string
	 * as typed shows three batches where there is one. Separators go; nothing
	 * else does, because what is left is somebody else's reference and this
	 * plugin has no business reformatting it.
	 *
	 * @param string $input Raw batch reference.
	 * @return string
	 */
	public static function normalise_batch( $input ) {

		$input = strtoupper( trim( (string) $input ) );

		return (string) preg_replace( '/[\s\-_]+/', '', $input );
	}

	/**
	 * Manufacturing year and month, when something can read them.
	 *
	 * Both null by default, and that is not a failure: most serials encode
	 * nothing, and a plugin that guessed would fill a column with wrong dates
	 * that look right.
	 *
	 * @param string $serial Normalised serial.
	 * @return array{year:int|null,month:int|null}
	 */
	public static function decode( $serial ) {

		$none = array(
			'year'  => null,
			'month' => null,
		);

		$serial = (string) $serial;

		if ( '' === $serial ) {
			return $none;
		}

		/**
		 * Filters the manufacturing date read out of a serial.
		 *
		 * Nothing is hooked by default. A shop whose supplier encodes a date
		 * in the number hooks a scheme here — `letter_year_month()` below is
		 * one, shipped and tested:
		 *
		 *     add_filter(
		 *         'dahu_serial_numbers_decode',
		 *         array( 'Dahu_Serial_Numbers_Format', 'letter_year_month' ),
		 *         10,
		 *         2
		 *     );
		 *
		 * @param array{year:int|null,month:int|null} $date   Both null by default.
		 * @param string                              $serial Normalised serial.
		 */
		$date = (array) apply_filters( 'dahu_serial_numbers_decode', $none, $serial );

		return self::sanitise_date( $date );
	}

	/**
	 * A date read out of a filter, made safe to write to the columns.
	 *
	 * A scheme is somebody else's code and it will one day return a month of
	 * 13, a year as a string, or the whole thing as a date object. None of
	 * those belongs in a smallint column, and a scheme that returns rubbish
	 * should cost a null, not a database error on an import of nine hundred
	 * rows.
	 *
	 * @param array $date Raw filter output.
	 * @return array{year:int|null,month:int|null}
	 */
	private static function sanitise_date( $date ) {

		$year  = isset( $date['year'] ) ? (int) $date['year'] : 0;
		$month = isset( $date['month'] ) ? (int) $date['month'] : 0;

		if ( $year < 1900 || $year > 2200 ) {
			$year = 0;
		}

		if ( $month < 1 || $month > 12 ) {
			$month = 0;
		}

		return array(
			'year'  => $year ? $year : null,
			'month' => $month ? $month : null,
		);
	}

	/**
	 * A shipped scheme: first letter is the year, second letter the month.
	 *
	 * `A` is 2001 and `Z` is 2026; `A` to `L` in the second position are
	 * January to December. This is a real supplier's encoding, which is why
	 * it ships — but it is hooked on, never assumed.
	 *
	 * ## The ambiguity, and how it is resolved
	 *
	 * Twenty-six letters over an indefinite number of years means every
	 * letter comes round again: `A` is 2001, and 2027, and 2053. The scheme
	 * picks the occurrence nearest the current year, which is right because
	 * a unit being received was manufactured recently — and it keeps being
	 * right as the years pass, which a table of fixed years would not. In
	 * 2027 this returns 2027 for `A`, not 2001.
	 *
	 * @param array  $date   Incoming date, replaced when this scheme can read one.
	 * @param string $serial Normalised serial.
	 * @return array{year:int|null,month:int|null}
	 */
	public static function letter_year_month( $date, $serial ) {

		$serial = strtoupper( (string) $serial );

		if ( strlen( $serial ) < 2 ) {
			return $date;
		}

		$year_offset  = ord( $serial[0] ) - ord( 'A' );
		$month_offset = ord( $serial[1] ) - ord( 'A' );

		if ( $year_offset < 0 || $year_offset > 25 || $month_offset < 0 || $month_offset > 11 ) {
			return $date;
		}

		$year    = self::CYCLE_START + $year_offset;
		$current = (int) gmdate( 'Y' );

		// Walk forward while the next occurrence is still the nearer one. The
		// comparison is written on the next cycle rather than on a distance,
		// because half a cycle is thirteen years and "nearer" has to break
		// its tie the same way every time.
		while ( ( $year + self::CYCLE_LENGTH ) - $current <= (int) ( self::CYCLE_LENGTH / 2 ) ) {
			$year += self::CYCLE_LENGTH;
		}

		return array(
			'year'  => $year,
			'month' => $month_offset + 1,
		);
	}
}
