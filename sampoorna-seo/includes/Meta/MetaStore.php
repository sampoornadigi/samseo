<?php
/**
 * Per-object SEO meta store.
 *
 * Stores SEO fields as discrete post-meta keys (one key per field) so they are
 * queryable and map cleanly during migration from Rank Math / Yoast / AIOSEO.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Meta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and reads/writes the plugin's per-post SEO meta fields.
 */
class MetaStore {

	const KEY_TITLE     = '_sampoorna_seo_title';
	const KEY_DESC      = '_sampoorna_seo_desc';
	const KEY_CANONICAL = '_sampoorna_seo_canonical';
	const KEY_NOINDEX   = '_sampoorna_seo_robots_noindex';
	const KEY_NOFOLLOW  = '_sampoorna_seo_robots_nofollow';
	const KEY_OG_TITLE  = '_sampoorna_seo_og_title';
	const KEY_OG_DESC   = '_sampoorna_seo_og_desc';
	const KEY_OG_IMAGE  = '_sampoorna_seo_og_image';
	const KEY_FOCUS_KW  = '_sampoorna_seo_focus_keyword';

	/**
	 * Singleton instance.
	 *
	 * @var MetaStore|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return MetaStore
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register the meta-registration hook.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Map of logical field name => post-meta key.
	 *
	 * @return array<string,string>
	 */
	public static function fields() {
		return array(
			'title'           => self::KEY_TITLE,
			'desc'            => self::KEY_DESC,
			'canonical'       => self::KEY_CANONICAL,
			'robots_noindex'  => self::KEY_NOINDEX,
			'robots_nofollow' => self::KEY_NOFOLLOW,
			'og_title'        => self::KEY_OG_TITLE,
			'og_desc'         => self::KEY_OG_DESC,
			'og_image'        => self::KEY_OG_IMAGE,
			'focus_keyword'   => self::KEY_FOCUS_KW,
		);
	}

	/**
	 * Register each meta key on all post types (sanitized, not exposed in REST).
	 *
	 * @return void
	 */
	public function register() {
		foreach ( self::fields() as $field => $key ) {
			register_meta(
				'post',
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => function ( $value ) use ( $field ) {
						return self::sanitize( $field, $value );
					},
					'auth_callback'     => function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}

	/**
	 * Sanitize a single field's raw value.
	 *
	 * @param string $field Logical field name.
	 * @param mixed  $value Raw value.
	 * @return string Sanitized value.
	 */
	public static function sanitize( $field, $value ) {
		switch ( $field ) {
			case 'robots_noindex':
			case 'robots_nofollow':
				return ( $value && '0' !== (string) $value ) ? '1' : '';
			case 'desc':
			case 'og_desc':
				return sanitize_textarea_field( (string) $value );
			case 'canonical':
			case 'og_image':
				return esc_url_raw( (string) $value );
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Read a single SEO field for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Logical field name.
	 * @return string Empty string when unset/unknown.
	 */
	public static function get( $post_id, $field ) {
		$fields = self::fields();
		if ( ! isset( $fields[ $field ] ) ) {
			return '';
		}
		return (string) get_post_meta( (int) $post_id, $fields[ $field ], true );
	}

	/**
	 * Read all SEO fields for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,string> Logical field name => value.
	 */
	public static function all( $post_id ) {
		$out = array();
		foreach ( self::fields() as $field => $key ) {
			$out[ $field ] = (string) get_post_meta( (int) $post_id, $key, true );
		}
		return $out;
	}

	/**
	 * Sanitize and persist a set of SEO fields for a post.
	 *
	 * Empty values delete the meta row to keep the table tidy.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $values  Logical field name => raw value.
	 * @return void
	 */
	public static function save( $post_id, array $values ) {
		$post_id = (int) $post_id;
		$fields  = self::fields();
		foreach ( $values as $field => $raw ) {
			if ( ! isset( $fields[ $field ] ) ) {
				continue;
			}
			$clean = self::sanitize( $field, $raw );
			if ( '' === $clean ) {
				delete_post_meta( $post_id, $fields[ $field ] );
			} else {
				update_post_meta( $post_id, $fields[ $field ], $clean );
			}
		}
	}
}
