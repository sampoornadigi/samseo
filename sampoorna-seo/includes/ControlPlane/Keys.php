<?php
/**
 * Per-site control-plane key management.
 *
 * Holds the rotatable HMAC shared secret (encrypted at rest), a key id for
 * rotation tracking, and the control-plane base URL. The secret is the trust
 * anchor between this site and the control plane.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\ControlPlane;

use Sampoorna\SEO\Security\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads, generates, and rotates the site's control-plane credentials.
 */
class Keys {

	const OPT_SECRET  = 'sampoorna_seo_cp_site_key';   // Encrypted HMAC secret.
	const OPT_KEY_ID  = 'sampoorna_seo_cp_key_id';     // Plaintext rotation id.
	const OPT_URL     = 'sampoorna_seo_cp_url';        // Control-plane base URL.
	const OPT_ROTATED = 'sampoorna_seo_cp_key_rotated'; // Last rotation timestamp.

	/**
	 * Generate and store a key if none exists yet.
	 *
	 * @return void
	 */
	public static function ensure_key() {
		if ( '' === (string) get_option( self::OPT_SECRET, '' ) ) {
			self::rotate();
		}
	}

	/**
	 * The decrypted shared secret (hex), or empty string when unset.
	 *
	 * @return string
	 */
	public static function secret() {
		return Crypto::decrypt( get_option( self::OPT_SECRET, '' ) );
	}

	/**
	 * The current key id used to select the secret during rotation.
	 *
	 * @return string
	 */
	public static function key_id() {
		return (string) get_option( self::OPT_KEY_ID, '' );
	}

	/**
	 * Generate a fresh secret + key id, replacing any existing pair.
	 *
	 * @return void
	 */
	public static function rotate() {
		$secret = bin2hex( random_bytes( 32 ) );
		$key_id = 'k_' . bin2hex( random_bytes( 6 ) );
		update_option( self::OPT_SECRET, Crypto::encrypt( $secret ), false );
		update_option( self::OPT_KEY_ID, $key_id, false );
		update_option( self::OPT_ROTATED, current_time( 'mysql' ), false );
	}

	/**
	 * The configured control-plane base URL.
	 *
	 * @return string
	 */
	public static function plane_url() {
		return (string) get_option( self::OPT_URL, '' );
	}

	/**
	 * Persist the control-plane base URL.
	 *
	 * @param string $url Base URL.
	 * @return void
	 */
	public static function set_plane_url( $url ) {
		update_option( self::OPT_URL, esc_url_raw( (string) $url ), false );
	}

	/**
	 * Whether a usable secret exists.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::secret();
	}
}
