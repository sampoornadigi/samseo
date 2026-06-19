<?php
/**
 * Deterministic SEO audit for the control plane.
 *
 * Read-only: scans recent published content for concrete, fixable meta gaps and
 * returns findings with a suggested value the control plane can approve and
 * deploy. No AI, no writes — the rule-based baseline beneath the future AI
 * audit layer.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\ControlPlane;

use Sampoorna\SEO\Meta\MetaStore;
use Sampoorna\SEO\Meta\TemplateEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces deployable audit findings for a site.
 */
class Audit {

	/** Posts scanned per audit. */
	const SCAN_LIMIT = 50;

	/** Suggested meta-description length cap. */
	const DESC_CAP = 160;

	/**
	 * Build the list of findings.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function findings() {
		$ids = get_posts(
			array(
				'post_type'        => array( 'post', 'page' ),
				'post_status'      => 'publish',
				'numberposts'      => self::SCAN_LIMIT,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		$findings = array();
		foreach ( (array) $ids as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			// Missing meta description → suggest an excerpt-derived one.
			if ( '' === MetaStore::get( $post->ID, 'desc' ) ) {
				$excerpt = TemplateEngine::render( '%excerpt%', array( 'post' => $post ) );
				$excerpt = self::truncate( $excerpt, self::DESC_CAP );
				if ( '' !== $excerpt ) {
					$findings[] = self::finding( $post->ID, 'desc', '', $excerpt, __( 'Missing meta description', 'sampoorna-seo' ), get_the_title( $post ) );
				}
			}

			// Missing social title → suggest the post title.
			if ( '' === MetaStore::get( $post->ID, 'og_title' ) ) {
				$title = (string) get_the_title( $post );
				if ( '' !== $title ) {
					$findings[] = self::finding( $post->ID, 'og_title', '', $title, __( 'Missing social (OG) title', 'sampoorna-seo' ), $title );
				}
			}
		}

		return $findings;
	}

	/**
	 * Build one finding row with a stable key.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $field     Logical field name.
	 * @param string $current   Current value.
	 * @param string $suggested Suggested value.
	 * @param string $reason    Human reason.
	 * @param string $label     Post label for display.
	 * @return array<string,mixed>
	 */
	private static function finding( $post_id, $field, $current, $suggested, $reason, $label ) {
		return array(
			'key'       => 'post:' . $post_id . ':' . $field,
			'type'      => 'post',
			'id'        => (int) $post_id,
			'field'     => $field,
			'current'   => $current,
			'suggested' => $suggested,
			'reason'    => $reason,
			'label'     => $label,
		);
	}

	/**
	 * Trim a string to a length cap on a word boundary.
	 *
	 * @param string $text Text.
	 * @param int    $cap  Max length.
	 * @return string
	 */
	private static function truncate( $text, $cap ) {
		$text = trim( (string) $text );
		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) <= $cap : strlen( $text ) <= $cap ) {
			return $text;
		}
		$cut = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $cap ) : substr( $text, 0, $cap );
		$sp  = strrpos( $cut, ' ' );
		if ( false !== $sp && $sp > 0 ) {
			$cut = substr( $cut, 0, $sp );
		}
		return rtrim( $cut );
	}
}
