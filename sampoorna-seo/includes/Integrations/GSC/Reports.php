<?php
/**
 * Reports: scheduled email digest/alerts and CSV exports.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Integrations\GSC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sampoorna\SEO\Core\Database;

/**
 * Generates scheduled email digests/alerts and CSV exports.
 */
class Reports {

	const OPT_ENABLED   = 'sampoorna_seo_digest_enabled';
	const OPT_FREQ      = 'sampoorna_seo_digest_freq';   // daily | weekly.
	const OPT_EMAIL     = 'sampoorna_seo_digest_email';
	const OPT_LAST_SENT = 'sampoorna_seo_digest_last_sent';

	/**
	 * Singleton instance.
	 *
	 * @var Reports|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return Reports
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire up cron and admin-post hooks.
	 */
	private function __construct() {
		// Wrapped in a closure so the action callback returns void; send_digest()
		// keeps its bool return for direct callers such as handle_test().
		add_action(
			SAMPOORNA_SEO_DIGEST_HOOK,
			function () {
				$this->send_digest();
			}
		);
		add_action( 'admin_post_sampoorna_seo_send_test_digest', array( $this, 'handle_test' ) );
		add_action( 'admin_post_sampoorna_seo_export', array( $this, 'handle_export' ) );
	}

	/* ---------- Settings helpers ---------- */

	/**
	 * Whether the email digest is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) get_option( self::OPT_ENABLED, false );
	}

	/**
	 * Current digest frequency, normalized to a core cron schedule.
	 *
	 * @return string Either 'daily' or 'weekly'.
	 */
	public static function frequency() {
		$f = get_option( self::OPT_FREQ, 'weekly' );
		return in_array( $f, array( 'daily', 'weekly' ), true ) ? $f : 'weekly';
	}

	/**
	 * Resolve the digest recipient address, falling back to the site admin.
	 *
	 * @return string Email address.
	 */
	public static function recipient() {
		$e = get_option( self::OPT_EMAIL, '' );
		$e = is_string( $e ) ? trim( $e ) : '';
		return ( '' !== $e && is_email( $e ) ) ? $e : get_option( 'admin_email' );
	}

	/**
	 * Timestamp of the last successful digest send.
	 *
	 * @return string
	 */
	public static function last_sent() {
		return (string) get_option( self::OPT_LAST_SENT, '' );
	}

	/**
	 * (Re)schedule the digest cron to match current settings. Call after saving.
	 */
	public static function reschedule() {
		wp_clear_scheduled_hook( SAMPOORNA_SEO_DIGEST_HOOK );
		if ( self::is_enabled() ) {
			$recurrence = self::frequency(); // 'daily' and 'weekly' are core schedules (WP 5.4+).
			wp_schedule_event( time() + HOUR_IN_SECONDS, $recurrence, SAMPOORNA_SEO_DIGEST_HOOK );
		}
	}

	/* ---------- Digest ---------- */

