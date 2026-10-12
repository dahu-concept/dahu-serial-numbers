<?php
/**
 * The contract an authentication backend has to honour.
 *
 * This interface exists so that the shipped backend — a login and password
 * per bench, in the plugin's own table — is replaceable without touching
 * anything else. A shop that would rather hand out WordPress Application
 * Passwords, or sign in against its own directory, writes a second class
 * implementing these four methods and a one-line filter.
 *
 * Nothing in the plugin names the concrete class except the filter default in
 * `Dahu_Serial_Numbers_Auth::get_provider()`. That is the only coupling, and
 * it is deliberate: a replacement that has to be registered in nine places
 * is not a replacement.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Authentication backend for workshop posts.
 */
interface Dahu_Serial_Numbers_Auth_Provider {

	/**
	 * Checks a login and password pair.
	 *
	 * @param string $login    Post login.
	 * @param string $password Post password, clear text, over HTTPS.
	 * @return int|WP_Error Post id on success.
	 */
	public function authenticate( $login, $password );

	/**
	 * Issues a bearer token to a post that has just been authenticated.
	 *
	 * The raw token is returned once and never stored as such.
	 *
	 * @param int $station_id Post id.
	 * @return string|WP_Error Raw token.
	 */
	public function issue_token( $station_id );

	/**
	 * Checks a bearer token.
	 *
	 * @param string $token Raw token from the Authorization header.
	 * @return int|WP_Error Post id on success.
	 */
	public function verify_token( $token );

	/**
	 * Drops a token: an explicit sign-out.
	 *
	 * @param string $token Raw token.
	 * @return true|WP_Error
	 */
	public function revoke_token( $token );

	/**
	 * Drops every token held by one post.
	 *
	 * Part of the contract rather than an extra, because this is what makes
	 * deactivating a post mean anything: without it, whoever is holding a
	 * token keeps working until it runs out on its own.
	 *
	 * @param int $station_id Post id.
	 * @return int Number of tokens dropped.
	 */
	public function revoke_station_tokens( $station_id );

	/**
	 * Drops every token whose deadline has passed.
	 *
	 * A backend that keeps no tokens of its own returns 0.
	 *
	 * @return int Number of tokens dropped.
	 */
	public function purge_expired_tokens();
}
