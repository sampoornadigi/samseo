<?php
/**
 * Deterministic on-page SEO analyzer (0–100 score).
 *
 * Rule-based and advisory: it inspects the post content against the SEO meta and
 * focus keyword and returns a weighted score plus per-check results. No AI, no
 * remote calls — this is the deterministic baseline beneath the future AI layer.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Computes a 0–100 on-page score from local content + SEO meta.
 */
class Analyzer {

	/**
	 * Analyze a post against its SEO meta.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string,string> $meta   SEO meta (title, desc, focus_keyword, ...) as from MetaStore::all().
	 * @return array{score:int,checks:array<int,array{id:string,label:string,status:string,msg:string}>}
	 */
	public static function analyze( $post_id, array $meta ) {
		$post = get_post( (int) $post_id );

		$title = isset( $meta['title'] ) && '' !== $meta['title'] ? $meta['title'] : ( $post ? get_the_title( $post ) : '' );
		$desc  = isset( $meta['desc'] ) ? $meta['desc'] : '';
		$focus = isset( $meta['focus_keyword'] ) ? trim( (string) $meta['focus_keyword'] ) : '';

		$content_text = '';
		$slug         = '';
		if ( $post ) {
			$content_text = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
			$content_text = trim( (string) preg_replace( '/\s+/', ' ', $content_text ) );
			$slug         = (string) $post->post_name;
		}
		$word_count = '' === $content_text ? 0 : count( preg_split( '/\s+/', $content_text ) );
		$first_para = self::first_paragraph( $post );
		$title_len  = self::len( $title );
		$desc_len   = self::len( $desc );
		$has_focus  = '' !== $focus;

		$checks = array();

		$checks[] = self::range_check(
			'title_length',
			__( 'SEO title length', 'sampoorna-seo' ),
			$title_len,
			30,
			60,
			1,
			70
		);
		$checks[] = self::range_check(
			'desc_length',
			__( 'Meta description length', 'sampoorna-seo' ),
			$desc_len,
			70,
			160,
			1,
			170
		);
		$checks[] = self::bool_check(
			'focus_set',
			__( 'Focus keyword set', 'sampoorna-seo' ),
			$has_focus,
			__( 'A focus keyword is set.', 'sampoorna-seo' ),
			__( 'Set a focus keyword.', 'sampoorna-seo' )
		);
		$checks[] = self::keyword_check( 'kw_in_title', __( 'Keyword in SEO title', 'sampoorna-seo' ), $has_focus, $focus, $title );
		$checks[] = self::keyword_check( 'kw_in_desc', __( 'Keyword in meta description', 'sampoorna-seo' ), $has_focus, $focus, $desc );
		$checks[] = self::keyword_check( 'kw_in_slug', __( 'Keyword in URL slug', 'sampoorna-seo' ), $has_focus, $focus, str_replace( '-', ' ', $slug ) );
		$checks[] = self::keyword_check( 'kw_in_intro', __( 'Keyword in first paragraph', 'sampoorna-seo' ), $has_focus, $focus, $first_para );
		$checks[] = self::range_check(
			'content_length',
			__( 'Content length (words)', 'sampoorna-seo' ),
			$word_count,
			300,
			100000,
			150,
			100000
		);

		// Weighted score: good = full weight, ok = half, bad = 0.
		$weights = array(
			'title_length'   => 10,
			'desc_length'    => 10,
			'focus_set'      => 10,
			'kw_in_title'    => 15,
			'kw_in_desc'     => 10,
			'kw_in_slug'     => 10,
			'kw_in_intro'    => 10,
			'content_length' => 15,
		);
		$total   = array_sum( $weights );
		$earned  = 0.0;
		foreach ( $checks as $check ) {
			$w = isset( $weights[ $check['id'] ] ) ? $weights[ $check['id'] ] : 0;
			if ( 'good' === $check['status'] ) {
				$earned += $w;
			} elseif ( 'ok' === $check['status'] ) {
				$earned += $w / 2;
			}
		}
		$score = (int) round( ( $earned / $total ) * 100 );

		return array(
			'score'  => $score,
			'checks' => $checks,
		);
	}

	/**
	 * Multibyte-aware length.
	 *
	 * @param string $str Input string.
	 * @return int
	 */
	private static function len( $str ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $str ) : strlen( (string) $str );
	}

	/**
	 * First paragraph of a post as plain text.
	 *
	 * @param \WP_Post|null $post Post object.
	 * @return string
	 */
	private static function first_paragraph( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}
		$text  = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
		$parts = preg_split( '/\n\s*\n/', trim( $text ) );
		$first = is_array( $parts ) && isset( $parts[0] ) ? $parts[0] : $text;
		return trim( (string) preg_replace( '/\s+/', ' ', $first ) );
	}

	/**
	 * Build a length-range check result.
	 *
	 * @param string $id        Check id.
	 * @param string $label     Human label.
	 * @param int    $value     Measured value.
	 * @param int    $good_min  Lower bound of the ideal range.
	 * @param int    $good_max  Upper bound of the ideal range.
	 * @param int    $ok_min    Lower bound of the acceptable range.
	 * @param int    $ok_max    Upper bound of the acceptable range.
	 * @return array{id:string,label:string,status:string,msg:string}
	 */
	private static function range_check( $id, $label, $value, $good_min, $good_max, $ok_min, $ok_max ) {
		if ( $value >= $good_min && $value <= $good_max ) {
			$status = 'good';
		} elseif ( $value >= $ok_min && $value <= $ok_max ) {
			$status = 'ok';
		} else {
			$status = 'bad';
		}
		/* translators: 1: measured value, 2: ideal minimum, 3: ideal maximum. */
		$msg = sprintf( __( 'Currently %1$d (ideal %2$d–%3$d).', 'sampoorna-seo' ), $value, $good_min, $good_max );
		return array(
			'id'     => $id,
			'label'  => $label,
			'status' => $status,
			'msg'    => $msg,
		);
	}

	/**
	 * Build a boolean check result.
	 *
	 * @param string $id      Check id.
	 * @param string $label   Human label.
	 * @param bool   $passed  Whether the condition holds.
	 * @param string $msg_ok  Message when passed.
	 * @param string $msg_bad Message when failed.
	 * @return array{id:string,label:string,status:string,msg:string}
	 */
	private static function bool_check( $id, $label, $passed, $msg_ok, $msg_bad ) {
		return array(
			'id'     => $id,
			'label'  => $label,
			'status' => $passed ? 'good' : 'bad',
			'msg'    => $passed ? $msg_ok : $msg_bad,
		);
	}

	/**
	 * Build a "keyword appears in haystack" check result.
	 *
	 * @param string $id        Check id.
	 * @param string $label     Human label.
	 * @param bool   $has_focus Whether a focus keyword is set.
	 * @param string $focus     Focus keyword.
	 * @param string $haystack  Text to search.
	 * @return array{id:string,label:string,status:string,msg:string}
	 */
	private static function keyword_check( $id, $label, $has_focus, $focus, $haystack ) {
		if ( ! $has_focus ) {
			return array(
				'id'     => $id,
				'label'  => $label,
				'status' => 'bad',
				'msg'    => __( 'Set a focus keyword first.', 'sampoorna-seo' ),
			);
		}
		$found = '' !== $haystack && false !== stripos( $haystack, $focus );
		return array(
			'id'     => $id,
			'label'  => $label,
			'status' => $found ? 'good' : 'bad',
			'msg'    => $found ? __( 'Keyword found.', 'sampoorna-seo' ) : __( 'Keyword not found.', 'sampoorna-seo' ),
		);
	}
}
