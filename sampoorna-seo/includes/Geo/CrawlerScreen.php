<?php
/**
 * "AI Crawlers" admin screen.
 *
 * Read-only report combining the robots.txt access check (Geo\AiAccess) with
 * the crawler-engagement log (Geo\CrawlerLog via the Database). Self-registers
 * its submenu under the Sampoorna SEO menu.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Geo;

use Sampoorna\SEO\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the AI Crawlers admin report.
 */
class CrawlerScreen {

	const PAGE = 'sampoorna-seo-ai-crawlers';

	/**
	 * Singleton instance.
	 *
	 * @var CrawlerScreen|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return CrawlerScreen
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire the admin menu hook.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 11 );
	}

	/**
	 * Register the AI Crawlers submenu.
	 *
	 * @return void
	 */
	public function menu() {
		add_submenu_page(
			'sampoorna-seo-dashboard',
			__( 'AI Crawlers', 'sampoorna-seo' ),
			__( 'AI Crawlers', 'sampoorna-seo' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the report.
	 *
	 * @return void
	 */
	public function render() {
		$access = AiAccess::report();
		$hits   = Database::ai_hits();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Crawlers', 'sampoorna-seo' ); ?></h1>

			<h2><?php esc_html_e( 'robots.txt access', 'sampoorna-seo' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Whether each AI crawler is allowed to crawl the site root per your effective robots.txt. (Server- or Cloudflare-level blocks are not checked here.)', 'sampoorna-seo' ); ?></p>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Bot', 'sampoorna-seo' ); ?></th>
					<th><?php esc_html_e( 'User-agent', 'sampoorna-seo' ); ?></th>
					<th><?php esc_html_e( 'Access', 'sampoorna-seo' ); ?></th>
					<th><?php esc_html_e( 'Matched group', 'sampoorna-seo' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $access as $row ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $row['label'] ); ?></strong></td>
						<td><code><?php echo esc_html( $row['token'] ); ?></code></td>
						<td>
							<?php if ( $row['allowed'] ) : ?>
								<span style="color:#1a7f37;font-weight:600;"><?php esc_html_e( 'Allowed', 'sampoorna-seo' ); ?></span>
							<?php else : ?>
								<span style="color:#b42318;font-weight:600;"><?php esc_html_e( 'Blocked', 'sampoorna-seo' ); ?></span>
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( $row['via'] ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Crawler engagement', 'sampoorna-seo' ); ?></h2>
			<p class="description"><?php esc_html_e( 'AI bots observed hitting this site, most recent first.', 'sampoorna-seo' ); ?></p>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Bot', 'sampoorna-seo' ); ?></th>
					<th><?php esc_html_e( 'Hits', 'sampoorna-seo' ); ?></th>
					<th><?php esc_html_e( 'Last URL', 'sampoorna-seo' ); ?></th>
					<th><?php esc_html_e( 'Last seen', 'sampoorna-seo' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $hits ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No AI-bot visits logged yet.', 'sampoorna-seo' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $hits as $row ) : ?>
						<tr>
							<td><strong><?php echo esc_html( AiBots::label( (string) $row['bot'] ) ); ?></strong></td>
							<td><?php echo esc_html( (string) $row['hits'] ); ?></td>
							<td><code><?php echo esc_html( (string) $row['last_url'] ); ?></code></td>
							<td><?php echo esc_html( mysql2date( 'Y-m-d H:i', (string) $row['last_seen'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
