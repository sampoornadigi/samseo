<?php
/**
 * Encryption helper for secrets at rest (OAuth tokens, client secret).
 *
 * Uses AES-256-CBC with a key derived from WordPress auth salts. If the salts
 * change, stored secrets become unreadable and the admin must reconnect — an
 * acceptable trade-off that avoids storing a separate key.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypts and decrypts secrets at rest using AES-256-CBC.
 *
 * The encryption key is derived from WordPress auth salts via SHA-256.
 */
class Crypto {

	const CIPHER = 'aes-256-cbc';

	/**
	 * Derive a 32-byte key from WordPress salts.
	 *
	 * @return string Raw 32-byte key.
	 */
	private static function key() {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'sampoorna-seo' )
			. ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : 'fallback' );
		return hash( 'sha256', $material, true );
	}

	/**
	 * Encrypt a plaintext string. Returns base64( iv . ciphertext ).
	 *
	 * @param string $plaintext Plain value.
	 * @return string
	 */
	public static function encrypt( $plaintext ) {
		if ( '' === (string) $plaintext ) {
			return '';
		}
		$iv         = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::CIPHER ) );
		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $ciphertext ) {
			return '';
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding binary token data, not obfuscation.
		return base64_encode( $iv . $ciphertext );
	}

	/**
	 * Decrypt a value produced by encrypt().
	 *
	 * @param string $payload Stored value.
	 * @return string Empty string on failure.
	 */
	public static function decrypt( $payload ) {
		if ( '' === (string) $payload ) {
			return '';
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding binary token data, not obfuscation.
		$raw    = base64_decode( $payload, true );
		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		if ( false === $raw || strlen( $raw ) <= $iv_len ) {
			return '';
		}
		$iv         = substr( $raw, 0, $iv_len );
		$ciphertext = substr( $raw, $iv_len );
		$plaintext  = openssl_decrypt( $ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );
		return false === $plaintext ? '' : $plaintext;
	}
}
