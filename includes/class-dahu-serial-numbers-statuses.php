<?php
/**
 * The lifecycle, and the matrix of moves it allows.
 *
 * This is the smallest file in the plugin and the one that decides what it is.
 * A serial number on its own is a string in a column; what makes it worth
 * keeping is that it has a history, and a history is only trustworthy if the
 * moves it records were checked when they happened. Every status change in
 * this plugin goes through `is_transition_allowed()` — there is no second door.
 *
 * ## The keys are English, and that is not cosmetic
 *
 * These eleven strings are written into a database column and read back by
 * whatever the shop builds on top: a report, an export, a filter in somebody
 * else's plugin. They are a contract, so they are never translated — the
 * labels are. The version this was extracted from had French keys (`recu`,
 * `en_stock`, `affecte`…), which works perfectly on one site and makes the
 * plugin unreadable to everyone else.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Statuses and legal transitions.
 */
class Dahu_Serial_Numbers_Statuses {

	const RECEIVED        = 'received';
	const IN_STOCK        = 'in_stock';
	const ASSIGNED        = 'assigned';
	const SHIPPED         = 'shipped';
	const RETURN_EXPECTED = 'return_expected';
	const RETURNED_OK     = 'returned_ok';
	const RETURNED_FAULTY = 'returned_faulty';
	const IN_REPAIR       = 'in_repair';
	const REPLACED        = 'replaced';
	const RESTOCKING      = 'restocking';
	const DESTROYED       = 'destroyed';

	/**
	 * Every known status key.
	 *
	 * @return string[]
	 */
	public static function all() {

		return array(
			self::RECEIVED,
			self::IN_STOCK,
			self::ASSIGNED,
			self::SHIPPED,
			self::RETURN_EXPECTED,
			self::RETURNED_OK,
			self::RETURNED_FAULTY,
			self::IN_REPAIR,
			self::REPLACED,
			self::RESTOCKING,
			self::DESTROYED,
		);
	}

	/**
	 * Labels, for screens and for people.
	 *
	 * Built on each call rather than held in a constant, because `__()` needs
	 * the text domain loaded and a constant is evaluated too early to have
	 * it. A plugin that caches its labels at load time shows English to a
	 * French shop and nobody can see why.
	 *
	 * @return array<string,string>
	 */
	public static function labels() {

		return array(
			self::RECEIVED        => __( 'Received', 'dahu-serial-numbers' ),
			self::IN_STOCK        => __( 'In stock', 'dahu-serial-numbers' ),
			self::ASSIGNED        => __( 'Assigned to an order', 'dahu-serial-numbers' ),
			self::SHIPPED         => __( 'Shipped', 'dahu-serial-numbers' ),
			self::RETURN_EXPECTED => __( 'Return expected', 'dahu-serial-numbers' ),
			self::RETURNED_OK     => __( 'Returned, as described', 'dahu-serial-numbers' ),
			self::RETURNED_FAULTY => __( 'Returned, not as described', 'dahu-serial-numbers' ),
			self::IN_REPAIR       => __( 'In repair', 'dahu-serial-numbers' ),
			self::REPLACED        => __( 'Replaced', 'dahu-serial-numbers' ),
			self::RESTOCKING      => __( 'Going back to stock', 'dahu-serial-numbers' ),
			self::DESTROYED       => __( 'Destroyed', 'dahu-serial-numbers' ),
		);
	}

	/**
	 * Label of one status, or the key itself when it is not one of ours.
	 *
	 * The key is returned rather than an empty string on purpose: a row whose
	 * status got written by something else should show what it actually says,
	 * not a blank cell that reads as "no status".
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public static function label( $status ) {

		$labels = self::labels();

		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * Is this one of ours?
	 *
	 * @param string $status Status key.
	 * @return bool
	 */
	public static function is_valid( $status ) {

		return in_array( $status, self::all(), true );
	}

