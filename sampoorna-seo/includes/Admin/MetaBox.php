<?php
/**
 * Per-post SEO editor meta box.
 *
 * Renders the SEO fields (title, description, canonical, robots, Open Graph,
 * focus keyword) plus a Google-style snippet preview and the deterministic
 * on-page score, and persists them via the MetaStore on save.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Admin;

use Sampoorna\SEO\Meta\MetaStore;
use Sampoorna\SEO\Meta\TemplateEngine;
use Sampoorna\SEO\Content\Analyzer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles the per-post SEO editor meta box.
 */
class MetaBox {

	const NONCE_ACTION = 'sampoorna_seo_metabox';
	const NONCE_FIELD  = 'sampoorna_seo_metabox_nonce';

	/**
	 * Singleton instance.
	 *
	 * @var MetaBox|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return MetaBox
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
		add_action( 'add_meta_boxes', array( $this, 'add_box' ) );
		add_action( 'save_post', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Register the meta box on public post-type edit screens.
	 *
	 * @param string $post_type Current screen's post type.
	 * @return void
	 */
	public function add_box( $post_type ) {
		$pt = get_post_type_object( $post_type );
		if ( ! $pt || empty( $pt->public ) || 'attachment' === $post_type ) {
			return;
		}
		add_meta_box(
			'sampoorna-seo',
			__( 'Sampoorna SEO', 'sampoorna-seo' ),
			array( $this, 'render' ),
			$post_type,
			'normal',
			'high'
		);
	}

