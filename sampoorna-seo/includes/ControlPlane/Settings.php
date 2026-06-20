<?php
/**
 * Templatable site-level settings for control-plane config templating.
 *
 * Config templates (vertical defaults) push site-level options — not per-object
 * meta — so this class is the allow-list and sanitizer for the option keys the
 * control plane is permitted to write. Deploy delegates `type: option` changes
 * here, which keeps templating on the same reversible apply/rollback journal as
 * meta deploys. Secrets (AI keys, GSC credentials, the CP key) are deliberately
 * absent from the allow-list and can never be templated.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\ControlPlane;

use Sampoorna\SEO\Technical\Robots;
use Sampoorna\SEO\Technical\IndexNow;
use Sampoorna\SEO\Schema\Graph;
use Sampoorna\SEO\Geo\LlmsTxt;
use Sampoorna\SEO\Integrations\GSC\Reports;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allow-list and sanitizer for control-plane-templatable site options.
 */
class Settings {

	/**
	 * Map of logical field => [ option key, type ].
	 *
	 * Type drives sanitization and round-trip representation:
	 * text | textarea | url | bool | enum:<a|b|...>.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function fields() {
		return array(
			'robots_txt'       => array( Robots::OPT_BODY, 'textarea' ),
			'org_name'         => array( Graph::OPT_ORG_NAME, 'text' ),
			'org_logo'         => array( Graph::OPT_ORG_LOGO, 'url' ),
			'indexnow_enabled' => array( IndexNow::OPT_ENABLED, 'bool' ),
			'llms_enabled'     => array( LlmsTxt::OPT_ENABLED, 'bool' ),
			'llms_intro'       => array( LlmsTxt::OPT_INTRO, 'textarea' ),
			'digest_enabled'   => array( Reports::OPT_ENABLED, 'bool' ),
			'digest_freq'      => array( Reports::OPT_FREQ, 'enum:daily|weekly' ),
		);
	}

	/**
	 * Whether a logical field is templatable.
	 *
	 * @param string $field Logical field.
	 * @return bool
	 */
	public static function has( $field ) {
		return isset( self::fields()[ $field ] );
	}

	/**
	 * Read a templatable option as a normalized string (for journaling/compare).
	 *
	 * @param string $field Logical field.
	 * @return string
	 */
	public static function read( $field ) {
		$def = self::fields();
		if ( ! isset( $def[ $field ] ) ) {
			return '';
		}
		list( $key, $type ) = $def[ $field ];
		$raw                = get_option( $key, '' );
		if ( 'bool' === $type ) {
			return ( $raw && '0' !== (string) $raw ) ? '1' : '0';
		}
		return (string) $raw;
	}

	/**
	 * Write a templatable option after sanitizing by type.
	 *
	 * @param string $field Logical field.
	 * @param string $value Incoming value.
	 * @return void
	 */
	public static function write( $field, $value ) {
		$def = self::fields();
		if ( ! isset( $def[ $field ] ) ) {
			return;
		}
		list( $key, $type ) = $def[ $field ];
		update_option( $key, self::sanitize( $type, (string) $value ) );
	}

	/**
	 * Sanitize a value for a given field type.
	 *
	 * @param string $type  Field type.
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function sanitize( $type, $value ) {
		if ( 'bool' === $type ) {
			return ( '' !== $value && '0' !== $value ) ? '1' : '0';
		}
		if ( 'textarea' === $type ) {
			return sanitize_textarea_field( $value );
		}
		if ( 'url' === $type ) {
			return esc_url_raw( $value );
		}
		if ( 0 === strpos( $type, 'enum:' ) ) {
			$allowed = explode( '|', substr( $type, 5 ) );
			return in_array( $value, $allowed, true ) ? $value : (string) reset( $allowed );
		}
		return sanitize_text_field( $value );
	}
}
