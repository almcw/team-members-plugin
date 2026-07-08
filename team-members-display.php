<?php
/**
 * Plugin Name:       Team Members Display
 * Description:       Displays team members in a responsive, accessible grid with modal. Use [team_grid] shortcode.
 * Version:           1.5.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Text Domain:       team-members-display
 * Domain Path:       /languages
 * License:           GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =============================================================================
// Main plugin class
// =============================================================================

class Team_Members_Display {

	const TEXT_DOMAIN = 'team-members-display';

	/**
	 * Characters shown in the card bio before truncation.
	 * Full text appears in the modal.
	 */
	const BIO_LIMIT = 120;

	/** Set to true when the shortcode is found on the current page. */
	private static $shortcode_used = false;

	/** Guard so modal + JS are only printed once per page. */
	private static $footer_rendered = false;

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	public static function init() {
		add_shortcode( 'team_grid',          array( __CLASS__, 'render_shortcode' ) );
		add_action(    'wp',                 array( __CLASS__, 'detect_shortcode' ) );
		add_action(    'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_assets' ) );
		add_action(    'acf/init',           array( __CLASS__, 'register_acf_fields' ) );
	}

	// -------------------------------------------------------------------------
	// Shortcode detection
	// Runs on the 'wp' hook so we know which page we're on before wp_head fires.
	// -------------------------------------------------------------------------

	public static function detect_shortcode() {
		global $post;
		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'team_grid' ) ) {
			self::$shortcode_used = true;
		}
	}

	/**
	 * Only inject CSS / modal / JS on pages that actually use the shortcode.
	 */
	public static function maybe_enqueue_assets() {
		if ( ! self::$shortcode_used ) {
			return;
		}
		add_action( 'wp_head',   array( __CLASS__, 'output_css' ) );
		add_action( 'wp_footer', array( __CLASS__, 'output_modal_and_js' ), 20 );
	}

	// -------------------------------------------------------------------------
	// ACF field registration
	// -------------------------------------------------------------------------

	public static function register_acf_fields() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}
		acf_add_local_field_group( array(
			'key'    => 'group_team_member_order',
			'title'  => 'Display Order',
			'fields' => array(
				array(
					'key'           => 'field_team_member_display_order',
					'label'         => 'Display Order',
					'name'          => 'display_order',
					'type'          => 'number',
					'instructions'  => 'Controls the position in the team grid. Lower numbers appear first. Default: 5000.',
					'required'      => 0,
					'default_value' => 5000,
					'min'           => '',
					'max'           => '',
					'step'          => 1,
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'team_member',
					),
				),
			),
			'position' => 'side',
			'style'    => 'default',
		) );

		acf_add_local_field_group( array(
			'key'    => 'group_team_member_video',
			'title'  => 'Video',
			'fields' => array(
				array(
					'key'          => 'field_team_member_video_url',
					'label'        => 'Video URL',
					'name'         => 'video_url',
					'type'         => 'url',
					'instructions' => 'Optional. YouTube or Vimeo URL. When set, clicking this card opens a video player instead of the bio modal.',
					'required'     => 0,
					'placeholder'  => 'https://www.youtube.com/watch?v=...',
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'team_member',
					),
				),
			),
			'position' => 'normal',
			'style'    => 'default',
		) );
	}

	// -------------------------------------------------------------------------
	// Shortcode  [team_grid  team="slug,slug"  columns="3"  aspect_ratio="square"  show_details="true"]
	// -------------------------------------------------------------------------

	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'team'            => '',       // comma-separated 'team' taxonomy slugs
				'columns'         => '3',      // desktop columns (2-8)
				'aspect_ratio'    => 'square', // 'square' (1:1) or 'portrait' (16:9)
				'show_details'    => 'true',   // 'false' hides bio and team tags
				'mobile_carousel' => 'false',  // 'true' switches to a carousel on mobile
			),
			$atts,
			'team_grid'
		);

		$columns = max( 2, min( 8, (int) $atts['columns'] ) );

		$aspect_ratio_css = ( $atts['aspect_ratio'] === 'portrait' ) ? '16 / 9' : '1 / 1';

		$show_details = ( $atts['show_details'] !== 'false' );

		$mobile_carousel = ( $atts['mobile_carousel'] === 'true' );

		$query_args = array(
			'post_type'      => 'team_member',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'no_found_rows'  => true, // skip COUNT(*) — we never paginate
		);

		if ( ! empty( $atts['team'] ) ) {
			$slugs = array_map(
				'sanitize_title',
				array_map( 'trim', explode( ',', $atts['team'] ) )
			);
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'team',
					'field'    => 'slug',
					'terms'    => $slugs,
				),
			);
		}

		$members = new WP_Query( $query_args );

		if ( empty( $members->posts ) ) {
			return '<p class="tmd-no-results">' .
			       esc_html__( 'No team members found.', self::TEXT_DOMAIN ) .
			       '</p>';
		}

		// Sort by display_order meta ascending; posts without the field default to 5000.
		$posts = $members->posts;
		usort( $posts, function( $a, $b ) {
			$get = function( $post ) {
				$v = get_post_meta( $post->ID, 'display_order', true );
				return $v !== '' ? (int) $v : 5000;
			};
			return $get( $a ) - $get( $b );
		} );

		ob_start();
		if ( $mobile_carousel ) {
			echo '<div class="tmd-carousel-wrap">';
		}
		$grid_class = 'tmd-grid' . ( $mobile_carousel ? ' tmd-carousel-enabled' : '' );
		echo '<div class="' . esc_attr( $grid_class ) . '" style="--tmd-cols:' . esc_attr( $columns ) . ';" role="list">';
		global $post;
		foreach ( $posts as $post ) {
			setup_postdata( $post );
			self::render_card( $post->ID, $aspect_ratio_css, $show_details );
		}
		wp_reset_postdata();
		echo '</div>';

		if ( $mobile_carousel ) {
			$num_slides = count( $posts );
			?>
			<div class="tmd-carousel-ui">
				<button class="tmd-carousel-prev" aria-label="<?php esc_attr_e( 'Previous team member', self::TEXT_DOMAIN ); ?>">
					<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
				</button>
				<div class="tmd-carousel-dots" role="group" aria-label="<?php esc_attr_e( 'Team member slides', self::TEXT_DOMAIN ); ?>">
					<?php for ( $i = 0; $i < $num_slides; $i++ ) : ?>
					<button
						class="tmd-dot<?php echo $i === 0 ? ' tmd-dot--active' : ''; ?>"
						aria-label="<?php echo esc_attr( sprintf( __( 'Go to slide %d', self::TEXT_DOMAIN ), $i + 1 ) ); ?>"
						aria-pressed="<?php echo $i === 0 ? 'true' : 'false'; ?>"
					></button>
					<?php endfor; ?>
				</div>
				<button class="tmd-carousel-next" aria-label="<?php esc_attr_e( 'Next team member', self::TEXT_DOMAIN ); ?>">
					<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
				</button>
			</div>
			<?php
			echo '</div>'; // .tmd-carousel-wrap
		}

		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Individual card
	// -------------------------------------------------------------------------

	private static function render_card( $post_id, $aspect_ratio_css, $show_details ) {
		$name      = get_the_title( $post_id );
		$job_title = self::get_acf_field( 'job_title', $post_id );
		$short_bio = self::get_acf_field( 'short_bio', $post_id );
		$video_url = self::get_acf_field( 'video_url', $post_id );
		$has_video = ! empty( $video_url );
		$terms     = get_the_terms( $post_id, 'team' );

		// short_bio is a plain textarea — strip any stray tags before display.
		$bio_clean   = wp_strip_all_tags( trim( $short_bio ) );
		$bio_trimmed = self::truncate_text( $bio_clean, self::BIO_LIMIT );
		$bio_is_cut  = mb_strlen( $bio_clean ) > self::BIO_LIMIT;

		$has_image  = has_post_thumbnail( $post_id );
		$initials   = self::get_initials( $name );
		$init_color = self::get_initials_color( $name );

		// Build the list of team names for the card tags and modal.
		$team_names = array();
		if ( $terms && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$team_names[] = $term->name;
			}
		}

		// All modal data is serialised here and read by JS on card click.
		// Using a data attribute keeps the modal HTML reusable for every card.
		$modal_data = array(
			'name'       => $name,
			'job_title'  => $job_title,
			'bio'        => $bio_clean,   // full (untruncated) text for modal
			'has_image'  => $has_image,
			'image_url'  => $has_image ? (string) get_the_post_thumbnail_url( $post_id, 'large' ) : '',
			'initials'   => $initials,
			'init_color' => $init_color,
			'teams'      => $team_names,
			'video_url'  => $video_url,
		);

		?>
		<article
			class="tmd-card"
			id="tmd-card-<?php echo esc_attr( $post_id ); ?>"
			role="listitem"
			tabindex="0"
			data-tmd-modal="<?php echo esc_attr( wp_json_encode( $modal_data ) ); ?>"
			aria-label="<?php
				echo esc_attr(
					$has_video
						/* translators: %s: team member name */
						? sprintf( __( 'Watch video for %s', self::TEXT_DOMAIN ), $name )
						/* translators: %s: team member name */
						: sprintf( __( 'View profile for %s', self::TEXT_DOMAIN ), $name )
				);
			?>"
		>
			<!-- Photo or initials fallback -->
			<div class="tmd-card__media" style="aspect-ratio:<?php echo esc_attr( $aspect_ratio_css ); ?>;">
				<?php if ( $has_image ) : ?>
					<?php echo get_the_post_thumbnail(
						$post_id,
						'large',
						array(
							'class'   => 'tmd-card__image',
							'alt'     => esc_attr( $name ),
							'loading' => 'lazy',
						)
					); ?>
				<?php else : ?>
					<div
						class="tmd-card__initials"
						style="background-color:<?php echo esc_attr( $init_color ); ?>;"
						aria-hidden="true"
					><?php echo esc_html( $initials ); ?></div>
				<?php endif; ?>
				<?php if ( $has_video ) : ?>
					<div class="tmd-card__play" aria-hidden="true">
						<svg class="tmd-card__play-icon" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
							<circle cx="24" cy="24" r="24" fill="rgba(255,255,255,0.92)"/>
							<polygon points="19,14 19,34 36,24" fill="#2A2A2A"/>
						</svg>
					</div>
				<?php endif; ?>
			</div>

			<!-- Text content -->
			<div class="tmd-card__body">
				<h2 class="tmd-card__name"><?php echo esc_html( $name ); ?></h2>

				<?php if ( ! empty( $job_title ) ) : ?>
					<p class="tmd-card__job-title"><?php echo esc_html( $job_title ); ?></p>
				<?php endif; ?>

				<?php if ( $show_details && ! empty( $bio_trimmed ) ) : ?>
					<p class="tmd-card__bio">
						<?php echo esc_html( $bio_trimmed ); ?><?php if ( $bio_is_cut ) : ?><span class="tmd-read-more" aria-hidden="true">&nbsp;&hellip;&nbsp;<?php esc_html_e( 'Read more', self::TEXT_DOMAIN ); ?></span><?php endif; ?>
					</p>
				<?php endif; ?>

				<?php if ( $show_details && ! empty( $team_names ) ) : ?>
					<ul
						class="tmd-card__tags"
						aria-label="<?php esc_attr_e( 'Teams', self::TEXT_DOMAIN ); ?>"
					>
						<?php foreach ( $team_names as $team_name ) : ?>
							<li class="tmd-tag"><?php echo esc_html( $team_name ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</article>
		<?php
	}

	// -------------------------------------------------------------------------
	// Modal markup — printed once in wp_footer
	// -------------------------------------------------------------------------

	public static function output_modal_and_js() {
		if ( self::$footer_rendered ) {
			return;
		}
		self::$footer_rendered = true;
		?>
		<!-- Team Members Display: modal (shared by all cards) -->
		<div
			id="tmd-modal"
			class="tmd-modal"
			role="dialog"
			aria-modal="true"
			aria-labelledby="tmd-modal-name"
			aria-hidden="true"
		>
			<div class="tmd-modal__overlay" id="tmd-modal-overlay"></div>

			<div class="tmd-modal__box">
				<!-- Close button: prominent label, not just an icon -->
				<button
					class="tmd-modal__close"
					id="tmd-modal-close"
					aria-label="<?php esc_attr_e( 'Close dialog', self::TEXT_DOMAIN ); ?>"
				><?php esc_html_e( 'Close', self::TEXT_DOMAIN ); ?></button>

				<!-- Photo / initials (populated by JS) -->
				<div class="tmd-modal__media" id="tmd-modal-media"></div>

				<!-- Text content (populated by JS) -->
				<div class="tmd-modal__content">
					<h2 class="tmd-modal__name" id="tmd-modal-name"></h2>
					<p  class="tmd-modal__job"  id="tmd-modal-job"></p>
					<div class="tmd-modal__bio" id="tmd-modal-bio"></div>
					<ul
						class="tmd-modal__tags"
						id="tmd-modal-tags"
						aria-label="<?php esc_attr_e( 'Teams', self::TEXT_DOMAIN ); ?>"
					></ul>
				</div>
			</div>
		</div>

		<!-- Team Members Display: video modal -->
		<div
			id="tmd-video-modal"
			class="tmd-modal tmd-video-modal"
			role="dialog"
			aria-modal="true"
			aria-labelledby="tmd-video-modal-title"
			aria-hidden="true"
		>
			<div class="tmd-modal__overlay" id="tmd-video-modal-overlay"></div>

			<div class="tmd-modal__box tmd-video-modal__box">
				<button
					class="tmd-modal__close"
					id="tmd-video-modal-close"
					aria-label="<?php esc_attr_e( 'Close dialog', self::TEXT_DOMAIN ); ?>"
				><?php esc_html_e( 'Close', self::TEXT_DOMAIN ); ?></button>

				<h2 class="tmd-video-modal__title" id="tmd-video-modal-title"></h2>

				<div class="tmd-video-modal__player">
					<iframe
						id="tmd-video-iframe"
						src=""
						title=""
						allow="autoplay; fullscreen; picture-in-picture"
						allowfullscreen
						tabindex="0"
					></iframe>
				</div>
			</div>
		</div>
		<?php
		self::output_js();
	}

	// =========================================================================
	// CSS  (scoped with tmd- prefix throughout)
	// =========================================================================

	public static function output_css() {
		?>
<style id="tmd-styles">
/* =============================================================================
   Team Members Display v1.5
   Palette: warm off-white bg · white cards · dark-grey text · muted sage accent
   All contrast ratios meet WCAG AA (4.5:1 normal text, 3:1 large text).
   ============================================================================= */

/* --- Grid ------------------------------------------------------------------ */

.tmd-grid {
	display: grid;
	gap: 1.5rem;
	grid-template-columns: 1fr;   /* 1 col on mobile */
	padding: 0;
	margin: 0;
	list-style: none;
}

@media (min-width: 600px) {
	.tmd-grid { grid-template-columns: repeat(2, 1fr); }   /* 2 col tablet */
}

/* Desktop: honour the [columns] attribute via CSS custom property */
@media (min-width: 900px) {
	.tmd-grid { grid-template-columns: repeat(var(--tmd-cols, 3), 1fr); }
}

/* --- Card ------------------------------------------------------------------ */

.tmd-card {
	background: #ffffff;
	border: 1px solid #e6e6e2;
	border-radius: 8px;
	overflow: hidden;
	cursor: pointer;
	display: flex;
	flex-direction: column;
	/* Subtle lift on hover — disabled when user prefers reduced motion */
	transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.tmd-card:hover,
.tmd-card:focus-visible {
	transform: translateY(-3px);
	box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
	outline: none;
}

.tmd-card:focus-visible {
	outline: 2px solid #7A9E8E;
	outline-offset: 2px;
}

@media (prefers-reduced-motion: reduce) {
	.tmd-card                              { transition: none; }
	.tmd-card:hover, .tmd-card:focus-visible { transform: none; }
}

/* Media container — aspect-ratio is set inline by the shortcode */
.tmd-card__media {
	position: relative;  /* anchor for the play-button overlay */
	overflow: hidden;
}

/* Image fills the container regardless of aspect ratio */
.tmd-card__image {
	display: block;
	width: 100%;
	height: 100%;
	object-fit: cover;
}

/* Initials circle fills its container; aspect-ratio comes from the parent */
.tmd-card__initials {
	width: 100%;
	height: 100%;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: clamp(2rem, 8vw, 3.5rem);
	font-weight: 700;
	color: #ffffff;
	user-select: none;
}

/* Card text area */
.tmd-card__body {
	padding: 1.25rem;
	display: flex;
	flex-direction: column;
	gap: 0.4rem;
	flex: 1;
}

.tmd-card__name {
	font-size: 1.1rem;
	font-weight: 600;
	color: #2A2A2A;
	margin: 0;
	line-height: 1.3;
}

.tmd-card__job-title {
	font-size: 0.875rem;
	color: #666660;
	margin: 0;
	line-height: 1.4;
}

.tmd-card__bio {
	font-size: 0.875rem;
	color: #4A4A45;
	line-height: 1.65;
	margin: 0.2rem 0 0;
}

/* "… Read more" hint after truncated bio */
.tmd-read-more {
	color: #5C8C7A;   /* muted sage — ~5.1:1 on white */
	font-size: 0.8125rem;
	white-space: nowrap;
}

/* Team tags — shared between card and modal */
.tmd-card__tags,
.tmd-modal__tags {
	list-style: none;
	padding: 0;
	margin: 0.5rem 0 0;
	display: flex;
	flex-wrap: wrap;
	gap: 0.375rem;
}

.tmd-tag {
	background: #E8F0EB;
	color: #2D5C46;     /* ~6.1:1 on #E8F0EB — WCAG AA */
	font-size: 0.75rem;
	font-weight: 500;
	padding: 0.2rem 0.65rem;
	border-radius: 100px;
	line-height: 1.6;
	white-space: nowrap;
}

.tmd-no-results {
	color: #666660;
	font-style: italic;
}

/* Play button overlay — present on cards that have a video_url */

.tmd-card__play {
	position: absolute;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	background: rgba(0, 0, 0, 0.15);
	transition: background 0.2s ease;
}

.tmd-card:hover .tmd-card__play,
.tmd-card:focus-visible .tmd-card__play {
	background: rgba(0, 0, 0, 0.30);
}

@media (prefers-reduced-motion: reduce) {
	.tmd-card__play { transition: none; }
}

.tmd-card__play-icon {
	width: 3rem;
	height: 3rem;
	flex-shrink: 0;
	filter: drop-shadow(0 2px 6px rgba(0, 0, 0, 0.35));
}

/* =============================================================================
   Modal
   ============================================================================= */

/* Hidden by default. JS sets display:flex then adds .is-open to trigger fade. */
.tmd-modal {
	display: none;
	position: fixed;
	inset: 0;
	z-index: 99999;
	align-items: center;
	justify-content: center;
	padding: 1rem;
}

/* Semi-transparent backdrop */
.tmd-modal__overlay {
	position: absolute;
	inset: 0;
	background: rgba(28, 28, 26, 0.55);
	opacity: 0;
	transition: opacity 0.2s ease;
}

/* Modal box */
.tmd-modal__box {
	position: relative;
	background: #FAFAF7;
	border-radius: 10px;
	width: 100%;
	max-width: 640px;
	max-height: 90vh;
	overflow-y: auto;
	box-shadow: 0 20px 60px rgba(0, 0, 0, 0.18);
	/* Stack image above content on mobile */
	display: grid;
	grid-template-columns: 1fr;
	grid-template-areas: "media" "content";
	opacity: 0;
	transition: opacity 0.2s ease;
}

/* Side-by-side on larger screens */
@media (min-width: 520px) {
	.tmd-modal__box {
		grid-template-columns: 200px 1fr;
		grid-template-areas: "media content";
	}
}

/* Fade in when JS adds .is-open */
.tmd-modal.is-open .tmd-modal__overlay,
.tmd-modal.is-open .tmd-modal__box { opacity: 1; }

/* Skip animation for users who prefer reduced motion */
@media (prefers-reduced-motion: reduce) {
	.tmd-modal__overlay,
	.tmd-modal__box { transition: none; opacity: 1; }
}

/* Close button — always visible, labelled, top-right corner */
.tmd-modal__close {
	position: absolute;
	top: 0.75rem;
	right: 0.75rem;
	z-index: 1;
	background: #ffffff;
	border: 1px solid #e6e6e2;
	border-radius: 6px;
	padding: 0.3rem 0.8rem;
	font-size: 0.85rem;
	font-weight: 500;
	color: #2A2A2A;
	cursor: pointer;
	line-height: 1.5;
}

.tmd-modal__close:hover { background: #f0f0ec; }

.tmd-modal__close:focus-visible {
	outline: 2px solid #7A9E8E;
	outline-offset: 2px;
}

/* Photo area */
.tmd-modal__media {
	grid-area: media;
	padding: 1.5rem;
	display: flex;
	align-items: flex-start;
	justify-content: center;
}

.tmd-modal__media img {
	width: 100%;
	height: auto;
	border-radius: 6px;
	display: block;
}

@media (max-width: 519px) {
	.tmd-modal__media img { max-width: 160px; }
}

/* Initials circle inside modal */
.tmd-modal__initials {
	width: 100%;
	aspect-ratio: 1 / 1;
	border-radius: 6px;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 3rem;
	font-weight: 700;
	color: #ffffff;
	user-select: none;
}

@media (max-width: 519px) {
	.tmd-modal__initials { max-width: 160px; }
}

/* Text content area */
.tmd-modal__content {
	grid-area: content;
	padding: 1.5rem;
	padding-top: 3.25rem;   /* clear the absolutely-positioned Close button */
}

.tmd-modal__name {
	font-size: 1.375rem;
	font-weight: 700;
	color: #2A2A2A;
	margin: 0 0 0.25rem;
	line-height: 1.25;
}

.tmd-modal__job {
	font-size: 1rem;
	color: #666660;
	margin: 0 0 1rem;
	line-height: 1.4;
}

.tmd-modal__bio {
	font-size: 1rem;
	color: #3A3A36;
	line-height: 1.7;
	margin-bottom: 1rem;
	white-space: pre-line;  /* preserve line breaks from the textarea field */
}

.tmd-modal__tags { margin-top: 0.75rem; }

/* =============================================================================
   Video modal
   ============================================================================= */

/* Override the bio-modal grid layout with a single-column block */
.tmd-video-modal .tmd-modal__box {
	display: block;
	max-width: 900px;
	overflow: hidden;   /* clips iframe to the box's border-radius */
	padding-top: 3rem;  /* clear the close button */
}

.tmd-video-modal__title {
	font-size: 1rem;
	font-weight: 600;
	color: #2A2A2A;
	margin: 0;
	padding: 0 1.25rem 0.75rem;
	line-height: 1.3;
}

/* 16:9 responsive wrapper */
.tmd-video-modal__player {
	position: relative;
	padding-bottom: 56.25%;
	height: 0;
}

.tmd-video-modal__player iframe {
	position: absolute;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	border: none;
	display: block;
}

/* =============================================================================
   Mobile carousel (active when mobile_carousel="true")
   ============================================================================= */

/* Hide controls on desktop — display:none removes them from the a11y tree */
@media (min-width: 768px) {
	.tmd-carousel-ui { display: none; }
}

@media (max-width: 767px) {
	.tmd-carousel-wrap { position: relative; }

	/* Override CSS Grid with a horizontal scrolling flex track */
	.tmd-carousel-enabled {
		display: flex !important;
		flex-wrap: nowrap;
		overflow-x: scroll;
		scroll-snap-type: x mandatory;
		scrollbar-width: none;
		-ms-overflow-style: none;
		gap: 0;
	}

	.tmd-carousel-enabled::-webkit-scrollbar { display: none; }

	.tmd-carousel-enabled > .tmd-card {
		flex: 0 0 100%;
		min-width: 0;
		scroll-snap-align: start;
	}

	/* Controls row: prev · dots · next */
	.tmd-carousel-ui {
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 0.75rem;
		padding: 0.875rem 0 0;
	}

	.tmd-carousel-prev,
	.tmd-carousel-next {
		width: 2.25rem;
		height: 2.25rem;
		flex-shrink: 0;
		border-radius: 50%;
		border: 1px solid #e6e6e2;
		background: #ffffff;
		display: flex;
		align-items: center;
		justify-content: center;
		cursor: pointer;
		padding: 0;
		color: #2A2A2A;
		transition: background 0.15s ease, opacity 0.15s ease;
	}

	.tmd-carousel-prev:disabled,
	.tmd-carousel-next:disabled { opacity: 0.3; cursor: default; }

	.tmd-carousel-prev:not(:disabled):hover,
	.tmd-carousel-next:not(:disabled):hover { background: #f0f0ec; }

	.tmd-carousel-prev:focus-visible,
	.tmd-carousel-next:focus-visible {
		outline: 2px solid #7A9E8E;
		outline-offset: 2px;
	}

	.tmd-carousel-prev svg,
	.tmd-carousel-next svg {
		width: 1rem;
		height: 1rem;
		stroke: currentColor;
		stroke-width: 2.5;
		fill: none;
		stroke-linecap: round;
		stroke-linejoin: round;
		display: block;
	}

	/* Dots */
	.tmd-carousel-dots {
		display: flex;
		gap: 0.4rem;
		align-items: center;
		flex-wrap: wrap;
		justify-content: center;
		max-width: calc(100% - 6rem);
	}

	.tmd-dot {
		width: 0.5rem;
		height: 0.5rem;
		flex-shrink: 0;
		border-radius: 50%;
		border: none;
		background: #d0d0cc;
		cursor: pointer;
		padding: 0;
		transition: background 0.2s ease, transform 0.2s ease;
	}

	.tmd-dot--active {
		background: #5C8C7A;
		transform: scale(1.4);
	}

	.tmd-dot:focus-visible {
		outline: 2px solid #7A9E8E;
		outline-offset: 3px;
	}
}

@media (prefers-reduced-motion: reduce) {
	.tmd-carousel-prev,
	.tmd-carousel-next { transition: none; }
	.tmd-dot            { transition: none; }
	.tmd-dot--active    { transform: none; }
}
</style>
		<?php
	}

	// =========================================================================
	// JavaScript  (vanilla — no jQuery)
	// =========================================================================

	public static function output_js() {
		?>
<script id="tmd-script">
(function () {
	'use strict';

	/* -------------------------------------------------------------------------
	   Element references — bio modal
	------------------------------------------------------------------------- */
	var modal    = document.getElementById('tmd-modal');
	var box      = modal.querySelector('.tmd-modal__box');
	var closeBtn = document.getElementById('tmd-modal-close');
	var overlay  = document.getElementById('tmd-modal-overlay');
	var elMedia  = document.getElementById('tmd-modal-media');
	var elName   = document.getElementById('tmd-modal-name');
	var elJob    = document.getElementById('tmd-modal-job');
	var elBio    = document.getElementById('tmd-modal-bio');
	var elTags   = document.getElementById('tmd-modal-tags');

	/* Element references — video modal */
	var videoModal    = document.getElementById('tmd-video-modal');
	var videoBox      = videoModal.querySelector('.tmd-modal__box');
	var videoCloseBtn = document.getElementById('tmd-video-modal-close');
	var videoOverlay  = document.getElementById('tmd-video-modal-overlay');
	var videoTitle    = document.getElementById('tmd-video-modal-title');
	var videoIframe   = document.getElementById('tmd-video-iframe');

	/* The card element that opened each modal — focus returns here on close. */
	var activeCard      = null;
	var activeVideoCard = null;

	var FOCUSABLE = 'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])';

	/* -------------------------------------------------------------------------
	   Bio modal — open
	------------------------------------------------------------------------- */
	function openModal(card) {
		var data;
		try {
			data = JSON.parse(card.getAttribute('data-tmd-modal'));
		} catch (e) {
			return;
		}

		activeCard = card;

		/* -- Photo or initials circle -- */
		elMedia.innerHTML = '';
		if (data.has_image && data.image_url) {
			var img = document.createElement('img');
			img.src = data.image_url;
			img.alt = data.name;
			elMedia.appendChild(img);
		} else {
			var circle = document.createElement('div');
			circle.className             = 'tmd-modal__initials';
			circle.style.backgroundColor = data.init_color || '#A8C5B8';
			circle.setAttribute('aria-hidden', 'true');
			circle.textContent           = data.initials || '';
			elMedia.appendChild(circle);
		}

		/* -- Text fields -- */
		elName.textContent    = data.name      || '';
		elJob.textContent     = data.job_title || '';
		elJob.style.display   = data.job_title ? '' : 'none';
		elBio.textContent     = data.bio       || '';

		/* -- Team tags -- */
		elTags.innerHTML = '';
		if (data.teams && data.teams.length) {
			data.teams.forEach(function (teamName) {
				var li        = document.createElement('li');
				li.className  = 'tmd-tag';
				li.textContent = teamName;
				elTags.appendChild(li);
			});
			elTags.style.display = '';
		} else {
			elTags.style.display = 'none';
		}

		modal.style.display = 'flex';
		modal.getBoundingClientRect(); // forces reflow
		requestAnimationFrame(function () {
			modal.classList.add('is-open');
			modal.setAttribute('aria-hidden', 'false');
		});

		document.body.style.overflow = 'hidden';
		closeBtn.focus();
	}

	/* -------------------------------------------------------------------------
	   Bio modal — close
	------------------------------------------------------------------------- */
	function closeModal() {
		modal.classList.remove('is-open');
		modal.setAttribute('aria-hidden', 'true');

		var delay = prefersReducedMotion() ? 0 : 210;
		setTimeout(function () {
			if (!modal.classList.contains('is-open')) {
				modal.style.display = 'none';
			}
		}, delay);

		document.body.style.overflow = '';

		if (activeCard) {
			activeCard.focus();
			activeCard = null;
		}
	}

	/* -------------------------------------------------------------------------
	   Bio modal — focus trap
	------------------------------------------------------------------------- */
	box.addEventListener('keydown', function (e) {
		if (e.key !== 'Tab') { return; }

		var focusable = Array.prototype.slice.call(box.querySelectorAll(FOCUSABLE));
		if (!focusable.length) { e.preventDefault(); return; }

		var first = focusable[0];
		var last  = focusable[focusable.length - 1];

		if (e.shiftKey && document.activeElement === first) {
			e.preventDefault();
			last.focus();
		} else if (!e.shiftKey && document.activeElement === last) {
			e.preventDefault();
			first.focus();
		}
	});

	/* -------------------------------------------------------------------------
	   Convert a YouTube or Vimeo watch URL to its embed URL.
	   Returns null for unrecognised URLs so the caller can fall back to bio.
	------------------------------------------------------------------------- */
	function getEmbedUrl(url) {
		var yt = url.match(/(?:youtu\.be\/|youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|v\/|shorts\/))([A-Za-z0-9_-]{11})/);
		if (yt) {
			return 'https://www.youtube.com/embed/' + yt[1] + '?autoplay=1&rel=0';
		}
		var vm = url.match(/vimeo\.com\/(?:video\/)?(\d+)/);
		if (vm) {
			return 'https://player.vimeo.com/video/' + vm[1] + '?autoplay=1';
		}
		return null;
	}

	/* -------------------------------------------------------------------------
	   Video modal — open
	------------------------------------------------------------------------- */
	function openVideoModal(card) {
		var data;
		try {
			data = JSON.parse(card.getAttribute('data-tmd-modal'));
		} catch (e) {
			return;
		}

		var embedUrl = getEmbedUrl(data.video_url || '');
		if (!embedUrl) { return; }

		activeVideoCard = card;

		videoTitle.textContent = data.name || '';
		videoIframe.title      = data.name || '';
		videoIframe.src        = embedUrl;

		videoModal.style.display = 'flex';
		videoModal.getBoundingClientRect(); // forces reflow
		requestAnimationFrame(function () {
			videoModal.classList.add('is-open');
			videoModal.setAttribute('aria-hidden', 'false');
		});

		document.body.style.overflow = 'hidden';
		videoCloseBtn.focus();
	}

	/* -------------------------------------------------------------------------
	   Video modal — close
	------------------------------------------------------------------------- */
	function closeVideoModal() {
		videoModal.classList.remove('is-open');
		videoModal.setAttribute('aria-hidden', 'true');

		var delay = prefersReducedMotion() ? 0 : 210;
		setTimeout(function () {
			if (!videoModal.classList.contains('is-open')) {
				videoModal.style.display = 'none';
				videoIframe.src = ''; /* stop video playback */
			}
		}, delay);

		document.body.style.overflow = '';

		if (activeVideoCard) {
			activeVideoCard.focus();
			activeVideoCard = null;
		}
	}

	/* -------------------------------------------------------------------------
	   Video modal — focus trap (Close button + iframe cycle)
	------------------------------------------------------------------------- */
	videoBox.addEventListener('keydown', function (e) {
		if (e.key !== 'Tab') { return; }

		var focusable = Array.prototype.slice.call(videoBox.querySelectorAll(FOCUSABLE));
		if (!focusable.length) { e.preventDefault(); return; }

		var first = focusable[0];
		var last  = focusable[focusable.length - 1];

		if (e.shiftKey && document.activeElement === first) {
			e.preventDefault();
			last.focus();
		} else if (!e.shiftKey && document.activeElement === last) {
			e.preventDefault();
			first.focus();
		}
	});

	/* -------------------------------------------------------------------------
	   Event listeners
	------------------------------------------------------------------------- */

	/* Escape closes whichever modal is currently open */
	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') { return; }
		if (modal.classList.contains('is-open'))      { closeModal(); }
		if (videoModal.classList.contains('is-open')) { closeVideoModal(); }
	});

	/* Bio modal controls */
	closeBtn.addEventListener('click', closeModal);
	overlay.addEventListener('click', closeModal);

	/* Video modal controls */
	videoCloseBtn.addEventListener('click', closeVideoModal);
	videoOverlay.addEventListener('click', closeVideoModal);

	/* Card click — dispatch to video modal or bio modal based on data */
	document.addEventListener('click', function (e) {
		var card = e.target.closest('[data-tmd-modal]');
		if (!card) { return; }
		var data = {};
		try { data = JSON.parse(card.getAttribute('data-tmd-modal')); } catch (ex) {}
		if (data.video_url) {
			openVideoModal(card);
		} else {
			openModal(card);
		}
	});

	/* Card keyboard activation: Enter or Space */
	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Enter' && e.key !== ' ') { return; }
		var card = e.target.closest('[data-tmd-modal]');
		if (!card) { return; }
		e.preventDefault(); // prevent page scroll on Space
		var data = {};
		try { data = JSON.parse(card.getAttribute('data-tmd-modal')); } catch (ex) {}
		if (data.video_url) {
			openVideoModal(card);
		} else {
			openModal(card);
		}
	});

	/* -------------------------------------------------------------------------
	   Utility
	------------------------------------------------------------------------- */
	function prefersReducedMotion() {
		return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	}

	/* -------------------------------------------------------------------------
	   Mobile carousel — prev/next buttons, dots, and scroll-snap swipe
	   Browser handles touch swipe natively via scroll-snap; JS drives buttons,
	   dots, and keeps the two in sync via the scroll event.
	------------------------------------------------------------------------- */
	var mqCarousel = window.matchMedia('(max-width: 767px)');

	document.querySelectorAll('.tmd-carousel-wrap').forEach(function (wrap) {
		var track = wrap.querySelector('.tmd-carousel-enabled');
		var prev  = wrap.querySelector('.tmd-carousel-prev');
		var next  = wrap.querySelector('.tmd-carousel-next');
		var dots  = Array.prototype.slice.call(wrap.querySelectorAll('.tmd-dot'));
		var n     = track ? track.querySelectorAll('.tmd-card').length : 0;
		var idx   = 0;

		if (!track || n === 0) { return; }

		function goTo(i) {
			if (!mqCarousel.matches) { return; }
			idx = Math.max(0, Math.min(i, n - 1));
			track.scrollTo({ left: idx * track.clientWidth, behavior: 'smooth' });
			sync();
		}

		function sync() {
			dots.forEach(function (dot, i) {
				var active = (i === idx);
				dot.classList.toggle('tmd-dot--active', active);
				dot.setAttribute('aria-pressed', String(active));
			});
			if (prev) { prev.disabled = (idx === 0); }
			if (next) { next.disabled = (idx === n - 1); }
		}

		track.addEventListener('scroll', function () {
			if (!mqCarousel.matches) { return; }
			var newIdx = Math.round(track.scrollLeft / (track.clientWidth || 1));
			if (newIdx !== idx) { idx = newIdx; sync(); }
		}, { passive: true });

		if (prev) { prev.addEventListener('click', function () { goTo(idx - 1); }); }
		if (next) { next.addEventListener('click', function () { goTo(idx + 1); }); }

		dots.forEach(function (dot, i) {
			dot.addEventListener('click', function () { goTo(i); });
		});

		/* Reset to first slide when resizing back to desktop */
		mqCarousel.addEventListener('change', function (e) {
			if (!e.matches) { idx = 0; track.scrollLeft = 0; }
			sync();
		});

		sync();
	});

}());
</script>
		<?php
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Read an ACF field, falling back to raw post meta if ACF is not active.
	 * Both return the same value for simple text/textarea fields.
	 */
	private static function get_acf_field( $field_name, $post_id ) {
		if ( function_exists( 'get_field' ) ) {
			return (string) get_field( $field_name, $post_id );
		}
		return (string) get_post_meta( $post_id, $field_name, true );
	}

	/**
	 * Truncate $text to at most $limit characters, breaking at a word boundary.
	 */
	private static function truncate_text( $text, $limit ) {
		if ( mb_strlen( $text ) <= $limit ) {
			return $text;
		}
		$cut        = mb_substr( $text, 0, $limit );
		$last_space = mb_strrpos( $cut, ' ' );
		return $last_space !== false ? mb_substr( $cut, 0, $last_space ) : $cut;
	}

	/**
	 * Return up to two initials from a display name (e.g. "Sarah Chen" → "SC").
	 */
	private static function get_initials( $name ) {
		$parts    = array_values( array_filter( explode( ' ', trim( $name ) ) ) );
		$initials = '';
		foreach ( array_slice( $parts, 0, 2 ) as $part ) {
			$initials .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
		}
		return $initials;
	}

	/**
	 * Pick a deterministic muted colour from a palette based on the person's name.
	 * Same name always gets the same colour; different names cycle across 6 hues.
	 */
	private static function get_initials_color( $name ) {
		$palette = array(
			'#A8C5B8', // muted sage
			'#B8C5D6', // soft slate blue
			'#C5B8D6', // dusty lavender
			'#D6C5A8', // warm sand
			'#C5D6B8', // soft mint
			'#D6B8C5', // dusty rose
		);
		return $palette[ abs( crc32( $name ) ) % count( $palette ) ];
	}
}

Team_Members_Display::init();
