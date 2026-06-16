<?php
/**
 * Smoke tests: classes load, tables exist, crypto round-trips.
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\Core\Database;
use Sampoorna\SEO\Security\Crypto;

class Sampoorna_Seo_Smoke_Test extends WP_UnitTestCase {

	public function test_core_classes_exist() {
		$this->assertTrue( class_exists( 'Sampoorna\SEO\Core\Database' ), 'Database should load' );
		$this->assertTrue( class_exists( 'Sampoorna\SEO\Security\Crypto' ), 'Crypto should load' );
		$this->assertTrue( class_exists( 'Sampoorna\SEO\Integrations\GSC\OAuth' ), 'OAuth should load' );
		$this->assertTrue( class_exists( 'Sampoorna\SEO\Integrations\GSC\Api' ), 'Api should load' );
		$this->assertTrue( class_exists( 'Sampoorna\SEO\Integrations\GSC\Inspector' ), 'Inspector should load' );
		$this->assertTrue( class_exists( 'Sampoorna\SEO\Integrations\GSC\Suggestions' ), 'Suggestions should load' );
		$this->assertTrue( class_exists( 'Sampoorna\SEO\Integrations\GSC\Reports' ), 'Reports should load' );
		$this->assertTrue( class_exists( 'Sampoorna\SEO\Admin\Screens' ), 'Admin Screens should load' );
	}

	public function test_crypto_round_trip() {
		$plain  = 'a-secret-refresh-token-12345';
		$cipher = Crypto::encrypt( $plain );

		$this->assertNotEmpty( $cipher );
		$this->assertNotSame( $plain, $cipher, 'Ciphertext must differ from plaintext' );
		$this->assertSame( $plain, Crypto::decrypt( $cipher ), 'Decrypt must recover plaintext' );
		$this->assertSame( '', Crypto::decrypt( 'not-valid-base64-$$$' ), 'Garbage decrypts to empty' );
	}

	public function test_tables_created() {
		global $wpdb;

		// maybe_upgrade() runs on plugins_loaded; ensure it has happened.
		Database::create_tables();

		foreach (
			array(
				Database::table(),
				Database::inspections_table(),
				Database::issues_table(),
				Database::queue_table(),
				Database::suggestions_table(),
			) as $table
		) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->assertSame( $table, $found, "Table {$table} should exist" );
		}
	}

	public function test_performance_upsert_and_read() {
		$property = 'https://example.test/';
		Database::create_tables();

		Database::upsert_row(
			$property,
			array(
				'date'        => gmdate( 'Y-m-d', strtotime( '-3 days' ) ),
				'page'        => 'https://example.test/sample/',
				'query'       => '',
				'clicks'      => 10,
				'impressions' => 100,
				'ctr'         => 0.1,
				'position'    => 5.0,
			)
		);

		$rows = Database::top_rows( $property, 'page', 28, 10 );
		$this->assertNotEmpty( $rows );
		$this->assertSame( 'https://example.test/sample/', $rows[0]['label'] );
		$this->assertSame( 10, (int) $rows[0]['clicks'] );
	}
}
