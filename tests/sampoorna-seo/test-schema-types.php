<?php
/**
 * Tests for the extra schema node types: FAQ, Product, LocalBusiness.
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\Schema\Faq;
use Sampoorna\SEO\Schema\HowTo;
use Sampoorna\SEO\Schema\Product;
use Sampoorna\SEO\Schema\LocalBusiness;

class Sampoorna_Seo_Schema_Types_Test extends WP_UnitTestCase {

	/* ---------- HowTo ---------- */

	public function test_howto_node_built_from_ordered_steps() {
		$content = "<p>Intro.</p>\n<ol>\n<li>Open the settings page.</li>\n<li>Paste your key.</li>\n<li>Click save.</li>\n</ol>";
		$node    = HowTo::howto_node( 'How to connect your site', $content, 'https://example.com/connect/' );

		$this->assertSame( 'HowTo', $node['@type'] );
		$this->assertSame( 'https://example.com/connect/#howto', $node['@id'] );
		$this->assertSame( 'How to connect your site', $node['name'] );
		$this->assertCount( 3, $node['step'] );
		$this->assertSame( 'HowToStep', $node['step'][0]['@type'] );
		$this->assertSame( 1, $node['step'][0]['position'] );
		$this->assertStringContainsString( 'settings page', $node['step'][0]['text'] );
	}

	public function test_howto_node_empty_when_title_is_not_a_how_to() {
		$content = '<ol><li>Step one.</li><li>Step two.</li></ol>';
		$this->assertSame( array(), HowTo::howto_node( 'Our company history', $content, 'https://example.com/x/' ) );
	}

	public function test_howto_node_empty_without_enough_steps() {
		$content = '<ol><li>Only one step.</li></ol>';
		$this->assertSame( array(), HowTo::howto_node( 'How to do it', $content, 'https://example.com/x/' ) );
	}

	/* ---------- FAQ ---------- */

	public function test_faq_node_built_from_two_question_pairs() {
		$content = "<h2>What is local SEO?</h2>\n<p>Local SEO helps a business rank in nearby map and search results.</p>\n"
			. "<h2>How do I start?</h2>\n<p>Claim and complete your Google Business Profile first.</p>";
		$node    = Faq::faq_node( $content, 'https://example.com/guide/' );

		$this->assertSame( 'FAQPage', $node['@type'] );
		$this->assertSame( 'https://example.com/guide/#faq', $node['@id'] );
		$this->assertCount( 2, $node['mainEntity'] );
		$this->assertSame( 'Question', $node['mainEntity'][0]['@type'] );
		$this->assertSame( 'What is local SEO?', $node['mainEntity'][0]['name'] );
		$this->assertSame( 'Answer', $node['mainEntity'][0]['acceptedAnswer']['@type'] );
		$this->assertStringContainsString( 'map and search', $node['mainEntity'][0]['acceptedAnswer']['text'] );
	}

	public function test_faq_node_empty_for_single_pair() {
		$content = "<h2>What is local SEO?</h2>\n<p>It helps a business rank locally.</p>\n<h2>Our services</h2>\n<p>We do many things.</p>";
		$this->assertSame( array(), Faq::faq_node( $content, 'https://example.com/x/' ) );
	}

	public function test_faq_node_empty_for_no_questions() {
		$content = '<h2>About us</h2><p>We are a team.</p><h2>Our work</h2><p>We build things.</p>';
		$this->assertSame( array(), Faq::faq_node( $content, 'https://example.com/x/' ) );
	}

	/* ---------- Product ---------- */

	public function test_product_node_from_data() {
		$node = Product::product_node(
			array(
				'name'         => 'Widget',
				'description'  => 'A useful widget.',
				'sku'          => 'WID-1',
				'image'        => 'https://example.com/widget.jpg',
				'price'        => '19.99',
				'currency'     => 'INR',
				'in_stock'     => true,
				'rating'       => 4.5,
				'review_count' => 12,
			),
			'https://example.com/product/widget/'
		);

		$this->assertSame( 'Product', $node['@type'] );
		$this->assertSame( 'https://example.com/product/widget/#product', $node['@id'] );
		$this->assertSame( 'Widget', $node['name'] );
		$this->assertSame( '19.99', $node['offers']['price'] );
		$this->assertSame( 'INR', $node['offers']['priceCurrency'] );
		$this->assertSame( 'https://schema.org/InStock', $node['offers']['availability'] );
		$this->assertSame( '4.5', $node['aggregateRating']['ratingValue'] );
		$this->assertSame( 12, $node['aggregateRating']['reviewCount'] );
	}

	public function test_product_node_empty_without_name() {
		$this->assertSame( array(), Product::product_node( array( 'price' => '5' ), 'https://example.com/p/' ) );
	}

	public function test_product_node_out_of_stock_and_no_rating() {
		$node = Product::product_node(
			array(
				'name'     => 'Gadget',
				'price'    => '5',
				'currency' => 'INR',
				'in_stock' => false,
			),
			'https://example.com/product/gadget/'
		);
		$this->assertSame( 'https://schema.org/OutOfStock', $node['offers']['availability'] );
		$this->assertArrayNotHasKey( 'aggregateRating', $node );
	}

	/* ---------- LocalBusiness ---------- */

	private function org_node() {
		return array(
			'@type' => 'Organization',
			'@id'   => 'https://example.com/#organization',
			'name'  => 'Acme',
			'url'   => 'https://example.com/',
		);
	}

	public function test_enrich_promotes_type_and_adds_address_geo() {
		$out = LocalBusiness::enrich(
			$this->org_node(),
			array(
				'type'        => 'ProfessionalService',
				'street'      => '1 Main St',
				'locality'    => 'Hyderabad',
				'region'      => 'TS',
				'postal'      => '500001',
				'country'     => 'IN',
				'telephone'   => '+91-40-1234',
				'lat'         => '17.385',
				'lng'         => '78.486',
				'price_range' => '₹₹',
			)
		);

		$this->assertSame( 'ProfessionalService', $out['@type'] );
		$this->assertSame( 'PostalAddress', $out['address']['@type'] );
		$this->assertSame( 'Hyderabad', $out['address']['addressLocality'] );
		$this->assertSame( '+91-40-1234', $out['telephone'] );
		$this->assertSame( '₹₹', $out['priceRange'] );
		$this->assertSame( 'GeoCoordinates', $out['geo']['@type'] );
		$this->assertSame( 17.385, $out['geo']['latitude'] );
		// Original identity is preserved.
		$this->assertSame( 'https://example.com/#organization', $out['@id'] );
	}

	public function test_enrich_omits_geo_without_both_coords() {
		$out = LocalBusiness::enrich(
			$this->org_node(),
			array(
				'type' => 'Store',
				'lat'  => '17.385',
			)
		);
		$this->assertSame( 'Store', $out['@type'] );
		$this->assertArrayNotHasKey( 'geo', $out );
	}

	public function test_enrich_defaults_invalid_type_and_skips_empty_address() {
		$out = LocalBusiness::enrich( $this->org_node(), array( 'type' => 'NotAThing', 'telephone' => '123' ) );
		$this->assertSame( 'LocalBusiness', $out['@type'] );
		$this->assertArrayNotHasKey( 'address', $out );
		$this->assertSame( '123', $out['telephone'] );
	}
}
