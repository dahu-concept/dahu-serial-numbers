<?php
/**
 * The history, which is the whole point.
 *
 * A serial number on its own is a string in a column. What makes serialising
 * worth the trouble is being able to answer "where did this unit go", two
 * years later, for a customer on the phone — and that answer is this table.
 *
 * Nothing in this plugin deletes from it. Not a status change, not an
 * un-assignment, not deleting the order it was attached to. A history with
 * holes in it answers worse than no history, because it answers confidently.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Append-only history of everything that happened to a serial.
 */
class Dahu_Serial_Numbers_Log {

	/**
	 * Writes one line.
	 *
	 * @param string $serial  Serial, normalised here.
	 * @param string $event   Event key, in English, under 64 characters.
	 * @param array  $context {
	 *     @type string $old_status Optional. Status before.
	 *     @type string $new_status Optional. Status after.
	 *     @type int    $order_id   Optional. Order concerned.
	 *     @type mixed  $context    Optional. Free payload, stored as JSON.
	 *     @type string $created_at Optional. UTC MySQL datetime, for a line
	 *                              written after the fact during an import of
	 *                              history that really happened earlier.
	 * }
	 * @return int|WP_Error Row id.
	 */
	public static function record( $serial, $event, array $context = array() ) {

		global $wpdb;

		$serial = Dahu_Serial_Numbers_Format::normalise( $serial );

		if ( '' === $serial ) {
			return new WP_Error(
				'dahu_serial_numbers_log_empty_serial',
				__( 'Nothing to record: the serial number is empty.', 'dahu-serial-numbers' )
			);
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			Dahu_Serial_Numbers_Install::log_table(),
			array(
				'serial'     => $serial,
				'event'      => substr( (string) $event, 0, 64 ),
				'old_status' => self::text_or_null( $context, 'old_status' ),
				'new_status' => self::text_or_null( $context, 'new_status' ),
				'order_id'   => self::id_or_null( $context, 'order_id' ),
				// Zero under cron and WP-CLI, where nobody is signed in. Not
				// a missing value: it says the site did this, not a person.
				'user_id'    => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
				'context'    => self::payload( $context ),
				'created_at' => self::created_at( $context ),
			)
		);

		if ( ! $inserted ) {
			return new WP_Error(
				'dahu_serial_numbers_log_failed',
				sprintf(
					/* translators: %s: serial number. */
					__( 'The history line for %s could not be written.', 'dahu-serial-numbers' ),
					$serial
				)
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * The history of one serial, newest first.
	 *
	 * @param string $serial Serial.
	 * @param int    $limit  Most lines to return. 0 means every one.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_serial( $serial, $limit = 100 ) {

		global $wpdb;

		$serial = Dahu_Serial_Numbers_Format::normalise( $serial );

		if ( '' === $serial ) {
			return array();
		}

		$table = Dahu_Serial_Numbers_Install::log_table();
		$limit = max( 0, (int) $limit );

		if ( 0 === $limit ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from $wpdb->prefix, own table.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE serial = %s ORDER BY created_at DESC, id DESC", $serial ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from $wpdb->prefix, own table.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE serial = %s ORDER BY created_at DESC, id DESC LIMIT %d", $serial, $limit ), ARRAY_A );
		}

		return $rows ? $rows : array();
	}

	/**
	 * The last lines written, whatever serial they belong to.
	 *
	 * @param int $limit Most lines to return.
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( $limit = 50 ) {

		global $wpdb;

		$table = Dahu_Serial_Numbers_Install::log_table();
		$limit = max( 1, (int) $limit );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from $wpdb->prefix, own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d", $limit ), ARRAY_A );

		return $rows ? $rows : array();
	}

	/**
	 * How many lines one serial has.
	 *
	 * @param string $serial Serial.
	 * @return int
	 */
	public static function count_for_serial( $serial ) {

		global $wpdb;

		$serial = Dahu_Serial_Numbers_Format::normalise( $serial );

		if ( '' === $serial ) {
			return 0;
		}

		$table = Dahu_Serial_Numbers_Install::log_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from $wpdb->prefix, own table.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE serial = %s", $serial ) );
	}

	/**
	 * The free payload of a line, decoded.
	 *
	 * @param array $row Log row.
	 * @return array<string,mixed>
	 */
	public static function context( array $row ) {

		if ( empty( $row['context'] ) ) {
			return array();
		}

		$decoded = json_decode( (string) $row['context'], true );

		return is_array( $decoded ) ? $decoded : array( 'raw' => (string) $row['context'] );
	}

	/**
	 * The free payload, ready to store.
	 *
	 * Empty values are dropped rather than stored as nulls inside the JSON:
	 * a payload of eleven keys of which two have values is a payload that is
	 * read as "nine things we do not know", when in fact nine of them were
	 * never relevant to this event.
	 *
	 * @param array $context Caller context.
	 * @return string|null
	 */
	private static function payload( array $context ) {

		if ( ! array_key_exists( 'context', $context ) ) {
			return null;
		}

		$payload = $context['context'];

		if ( is_array( $payload ) ) {
			$payload = array_filter(
				$payload,
				static function ( $value ) {
					return null !== $value && '' !== $value;
				}
			);

			return $payload ? (string) wp_json_encode( $payload ) : null;
		}

		if ( null === $payload || '' === $payload ) {
			return null;
		}

		return (string) $payload;
	}

	/**
	 * When this line says it happened.
	 *
	 * UTC, like every datetime this plugin writes. An explicit value is taken
	 * as given so that importing a history that really happened last March
	 * records last March — the alternative is a table in which every past
	 * event happened on the day of the import.
	 *
	 * @param array $context Caller context.
	 * @return string
	 */
	private static function created_at( array $context ) {

		if ( isset( $context['created_at'] ) && '' !== $context['created_at'] ) {
			return (string) $context['created_at'];
		}

		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * A context value as text, or null when it is empty.
	 *
	 * @param array  $context Context.
	 * @param string $key     Key.
	 * @return string|null
	 */
	private static function text_or_null( array $context, $key ) {

		return isset( $context[ $key ] ) && '' !== $context[ $key ] ? (string) $context[ $key ] : null;
	}

	/**
	 * A context value as a positive id, or null.
	 *
	 * @param array  $context Context.
	 * @param string $key     Key.
	 * @return int|null
	 */
	private static function id_or_null( array $context, $key ) {

		$value = isset( $context[ $key ] ) ? (int) $context[ $key ] : 0;

		return $value > 0 ? $value : null;
	}
}
