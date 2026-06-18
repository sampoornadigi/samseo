<?php
/**
 * Migration admin screen: detect → dry-run → import → verify.
 *
 * Drives the Migrator from wp-admin. Imports run in resumable AJAX batches so
 * large sites don't time out; dry-run and verify stash their results in a
 * short-lived per-user transient for display.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the Migration screen.
 */
class AdminPage {

	const PAGE          = 'sampoorna-seo-migration';
	const NONCE         = 'sampoorna_seo_migrate';
	const BATCH         = 50;
	const DRY_PREFIX    = 'sampoorna_seo_migrate_dry_';
	const VERIFY_PREFIX = 'sampoorna_seo_migrate_vfy_';

	/**
	 * Singleton instance.
	 *
	 * @var AdminPage|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return AdminPage
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire admin hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 11 );
		add_action( 'admin_post_sampoorna_seo_migrate_dryrun', array( $this, 'handle_dryrun' ) );
		add_action( 'admin_post_sampoorna_seo_migrate_verify', array( $this, 'handle_verify' ) );
		add_action( 'wp_ajax_sampoorna_seo_migrate_batch', array( $this, 'ajax_batch' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Register the Migration submenu.
	 *
	 * @return void
	 */
	public function menu() {
		add_submenu_page(
			'sampoorna-seo-dashboard',
			__( 'Migration', 'sampoorna-seo' ),
			__( 'Migration', 'sampoorna-seo' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueue the batch-import script on the Migration screen only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( 'sampoorna-seo_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_script( 'sampoorna-seo-migrate', SAMPOORNA_SEO_URL . 'assets/js/migrate.js', array(), SAMPOORNA_SEO_VERSION, true );
		wp_localize_script(
			'sampoorna-seo-migrate',
			'SampoornaMigrate',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
			)
		);
	}

	/**
	 * Dry-run: compute the diff and stash it for display.
	 *
	 * @return void
	 */
	public function handle_dryrun() {
		$source = $this->guard_and_source();
		set_transient( self::DRY_PREFIX . get_current_user_id(), Migrator::diff( $source, 1000 ), 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&sampoorna_seo_notice=migrate_dry' ) );
		exit;
	}

	/**
	 * Verify: compare imported data to the source and stash the result.
	 *
	 * @return void
	 */
	public function handle_verify() {
		$source = $this->guard_and_source();
		set_transient( self::VERIFY_PREFIX . get_current_user_id(), Migrator::verify( $source, 0 ), 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&sampoorna_seo_notice=migrate_verify' ) );
		exit;
	}

	/**
	 * AJAX: import one batch.
	 *
	 * @return void
	 */
	public function ajax_batch() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sampoorna-seo' ) ), 403 );
		}
		$slug   = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$source = Registry::get( $slug );
		if ( null === $source ) {
			wp_send_json_error( array( 'message' => __( 'Unknown migration source.', 'sampoorna-seo' ) ) );
		}
		$after = isset( $_POST['after_id'] ) ? absint( wp_unslash( $_POST['after_id'] ) ) : 0;
		wp_send_json_success( Migrator::import( $source, self::BATCH, $after ) );
	}

	/**
	 * Capability + nonce guard shared by the admin-post handlers; returns the source.
	 *
	 * @return Source
	 */
	private function guard_and_source() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( self::NONCE ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sampoorna-seo' ) );
		}
		$slug   = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$source = Registry::get( $slug );
		if ( null === $source ) {
			wp_die( esc_html__( 'Unknown migration source.', 'sampoorna-seo' ) );
		}
		return $source;
	}

	/**
	 * Render the Migration screen.
	 *
	 * @return void
	 */
	public function render() {
		$detected = Registry::detected();
		$uid      = get_current_user_id();
		$dry      = get_transient( self::DRY_PREFIX . $uid );
		$verify   = get_transient( self::VERIFY_PREFIX . $uid );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Migration', 'sampoorna-seo' ); ?></h1>
			<?php $this->notice(); ?>
			<p class="description"><?php esc_html_e( 'Import SEO data from another plugin. Imports are non-destructive (the source plugin\'s data is left untouched) and only fill fields you have not already set.', 'sampoorna-seo' ); ?></p>

			<?php
			if ( empty( $detected ) ) {
				echo '<p>' . esc_html__( 'No supported source plugin data was detected on this site.', 'sampoorna-seo' ) . '</p></div>';
				return;
			}
			?>

			<?php foreach ( $detected as $source ) : ?>
				<?php $slug = $source->slug(); ?>
				<h2><?php echo esc_html( $source->label() ); ?></h2>
				<p>
					<?php
					/* translators: %d: number of posts with source SEO data. */
					echo esc_html( sprintf( _n( '%d post has data to import.', '%d posts have data to import.', $source->count(), 'sampoorna-seo' ), $source->count() ) );
					?>
				</p>

				<p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=sampoorna_seo_migrate_dryrun' ) ); ?>" style="display:inline;">
						<?php wp_nonce_field( self::NONCE ); ?>
						<input type="hidden" name="source" value="<?php echo esc_attr( $slug ); ?>" />
						<button class="button"><?php esc_html_e( 'Dry run (preview)', 'sampoorna-seo' ); ?></button>
					</form>
					<button class="button button-primary" id="sseo-migrate-start" data-source="<?php echo esc_attr( $slug ); ?>" data-total="<?php echo esc_attr( (string) $source->count() ); ?>"><?php esc_html_e( 'Import now', 'sampoorna-seo' ); ?></button>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=sampoorna_seo_migrate_verify' ) ); ?>" style="display:inline;">
						<?php wp_nonce_field( self::NONCE ); ?>
						<input type="hidden" name="source" value="<?php echo esc_attr( $slug ); ?>" />
						<button class="button"><?php esc_html_e( 'Verify', 'sampoorna-seo' ); ?></button>
					</form>
				</p>
				<div id="sseo-migrate-progress" style="display:none;margin:10px 0;max-width:480px;">
					<div style="height:14px;background:#dcdcde;border-radius:7px;overflow:hidden;">
						<div id="sseo-migrate-bar" style="height:100%;width:0;background:#2271b1;transition:width .2s;"></div>
					</div>
					<p id="sseo-migrate-status" class="description"></p>
				</div>
			<?php endforeach; ?>

			<?php if ( is_array( $dry ) ) : ?>
				<h2><?php esc_html_e( 'Dry-run preview', 'sampoorna-seo' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: 1: posts scanned, 2: fields to add, 3: already-set fields skipped, 4: unchanged fields. */
						esc_html__( 'Scanned %1$d posts: %2$d fields to add, %3$d skipped (already set), %4$d already match.', 'sampoorna-seo' ),
						(int) $dry['posts'],
						(int) $dry['counts']['add'],
						(int) $dry['counts']['skip_exists'],
						(int) $dry['counts']['same']
					);
					?>
				</p>
				<?php $this->render_rows( $dry['sample'], array( 'field', 'action', 'from', 'to' ) ); ?>
			<?php endif; ?>

			<?php if ( is_array( $verify ) ) : ?>
				<h2><?php esc_html_e( 'Verification', 'sampoorna-seo' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: 1: posts checked, 2: matching fields, 3: mismatching fields. */
						esc_html__( 'Checked %1$d posts: %2$d fields match, %3$d differ.', 'sampoorna-seo' ),
						(int) $verify['checked'],
						(int) $verify['match'],
						(int) $verify['mismatch']
					);
					?>
				</p>
				<?php if ( ! empty( $verify['sample'] ) ) : ?>
					<?php $this->render_rows( $verify['sample'], array( 'field', 'expected', 'actual' ) ); ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a capped table of diff/verify rows.
	 *
	 * @param array<int,array<string,mixed>> $rows    Rows.
	 * @param string[]                       $columns Column keys to show after post_id.
	 * @return void
	 */
	private function render_rows( array $rows, array $columns ) {
		if ( empty( $rows ) ) {
			echo '<p class="description">' . esc_html__( 'Nothing to show.', 'sampoorna-seo' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Post', 'sampoorna-seo' ) . '</th>';
		foreach ( $columns as $col ) {
			echo '<th>' . esc_html( ucfirst( str_replace( '_', ' ', $col ) ) ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td><a href="' . esc_url( get_edit_post_link( (int) $row['post_id'] ) ) . '">' . (int) $row['post_id'] . '</a></td>';
			foreach ( $columns as $col ) {
				echo '<td>' . esc_html( (string) ( $row[ $col ] ?? '' ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Render an admin notice for our redirect flags.
	 *
	 * @return void
	 */
	private function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag set on redirect.
		$key = isset( $_GET['sampoorna_seo_notice'] ) ? sanitize_key( $_GET['sampoorna_seo_notice'] ) : '';
		$map = array(
			'migrate_dry'      => __( 'Dry run complete — review the preview below.', 'sampoorna-seo' ),
			'migrate_verify'   => __( 'Verification complete.', 'sampoorna-seo' ),
			'migrate_imported' => __( 'Import complete.', 'sampoorna-seo' ),
		);
		if ( isset( $map[ $key ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $map[ $key ] ) );
		}
	}
}
