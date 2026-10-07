<?php
/**
 * همگام‌سازی هویت حرفه‌ای میان حساب، پروفایل person و person_author.
 *
 * @package Revayat_Companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Revayat_Companion_Person_Identity {
	const TERM_PERSON_META = '_revayat_person_id';

	/** ترم متناظر یک پروفایل را بدون تغییر ناخواسته slug ایجاد/بازیابی می‌کند. */
	public static function ensure_term_for_person( $person_id ) {
		$person_id = absint( $person_id );
		$person    = get_post( $person_id );
		if ( ! $person || 'person' !== $person->post_type || ! taxonomy_exists( 'person_author' ) ) {
			return new WP_Error( 'invalid_person', 'پروفایل شخص معتبر نیست.' );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'person_author',
				'hide_empty' => false,
				'number'     => 1,
				'meta_key'   => self::TERM_PERSON_META,
				'meta_value' => $person_id,
			)
		);
		$term  = ! is_wp_error( $terms ) && $terms ? $terms[0] : null;

		if ( ! $term ) {
			$legacy = get_term_by( 'slug', $person->post_name, 'person_author' );
			if ( $legacy instanceof WP_Term ) {
				$term = $legacy;
			}
		}

		if ( $term instanceof WP_Term ) {
			if ( $term->name !== $person->post_title ) {
				$updated = wp_update_term( $term->term_id, 'person_author', array( 'name' => $person->post_title ) );
				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
			}
			update_term_meta( $term->term_id, self::TERM_PERSON_META, $person_id );
			return (int) $term->term_id;
		}

		$created = wp_insert_term(
			$person->post_title,
			'person_author',
			array( 'slug' => $person->post_name ?: sanitize_title( $person->post_title ) )
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$term_id = (int) $created['term_id'];
		update_term_meta( $term_id, self::TERM_PERSON_META, $person_id );
		return $term_id;
	}

	/** هوک ذخیره person. */
	public static function sync_person( $post_id, $post ) {
		if ( ! $post instanceof WP_Post || 'person' !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'trash' === $post->post_status ) {
			return;
		}
		self::ensure_term_for_person( $post_id );
	}

	/** شناسه person متناظر یک ترم را از منبع کانونیکال بازمی‌گرداند. */
	public static function get_person_id_for_term( $term ) {
		$term = $term instanceof WP_Term ? $term : get_term( absint( $term ), 'person_author' );
		if ( ! $term instanceof WP_Term || is_wp_error( $term ) ) {
			return 0;
		}
		$person_id = absint( get_term_meta( $term->term_id, self::TERM_PERSON_META, true ) );
		return $person_id && 'person' === get_post_type( $person_id ) ? $person_id : 0;
	}

	public static function get_term_for_person( $person_id ) {
		$terms = get_terms( array( 'taxonomy' => 'person_author', 'hide_empty' => false, 'number' => 1, 'meta_key' => self::TERM_PERSON_META, 'meta_value' => absint( $person_id ) ) );
		return ! is_wp_error( $terms ) && $terms ? $terms[0] : null;
	}

	/**
	 * آرشیو رابطه‌ای پدیدآورنده را به پروفایل عمومی کانونیکال هدایت می‌کند.
	 *
	 * `person_author` برای اتصال محتواست و نباید یک نمای عمومی موازی و متفاوت
	 * برای همان شخص بسازد. ترم‌های یتیم بدون هدایت، روی fallback بومی می‌مانند.
	 */
	public static function redirect_author_archive_to_person() {
		if ( is_admin() || wp_doing_ajax() || ! is_tax( 'person_author' ) ) {
			return;
		}

		$term = get_queried_object();
		if ( ! $term instanceof WP_Term ) {
			return;
		}

		$person_id = self::get_person_id_for_term( $term );
		if ( ! $person_id ) {
			$person = get_page_by_path( $term->slug, OBJECT, 'person' );
			$person_id = $person instanceof WP_Post ? (int) $person->ID : 0;
		}
		if ( ! $person_id || 'publish' !== get_post_status( $person_id ) ) {
			return;
		}

		$target = get_permalink( $person_id );
		if ( ! $target ) {
			return;
		}

		wp_safe_redirect( $target, 301, 'Revayat Iran' );
		exit;
	}

	/** یادداشت را به پروفایل حرفه‌ای صاحب حساب متصل می‌کند. */
	public static function assign_submission( $post_id, $user_id ) {
		$post_id   = absint( $post_id );
		$user_id   = absint( $user_id );
		$person_id = absint( get_user_meta( $user_id, '_revayat_person_id', true ) );
		if ( ! $person_id || 'person' !== get_post_type( $person_id ) ) {
			return new WP_Error( 'missing_person', 'پروفایل حرفه‌ای حساب یافت نشد.' );
		}
		$term_id = self::ensure_term_for_person( $person_id );
		if ( is_wp_error( $term_id ) ) {
			return $term_id;
		}
		$result = wp_set_object_terms( $post_id, array( (int) $term_id ), 'person_author', false );
		return is_wp_error( $result ) ? $result : (int) $term_id;
	}

	/** مهاجرت تکرارپذیر داده‌های موجود؛ خروجی برای ابزار مدیریتی و آزمون قابل استفاده است. */
	public static function migrate_existing() {
		$summary = array( 'people' => 0, 'submissions' => 0, 'errors' => array() );
		$people  = get_posts( array( 'post_type' => 'person', 'post_status' => array_keys( get_post_stati() ), 'posts_per_page' => -1, 'fields' => 'ids' ) );
		foreach ( $people as $person_id ) {
			$result = self::ensure_term_for_person( $person_id );
			if ( is_wp_error( $result ) ) {
				$summary['errors'][] = $result->get_error_message();
			} else {
				++$summary['people'];
			}
		}
		$posts = get_posts( array( 'post_type' => 'analyst_post', 'post_status' => array_keys( get_post_stati() ), 'posts_per_page' => -1 ) );
		foreach ( $posts as $post ) {
			if ( ! wp_get_object_terms( $post->ID, 'person_author', array( 'fields' => 'ids' ) ) ) {
				$result = self::assign_submission( $post->ID, $post->post_author );
				if ( is_wp_error( $result ) ) {
					$summary['errors'][] = $result->get_error_message();
				} else {
					++$summary['submissions'];
				}
			}
			if ( class_exists( 'Revayat_Companion_Analyst_Ratings' ) ) {
				Revayat_Companion_Analyst_Ratings::rebuild_post( $post->ID );
			}
		}
		return $summary;
	}

	public static function register_tools_page() {
		add_management_page( 'ترمیم شبکه تحلیلگران', 'ترمیم شبکه تحلیلگران', 'manage_options', 'revayat-analyst-repair', array( __CLASS__, 'render_tools_page' ) );
	}

	public static function render_tools_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$people      = isset( $_GET['people'] ) ? absint( $_GET['people'] ) : null;
		$submissions = isset( $_GET['submissions'] ) ? absint( $_GET['submissions'] ) : null;
		?>
		<div class="wrap"><h1><?php esc_html_e( 'ترمیم شبکه تحلیلگران', 'revayat-companion' ); ?></h1>
		<?php if ( null !== $people ) : ?><div class="notice notice-success"><p><?php echo esc_html( sprintf( 'همگام‌سازی انجام شد: %d پروفایل و %d یادداشت.', $people, $submissions ) ); ?></p></div><?php endif; ?>
		<p><?php esc_html_e( 'این عملیات تکرارپذیر، personها را به person_author و یادداشت‌های بدون انتساب را به پروفایل صاحب حساب متصل می‌کند.', 'revayat-companion' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="revayat_migrate_analyst_identity"><?php wp_nonce_field( 'revayat_migrate_analyst_identity' ); ?><button class="button button-primary" type="submit"><?php esc_html_e( 'اجرای همگام‌سازی', 'revayat-companion' ); ?></button></form></div>
		<?php
	}

	public static function handle_migration() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز است.', 'revayat-companion' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'revayat_migrate_analyst_identity' );
		$result = self::migrate_existing();
		wp_safe_redirect( add_query_arg( array( 'page' => 'revayat-analyst-repair', 'people' => $result['people'], 'submissions' => $result['submissions'] ), admin_url( 'tools.php' ) ) );
		exit;
	}
}
