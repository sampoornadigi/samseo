<?php
/**
 * Tests for the control-plane audit + reversible deploy/rollback.
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\ControlPlane\Audit;
use Sampoorna\SEO\ControlPlane\Deploy;
use Sampoorna\SEO\ControlPlane\Keys;
use Sampoorna\SEO\Core\Database;
use Sampoorna\SEO\Meta\MetaStore;
use Sampoorna\SEO\Meta\TermMeta;
use Sampoorna\SEO\Security\Signer;

class Sampoorna_Seo_Deploy_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Database::create_tables();
		Keys::rotate();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	/* ---------- Deploy::apply / rollback ---------- */

	public function test_apply_writes_and_journals_then_rollback_restores() {
		$post = self::factory()->post->create();
		MetaStore::save( $post, array( 'desc' => 'Original desc.' ) );

		$res = Deploy::apply(
			'd_test1',
			array(
				array(
					'type'  => 'post',
					'id'    => $post,
					'field' => 'desc',
					'value' => 'Deployed desc.',
				),
			)
		);
		$this->assertSame( 1, $res['applied'] );
		$this->assertSame( 'Deployed desc.', MetaStore::get( $post, 'desc' ) );

		$rows = Database::changes_for_deploy( 'd_test1' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Original desc.', $rows[0]['old_value'] );
		$this->assertSame( 'Deployed desc.', $rows[0]['new_value'] );

		$rb = Deploy::rollback( 'd_test1' );
		$this->assertSame( 1, $rb['restored'] );
		$this->assertSame( 0, $rb['skipped'] );
		$this->assertSame( 'Original desc.', MetaStore::get( $post, 'desc' ) );
	}

	public function test_apply_is_idempotent() {
		$post = self::factory()->post->create();
		$change = array(
			array(
				'type'  => 'post',
				'id'    => $post,
				'field' => 'desc',
				'value' => 'X.',
			),
		);
		$this->assertSame( 1, Deploy::apply( 'd_idem', $change )['applied'] );
		$second = Deploy::apply( 'd_idem', $change );
		$this->assertSame( 0, $second['applied'] );
		$this->assertTrue( ! empty( $second['idempotent'] ) );
	}

	public function test_rollback_skips_externally_modified_value() {
		$post = self::factory()->post->create();
		Deploy::apply(
			'd_mod',
			array(
				array(
					'type'  => 'post',
					'id'    => $post,
					'field' => 'desc',
					'value' => 'Deployed.',
				),
			)
		);
		// A human edits the field after deploy.
		MetaStore::save( $post, array( 'desc' => 'Human edit.' ) );

		$rb = Deploy::rollback( 'd_mod' );
		$this->assertSame( 0, $rb['restored'] );
		$this->assertSame( 1, $rb['skipped'] );
		// The human edit is preserved, never clobbered.
		$this->assertSame( 'Human edit.', MetaStore::get( $post, 'desc' ) );
	}

	public function test_apply_supports_terms() {
		$cat = self::factory()->category->create();
		Deploy::apply(
			'd_term',
			array(
				array(
					'type'  => 'term',
					'id'    => $cat,
					'field' => 'desc',
					'value' => 'Term deployed desc.',
				),
			)
		);
		$this->assertSame( 'Term deployed desc.', TermMeta::get( $cat, 'desc' ) );
		Deploy::rollback( 'd_term' );
		$this->assertSame( '', TermMeta::get( $cat, 'desc' ) );
	}

	public function test_apply_rejects_unknown_field_and_type() {
		$post = self::factory()->post->create();
		$res  = Deploy::apply(
			'd_bad',
			array(
				array(
					'type'  => 'post',
					'id'    => $post,
					'field' => 'not_a_field',
					'value' => 'x',
				),
				array(
					'type'  => 'widget',
					'id'    => $post,
					'field' => 'desc',
					'value' => 'x',
				),
			)
		);
		$this->assertSame( 0, $res['applied'] );
	}

	/* ---------- Config templating: option-type changes ---------- */

	public function test_apply_writes_option_then_rollback_restores() {
		update_option( \Sampoorna\SEO\Geo\LlmsTxt::OPT_INTRO, 'Original intro.' );

		$res = Deploy::apply(
			'd_opt',
			array(
				array(
					'type'  => 'option',
					'id'    => 0,
					'field' => 'llms_intro',
					'value' => 'Vertical template intro.',
				),
				array(
					'type'  => 'option',
					'id'    => 0,
					'field' => 'indexnow_enabled',
					'value' => '1',
				),
			)
		);
		$this->assertSame( 2, $res['applied'] );
		$this->assertSame( 'Vertical template intro.', get_option( \Sampoorna\SEO\Geo\LlmsTxt::OPT_INTRO ) );
		$this->assertSame( '1', \Sampoorna\SEO\ControlPlane\Settings::read( 'indexnow_enabled' ) );

		Deploy::rollback( 'd_opt' );
		$this->assertSame( 'Original intro.', get_option( \Sampoorna\SEO\Geo\LlmsTxt::OPT_INTRO ) );
	}

	public function test_apply_rejects_non_allowlisted_option() {
		$res = Deploy::apply(
			'd_opt_bad',
			array(
				array(
					'type'  => 'option',
					'id'    => 0,
					'field' => 'ai_api_key',
					'value' => 'secret',
				),
			)
		);
		$this->assertSame( 0, $res['applied'] );
		$this->assertSame( '', get_option( 'sampoorna_seo_ai_api_key', '' ) );
	}

	public function test_option_bool_sanitizes_to_canonical_string() {
		\Sampoorna\SEO\ControlPlane\Settings::write( 'llms_enabled', 'true' );
		$this->assertSame( '1', \Sampoorna\SEO\ControlPlane\Settings::read( 'llms_enabled' ) );
		\Sampoorna\SEO\ControlPlane\Settings::write( 'llms_enabled', '0' );
		$this->assertSame( '0', \Sampoorna\SEO\ControlPlane\Settings::read( 'llms_enabled' ) );
	}

	/* ---------- Audit ---------- */

	public function test_audit_flags_missing_description() {
		$post = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_excerpt' => 'A clear manual excerpt for the snippet.',
			)
		);
		$keys = wp_list_pluck( Audit::findings(), 'key' );
		$this->assertContains( 'post:' . $post . ':desc', $keys );
	}

	/* ---------- Signed routes ---------- */

	public function test_apply_route_requires_signature() {
		$res = rest_get_server()->dispatch( new \WP_REST_Request( 'POST', '/sampoorna-seo/v1/apply' ) );
		$this->assertSame( 401, $res->get_status() );
	}

	public function test_apply_and_rollback_routes_with_signature() {
		$post = self::factory()->post->create();
		$body = wp_json_encode(
			array(
				'deploy_id' => 'd_route',
				'changes'   => array(
					array(
						'type'  => 'post',
						'id'    => $post,
						'field' => 'desc',
						'value' => 'Routed desc.',
					),
				),
			)
		);
		$status = $this->dispatch_signed( 'POST', '/sampoorna-seo/v1/apply', $body );
		$this->assertSame( 200, $status );
		$this->assertSame( 'Routed desc.', MetaStore::get( $post, 'desc' ) );

		$rb_body = wp_json_encode( array( 'deploy_id' => 'd_route' ) );
		$status  = $this->dispatch_signed( 'POST', '/sampoorna-seo/v1/rollback', $rb_body );
		$this->assertSame( 200, $status );
		$this->assertSame( '', MetaStore::get( $post, 'desc' ) );
	}

	/**
	 * Dispatch a signed POST and return the status code.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  REST route.
	 * @param string $body   Raw JSON body.
	 * @return int
	 */
	private function dispatch_signed( $method, $route, $body ) {
		$ts  = (string) time();
		$sig = Signer::sign( $method, $route, $ts, $body, Keys::secret() );
		$req = new \WP_REST_Request( $method, $route );
		$req->set_header( 'X-Sampoorna-Key-Id', Keys::key_id() );
		$req->set_header( 'X-Sampoorna-Timestamp', $ts );
		$req->set_header( 'X-Sampoorna-Signature', $sig );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( $body );
		return rest_get_server()->dispatch( $req )->get_status();
	}
}
