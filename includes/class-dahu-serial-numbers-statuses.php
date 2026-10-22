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
 * These twelve strings are written into a database column and read back by
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
	const REPAIRED        = 'repaired';
	const RESTOCKING      = 'restocking';
	const DESTROYED       = 'destroyed';

	/**
	 * Held, but not counted as stock.
	 *
	 * The honest name for a unit the shop has decided not to offer for sale,
	 * for a reason that is not a lifecycle event: a shelf count that came up
	 * short and left this row with nothing behind it, a part kept back for
	 * the workshop's own repairs, a unit quarantined pending a decision.
	 * Before this status existed the only ways to take such a unit out of the
	 * count were to call it destroyed — which is a claim about the physical world —
	 * or to leave it in stock and let the number be wrong.
	 *
	 * It is not terminal. The one way out is back to stock, which is the
	 * point: a unit parked here is a unit somebody still has.
	 */
	const ADJUSTED_OUT = 'adjusted_out';

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
			self::REPAIRED,
			self::RESTOCKING,
			self::ADJUSTED_OUT,
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
			self::REPAIRED        => __( 'Repaired', 'dahu-serial-numbers' ),
			self::RESTOCKING      => __( 'Going back to stock', 'dahu-serial-numbers' ),
			self::ADJUSTED_OUT    => __( 'Held, not counted as stock', 'dahu-serial-numbers' ),
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
	 * - `RETURNED_FAULTY` DID have no outlet, on the stated grounds that what
	 *   to do with a unit that came back as something other than expected is
	 *   "a decision, not a transition", and that the plugin should refuse to
	 *   guess it. That reasoning is overturned here, and saying why matters
	 *   more than the three lines of code it changed.
	 *
	 *   A transition is precisely how a decision gets recorded. Making the
	 *   status terminal did not make the shop decide — it made the shop's
	 *   decision unwritable. The unit stayed in `returned_faulty` for good,
	 *   and because a unit in an open consignment is refused by every other
	 *   consignment, it could not even be recorded as going back out. Manual
	 *   SQL was the only way out.
	 *
	 *   `RETURNED_OK` was already the counter-example: three outlets, three
	 *   decisions, and nothing guessed. The state that most needs a decision
	 *   recorded was the only one unable to carry it.
	 *
	 *   So `RETURNED_FAULTY` now offers repair, replacement, or destruction —
	 *   and NOT `RESTOCKING`. A unit that came back as something other than
	 *   what was described does not go back on the shelf without passing
	 *   through somebody's hands first; that one really would be a guess.
	 *
	 * - `REPAIRED` is new, and it exists because `IN_REPAIR -> REPLACED` was
	 *   the only way out of a repair. "Replaced" means the customer got a
	 *   different unit; there was no way to say the commonest outcome of all,
	 *   which is that this unit was mended.
	 *
	 *   It leads to `RESTOCKING`, back to `IN_REPAIR`, or to `DESTROYED`. The
	 *   last two are there because "repaired, and then it failed the final
	 *   check" is the commonest thing to happen after a repair, and an
	 *   adversarial review found it unrecordable: `REPAIRED` had no
	 *   destructive outlet at all, so declaring a repair was irreversible in
	 *   the one direction that matters.
	 *
	 *   `REPAIRED -> ASSIGNED` is the edge that matters, and it went in, came
	 *   out, and went back in. It looked obvious — the unit goes back out on
	 *   the order it came from — and an adversarial review measured what it
	 *   cost: `ship()` collected every `assigned` unit of an order with no
	 *   quantity check at all, so a quantity-one line that had already had a
	 *   replacement shipped ended up with TWO units recorded as shipped
	 *   against one ordered, and the second was still on the bench while its
	 *   history said it was with the customer.
	 *
	 *   The honest fix was not to remove the edge but to make `ship()` count,
	 *   which it now does: no line ships more units than it ordered, and the
	 *   whole parcel is refused rather than trimmed. The edge is therefore
	 *   back, and it is back because the defect it exposed was in shipping
	 *   and not in the lifecycle. Going through `RESTOCKING -> IN_STOCK`
	 *   instead would have worked, at the price of clearing the order, the
	 *   order line and the service ticket — losing the link between a unit
	 *   and its repair, in a plugin whose whole purpose is that link.
	 *
	 * - `IN_REPAIR -> DESTROYED` is also new: a repair that cannot be done is
	 *   not a replacement, and before this the only way to record it was to
	 *   claim the customer got another unit.
	 *
	 * - Nothing was removed from this matrix, only added. A shop upgrading
	 *   cannot find a move it relied on has gone — including
	 *   `RETURNED_OK -> IN_REPAIR`, which reads oddly but is somebody's
	 *   second inspection and is not ours to take away.
	 *
	 * @return array<string,string[]>
	 */
	public static function transitions() {

		$transitions = array(
			self::RECEIVED        => array( self::IN_STOCK ),
			self::IN_STOCK        => array( self::ASSIGNED, self::ADJUSTED_OUT ),
			self::ASSIGNED        => array( self::SHIPPED, self::IN_STOCK ),
			self::SHIPPED         => array( self::RETURN_EXPECTED, self::ASSIGNED, self::IN_STOCK ),
			self::RETURN_EXPECTED => array( self::RETURNED_OK, self::RETURNED_FAULTY ),
			self::RETURNED_OK     => array( self::IN_REPAIR, self::RESTOCKING, self::DESTROYED ),
			self::RETURNED_FAULTY => array( self::IN_REPAIR, self::REPLACED, self::DESTROYED ),
			self::IN_REPAIR       => array( self::REPAIRED, self::REPLACED, self::DESTROYED ),
			self::REPAIRED        => array( self::ASSIGNED, self::RESTOCKING, self::IN_REPAIR, self::DESTROYED ),
			self::RESTOCKING      => array( self::IN_STOCK ),
			// Back to stock, and nowhere else. A unit parked out of the count
			// has not been sold, repaired or destroyed, so the only honest
			// move is the one that undoes the parking; anything else would
			// let a unit reach `shipped` without ever having been assigned.
			self::ADJUSTED_OUT    => array( self::IN_STOCK ),
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
