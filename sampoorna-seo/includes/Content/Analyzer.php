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

	/* ---------- Readability ---------- */

	/**
	 * Readability score (0–100) from sentence/paragraph metrics.
	 *
	 * @param int $post_id Post ID.
	 * @return array{score:int,checks:array<int,array{id:string,label:string,status:string,msg:string}>}
	 */
	public static function readability( $post_id ) {
		$post      = get_post( (int) $post_id );
		$text      = $post ? self::plain_text( $post ) : '';
		$words     = self::word_list( $text );
		$wc        = count( $words );
		$sentences = self::sentences( $text );
		$sc        = max( 1, count( $sentences ) );
		$avg_words = $wc > 0 ? (int) round( $wc / $sc ) : 0;

		$long = 0;
		foreach ( $sentences as $sentence ) {
			if ( count( self::word_list( $sentence ) ) > 25 ) {
				++$long;
			}
		}
		$long_pct = (int) round( ( $long / $sc ) * 100 );

		$passive = 0;
		foreach ( $sentences as $sentence ) {
			if ( self::is_passive( $sentence ) ) {
				++$passive;
			}
		}
		$passive_pct = (int) round( ( $passive / $sc ) * 100 );

		$paragraphs = self::paragraphs( $post );
		$pc         = max( 1, count( $paragraphs ) );
		$long_para  = 0;
		foreach ( $paragraphs as $paragraph ) {
			if ( count( self::word_list( $paragraph ) ) > 150 ) {
				++$long_para;
			}
		}
		$long_para_pct = (int) round( ( $long_para / $pc ) * 100 );

		$syllables = 0;
		foreach ( $words as $word ) {
			$syllables += self::syllables( $word );
		}
		$flesch  = $wc > 0 ? (int) round( 206.835 - 1.015 * ( $wc / $sc ) - 84.6 * ( $syllables / $wc ) ) : 0;
		$has_sub = $post ? count( self::headings( (string) $post->post_content ) ) > 0 : false;

		$checks = array(
			self::make_check( 'flesch', __( 'Reading ease', 'sampoorna-seo' ), $flesch >= 60 ? 'good' : ( $flesch >= 30 ? 'ok' : 'bad' ), sprintf( /* translators: %d: Flesch reading-ease score. */ __( 'Flesch score ~%d (higher is easier).', 'sampoorna-seo' ), $flesch ) ),
			self::range_check( 'sentence_length', __( 'Average sentence length', 'sampoorna-seo' ), $avg_words, 1, 20, 1, 25 ),
			self::make_check( 'long_sentences', __( 'Long sentences', 'sampoorna-seo' ), $long_pct <= 25 ? 'good' : ( $long_pct <= 35 ? 'ok' : 'bad' ), sprintf( /* translators: %d: percentage of long sentences. */ __( '%d%% of sentences exceed 25 words.', 'sampoorna-seo' ), $long_pct ) ),
			self::make_check( 'paragraph_length', __( 'Paragraph length', 'sampoorna-seo' ), $long_para_pct <= 10 ? 'good' : ( $long_para_pct <= 30 ? 'ok' : 'bad' ), sprintf( /* translators: %d: percentage of long paragraphs. */ __( '%d%% of paragraphs exceed 150 words.', 'sampoorna-seo' ), $long_para_pct ) ),
			self::bool_check( 'subheadings', __( 'Subheadings', 'sampoorna-seo' ), $wc < 300 || $has_sub, __( 'Content is broken up with subheadings.', 'sampoorna-seo' ), __( 'Add H2/H3 subheadings to long content.', 'sampoorna-seo' ) ),
			self::make_check( 'passive_voice', __( 'Passive voice', 'sampoorna-seo' ), $passive_pct <= 10 ? 'good' : ( $passive_pct <= 20 ? 'ok' : 'bad' ), sprintf( /* translators: %d: percentage of passive sentences. */ __( '%d%% of sentences use passive voice.', 'sampoorna-seo' ), $passive_pct ) ),
		);

		$weights = array(
			'flesch'           => 20,
			'sentence_length'  => 20,
			'long_sentences'   => 15,
			'paragraph_length' => 15,
			'subheadings'      => 15,
			'passive_voice'    => 15,
		);
		return array(
			'score'  => self::score_from_checks( $checks, $weights ),
			'checks' => $checks,
		);
	}

	/* ---------- AEO (Answer-Engine Optimization) ---------- */

	/**
	 * AEO score (0–100): how quotable the content is for answer engines.
	 *
	 * @param int $post_id Post ID.
	 * @return array{score:int,checks:array<int,array{id:string,label:string,status:string,msg:string}>}
	 */
	public static function aeo( $post_id ) {
		$post     = get_post( (int) $post_id );
		$content  = $post ? (string) $post->post_content : '';
		$headings = self::headings( $content );

		$question_headings = array();
		foreach ( $headings as $heading ) {
			if ( self::is_question( $heading ) ) {
				$question_headings[] = $heading;
			}
		}
		$has_question = count( $question_headings ) >= 1;
		$has_faq      = count( $question_headings ) >= 2 || 1 === preg_match( '/frequently\s+asked|faq|wp:yoast\/faq-block/i', $content );
		$has_list     = 1 === preg_match( '/<(ul|ol|table)\b/i', $content );

		$paragraphs = self::paragraphs( $post );
		$direct     = false;
		foreach ( array_slice( $paragraphs, 0, 3 ) as $paragraph ) {
			$len = self::len( $paragraph );
			if ( $len >= 40 && $len <= 320 ) {
				$direct = true;
				break;
			}
		}
		$first    = self::first_paragraph( $post );
		$intro_ok = '' !== $first && self::len( $first ) <= 320;

		$checks = array(
			self::bool_check( 'question_heading', __( 'Question heading', 'sampoorna-seo' ), $has_question, __( 'Has a question-style heading.', 'sampoorna-seo' ), __( 'Add a heading phrased as a question (what/why/how…).', 'sampoorna-seo' ) ),
			self::bool_check( 'direct_answer', __( 'Direct answer', 'sampoorna-seo' ), $direct, __( 'Has a concise answer paragraph near the top.', 'sampoorna-seo' ), __( 'Add a short (40–320 char) direct answer near the top.', 'sampoorna-seo' ) ),
			self::bool_check( 'lists_or_tables', __( 'Lists or tables', 'sampoorna-seo' ), $has_list, __( 'Contains an extractable list or table.', 'sampoorna-seo' ), __( 'Add a bulleted/numbered list or a table.', 'sampoorna-seo' ) ),
			self::bool_check( 'faq', __( 'FAQ section', 'sampoorna-seo' ), $has_faq, __( 'Has an FAQ / multiple Q&A headings.', 'sampoorna-seo' ), __( 'Add an FAQ section with question headings.', 'sampoorna-seo' ) ),
			self::bool_check( 'concise_intro', __( 'Concise intro', 'sampoorna-seo' ), $intro_ok, __( 'The intro paragraph is concise.', 'sampoorna-seo' ), __( 'Tighten the opening paragraph.', 'sampoorna-seo' ) ),
		);

		$weights = array(
			'question_heading' => 25,
			'direct_answer'    => 25,
			'lists_or_tables'  => 20,
			'faq'              => 15,
			'concise_intro'    => 15,
		);
		return array(
			'score'  => self::score_from_checks( $checks, $weights ),
			'checks' => $checks,
		);
	}

	/* ---------- Shared helpers ---------- */

	/**
	 * Build a check result with an explicit status/message.
	 *
	 * @param string $id     Check id.
	 * @param string $label  Human label.
	 * @param string $status good|ok|bad.
	 * @param string $msg    Message.
	 * @return array{id:string,label:string,status:string,msg:string}
	 */
	private static function make_check( $id, $label, $status, $msg ) {
		return array(
			'id'     => $id,
			'label'  => $label,
			'status' => $status,
			'msg'    => $msg,
		);
	}

	/**
	 * Weighted 0–100 score (good = full weight, ok = half).
	 *
	 * @param array<int,array{id:string,status:string}> $checks  Checks.
	 * @param array<string,int>                         $weights id => weight.
	 * @return int
	 */
	private static function score_from_checks( array $checks, array $weights ) {
		$total  = array_sum( $weights );
		$earned = 0.0;
		foreach ( $checks as $check ) {
			$w = isset( $weights[ $check['id'] ] ) ? $weights[ $check['id'] ] : 0;
			if ( 'good' === $check['status'] ) {
				$earned += $w;
			} elseif ( 'ok' === $check['status'] ) {
				$earned += $w / 2;
			}
		}
		return $total > 0 ? (int) round( ( $earned / $total ) * 100 ) : 0;
	}

	/**
	 * Plain text of a post (shortcodes/tags stripped, whitespace collapsed).
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private static function plain_text( $post ) {
		$text = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * Split text into non-empty sentences.
	 *
	 * @param string $text Plain text.
	 * @return string[]
	 */
	private static function sentences( $text ) {
		$parts = preg_split( '/[.!?]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $parts ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', $parts ) ) );
	}

	/**
	 * Split text into words.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function word_list( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return array();
		}
		$parts = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $parts ) ? $parts : array();
	}

	/**
	 * Rough syllable count for an English word (vowel groups, minus silent e).
	 *
	 * @param string $word Word.
	 * @return int
	 */
	private static function syllables( $word ) {
		$w = strtolower( (string) preg_replace( '/[^A-Za-z]/', '', $word ) );
		if ( '' === $w ) {
			return 0;
		}
		$count = preg_match_all( '/[aeiouy]+/', $w );
		$count = is_int( $count ) ? $count : 0;
		if ( 'e' === substr( $w, -1 ) ) {
			--$count;
		}
		return max( 1, $count );
	}

	/**
	 * Rough passive-voice detection (be-verb + past participle heuristic).
	 *
	 * @param string $sentence Sentence.
	 * @return bool
	 */
	private static function is_passive( $sentence ) {
		return 1 === preg_match( '/\b(is|are|was|were|be|been|being)\b\s+(\w+\s+){0,2}\w+(ed|en)\b/i', (string) $sentence );
	}

	/**
	 * Whether a heading reads as a question.
	 *
	 * @param string $heading Heading text.
	 * @return bool
	 */
	private static function is_question( $heading ) {
		$heading = trim( (string) $heading );
		if ( '' === $heading ) {
			return false;
		}
		if ( false !== strpos( $heading, '?' ) ) {
			return true;
		}
		return 1 === preg_match( '/^(who|what|why|how|when|where|which|is|are|can|does|do|should|will)\b/i', $heading );
	}

	/**
	 * Extract heading texts (H1–H6) from HTML content.
	 *
	 * @param string $content Post content HTML.
	 * @return string[]
	 */
	private static function headings( $content ) {
		$doc = self::dom( $content );
		if ( null === $doc ) {
			return array();
		}
		$out = array();
		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $tag ) {
			foreach ( $doc->getElementsByTagName( $tag ) as $el ) {
				$text = trim( (string) preg_replace( '/\s+/', ' ', $el->textContent ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode::textContent is a PHP DOM API property.
				if ( '' !== $text ) {
					$out[] = $text;
				}
			}
		}
		return $out;
	}

	/**
	 * Paragraph texts: DOM <p> elements, falling back to blank-line splitting.
	 *
	 * @param \WP_Post|null $post Post.
	 * @return string[]
	 */
	private static function paragraphs( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}
		$out = array();
		$doc = self::dom( (string) $post->post_content );
		if ( null !== $doc ) {
			foreach ( $doc->getElementsByTagName( 'p' ) as $el ) {
				$text = trim( (string) preg_replace( '/\s+/', ' ', $el->textContent ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode::textContent is a PHP DOM API property.
				if ( '' !== $text ) {
					$out[] = $text;
				}
			}
		}
		if ( empty( $out ) ) {
			foreach ( preg_split( '/\n\s*\n/', self::plain_text( $post ) ) as $para ) {
				$para = trim( (string) $para );
				if ( '' !== $para ) {
					$out[] = $para;
				}
			}
		}
		return $out;
	}

	/**
	 * Parse HTML content into a DOMDocument (UTF-8, malformed-tolerant).
	 *
	 * @param string $content HTML.
	 * @return \DOMDocument|null
	 */
	private static function dom( $content ) {
		$content = trim( (string) $content );
		if ( '' === $content || ! class_exists( '\DOMDocument' ) ) {
			return null;
		}
		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<html><head><meta charset="utf-8"></head><body>' . $content . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $doc;
	}
}
