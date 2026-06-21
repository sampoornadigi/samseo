<?php
/**
 * Lead capture (Phase 4 SEO->CRM flow). Hooks common form plugins (Contact Form 7,
 * WPForms, Gravity Forms, Ninja Forms) plus a generic filter, normalizes the
 * submission to name/email/phone + attribution, and forwards it (signed) to the
 * control plane, which emits seo.lead.captured -> a CRM contact.
 *
 * Field detection is best-effort by common field keys/labels; refine per-site with
 * the `sampoorna_seo_lead` filter (return falsy to skip a submission).
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Integrations\Leads;

use Sampoorna\SEO\ControlPlane\Handshake;

defined( 'ABSPATH' ) || exit;

class LeadCapture {

	/** @var LeadCapture|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		add_action( 'wpcf7_mail_sent', array( $this, 'on_cf7' ), 10, 1 );
		add_action( 'wpforms_process_complete', array( $this, 'on_wpforms' ), 10, 4 );
		add_action( 'gform_after_submission', array( $this, 'on_gravity' ), 10, 2 );
		add_action( 'ninja_forms_after_submission', array( $this, 'on_ninja' ), 10, 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_attr_js' ) );
	}

	public function on_cf7( $contact_form ) { // phpcs:ignore
		$data = array();
		if ( class_exists( '\WPCF7_Submission' ) ) {
			$sub = \WPCF7_Submission::get_instance();
			if ( $sub ) {
				$data = (array) $sub->get_posted_data();
			}
		}
		$this->capture( $this->from_fields( $data ) );
	}

	public function on_wpforms( $fields, $entry, $form_data, $entry_id ) { // phpcs:ignore
		$vals = array();
		foreach ( (array) $fields as $f ) {
			$vals[ strtolower( (string) ( $f['name'] ?? '' ) ) ] = $f['value'] ?? '';
		}
		$this->capture( $this->from_fields( $vals ) );
	}

	public function on_gravity( $entry, $form ) { // phpcs:ignore
		$vals = array();
		foreach ( (array) ( $form['fields'] ?? array() ) as $f ) {
			$label = strtolower( (string) ( is_object( $f ) ? ( $f->label ?? '' ) : ( $f['label'] ?? '' ) ) );
			$id    = is_object( $f ) ? ( $f->id ?? '' ) : ( $f['id'] ?? '' );
			if ( '' !== $label && isset( $entry[ $id ] ) ) {
				$vals[ $label ] = $entry[ $id ];
			}
		}
		$this->capture( $this->from_fields( $vals ) );
	}

	public function on_ninja( $form_data ) { // phpcs:ignore
		$vals = array();
		foreach ( (array) ( $form_data['fields'] ?? array() ) as $f ) {
			$key = strtolower( (string) ( $f['key'] ?? ( $f['label'] ?? '' ) ) );
			if ( '' !== $key ) {
				$vals[ $key ] = $f['value'] ?? '';
			}
		}
		$this->capture( $this->from_fields( $vals ) );
	}

	/**
	 * Heuristically pull name/email/phone from a flat field map.
	 *
	 * @param array<string,mixed> $vals
	 * @return array<string,string>
	 */
	private function from_fields( array $vals ) {
		$lead = array(
			'name'  => '',
			'email' => '',
			'phone' => '',
		);
		foreach ( $vals as $k => $v ) {
			$v  = is_array( $v ) ? implode( ' ', $v ) : (string) $v;
			$kk = (string) $k;
			if ( '' === $lead['email'] && ( false !== strpos( $kk, 'email' ) || is_email( $v ) ) ) {
				$lead['email'] = sanitize_email( $v );
			} elseif ( '' === $lead['phone'] && ( false !== strpos( $kk, 'phone' ) || false !== strpos( $kk, 'mobile' ) || false !== strpos( $kk, 'tel' ) ) ) {
				$lead['phone'] = preg_replace( '/[^0-9+]/', '', $v );
			} elseif ( '' === $lead['name'] && false !== strpos( $kk, 'name' ) ) {
				$lead['name'] = sanitize_text_field( $v );
			}
		}
		return $lead;
	}

	/**
	 * Attach attribution (from the sampoorna_attr cookie + referrer) and forward.
	 *
	 * @param array<string,string> $lead
	 */
	private function capture( array $lead ) {
		$attr = array();
		if ( isset( $_COOKIE['sampoorna_attr'] ) ) {
			$decoded = json_decode( stripslashes( $_COOKIE['sampoorna_attr'] ), true ); // phpcs:ignore
			if ( is_array( $decoded ) ) {
				$attr = $decoded;
			}
		}
		$lead['utm']         = array(
			'source'   => (string) ( $attr['utm_source'] ?? '' ),
			'medium'   => (string) ( $attr['utm_medium'] ?? '' ),
			'campaign' => (string) ( $attr['utm_campaign'] ?? '' ),
			'term'     => (string) ( $attr['utm_term'] ?? '' ),
			'content'  => (string) ( $attr['utm_content'] ?? '' ),
		);
		$lead['gclid']       = (string) ( $attr['gclid'] ?? '' );
		$lead['fbclid']      = (string) ( $attr['fbclid'] ?? '' );
		$lead['landingPage'] = (string) ( $attr['landing'] ?? '' );
		$lead['referrer']    = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

		/**
		 * Refine or override a captured lead before it is forwarded. Return a
		 * falsy value to skip forwarding this submission.
		 *
		 * @param array $lead
		 */
		$lead = apply_filters( 'sampoorna_seo_lead', $lead );
		if ( empty( $lead ) || ( empty( $lead['email'] ) && empty( $lead['phone'] ) ) ) {
			return;
		}
		Handshake::instance()->send_lead( $lead );
	}

	/** Stamp UTM/gclid/fbclid + landing into the sampoorna_attr cookie on first touch. */
	public function enqueue_attr_js() {
		$handle = 'sampoorna-seo-attr';
		wp_register_script( $handle, '', array(), SAMPOORNA_SEO_VERSION, false );
		wp_enqueue_script( $handle );
		$js = "(function(){try{var p=new URLSearchParams(location.search);var k=['utm_source','utm_medium','utm_campaign','utm_term','utm_content','gclid','fbclid'];var c={},has=false;k.forEach(function(x){var v=p.get(x);if(v){c[x]=v;has=true;}});if(has||document.cookie.indexOf('sampoorna_attr=')===-1){c.landing=location.pathname+location.search;document.cookie='sampoorna_attr='+encodeURIComponent(JSON.stringify(c))+';path=/;max-age=2592000;samesite=lax';}}catch(e){}})();";
		wp_add_inline_script( $handle, $js );
	}
}