	/**
	 * Admin-post handler for the "send test digest" button.
	 *
	 * @return void
	 */
	public function handle_test() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sampoorna_seo_send_test_digest' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sampoorna-seo' ) );
		}
		$ok     = $this->send_digest( true );
		$notice = $ok ? 'digest_sent' : 'digest_failed';
		wp_safe_redirect( admin_url( 'admin.php?page=sampoorna-seo-settings&sampoorna_seo_notice=' . $notice ) );
		exit;
	}

	/**
	 * Compose and send the digest email.
	 *
	 * @param bool $force Send even if the digest is disabled (used by the test button).
	 * @return bool Whether the mail was accepted for delivery.
	 */
	public function send_digest( $force = false ) {
		if ( ! $force && ! self::is_enabled() ) {
			return false;
		}
		$oauth    = OAuth::instance();
		$property = $oauth->selected_property();
		if ( ! $oauth->is_connected() || '' === $property ) {
			return false;
		}

		$html    = $this->build_digest_html( $property );
		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Search Console digest', 'sampoorna-seo' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		add_filter( 'wp_mail_content_type', array( $this, 'html_content_type' ) );
		$sent = wp_mail( self::recipient(), $subject, $html );
		remove_filter( 'wp_mail_content_type', array( $this, 'html_content_type' ) );

		if ( $sent ) {
			update_option( self::OPT_LAST_SENT, current_time( 'mysql' ), false );
		}
		return (bool) $sent;
	}

	/**
	 * Force HTML content type for the digest email.
	 *
	 * @return string
	 */
	public function html_content_type() {
		return 'text/html';
	}

	/**
	 * Build the HTML body of the digest.
	 *
	 * @param string $property Property URL.
	 * @return string
	 */
	private function build_digest_html( $property ) {
		$cmp    = Database::compare_windows( $property, 7 );
		$cur    = $cmp['current'];
		$prev   = $cmp['previous'];
		$drops  = Database::click_drops( $property, 7, (int) get_option( 'sampoorna_seo_drop_threshold', 30 ) / 100 );
		$issues = Database::issue_type_counts();
		$sugg   = Database::suggestion_type_counts();

		$pct = function ( $c, $p ) {
			if ( $p <= 0 ) {
				return '—';
			}
			$v     = round( ( ( $c - $p ) / $p ) * 100, 1 );
			$color = $v >= 0 ? '#16794a' : '#b32d2e';
			$sign  = $v >= 0 ? '+' : '';
			return '<span style="color:' . $color . ';">' . $sign . $v . '%</span>';
		};

		$dash = esc_url( admin_url( 'admin.php?page=sampoorna-seo-dashboard' ) );

		ob_start();
		?>
		<div style="font-family:Arial,Helvetica,sans-serif;max-width:640px;margin:0 auto;color:#1d2327;">
			<h2 style="margin:0 0 4px;">Search Console digest</h2>
			<p style="color:#646970;margin:0 0 16px;">
				<?php echo esc_html( $property ); ?> &middot; <?php echo esc_html( gmdate( 'M j, Y' ) ); ?>
			</p>

			<h3 style="border-bottom:1px solid #dcdcde;padding-bottom:6px;">Last 7 days vs prior 7 days</h3>
			<table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
				<tr>
					<td style="padding:8px 0;">Clicks</td>
					<td style="text-align:right;font-weight:bold;"><?php echo esc_html( number_format_i18n( $cur['clicks'] ) ); ?> <?php echo wp_kses_post( $pct( $cur['clicks'], $prev['clicks'] ) ); ?></td>
				</tr>
				<tr>
					<td style="padding:8px 0;">Impressions</td>
					<td style="text-align:right;font-weight:bold;"><?php echo esc_html( number_format_i18n( $cur['impressions'] ) ); ?> <?php echo wp_kses_post( $pct( $cur['impressions'], $prev['impressions'] ) ); ?></td>
				</tr>
				<tr>
					<td style="padding:8px 0;">Avg. position</td>
					<td style="text-align:right;font-weight:bold;"><?php echo esc_html( $cur['position'] ); ?></td>
				</tr>
			</table>

			<h3 style="border-bottom:1px solid #dcdcde;padding-bottom:6px;">Top click drops</h3>
			<?php if ( empty( $drops ) ) : ?>
				<p style="color:#646970;">No significant drops this week.</p>
			<?php else : ?>
				<table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
					<?php foreach ( array_slice( $drops, 0, 5 ) as $d ) : ?>
						<tr>
							<td style="padding:6px 0;font-size:13px;"><?php echo esc_html( $d['page_url'] ); ?></td>
							<td style="padding:6px 0;text-align:right;color:#b32d2e;white-space:nowrap;">&minus;<?php echo esc_html( $d['drop_pct'] ); ?>%</td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>

			<h3 style="border-bottom:1px solid #dcdcde;padding-bottom:6px;">Open issues</h3>
			<p style="margin-bottom:16px;">
				<?php
				if ( empty( $issues ) ) {
					echo 'None.';
				} else {
					$parts = array();
					foreach ( $issues as $type => $c ) {
						$parts[] = esc_html( ucfirst( $type ) . ': ' . $c );
					}
					echo wp_kses_post( implode( ' &nbsp;|&nbsp; ', $parts ) );
				}
				?>
			</p>

			<h3 style="border-bottom:1px solid #dcdcde;padding-bottom:6px;">New suggestions</h3>
			<p style="margin-bottom:16px;">
				<?php
				if ( empty( $sugg ) ) {
					echo 'None.';
				} else {
					$parts = array();
					foreach ( $sugg as $type => $c ) {
						$parts[] = esc_html( ucfirst( $type ) . ': ' . $c );
					}
					echo wp_kses_post( implode( ' &nbsp;|&nbsp; ', $parts ) );
				}
				?>
			</p>

			<p style="margin-top:20px;">
				<a href="<?php echo $dash; // phpcs:ignore WordPress.Security.EscapeOutput ?>" style="background:#2271b1;color:#fff;padding:10px 16px;text-decoration:none;border-radius:4px;">Open dashboard</a>
			</p>
			<p style="color:#a7aaad;font-size:12px;margin-top:24px;">Sent by Sampoorna SEO. Manage this digest under Search Console &rarr; Settings.</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/* ---------- CSV export ---------- */

	/**
	 * Admin-post handler that streams the requested dataset as a CSV download.
	 *
	 * @return void
	 */
	public function handle_export() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sampoorna_seo_export' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sampoorna-seo' ) );
		}
		$dataset  = isset( $_GET['dataset'] ) ? sanitize_key( $_GET['dataset'] ) : '';
		$property = OAuth::instance()->selected_property();

		switch ( $dataset ) {
			case 'perf_pages':
				$rows = Database::top_rows( $property, 'page', 28, 5000 );
				$this->stream_csv(
					'gsc-top-pages.csv',
					array( 'page', 'clicks', 'impressions', 'ctr', 'position' ),
					array_map(
						fn( $r ) => array( $r['label'], (int) $r['clicks'], (int) $r['impressions'], round( (float) $r['ctr'] * 100, 2 ) . '%', round( (float) $r['position'], 1 ) ),
						$rows
					)
				);
				break;

			case 'perf_queries':
				$rows = Database::top_rows( $property, 'query', 28, 5000 );
				$this->stream_csv(
					'gsc-top-queries.csv',
					array( 'query', 'clicks', 'impressions', 'ctr', 'position' ),
					array_map(
						fn( $r ) => array( $r['label'], (int) $r['clicks'], (int) $r['impressions'], round( (float) $r['ctr'] * 100, 2 ) . '%', round( (float) $r['position'], 1 ) ),
						$rows
					)
				);
				break;

			case 'issues':
				$rows = Database::get_issues(
					array(
						'status' => 'all',
						'limit'  => 10000,
					)
				);
				$this->stream_csv(
					'gsc-issues.csv',
					array( 'type', 'severity', 'status', 'url', 'summary', 'detected_at' ),
					array_map(
						fn( $r ) => array( $r['type'], $r['severity'], $r['status'], $r['url'], $r['summary'], $r['detected_at'] ),
						$rows
					)
				);
				break;

			case 'suggestions':
				$rows = Database::get_suggestions(
					array(
						'status' => 'all',
						'limit'  => 10000,
					)
				);
				$this->stream_csv(
					'gsc-suggestions.csv',
					array( 'type', 'priority', 'status', 'url', 'current_value', 'suggested_value', 'recommendation' ),
					array_map(
						fn( $r ) => array( $r['type'], $r['priority'], $r['status'], $r['url'], $r['current_value'], $r['suggested_value'], $r['recommendation'] ),
						$rows
					)
				);
				break;

			default:
				wp_safe_redirect( admin_url( 'admin.php?page=sampoorna-seo-dashboard' ) );
				exit;
		}
	}

	/**
	 * Stream an array of rows to the browser as a CSV download.
	 *
	 * @param string $filename Download filename.
	 * @param array  $headers  Column headers.
	 * @param array  $rows     Rows (array of arrays).
	 */
	private function stream_csv( $filename, array $headers, array $rows ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, $headers );
		foreach ( $rows as $row ) {
			fputcsv( $out, $row );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing a PHP output stream, not a filesystem file.
		exit;
	}
}
