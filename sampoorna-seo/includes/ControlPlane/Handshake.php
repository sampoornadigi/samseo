<?php
/**
 * Control-plane handshake: signed REST endpoints + verification.
 *
 * Registers the site's `sampoorna-seo/v1` REST routes that the control plane
 * calls, authenticating every request with the per-site HMAC key (contract v1:
 * see Security\Signer). Also signs the site->plane direction. The control plane
 * is optional at runtime — these routes simply 401 until a key is in use.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\ControlPlane;

use Sampoorna\SEO\Security\Signer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and authenticates the control-plane REST endpoints.
 */
class Handshake {

	const NAMESPACE_V1 = 'sampoorna-seo/v1';

	/** Maximum allowed clock skew (seconds) between signer and verifier. */
	const MAX_SKEW = 300;

	/**
	 * Singleton instance.
	 *
	 * @var Handshake|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return Handshake
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire the REST route registration.
	 */
	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the v1 handshake routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_status' ),
				'permission_callback' => array( $this, 'verify_request' ),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/handshake',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_handshake' ),
				'permission_callback' => array( $this, 'verify_request' ),
			)
		);
	}

	/**
	 * Authenticate an incoming control-plane request (HMAC contract v1).
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return true|\WP_Error
	 */
	public function verify_request( $request ) {
		$secret = Keys::secret();
		if ( '' === $secret ) {
			return new \WP_Error( 'sampoorna_seo_not_configured', __( 'Control plane is not configured on this site.', 'sampoorna-seo' ), array( 'status' => 503 ) );
		}

		$key_id    = (string) $request->get_header( 'X-Sampoorna-Key-Id' );
		$timestamp = (string) $request->get_header( 'X-Sampoorna-Timestamp' );
		$signature = (string) $request->get_header( 'X-Sampoorna-Signature' );

		if ( '' === $key_id || '' === $timestamp || '' === $signature ) {
			return self::unauthorized();
		}
		if ( ! hash_equals( Keys::key_id(), $key_id ) ) {
			return self::unauthorized();
		}
		if ( abs( time() - (int) $timestamp ) > self::MAX_SKEW ) {
			return self::unauthorized();
		}

		$ok = Signer::verify(
			$request->get_method(),
			$request->get_route(),
			$timestamp,
			$request->get_body(),
			$signature,
			$secret
		);
		return $ok ? true : self::unauthorized();
	}

	/**
	 * GET /status — return the site descriptor.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function handle_status( $request ) {
		unset( $request );
		return new \WP_REST_Response( $this->descriptor(), 200 );
	}

	/**
	 * POST /handshake — confirm the handshake and return the site descriptor.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function handle_handshake( $request ) {
		$data              = $this->descriptor();
		$data['handshake'] = 'ok';
		$plane_id          = $request->get_param( 'plane_id' );
		$data['plane_id']  = is_string( $plane_id ) ? sanitize_text_field( $plane_id ) : '';
		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * The site descriptor returned to the control plane.
	 *
	 * @return array<string,mixed>
	 */
	private function descriptor() {
		return array(
			'name'           => 'sampoorna-seo',
			'plugin_version' => SAMPOORNA_SEO_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'site_url'       => home_url( '/' ),
			'key_id'         => Keys::key_id(),
			'modules'        => array(
				'meta'          => true,
				'gsc_connected' => \Sampoorna\SEO\Integrations\GSC\OAuth::instance()->is_connected(),
			),
			'time'           => time(),
		);
	}

	/**
	 * Build the signed headers for an outbound request to the control plane.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route/path being signed.
	 * @param string $body   Raw request body.
	 * @return array<string,string>
	 */
	public function signed_headers( $method, $route, $body = '' ) {
		$timestamp = (string) time();
		return array(
			'X-Sampoorna-Key-Id'    => Keys::key_id(),
			'X-Sampoorna-Timestamp' => $timestamp,
			'X-Sampoorna-Signature' => Signer::sign( $method, $route, $timestamp, $body, Keys::secret() ),
		);
	}

	/**
	 * Announce this site to the control plane (site->plane handshake).
	 *
	 * No-op when no control-plane URL is configured.
	 *
	 * @return array|\WP_Error|null Response, error, or null when unconfigured.
	 */
	public function announce() {
		$base = Keys::plane_url();
		if ( '' === $base || ! Keys::is_configured() ) {
			return null;
		}
		$url   = trailingslashit( $base ) . 'sites/announce';
		$body  = (string) wp_json_encode( $this->descriptor() );
		$route = '/sites/announce';
		return wp_remote_post(
			$url,
			array(
				'headers' => array_merge(
					array( 'Content-Type' => 'application/json' ),
					$this->signed_headers( 'POST', $route, $body )
				),
				'body'    => $body,
				'timeout' => 15,
			)
		);
	}

	/**
	 * Standard 401 response for failed authentication.
	 *
	 * @return \WP_Error
	 */
	private static function unauthorized() {
		return new \WP_Error( 'sampoorna_seo_unauthorized', __( 'Invalid or missing request signature.', 'sampoorna-seo' ), array( 'status' => 401 ) );
	}
}
