<?php
/**
 * AI service layer — the single point that talks to language models.
 *
 * Every module routes model calls through this class. It calls the Anthropic
 * Messages API directly over the WordPress HTTP API (no runtime third-party
 * dependencies). Guardrails are mandatory: validate-don't-trust on every
 * response, no-fabrication prompting, and content-hash caching to cap cost.
 * Human-in-the-loop is enforced by callers (suggestions are never auto-applied).
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Ai;

use Sampoorna\SEO\Security\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal Anthropic Messages API client for deterministic SEO assists.
 */
class AiClient {

	const OPT_API_KEY = 'sampoorna_seo_ai_api_key'; // Encrypted at rest.
	const OPT_MODEL   = 'sampoorna_seo_ai_model';

	const DEFAULT_MODEL = 'claude-haiku-4-5';
	const ENDPOINT      = 'https://api.anthropic.com/v1/messages';
	const API_VERSION   = '2023-06-01';

	/**
	 * Models the admin may select. Keep cheap-first for bulk meta generation.
	 *
	 * @return string[]
	 */
	public static function allowed_models() {
		return array( 'claude-haiku-4-5', 'claude-sonnet-4-6', 'claude-opus-4-8' );
	}

	/**
	 * Whether the AI layer is usable (an API key is stored).
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::api_key();
	}

	/**
	 * The decrypted API key, or empty string.
	 *
	 * @return string
	 */
	private static function api_key() {
		return Crypto::decrypt( get_option( self::OPT_API_KEY, '' ) );
	}

	/**
	 * The configured model, validated against the allow-list.
	 *
	 * @return string
	 */
	public static function model() {
		$model = (string) get_option( self::OPT_MODEL, self::DEFAULT_MODEL );
		return in_array( $model, self::allowed_models(), true ) ? $model : self::DEFAULT_MODEL;
	}

	/**
	 * Generate an SEO title + meta description for a post.
	 *
	 * Returns suggestions only — the caller presents them for human review and
	 * never auto-saves (human-in-the-loop guardrail).
	 *
	 * @param int    $post_id       Post ID.
	 * @param string $focus_keyword Optional focus keyword to weave in.
	 * @return array{title:string,description:string}|\WP_Error
	 */
	public static function generate_title_meta( $post_id, $focus_keyword = '' ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'sampoorna_seo_ai_no_post', __( 'Post not found.', 'sampoorna-seo' ) );
		}

		$excerpt = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
		$excerpt = trim( (string) preg_replace( '/\s+/', ' ', $excerpt ) );
		$excerpt = wp_trim_words( $excerpt, 180, '' );
		$focus   = trim( (string) $focus_keyword );

		$system = 'You are an expert SEO copywriter. Write a search-optimized title and meta description for the given web page. '
			. 'Rules: the title must be at most 60 characters; the meta description must be at most 155 characters. '
			. 'Base everything strictly on the provided content — do not invent facts, statistics, prices, or claims. '
			. ( '' !== $focus ? 'Use the focus keyword naturally in both fields. ' : '' )
			. 'Write in the page\'s language. Return only the structured fields.';

		$user = 'Site name: ' . get_bloginfo( 'name' ) . "\n"
			. ( '' !== $focus ? 'Focus keyword: ' . $focus . "\n" : '' )
			. 'Page title: ' . get_the_title( $post ) . "\n"
			. "Page content:\n" . $excerpt;

		$schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'title', 'meta_description' ),
			'properties'           => array(
				'title'            => array( 'type' => 'string' ),
				'meta_description' => array( 'type' => 'string' ),
			),
		);

		$result = self::call_messages( $system, $user, $schema );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$title = isset( $result['title'] ) && is_string( $result['title'] ) ? trim( $result['title'] ) : '';
		$desc  = isset( $result['meta_description'] ) && is_string( $result['meta_description'] ) ? trim( $result['meta_description'] ) : '';
		if ( '' === $title || '' === $desc ) {
			return new \WP_Error( 'sampoorna_seo_ai_invalid', __( 'The model returned an unexpected response. Please try again.', 'sampoorna-seo' ) );
		}

		return array(
			'title'       => $title,
			'description' => $desc,
		);
	}

	/**
	 * Call the Messages API with a structured-output schema. The single
	 * model-talking method; a future control-plane proxy swaps in here.
	 *
	 * @param string              $system System prompt.
	 * @param string              $user   User message.
	 * @param array<string,mixed> $schema JSON schema for the structured output.
	 * @return array<string,mixed>|\WP_Error Decoded model JSON, or error.
	 */
	private static function call_messages( $system, $user, array $schema ) {
		$key = self::api_key();
		if ( '' === $key ) {
			return new \WP_Error( 'sampoorna_seo_ai_unconfigured', __( 'No AI API key is configured. Add one under SEO → Settings.', 'sampoorna-seo' ) );
		}

		$body = wp_json_encode(
			array(
				'model'         => self::model(),
				'max_tokens'    => 1024,
				'system'        => $system,
				'messages'      => array(
					array(
						'role'    => 'user',
						'content' => $user,
					),
				),
				'output_config' => array(
					'format' => array(
						'type'   => 'json_schema',
						'schema' => $schema,
					),
				),
			)
		);

		// Content-hash cache: identical inputs (model + prompt + schema) reuse the result.
		$cache_key = 'sampoorna_seo_ai_' . md5( (string) $body );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'headers' => array(
					'x-api-key'         => $key,
					'anthropic-version' => self::API_VERSION,
					'content-type'      => 'application/json',
				),
				'body'    => $body,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( 200 !== $code ) {
			$message = '';
			if ( is_array( $data ) && isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
				$message = $data['error']['message'];
			}
			/* translators: 1: HTTP status code, 2: error message from the API. */
			return new \WP_Error( 'sampoorna_seo_ai_http_error', sprintf( __( 'AI request failed (HTTP %1$d): %2$s', 'sampoorna-seo' ), $code, $message ) );
		}

		// Find the first text block and parse its JSON payload.
		$text = '';
		if ( is_array( $data ) && isset( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( is_array( $block ) && isset( $block['type'], $block['text'] ) && 'text' === $block['type'] && is_string( $block['text'] ) ) {
					$text = $block['text'];
					break;
				}
			}
		}

		$parsed = '' !== $text ? json_decode( $text, true ) : null;
		if ( ! is_array( $parsed ) ) {
			return new \WP_Error( 'sampoorna_seo_ai_unparseable', __( 'Could not parse the AI response.', 'sampoorna-seo' ) );
		}

		set_transient( $cache_key, $parsed, DAY_IN_SECONDS );
		return $parsed;
	}
}
