<?php
/** رأی یادداشت، تجمیع امتیاز شخص و leaderboard. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Revayat_Companion_Analyst_Ratings {
	const SEED_META = '_revayat_analyst_seed_score';
	const SCORE_META = '_revayat_analyst_score';
	const COUNT_META = '_revayat_analyst_votes';
	const SUM_META   = '_revayat_analyst_vote_sum';
	const READY_META = '_revayat_analyst_rating_ready';

	/** ثبت رأی با قفل کوتاه دیتابیسی و بازسازی تجمیع پس از نوشتن منبع. */
	public static function record_vote( $post_id, $user_id, $rating ) {
		$post_id = absint( $post_id );
		$user_id = absint( $user_id );
		$rating  = absint( $rating );
        if ( $rating < 1 || $rating > 5 || ! Revayat_Companion_Member_Policy::can_rate( $user_id, $post_id ) ) {
            return new WP_Error( 'forbidden', 'امکان ثبت این رأی وجود ندارد.' );
        }
        return Revayat_Companion_Workflow_Lock::run( 'vote:' . $post_id, static function () use ( $post_id, $user_id, $rating ) {
            update_user_meta( $user_id, '_revayat_vote_analyst_' . $post_id, $rating );
            return self::rebuild_post( $post_id );
        } );
	}

	/** Explicit, repeatable seeding; actual votes always take precedence. */
	public static function seed_posts( $post_ids ) {
		$created = 0;
		foreach ( array_unique( array_map( 'absint', $post_ids ) ) as $post_id ) {
			if ( 'analyst_post' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) { continue; }
			if ( ! metadata_exists( 'post', $post_id, self::SEED_META ) ) {
				add_post_meta( $post_id, self::SEED_META, wp_rand( 10, 50 ) / 10, true );
				++$created;
			}
			self::rebuild_post( $post_id );
		}
		return $created;
	}

	public static function get_summary( $post_id, $rebuild_if_missing = true ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || 'analyst_post' !== get_post_type( $post_id ) ) {
			return array( 'count' => 0, 'average' => 0.0, 'sum' => 0 );
		}
		if ( $rebuild_if_missing && ! metadata_exists( 'post', $post_id, self::READY_META ) ) {
			return self::rebuild_post( $post_id );
		}
		return array(
			'count'   => absint( get_post_meta( $post_id, self::COUNT_META, true ) ),
			'average' => (float) get_post_meta( $post_id, self::SCORE_META, true ),
			'sum'     => absint( get_post_meta( $post_id, self::SUM_META, true ) ),
		);
	}

	public static function rebuild_post( $post_id ) {
		$post_id = absint( $post_id );
		$key     = '_revayat_vote_analyst_' . $post_id;
		$ids     = get_users( array( 'meta_key' => $key, 'fields' => 'ID' ) );
		$sum     = 0;
		$count   = 0;
		foreach ( $ids as $user_id ) {
			$value = (int) get_user_meta( $user_id, $key, true );
			if ( $value >= 1 && $value <= 5 ) {
				$sum += $value;
				++$count;
			}
		}
		$seed = (float) get_post_meta( $post_id, self::SEED_META, true );
		$average = $count ? round( $sum / $count, 1 ) : ( $seed >= 1 && $seed <= 5 ? $seed : 0.0 );
		update_post_meta( $post_id, self::COUNT_META, $count );
		update_post_meta( $post_id, self::SUM_META, $sum );
		update_post_meta( $post_id, self::SCORE_META, $average );
		update_post_meta( $post_id, self::READY_META, 1 );
		self::rebuild_people_for_post( $post_id );
		return array( 'count' => $count, 'average' => $average, 'sum' => $sum );
	}

	public static function rebuild_people_for_post( $post_id ) {
		$terms = wp_get_object_terms( absint( $post_id ), 'person_author' );
		if ( is_wp_error( $terms ) ) {
			return;
		}
		foreach ( $terms as $term ) {
			$person_id = Revayat_Companion_Person_Identity::get_person_id_for_term( $term );
			if ( $person_id ) {
				self::rebuild_person( $person_id, $term->term_id );
			}
		}
	}

	public static function rebuild_person( $person_id, $term_id = 0 ) {
		$person_id = absint( $person_id );
		if ( ! $term_id ) {
			$term_id = Revayat_Companion_Person_Identity::ensure_term_for_person( $person_id );
		}
		if ( is_wp_error( $term_id ) || ! $term_id ) {
			return array( 'score' => 0.0, 'votes' => 0, 'analyses_count' => 0 );
		}
		$ids = get_posts(
			array(
				'post_type'      => 'analyst_post',
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array( array( 'taxonomy' => 'person_author', 'field' => 'term_id', 'terms' => array( (int) $term_id ) ) ),
			)
		);
		$score_sum = 0.0;
		$scored    = 0;
		$votes     = 0;
		foreach ( $ids as $post_id ) {
			$summary = self::get_summary( $post_id, false );
			$votes  += $summary['count'];
			if ( $summary['average'] > 0 ) {
				$score_sum += $summary['average'];
				++$scored;
			}
		}
		$score = $scored ? round( $score_sum / $scored, 1 ) : 0.0;
		update_post_meta( $person_id, '_revayat_person_total_score', $score );
		update_post_meta( $person_id, '_revayat_person_votes', $votes );
		update_post_meta( $person_id, '_revayat_person_analyses_count', count( $ids ) );
		return array( 'score' => $score, 'votes' => $votes, 'analyses_count' => count( $ids ) );
	}

	public static function handle_status_change( $new_status, $old_status, $post ) {
		if ( $post instanceof WP_Post && 'analyst_post' === $post->post_type && $new_status !== $old_status ) {
			self::rebuild_people_for_post( $post->ID );
		}
	}

	public static function handle_terms_change( $object_id, $terms, $tt_ids, $taxonomy, $append = false, $old_tt_ids = array() ) {
		if ( 'person_author' === $taxonomy && 'analyst_post' === get_post_type( $object_id ) ) {
			self::rebuild_people_for_post( $object_id );
			foreach ( (array) $old_tt_ids as $tt_id ) {
				$term = get_term_by( 'term_taxonomy_id', absint( $tt_id ), 'person_author' );
				$person_id = $term instanceof WP_Term ? Revayat_Companion_Person_Identity::get_person_id_for_term( $term ) : 0;
				if ( $person_id ) {
					self::rebuild_person( $person_id, $term->term_id );
				}
			}
		}
	}

	public static function get_leaderboard( $limit = 5 ) {
		$people = get_posts(
			array(
				'post_type'      => 'person',
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => -1,
				'meta_key'       => '_revayat_person_total_score',
				'meta_value'     => 0,
				'meta_compare'   => '>',
				'meta_type'      => 'NUMERIC',
			)
		);
		usort(
			$people,
			static function ( $a, $b ) {
				$keys = array( '_revayat_person_total_score', '_revayat_person_votes', '_revayat_person_analyses_count' );
				foreach ( $keys as $key ) {
					$av = (float) get_post_meta( $a->ID, $key, true );
					$bv = (float) get_post_meta( $b->ID, $key, true );
					if ( $av !== $bv ) {
						return $av < $bv ? 1 : -1;
					}
				}
				return $a->ID <=> $b->ID;
			}
		);
		return array_slice( $people, 0, max( 1, min( 50, absint( $limit ) ) ) );
	}
}
