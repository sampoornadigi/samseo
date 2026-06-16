<?php
/**
 * Admin menus, settings, and screens.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Admin;

use Sampoorna\SEO\Security\Crypto;
use Sampoorna\SEO\Core\Database;
use Sampoorna\SEO\Integrations\GSC\OAuth;
use Sampoorna\SEO\Integrations\GSC\Api;
use Sampoorna\SEO\Integrations\GSC\Sync;
use Sampoorna\SEO\Integrations\GSC\Inspector;
use Sampoorna\SEO\Integrations\GSC\Suggestions;
use Sampoorna\SEO\Integrations\GSC\Reports;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers admin menus, settings handlers, and renders the plugin screens.
 */
class Screens {

	/**
	 * Singleton instance.
	 *
	 * @var Screens|null
	 */
	private static $instance = null;

	/**
	 * Returns the shared singleton instance.
	 *
	 * @return Screens
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wires up admin hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_sampoorna_seo_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_sampoorna_seo_select_property', array( $this, 'select_property' ) );
		add_action( 'admin_post_sampoorna_seo_issue_bulk', array( $this, 'issue_bulk' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Registers the top-level menu and submenus.
	 *
	 * @return void
	 */
	public function menu() {
		add_menu_page(
			__( 'Search Console', 'sampoorna-seo' ),
			__( 'Search Console', 'sampoorna-seo' ),
			'manage_options',
			'sampoorna-seo-dashboard',
			array( $this, 'render_dashboard' ),
			'dashicons-chart-area',
			58
		);
		add_submenu_page( 'sampoorna-seo-dashboard', __( 'Dashboard', 'sampoorna-seo' ), __( 'Dashboard', 'sampoorna-seo' ), 'manage_options', 'sampoorna-seo-dashboard', array( $this, 'render_dashboard' ) );
		add_submenu_page( 'sampoorna-seo-dashboard', __( 'Performance', 'sampoorna-seo' ), __( 'Performance', 'sampoorna-seo' ), 'manage_options', 'sampoorna-seo-performance', array( $this, 'render_performance' ) );
		add_submenu_page( 'sampoorna-seo-dashboard', __( 'Issues', 'sampoorna-seo' ), __( 'Issues', 'sampoorna-seo' ), 'manage_options', 'sampoorna-seo-issues', array( $this, 'render_issues' ) );
		add_submenu_page( 'sampoorna-seo-dashboard', __( 'Suggestions', 'sampoorna-seo' ), __( 'Suggestions', 'sampoorna-seo' ), 'manage_options', 'sampoorna-seo-suggestions', array( $this, 'render_suggestions' ) );
		add_submenu_page( 'sampoorna-seo-dashboard', __( 'Settings', 'sampoorna-seo' ), __( 'Settings', 'sampoorna-seo' ), 'manage_options', 'sampoorna-seo-settings', array( $this, 'render_settings' ) );
	}

	/**
	 * Enqueues admin assets on plugin screens.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( false === strpos( $hook, 'sampoorna-seo-' ) ) {
			return;
		}
		// Chart.js for the trend chart (from CDN; bundle locally for production).
		wp_enqueue_script( 'sampoorna-seo-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', array(), '4.4.1', true );
	}

	/* ---------- Settings actions ---------- */