	/**
	 * The matrix: from => the statuses it may legally become.
	 *
	 * Notes on the shapes that are not obvious:
	 *
	 * - `RESTOCKING` is a transient state. It exists so that a unit coming
	 *   back from a return is not silently indistinguishable from one that
	 *   never left, which is the difference somebody will want when a batch
	 *   turns out to be faulty.
	 * - `REPLACED` and `DESTROYED` are terminal. Nothing leaves them, and
	 *   that is deliberate: a destroyed unit reappearing in stock is either a
	 *   data-entry mistake or a story the history should be able to tell.
	 * - `ASSIGNED -> IN_STOCK` is an explicit un-assignment, and it is logged
	 *   like any other move. Without it, freeing a unit means editing a row.
	 * - `SHIPPED -> IN_STOCK` exists for one real case: an order moved back
	 *   from completed to processing. Forcing a detour through `ASSIGNED`
	 *   would make the history say something that did not happen.
	 * - `RETURNED_FAULTY` has no outlet. It is an alert state: what to do
	 *   with a unit that came back as something other than what was expected
	 *   is a decision, not a transition, and the plugin refuses to guess it.
	 *
	 * @return array<string,string[]>
	 */
	public static function transitions() {

		$transitions = array(
			self::RECEIVED        => array( self::IN_STOCK ),
			self::IN_STOCK        => array( self::ASSIGNED ),
			self::ASSIGNED        => array( self::SHIPPED, self::IN_STOCK ),
			self::SHIPPED         => array( self::RETURN_EXPECTED, self::ASSIGNED, self::IN_STOCK ),
			self::RETURN_EXPECTED => array( self::RETURNED_OK, self::RETURNED_FAULTY ),
			self::RETURNED_OK     => array( self::IN_REPAIR, self::RESTOCKING, self::DESTROYED ),
			self::RETURNED_FAULTY => array(),
			self::IN_REPAIR       => array( self::REPLACED ),
			self::RESTOCKING      => array( self::IN_STOCK ),
			self::REPLACED        => array(),
			self::DESTROYED       => array(),
		);

		/**
		 * Filters the legal transition matrix.
		 *
		 * A shop with a step of its own — a quarantine, a second inspection —
		 * widens the matrix here rather than writing statuses behind the
		 * plugin's back. Targets that are not known statuses are ignored:
		 * a typo in a filter must not open a door.
		 *
		 * @param array<string,string[]> $transitions from => allowed targets.
		 */
		$transitions = (array) apply_filters( 'dahu_serial_numbers_transitions', $transitions );

		return self::sanitize_matrix( $transitions );
	}

	/**
	 * Keep only the known statuses, on both sides of the arrow.
	 *
	 * @param array<string,mixed> $transitions Raw matrix.
	 * @return array<string,string[]>
	 */
	private static function sanitize_matrix( $transitions ) {

		$known = self::all();
		$clean = array();

		foreach ( $known as $from ) {
			$targets = isset( $transitions[ $from ] ) ? (array) $transitions[ $from ] : array();
			$kept    = array();

			foreach ( $targets as $to ) {
				if ( in_array( $to, $known, true ) && ! in_array( $to, $kept, true ) && $to !== $from ) {
					$kept[] = $to;
				}
			}

			$clean[ $from ] = $kept;
		}

		return $clean;
	}

	/**
	 * May a unit go from one status to the other?
	 *
	 * @param string $from Current status.
	 * @param string $to   Wanted status.
	 * @return bool
	 */
	public static function is_transition_allowed( $from, $to ) {

		$transitions = self::transitions();

		if ( ! isset( $transitions[ $from ] ) ) {
			return false;
		}

		return in_array( $to, $transitions[ $from ], true );
	}

	/**
	 * Statuses nothing leaves.
	 *
	 * @return string[]
	 */
	public static function terminal() {

		$terminal = array();

		foreach ( self::transitions() as $status => $targets ) {
			if ( ! $targets ) {
				$terminal[] = $status;
			}
		}

		return $terminal;
	}

	/**
	 * Statuses a unit may move to from here, as key => label.
	 *
	 * @param string $from Current status.
	 * @return array<string,string>
	 */
	public static function next( $from ) {

		$transitions = self::transitions();
		$targets     = isset( $transitions[ $from ] ) ? $transitions[ $from ] : array();
		$labels      = self::labels();
		$out         = array();

		foreach ( $targets as $to ) {
			$out[ $to ] = isset( $labels[ $to ] ) ? $labels[ $to ] : $to;
		}

		return $out;
	}
}
