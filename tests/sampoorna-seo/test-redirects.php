<?php
/**
 * Tests for the redirect manager + 404 monitor.
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\Core\Database;
use Sampoorna\SEO\Technical\Redirects;

class Sampoorna_Seo_Redirects_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Database::create_tables();
	}

	private function matcher() {
		return Redirects::instance();
	}

	public function test_tables_exist() {
		global $wpdb;
		foreach ( array( Database::redirects_table(), Database::not_found_table() ) as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->assertSame( $table, $found, "Table {$table} should exist" );
		}
	}

	public function test_insert_get_delete_redirect() {
		$id = Database::insert_redirect(
			array(
				'source' => '/old',
				'target' => '/new',
				'type'   => 301,
			)
		);
		$this->assertGreaterThan( 0, $id );

		$all = Database::get_redirects( array( 'status' => 'all' ) );
		$this->assertCount( 1, $all );
		$this->assertSame( '/old', $all[0]['source'] );

		Database::set_redirect_status( array( $id ), 'disabled' );
		$this->assertCount( 0, Database::active_redirects() );

		Database::delete_redirects( array( $id ) );
		$this->assertCount( 0, Database::get_redirects( array( 'status' => 'all' ) ) );
	}

	public function test_find_exact_redirect() {
		Database::insert_redirect(
			array(
				'source' => '/old-page/',
				'target' => '/new-page/',
				'type'   => 301,
			)
		);
		$match = $this->matcher()->find_redirect( '/old-page' );
		$this->assertNotNull( $match );
		$this->assertSame( 301, $match['type'] );
		$this->assertStringContainsString( '/new-page', $match['target'] );

		$this->assertNull( $this->matcher()->find_redirect( '/something-else' ) );
	}

	public function test_disabled_redirect_is_ignored() {
		$id = Database::insert_redirect(
			array(
				'source' => '/old',
				'target' => '/new',
				'type'   => 301,
			)
		);
		Database::set_redirect_status( array( $id ), 'disabled' );
		$this->assertNull( $this->matcher()->find_redirect( '/old' ) );
	}

	public function test_regex_redirect_with_backreference() {
		Database::insert_redirect(
			array(
				'source'   => '^/blog/(.+)$',
				'target'   => '/articles/$1',
				'type'     => 301,
				'is_regex' => 1,
			)
		);
		$match = $this->matcher()->find_redirect( '/blog/hello-world' );
		$this->assertNotNull( $match );
		$this->assertStringContainsString( '/articles/hello-world', $match['target'] );
	}

	public function test_loop_is_detected() {
		Database::insert_redirect(
			array(
				'source' => '/loop',
				'target' => '/loop/',
				'type'   => 301,
			)
		);
		$this->assertNull( $this->matcher()->find_redirect( '/loop' ), 'Self-target should be skipped to avoid a loop' );
	}

	public function test_410_returns_gone() {
		Database::insert_redirect(
			array(
				'source' => '/gone',
				'target' => '',
				'type'   => 410,
			)
		);
		$match = $this->matcher()->find_redirect( '/gone' );
		$this->assertNotNull( $match );
		$this->assertSame( 410, $match['type'] );
	}

	public function test_404_log_upserts_and_counts() {
		Database::log_not_found( '/missing', 'https://ref.test/', 'UA' );
		Database::log_not_found( '/missing', 'https://ref.test/', 'UA' );

		$rows = Database::get_not_found( array( 'status' => 'all' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( '/missing', $rows[0]['url'] );
		$this->assertSame( 2, (int) $rows[0]['hits'] );

		Database::set_not_found_status( array( (int) $rows[0]['id'] ), 'ignored' );
		$this->assertCount( 0, Database::get_not_found( array( 'status' => 'new' ) ) );

		Database::delete_not_found( array( (int) $rows[0]['id'] ) );
		$this->assertCount( 0, Database::get_not_found( array( 'status' => 'all' ) ) );
	}
}