	/**
	 * Persists the settings form submitted from the Settings screen.
	 *
	 * @return void
	 */
	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sampoorna_seo_save_settings' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sampoorna-seo' ) );
		}

		$client_id = isset( $_POST['sampoorna_seo_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['sampoorna_seo_client_id'] ) ) : '';
		update_option( OAuth::OPT_CLIENT_ID, $client_id );

		// Only overwrite the secret if a new value was entered.
		$secret = isset( $_POST['sampoorna_seo_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['sampoorna_seo_client_secret'] ) ) : '';
		if ( '' !== $secret ) {
			update_option( OAuth::OPT_SECRET, Crypto::encrypt( $secret ), false );
		}

		$days = isset( $_POST['sampoorna_seo_initial_days'] ) ? absint( $_POST['sampoorna_seo_initial_days'] ) : 90;
		update_option( 'sampoorna_seo_initial_days', max( 1, min( 480, $days ) ) );

		$threshold = isset( $_POST['sampoorna_seo_drop_threshold'] ) ? absint( $_POST['sampoorna_seo_drop_threshold'] ) : 30;
		update_option( 'sampoorna_seo_drop_threshold', max( 1, min( 99, $threshold ) ) );

		// Email digest settings.
		update_option( Reports::OPT_ENABLED, isset( $_POST['sampoorna_seo_digest_enabled'] ) ? 1 : 0 );
		$freq = isset( $_POST['sampoorna_seo_digest_freq'] ) ? sanitize_key( $_POST['sampoorna_seo_digest_freq'] ) : 'weekly';
		update_option( Reports::OPT_FREQ, in_array( $freq, array( 'daily', 'weekly' ), true ) ? $freq : 'weekly' );
		$email = isset( $_POST['sampoorna_seo_digest_email'] ) ? sanitize_email( wp_unslash( $_POST['sampoorna_seo_digest_email'] ) ) : '';
		update_option( Reports::OPT_EMAIL, $email );

		// Apply the schedule to match the saved settings.
		Reports::reschedule();

		wp_safe_redirect( admin_url( 'admin.php?page=sampoorna-seo-settings&sampoorna_seo_notice=saved' ) );
		exit;
	}

	/**
	 * Stores the selected Search Console property.
	 *
	 * @return void
	 */
	public function select_property() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sampoorna_seo_select_property' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sampoorna-seo' ) );
		}
		$property = isset( $_POST['sampoorna_seo_property'] ) ? esc_url_raw( wp_unslash( $_POST['sampoorna_seo_property'] ) ) : '';
		update_option( OAuth::OPT_PROPERTY, $property );
		wp_safe_redirect( admin_url( 'admin.php?page=sampoorna-seo-settings&sampoorna_seo_notice=property_saved' ) );
		exit;
	}

	/**
	 * Applies a bulk status change to selected issues.
	 *
	 * @return void
	 */
	public function issue_bulk() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sampoorna_seo_issue_bulk' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sampoorna-seo' ) );
		}
		$ids    = isset( $_POST['issue'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['issue'] ) ) : array();
		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( $_POST['bulk_action'] ) : '';
		if ( $ids && in_array( $action, array( 'ignore', 'resolve', 'reopen' ), true ) ) {
			$map = array(
				'ignore'  => 'ignored',
				'resolve' => 'resolved',
				'reopen'  => 'open',
			);
			Database::set_issue_status( $ids, $map[ $action ] );
		}
		$status = isset( $_POST['cur_status'] ) ? sanitize_key( $_POST['cur_status'] ) : 'open';
		wp_safe_redirect( admin_url( 'admin.php?page=sampoorna-seo-issues&status=' . $status . '&sampoorna_seo_notice=issues_updated' ) );
		exit;
	}

	/* ---------- Shared bits ---------- */

	/**
	 * Renders a dismissible admin notice based on the sampoorna_seo_notice query flag.
	 *
	 * @return void
	 */
	private function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag set on redirect, no state change.
		$key = isset( $_GET['sampoorna_seo_notice'] ) ? sanitize_key( $_GET['sampoorna_seo_notice'] ) : '';
		if ( '' === $key ) {
			return;
		}
		$map = array(
			'connected'             => array( 'success', __( 'Connected to Google Search Console.', 'sampoorna-seo' ) ),
			'disconnected'          => array( 'success', __( 'Disconnected. Stored tokens removed.', 'sampoorna-seo' ) ),
			'saved'                 => array( 'success', __( 'Settings saved.', 'sampoorna-seo' ) ),
			'property_saved'        => array( 'success', __( 'Property selected.', 'sampoorna-seo' ) ),
			'synced'                => array( 'success', __( 'Performance data synced.', 'sampoorna-seo' ) ),
			'seeded'                => array( 'success', __( 'Queue populated. Inspection runs in the background under Google\'s daily limit.', 'sampoorna-seo' ) ),
			'inspected'             => array( 'success', __( 'Processed a batch of URL inspections.', 'sampoorna-seo' ) ),
			'issues_updated'        => array( 'success', __( 'Issues updated.', 'sampoorna-seo' ) ),
			'suggestions_generated' => array( 'success', __( 'Suggestions generated.', 'sampoorna-seo' ) ),
			'suggestions_updated'   => array( 'success', __( 'Suggestions updated.', 'sampoorna-seo' ) ),
			'digest_sent'           => array( 'success', __( 'Test digest sent.', 'sampoorna-seo' ) ),
			'digest_failed'         => array( 'error', __( 'Could not send the digest. Check the recipient address and that the site can send email.', 'sampoorna-seo' ) ),
			'missing_credentials'   => array( 'error', __( 'Enter your Client ID and Secret first.', 'sampoorna-seo' ) ),
			'bad_state'             => array( 'error', __( 'Security check failed (state mismatch). Try again.', 'sampoorna-seo' ) ),
			'denied'                => array( 'error', __( 'Authorization was denied.', 'sampoorna-seo' ) ),
			'no_code'               => array( 'error', __( 'No authorization code returned by Google.', 'sampoorna-seo' ) ),
			'token_error'           => array( 'error', __( 'Failed to exchange the authorization code. Check the redirect URI matches Google Cloud exactly.', 'sampoorna-seo' ) ),
			'sync_error'            => array( 'error', __( 'Sync failed. See the log on the dashboard.', 'sampoorna-seo' ) ),
		);
		if ( ! isset( $map[ $key ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $map[ $key ][0] ),
			esc_html( $map[ $key ][1] )
		);
	}

	/**
	 * Builds an escaped admin-post.php action URL.
	 *
	 * @param string $action The admin-post action name.
	 * @return string Escaped URL.
	 */
	private function action_url( $action ) {
		return esc_url( admin_url( 'admin-post.php?action=' . $action ) );
	}

	/**
	 * Builds an escaped, nonce-protected CSV export URL.
	 *
	 * @param string $dataset The dataset identifier to export.
	 * @return string Escaped URL.
	 */
	private function export_url( $dataset ) {
		return esc_url(
			wp_nonce_url(
				admin_url( 'admin-post.php?action=sampoorna_seo_export&dataset=' . $dataset ),
				'sampoorna_seo_export'
			)
		);
	}

	/* ---------- Screens ---------- */

	/**
	 * Renders the Settings screen.
	 *
	 * @return void
	 */
	public function render_settings() {
		$oauth = OAuth::instance();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Search Console — Settings', 'sampoorna-seo' ); ?></h1>
			<?php $this->notice(); ?>

			<h2><?php esc_html_e( '1. Google API credentials', 'sampoorna-seo' ); ?></h2>
			<p>
				<?php esc_html_e( 'In Google Cloud Console, enable the Search Console API and create an OAuth Web Application client. Set the authorized redirect URI to:', 'sampoorna-seo' ); ?>
			</p>
			<p><code><?php echo esc_html( $oauth->redirect_uri() ); ?></code></p>

			<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_save_settings' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>">
				<?php wp_nonce_field( 'sampoorna_seo_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sampoorna_seo_client_id"><?php esc_html_e( 'Client ID', 'sampoorna-seo' ); ?></label></th>
						<td><input name="sampoorna_seo_client_id" id="sampoorna_seo_client_id" type="text" class="regular-text" value="<?php echo esc_attr( $oauth->client_id() ); ?>" autocomplete="off"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sampoorna_seo_client_secret"><?php esc_html_e( 'Client Secret', 'sampoorna-seo' ); ?></label></th>
						<td>
							<input name="sampoorna_seo_client_secret" id="sampoorna_seo_client_secret" type="password" class="regular-text" value="" autocomplete="off" placeholder="<?php echo $oauth->client_secret() ? esc_attr__( '•••••••• (stored — leave blank to keep)', 'sampoorna-seo' ) : ''; ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sampoorna_seo_initial_days"><?php esc_html_e( 'Initial sync window (days)', 'sampoorna-seo' ); ?></label></th>
						<td><input name="sampoorna_seo_initial_days" id="sampoorna_seo_initial_days" type="number" min="1" max="480" value="<?php echo esc_attr( get_option( 'sampoorna_seo_initial_days', 90 ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sampoorna_seo_drop_threshold"><?php esc_html_e( 'Click-drop alert threshold (%)', 'sampoorna-seo' ); ?></label></th>
						<td><input name="sampoorna_seo_drop_threshold" id="sampoorna_seo_drop_threshold" type="number" min="1" max="99" value="<?php echo esc_attr( get_option( 'sampoorna_seo_drop_threshold', 30 ) ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( '4. Email digest', 'sampoorna-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable digest', 'sampoorna-seo' ); ?></th>
						<td>
							<label>
								<input name="sampoorna_seo_digest_enabled" type="checkbox" value="1" <?php checked( Reports::is_enabled() ); ?>>
								<?php esc_html_e( 'Email a periodic summary (KPIs, drops, issues, suggestions).', 'sampoorna-seo' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sampoorna_seo_digest_freq"><?php esc_html_e( 'Frequency', 'sampoorna-seo' ); ?></label></th>
						<td>
							<select name="sampoorna_seo_digest_freq" id="sampoorna_seo_digest_freq">
								<option value="weekly" <?php selected( Reports::frequency(), 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'sampoorna-seo' ); ?></option>
								<option value="daily" <?php selected( Reports::frequency(), 'daily' ); ?>><?php esc_html_e( 'Daily', 'sampoorna-seo' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sampoorna_seo_digest_email"><?php esc_html_e( 'Recipient', 'sampoorna-seo' ); ?></label></th>
						<td>
							<input name="sampoorna_seo_digest_email" id="sampoorna_seo_digest_email" type="email" class="regular-text" value="<?php echo esc_attr( get_option( Reports::OPT_EMAIL, '' ) ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
							<p class="description"><?php esc_html_e( 'Leave blank to use the site admin email.', 'sampoorna-seo' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'sampoorna-seo' ) ); ?>
			</form>

			<p>
				<strong><?php esc_html_e( 'Last digest sent:', 'sampoorna-seo' ); ?></strong>
				<?php
				$last_digest = Reports::last_sent();
				echo esc_html( $last_digest ? $last_digest : __( 'never', 'sampoorna-seo' ) );
				?>
				<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_send_test_digest' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>" style="display:inline;margin-left:8px;">
					<?php wp_nonce_field( 'sampoorna_seo_send_test_digest' ); ?>
					<button class="button"><?php esc_html_e( 'Send test digest now', 'sampoorna-seo' ); ?></button>
				</form>
			</p>

			<hr>
			<h2><?php esc_html_e( '2. Connect your Google account', 'sampoorna-seo' ); ?></h2>
			<?php if ( ! $oauth->is_configured() ) : ?>
				<p><em><?php esc_html_e( 'Enter and save your Client ID and Secret above before connecting.', 'sampoorna-seo' ); ?></em></p>
			<?php elseif ( $oauth->is_connected() ) : ?>
				<p>
					<span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span>
					<?php esc_html_e( 'Connected.', 'sampoorna-seo' ); ?>
				</p>
				<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_disconnect' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>" style="display:inline;">
					<?php wp_nonce_field( 'sampoorna_seo_disconnect' ); ?>
					<button class="button button-secondary"><?php esc_html_e( 'Disconnect & delete data', 'sampoorna-seo' ); ?></button>
				</form>
			<?php else : ?>
				<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_connect' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>">
					<?php wp_nonce_field( 'sampoorna_seo_connect' ); ?>
					<button class="button button-primary"><?php esc_html_e( 'Connect Google Search Console', 'sampoorna-seo' ); ?></button>
				</form>
			<?php endif; ?>

			<?php if ( $oauth->is_connected() ) : ?>
				<hr>
				<h2><?php esc_html_e( '3. Choose a property', 'sampoorna-seo' ); ?></h2>
				<?php
				$sites = Api::list_sites();
				if ( is_wp_error( $sites ) ) {
					echo '<p class="notice notice-error" style="padding:8px;">' . esc_html( $sites->get_error_message() ) . '</p>';
				} elseif ( empty( $sites ) ) {
					esc_html_e( 'No verified properties found for this account.', 'sampoorna-seo' );
				} else {
					$selected = $oauth->selected_property();
					?>
					<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_select_property' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>">
						<?php wp_nonce_field( 'sampoorna_seo_select_property' ); ?>
						<select name="sampoorna_seo_property">
							<?php foreach ( $sites as $site ) : ?>
								<option value="<?php echo esc_attr( $site['url'] ); ?>" <?php selected( $selected, $site['url'] ); ?>>
									<?php echo esc_html( $site['url'] . ' (' . $site['permission'] . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php submit_button( __( 'Use this property', 'sampoorna-seo' ), 'secondary', '', false ); ?>
					</form>
					<?php
				}
			endif;
			?>
		</div>
		<?php
	}

	/**
	 * Renders the Dashboard screen.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		$oauth    = OAuth::instance();
		$property = $oauth->selected_property();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Search Console — Dashboard', 'sampoorna-seo' ); ?></h1>
			<?php $this->notice(); ?>

			<?php
			if ( ! $oauth->is_connected() || '' === $property ) {
				?>
				<p><?php esc_html_e( 'Connect your account and select a property on the Settings screen to begin.', 'sampoorna-seo' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=sampoorna-seo-settings' ) ); ?>"><?php esc_html_e( 'Go to Settings', 'sampoorna-seo' ); ?></a></p>
				<?php
				return;
			}
			?>

			<p>
				<strong><?php esc_html_e( 'Property:', 'sampoorna-seo' ); ?></strong> <code><?php echo esc_html( $property ); ?></code>
				&nbsp;|&nbsp;
				<strong><?php esc_html_e( 'Last sync:', 'sampoorna-seo' ); ?></strong>
				<?php
				$last_sync = Sync::last_sync();
				echo esc_html( $last_sync ? $last_sync : __( 'never', 'sampoorna-seo' ) );
				?>
				&nbsp;
				<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_sync_now' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>" style="display:inline;">
					<?php wp_nonce_field( 'sampoorna_seo_sync_now' ); ?>
					<button class="button button-primary"><?php esc_html_e( 'Sync now', 'sampoorna-seo' ); ?></button>
				</form>
			</p>

			<?php
			$cmp   = Database::compare_windows( $property, 7 );
			$cur   = $cmp['current'];
			$prev  = $cmp['previous'];
			$delta = function ( $c, $p ) {
				if ( $p <= 0 ) {
					return '';
				}
				$pct   = round( ( ( $c - $p ) / $p ) * 100, 1 );
				$col   = $pct >= 0 ? '#46b450' : '#dc3232';
				$arrow = $pct >= 0 ? '▲' : '▼';
				return ' <span style="color:' . $col . ';font-size:13px;">' . $arrow . ' ' . abs( $pct ) . '%</span>';
			};
		?>
			<h2><?php esc_html_e( 'Last 7 days vs prior 7 days', 'sampoorna-seo' ); ?></h2>
			<div style="display:flex;gap:16px;flex-wrap:wrap;">
				<?php
				$cards = array(
					array( __( 'Clicks', 'sampoorna-seo' ), $cur['clicks'], $delta( $cur['clicks'], $prev['clicks'] ) ),
					array( __( 'Impressions', 'sampoorna-seo' ), $cur['impressions'], $delta( $cur['impressions'], $prev['impressions'] ) ),
					array( __( 'Avg. position', 'sampoorna-seo' ), $cur['position'], '' ),
				);
				foreach ( $cards as $c ) :
					?>
					<div style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:16px 20px;min-width:160px;">
						<div style="color:#646970;font-size:12px;text-transform:uppercase;"><?php echo esc_html( $c[0] ); ?></div>
						<div style="font-size:26px;font-weight:600;"><?php echo esc_html( number_format_i18n( $c[1] ) ); ?><?php echo wp_kses_post( $c[2] ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>

			<h2><?php esc_html_e( 'Clicks & impressions trend', 'sampoorna-seo' ); ?></h2>
			<canvas id="sampoorna-seo-trend" height="90"></canvas>

			<?php
			$threshold = (int) get_option( 'sampoorna_seo_drop_threshold', 30 ) / 100;
			$drops     = Database::click_drops( $property, 7, $threshold );
			?>
			<h2><?php esc_html_e( 'Pages with click drops', 'sampoorna-seo' ); ?></h2>
			<?php if ( empty( $drops ) ) : ?>
				<p><?php esc_html_e( 'No significant drops detected in the latest window.', 'sampoorna-seo' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Page', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Prev clicks', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Current clicks', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Drop', 'sampoorna-seo' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( array_slice( $drops, 0, 25 ) as $d ) : ?>
						<tr>
							<td><?php echo esc_html( $d['page_url'] ); ?></td>
							<td><?php echo esc_html( $d['prev_clicks'] ); ?></td>
							<td><?php echo esc_html( $d['cur_clicks'] ); ?></td>
							<td style="color:#dc3232;"><?php echo esc_html( $d['drop_pct'] ); ?>%</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php
			$series = Database::daily_totals( $property, 90 );
			$labels = wp_list_pluck( $series, 'date' );
			$clicks = array_map( 'intval', wp_list_pluck( $series, 'clicks' ) );
			$imps   = array_map( 'intval', wp_list_pluck( $series, 'impressions' ) );
			?>
			<script>
			document.addEventListener('DOMContentLoaded', function () {
				if (typeof Chart === 'undefined') { return; }
				var ctx = document.getElementById('sampoorna-seo-trend');
				if (!ctx) { return; }
				new Chart(ctx, {
					type: 'line',
					data: {
						labels: <?php echo wp_json_encode( $labels ); ?>,
						datasets: [
							{ label: 'Clicks', data: <?php echo wp_json_encode( $clicks ); ?>, borderColor: '#2271b1', tension: 0.3, yAxisID: 'y' },
							{ label: 'Impressions', data: <?php echo wp_json_encode( $imps ); ?>, borderColor: '#9b59b6', tension: 0.3, yAxisID: 'y1' }
						]
					},
					options: {
						responsive: true,
						interaction: { mode: 'index', intersect: false },
						scales: {
							y:  { type: 'linear', position: 'left',  title: { display: true, text: 'Clicks' } },
							y1: { type: 'linear', position: 'right', title: { display: true, text: 'Impressions' }, grid: { drawOnChartArea: false } }
						}
					}
				});
			});
			</script>
		</div>
		<?php
	}

	/**
	 * Renders the Performance screen.
	 *
	 * @return void
	 */
	public function render_performance() {
		$oauth    = OAuth::instance();
		$property = $oauth->selected_property();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Search Console — Performance', 'sampoorna-seo' ); ?></h1>
			<?php $this->notice(); ?>

			<?php
			if ( ! $oauth->is_connected() || '' === $property ) {
				?>
				<p><?php esc_html_e( 'Connect a property in Settings first.', 'sampoorna-seo' ); ?></p>
				<?php
				return;
			}
			?>

			<?php
			$tabs = array(
				'page'  => __( 'Top pages', 'sampoorna-seo' ),
				'query' => __( 'Top queries', 'sampoorna-seo' ),
			);
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selector, value compared against a literal; no state change.
			$active = isset( $_GET['dim'] ) && 'query' === $_GET['dim'] ? 'query' : 'page';
			?>
			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $dim => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=sampoorna-seo-performance&dim=' . $dim ) ); ?>"
						class="nav-tab <?php echo $active === $dim ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<p>
				<a class="button" href="<?php echo $this->export_url( 'page' === $active ? 'perf_pages' : 'perf_queries' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- export_url() returns an esc_url()-escaped string. ?>">
					<?php esc_html_e( 'Export CSV', 'sampoorna-seo' ); ?>
				</a>
			</p>

			<?php
			$rows = Database::top_rows( $property, $active, 28, 200 );
			?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php echo 'query' === $active ? esc_html__( 'Query', 'sampoorna-seo' ) : esc_html__( 'Page', 'sampoorna-seo' ); ?></th>
					<th><?php esc_html_e( 'Clicks', 'sampoorna-seo' ); ?></th>
					<th><?php esc_html_e( 'Impressions', 'sampoorna-seo' ); ?></th>
					<th><?php esc_html_e( 'CTR', 'sampoorna-seo' ); ?></th>
					<th><?php esc_html_e( 'Avg. position', 'sampoorna-seo' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No data yet. Run a sync from the Dashboard.', 'sampoorna-seo' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><?php echo esc_html( $r['label'] ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $r['clicks'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $r['impressions'] ) ); ?></td>
							<td><?php echo esc_html( (string) round( (float) $r['ctr'] * 100, 2 ) ); ?>%</td>
							<td><?php echo esc_html( (string) round( (float) $r['position'], 1 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Aggregated over the last 28 days of synced data.', 'sampoorna-seo' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renders the Issues screen.
	 *
	 * @return void
	 */
	public function render_issues() {
		$oauth    = OAuth::instance();
		$property = $oauth->selected_property();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin list filter, no state change.
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'open';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin list filter, no state change.
		$type = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin list filter, no state change.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Search Console — Issues', 'sampoorna-seo' ); ?></h1>
			<?php $this->notice(); ?>

			<?php
			if ( ! $oauth->is_connected() || '' === $property ) {
				?>
				<p><?php esc_html_e( 'Connect a property in Settings first.', 'sampoorna-seo' ); ?></p>
				<?php
				return;
			}
			?>

			<?php
			$progress = Inspector::progress();
			$used     = Inspector::daily_used();
			$pct      = $progress['total'] > 0 ? round( ( $progress['done'] / $progress['total'] ) * 100 ) : 0;
			?>
			<div style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:14px 18px;margin:12px 0;">
				<strong><?php esc_html_e( 'Scan progress', 'sampoorna-seo' ); ?>:</strong>
				<?php echo esc_html( sprintf( '%d of %d URLs inspected (%d%%)', $progress['done'], $progress['total'], $pct ) ); ?>
				&nbsp;|&nbsp; <?php echo esc_html( sprintf( 'pending: %d, errors: %d', $progress['pending'], $progress['errors'] ) ); ?>
				&nbsp;|&nbsp; <?php echo esc_html( sprintf( 'inspections used today: %d / %d', $used, Inspector::DAILY_CAP ) ); ?>
				<div style="background:#e2e4e7;border-radius:4px;height:10px;margin-top:8px;overflow:hidden;">
					<div style="background:#2271b1;height:10px;width:<?php echo esc_attr( (string) $pct ); ?>%;"></div>
				</div>
				<p style="margin-top:10px;">
					<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_seed_queue' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>" style="display:inline;">
						<?php wp_nonce_field( 'sampoorna_seo_seed_queue' ); ?>
						<button class="button"><?php esc_html_e( 'Build / refresh queue', 'sampoorna-seo' ); ?></button>
					</form>
					<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_inspect_now' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>" style="display:inline;">
						<?php wp_nonce_field( 'sampoorna_seo_inspect_now' ); ?>
						<button class="button button-primary"><?php esc_html_e( 'Inspect a batch now', 'sampoorna-seo' ); ?></button>
					</form>
					<a class="button" href="<?php echo $this->export_url( 'issues' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- export_url() returns an esc_url()-escaped string. ?>"><?php esc_html_e( 'Export CSV', 'sampoorna-seo' ); ?></a>
					<span class="description"><?php esc_html_e( 'Inspection otherwise runs automatically every 15 minutes, throttled under Google\'s 2,000/day limit.', 'sampoorna-seo' ); ?></span>
				</p>
			</div>

			<?php
			$counts = Database::issue_type_counts();
			$labels = array(
				'indexing'  => __( 'Indexing', 'sampoorna-seo' ),
				'canonical' => __( 'Canonical', 'sampoorna-seo' ),
				'mobile'    => __( 'Mobile', 'sampoorna-seo' ),
				'schema'    => __( 'Schema', 'sampoorna-seo' ),
			);
			?>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=sampoorna-seo-issues&status=' . $status ) ); ?>" class="button <?php echo '' === $type ? 'button-primary' : ''; ?>">
					<?php esc_html_e( 'All open', 'sampoorna-seo' ); ?> (<?php echo (int) array_sum( $counts ); ?>)
				</a>
				<?php foreach ( $labels as $key => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=sampoorna-seo-issues&status=' . $status . '&type=' . $key ) ); ?>" class="button <?php echo $type === $key ? 'button-primary' : ''; ?>">
						<?php echo esc_html( $label ); ?> (<?php echo (int) ( $counts[ $key ] ?? 0 ); ?>)
					</a>
				<?php endforeach; ?>
			</p>

			<ul class="subsubsub">
				<?php
				$states = array(
					'open'     => __( 'Open', 'sampoorna-seo' ),
					'resolved' => __( 'Resolved', 'sampoorna-seo' ),
					'ignored'  => __( 'Ignored', 'sampoorna-seo' ),
					'all'      => __( 'All', 'sampoorna-seo' ),
				);
				$i      = 0;
				foreach ( $states as $key => $label ) :
					++$i;
					?>
					<li>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=sampoorna-seo-issues&status=' . $key . ( $type ? '&type=' . $type : '' ) ) ); ?>" class="<?php echo $status === $key ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?></a><?php echo $i < count( $states ) ? ' |' : ''; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<form method="get" style="margin:8px 0;">
				<input type="hidden" name="page" value="sampoorna-seo-issues">
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
				<?php
				if ( $type ) :
					?>
					<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>"><?php endif; ?>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Filter by URL…', 'sampoorna-seo' ); ?>">
				<button class="button"><?php esc_html_e( 'Search', 'sampoorna-seo' ); ?></button>
			</form>

			<?php
			$issues = Database::get_issues(
				array(
					'status' => $status,
					'type'   => $type,
					'search' => $search,
					'limit'  => 200,
				)
			);
			?>
			<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_issue_bulk' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>">
				<?php wp_nonce_field( 'sampoorna_seo_issue_bulk' ); ?>
				<input type="hidden" name="cur_status" value="<?php echo esc_attr( $status ); ?>">
				<div class="tablenav top">
					<select name="bulk_action">
						<option value=""><?php esc_html_e( 'Bulk actions', 'sampoorna-seo' ); ?></option>
						<option value="ignore"><?php esc_html_e( 'Ignore', 'sampoorna-seo' ); ?></option>
						<option value="resolve"><?php esc_html_e( 'Mark resolved', 'sampoorna-seo' ); ?></option>
						<option value="reopen"><?php esc_html_e( 'Reopen', 'sampoorna-seo' ); ?></option>
					</select>
					<button class="button action"><?php esc_html_e( 'Apply', 'sampoorna-seo' ); ?></button>
				</div>
				<table class="widefat striped">
					<thead><tr>
						<td class="check-column"><input type="checkbox" onclick="jQuery('.sampoorna-seo-cb').prop('checked',this.checked);"></td>
						<th><?php esc_html_e( 'Type', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Severity', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'URL', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Summary', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Detected', 'sampoorna-seo' ); ?></th>
					</tr></thead>
					<tbody>
					<?php if ( empty( $issues ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No issues for this filter. If you just installed, build the queue and let inspection run.', 'sampoorna-seo' ); ?></td></tr>
					<?php else : ?>
						<?php
						foreach ( $issues as $iss ) :
							$details = json_decode( (string) $iss['details_json'], true );
							$sev_col = 'error' === $iss['severity'] ? '#dc3232' : ( 'notice' === $iss['severity'] ? '#646970' : '#dba617' );
							?>
							<tr>
								<th class="check-column"><input class="sampoorna-seo-cb" type="checkbox" name="issue[]" value="<?php echo (int) $iss['id']; ?>"></th>
								<td><?php echo esc_html( $labels[ $iss['type'] ] ?? $iss['type'] ); ?></td>
								<td><span style="color:<?php echo esc_attr( $sev_col ); ?>;font-weight:600;"><?php echo esc_html( ucfirst( $iss['severity'] ) ); ?></span></td>
								<td><a href="<?php echo esc_url( $iss['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $iss['url'] ); ?></a></td>
								<td>
									<?php echo esc_html( $iss['summary'] ); ?>
									<?php if ( ! empty( $details ) ) : ?>
										<details style="margin-top:4px;">
											<summary style="cursor:pointer;color:#2271b1;"><?php esc_html_e( 'details', 'sampoorna-seo' ); ?></summary>
											<pre style="white-space:pre-wrap;background:#f6f7f7;padding:8px;border-radius:4px;margin-top:4px;"><?php echo esc_html( wp_json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
										</details>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( mysql2date( 'Y-m-d H:i', $iss['detected_at'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</form>
			<p class="description"><?php esc_html_e( 'Note: the aggregate Coverage report in the GSC web UI is not available via API; this view is rebuilt URL-by-URL via the URL Inspection API.', 'sampoorna-seo' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renders the Suggestions screen.
	 *
	 * @return void
	 */
	public function render_suggestions() {
		$oauth    = OAuth::instance();
		$property = $oauth->selected_property();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin list filter, no state change.
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'new';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin list filter, no state change.
		$type = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin list filter, no state change.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Search Console — Suggestions', 'sampoorna-seo' ); ?></h1>
			<?php $this->notice(); ?>

			<?php
			if ( ! $oauth->is_connected() || '' === $property ) {
				?>
				<p><?php esc_html_e( 'Connect a property in Settings first.', 'sampoorna-seo' ); ?></p>
				<?php
				return;
			}
			?>

			<div style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:14px 18px;margin:12px 0;">
				<strong><?php esc_html_e( 'Last generated:', 'sampoorna-seo' ); ?></strong>
				<?php
				$last_run = Suggestions::last_run();
				echo esc_html( $last_run ? $last_run : __( 'never', 'sampoorna-seo' ) );
				?>
				<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_generate_suggestions' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>" style="display:inline;margin-left:8px;">
					<?php wp_nonce_field( 'sampoorna_seo_generate_suggestions' ); ?>
					<button class="button button-primary"><?php esc_html_e( 'Generate suggestions', 'sampoorna-seo' ); ?></button>
				</form>
				<a class="button" href="<?php echo $this->export_url( 'suggestions' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- export_url() returns an esc_url()-escaped string. ?>" style="margin-left:4px;"><?php esc_html_e( 'Export CSV', 'sampoorna-seo' ); ?></a>
				<p class="description" style="margin-top:8px;">
					<?php esc_html_e( 'Suggestions are advisory and built from inspection issues, title/meta length checks, and low-CTR pages. Review and apply changes yourself; nothing is edited automatically.', 'sampoorna-seo' ); ?>
				</p>
			</div>

			<?php
			$counts = Database::suggestion_type_counts();
			$labels = array(
				'title'     => __( 'Title', 'sampoorna-seo' ),
				'meta'      => __( 'Meta description', 'sampoorna-seo' ),
				'ctr'       => __( 'Low CTR', 'sampoorna-seo' ),
				'canonical' => __( 'Canonical', 'sampoorna-seo' ),
				'indexing'  => __( 'Indexing', 'sampoorna-seo' ),
				'mobile'    => __( 'Mobile', 'sampoorna-seo' ),
				'schema'    => __( 'Schema', 'sampoorna-seo' ),
			);
			?>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=sampoorna-seo-suggestions&status=' . $status ) ); ?>" class="button <?php echo '' === $type ? 'button-primary' : ''; ?>">
					<?php esc_html_e( 'All', 'sampoorna-seo' ); ?> (<?php echo (int) array_sum( $counts ); ?>)
				</a>
				<?php foreach ( $labels as $key => $label ) : ?>
					<?php if ( isset( $counts[ $key ] ) ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=sampoorna-seo-suggestions&status=' . $status . '&type=' . $key ) ); ?>" class="button <?php echo $type === $key ? 'button-primary' : ''; ?>">
							<?php echo esc_html( $label ); ?> (<?php echo (int) $counts[ $key ]; ?>)
						</a>
					<?php endif; ?>
				<?php endforeach; ?>
			</p>

			<ul class="subsubsub">
				<?php
				$states = array(
					'new'       => __( 'New', 'sampoorna-seo' ),
					'applied'   => __( 'Applied', 'sampoorna-seo' ),
					'dismissed' => __( 'Dismissed', 'sampoorna-seo' ),
					'all'       => __( 'All', 'sampoorna-seo' ),
				);
				$i      = 0;
				foreach ( $states as $key => $label ) :
					++$i;
					?>
					<li>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=sampoorna-seo-suggestions&status=' . $key . ( $type ? '&type=' . $type : '' ) ) ); ?>" class="<?php echo $status === $key ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?></a><?php echo $i < count( $states ) ? ' |' : ''; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<form method="get" style="margin:8px 0;">
				<input type="hidden" name="page" value="sampoorna-seo-suggestions">
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
				<?php
				if ( $type ) :
					?>
					<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>"><?php endif; ?>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Filter by URL…', 'sampoorna-seo' ); ?>">
				<button class="button"><?php esc_html_e( 'Search', 'sampoorna-seo' ); ?></button>
			</form>

			<?php
			$suggestions = Database::get_suggestions(
				array(
					'status' => $status,
					'type'   => $type,
					'search' => $search,
					'limit'  => 300,
				)
			);
			?>
			<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_suggestion_bulk' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>">
				<?php wp_nonce_field( 'sampoorna_seo_suggestion_bulk' ); ?>
				<input type="hidden" name="cur_status" value="<?php echo esc_attr( $status ); ?>">
				<div class="tablenav top">
					<select name="bulk_action">
						<option value=""><?php esc_html_e( 'Bulk actions', 'sampoorna-seo' ); ?></option>
						<option value="apply"><?php esc_html_e( 'Mark applied', 'sampoorna-seo' ); ?></option>
						<option value="dismiss"><?php esc_html_e( 'Dismiss', 'sampoorna-seo' ); ?></option>
						<option value="reset"><?php esc_html_e( 'Reset to new', 'sampoorna-seo' ); ?></option>
					</select>
					<button class="button action"><?php esc_html_e( 'Apply', 'sampoorna-seo' ); ?></button>
				</div>
				<table class="widefat striped">
					<thead><tr>
						<td class="check-column"><input type="checkbox" onclick="jQuery('.sampoorna-seo-scb').prop('checked',this.checked);"></td>
						<th><?php esc_html_e( 'Type', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Priority', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Page', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Current', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Suggested', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Recommendation', 'sampoorna-seo' ); ?></th>
					</tr></thead>
					<tbody>
					<?php if ( empty( $suggestions ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No suggestions for this filter. Click "Generate suggestions" above.', 'sampoorna-seo' ); ?></td></tr>
					<?php else : ?>
						<?php
						foreach ( $suggestions as $s ) :
							$pri_col = 'high' === $s['priority'] ? '#dc3232' : ( 'low' === $s['priority'] ? '#646970' : '#dba617' );
							$edit    = $s['post_id'] ? get_edit_post_link( (int) $s['post_id'] ) : '';
							?>
							<tr>
								<th class="check-column"><input class="sampoorna-seo-scb" type="checkbox" name="sugg[]" value="<?php echo (int) $s['id']; ?>"></th>
								<td><?php echo esc_html( $labels[ $s['type'] ] ?? $s['type'] ); ?></td>
								<td><span style="color:<?php echo esc_attr( $pri_col ); ?>;font-weight:600;"><?php echo esc_html( ucfirst( $s['priority'] ) ); ?></span></td>
								<td>
									<a href="<?php echo esc_url( $s['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $s['url'] ); ?></a>
									<?php if ( $edit ) : ?>
										<br><a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', 'sampoorna-seo' ); ?></a>
									<?php endif; ?>
								</td>
								<td><?php echo '' !== $s['current_value'] ? esc_html( $s['current_value'] ) : '<em>—</em>'; ?></td>
								<td><?php echo '' !== $s['suggested_value'] ? esc_html( $s['suggested_value'] ) : '<em>—</em>'; ?></td>
								<td><?php echo esc_html( $s['recommendation'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</form>
		</div>
		<?php
	}
}