	/**
	 * Enqueue editor assets on the post edit screens only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'sampoorna-seo-editor', SAMPOORNA_SEO_URL . 'assets/css/editor.css', array(), SAMPOORNA_SEO_VERSION );
		wp_enqueue_script( 'sampoorna-seo-editor', SAMPOORNA_SEO_URL . 'assets/js/editor.js', array(), SAMPOORNA_SEO_VERSION, true );
		wp_localize_script(
			'sampoorna-seo-editor',
			'SampoornaSEO',
			array(
				'siteName' => get_bloginfo( 'name' ),
				'sep'      => TemplateEngine::separator(),
				'home'     => home_url( '/' ),
			)
		);
	}

	/**
	 * Render the meta box fields.
	 *
	 * @param \WP_Post $post Current post.
	 * @return void
	 */
	public function render( $post ) {
		$meta  = MetaStore::all( $post->ID );
		$score = Analyzer::analyze( $post->ID, $meta );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<div class="sseo-box">
			<div class="sseo-score sseo-score--<?php echo esc_attr( self::score_band( $score['score'] ) ); ?>">
				<span class="sseo-score__num"><?php echo esc_html( (string) $score['score'] ); ?></span>
				<span class="sseo-score__label"><?php esc_html_e( 'On-page score', 'sampoorna-seo' ); ?></span>
			</div>

			<div class="sseo-snippet">
				<div class="sseo-snippet__title" id="sseo-snippet-title"></div>
				<div class="sseo-snippet__url" id="sseo-snippet-url"></div>
				<div class="sseo-snippet__desc" id="sseo-snippet-desc"></div>
			</div>

			<p>
				<label for="sseo-title"><strong><?php esc_html_e( 'SEO title', 'sampoorna-seo' ); ?></strong></label>
				<input type="text" id="sseo-title" name="sampoorna_seo[title]" class="widefat" value="<?php echo esc_attr( $meta['title'] ); ?>" data-slug="<?php echo esc_attr( $post->post_name ); ?>" />
				<span class="sseo-count" id="sseo-title-count"></span>
				<span class="description"><?php esc_html_e( 'Leave blank to use the global title template. Template tokens are supported, e.g.', 'sampoorna-seo' ); ?> <code>%title%</code> <code>%sitename%</code> <code>%sep%</code></span>
			</p>

			<p>
				<label for="sseo-desc"><strong><?php esc_html_e( 'Meta description', 'sampoorna-seo' ); ?></strong></label>
				<textarea id="sseo-desc" name="sampoorna_seo[desc]" class="widefat" rows="3"><?php echo esc_textarea( $meta['desc'] ); ?></textarea>
				<span class="sseo-count" id="sseo-desc-count"></span>
			</p>

			<p>
				<label for="sseo-focus"><strong><?php esc_html_e( 'Focus keyword', 'sampoorna-seo' ); ?></strong></label>
				<input type="text" id="sseo-focus" name="sampoorna_seo[focus_keyword]" class="widefat" value="<?php echo esc_attr( $meta['focus_keyword'] ); ?>" />
			</p>

			<p>
				<label for="sseo-canonical"><strong><?php esc_html_e( 'Canonical URL', 'sampoorna-seo' ); ?></strong></label>
				<input type="url" id="sseo-canonical" name="sampoorna_seo[canonical]" class="widefat" value="<?php echo esc_attr( $meta['canonical'] ); ?>" placeholder="<?php echo esc_attr( (string) get_permalink( $post ) ); ?>" />
			</p>

			<p>
				<label><input type="checkbox" name="sampoorna_seo[robots_noindex]" value="1" <?php checked( '1', $meta['robots_noindex'] ); ?> /> <?php esc_html_e( 'No-index this content (exclude from search results)', 'sampoorna-seo' ); ?></label><br />
				<label><input type="checkbox" name="sampoorna_seo[robots_nofollow]" value="1" <?php checked( '1', $meta['robots_nofollow'] ); ?> /> <?php esc_html_e( 'No-follow links on this content', 'sampoorna-seo' ); ?></label>
			</p>

			<details class="sseo-social">
				<summary><?php esc_html_e( 'Social (Open Graph / Twitter)', 'sampoorna-seo' ); ?></summary>
				<p>
					<label for="sseo-og-title"><?php esc_html_e( 'Social title', 'sampoorna-seo' ); ?></label>
					<input type="text" id="sseo-og-title" name="sampoorna_seo[og_title]" class="widefat" value="<?php echo esc_attr( $meta['og_title'] ); ?>" />
				</p>
				<p>
					<label for="sseo-og-desc"><?php esc_html_e( 'Social description', 'sampoorna-seo' ); ?></label>
					<textarea id="sseo-og-desc" name="sampoorna_seo[og_desc]" class="widefat" rows="2"><?php echo esc_textarea( $meta['og_desc'] ); ?></textarea>
				</p>
				<p>
					<label for="sseo-og-image"><?php esc_html_e( 'Social image URL', 'sampoorna-seo' ); ?></label>
					<input type="url" id="sseo-og-image" name="sampoorna_seo[og_image]" class="widefat" value="<?php echo esc_attr( $meta['og_image'] ); ?>" />
				</p>
			</details>

			<ul class="sseo-checks">
				<?php foreach ( $score['checks'] as $check ) : ?>
					<li class="sseo-check sseo-check--<?php echo esc_attr( $check['status'] ); ?>">
						<span class="sseo-check__label"><?php echo esc_html( $check['label'] ); ?></span>
						<span class="sseo-check__msg"><?php echo esc_html( $check['msg'] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Persist SEO fields on save.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized per-field in MetaStore::save().
		$raw = isset( $_POST['sampoorna_seo'] ) ? wp_unslash( $_POST['sampoorna_seo'] ) : array();
		$raw = is_array( $raw ) ? $raw : array();

		MetaStore::save(
			$post_id,
			array(
				'title'           => isset( $raw['title'] ) ? $raw['title'] : '',
				'desc'            => isset( $raw['desc'] ) ? $raw['desc'] : '',
				'canonical'       => isset( $raw['canonical'] ) ? $raw['canonical'] : '',
				'focus_keyword'   => isset( $raw['focus_keyword'] ) ? $raw['focus_keyword'] : '',
				'og_title'        => isset( $raw['og_title'] ) ? $raw['og_title'] : '',
				'og_desc'         => isset( $raw['og_desc'] ) ? $raw['og_desc'] : '',
				'og_image'        => isset( $raw['og_image'] ) ? $raw['og_image'] : '',
				'robots_noindex'  => isset( $raw['robots_noindex'] ) ? '1' : '',
				'robots_nofollow' => isset( $raw['robots_nofollow'] ) ? '1' : '',
			)
		);
	}

	/**
	 * Map a 0–100 score to a band label used for color.
	 *
	 * @param int $score Score.
	 * @return string good|ok|bad
	 */
	private static function score_band( $score ) {
		if ( $score >= 70 ) {
			return 'good';
		}
		if ( $score >= 40 ) {
			return 'ok';
		}
		return 'bad';
	}
}
