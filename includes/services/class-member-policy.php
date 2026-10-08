<?php
/** One authorization policy for member-facing UI and write handlers. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Revayat_Companion_Member_Policy {
	public static function active( $user_id ) {
		return $user_id && get_user_by( 'id', $user_id ) && Revayat_Companion_User_Portal::is_account_active( $user_id );
	}
	public static function owns_note( $user_id, $post ) {
		$post = get_post( $post );
		if ( ! $post ) { return false; }
		$terms = wp_get_object_terms( $post->ID, 'person_author' );
		if ( ! is_wp_error( $terms ) && $terms ) {
			foreach ( $terms as $term ) {
				$person = Revayat_Companion_Person_Identity::get_person_id_for_term( $term );
				if ( $person && ( absint( get_user_meta( $user_id, '_revayat_person_id', true ) ) === $person || absint( get_post_meta( $person, '_revayat_user_id', true ) ) === (int) $user_id ) ) { return true; }
			}
			return false;
		}
		return (int) $post->post_author === (int) $user_id;
	}
	public static function can_rate( $user_id, $post_id ) {
		$post = get_post( $post_id );
		return self::active( $user_id ) && $post && 'analyst_post' === $post->post_type && 'publish' === $post->post_status && '' === $post->post_password && ! self::owns_note( $user_id, $post );
	}
	public static function can_read_room( $user_id ) {
		if ( ! self::active( $user_id ) ) { return false; }
		if ( user_can( $user_id, 'manage_options' ) ) { return true; }
		$status = get_user_meta( $user_id, '_revayat_special_access_status', true );
		if ( in_array( $status, array( 'revoked', 'rejected', 'pending', 'needs_changes', 'withdrawn' ), true ) ) { return false; }
		return user_can( $user_id, 'revayat_read_situation_room' ) || user_can( $user_id, 'edit_others_posts' );
	}
    public static function comment_guard( $data ) {
        if ( 'analyst_post' === get_post_type( $data['comment_post_ID'] ?? 0 ) && get_current_user_id() && ! self::active( get_current_user_id() ) ) {
            wp_die( 'حساب شما برای ثبت دیدگاه فعال نیست.', '', array( 'response' => 403, 'back_link' => true ) );
        }
        return $data;
    }
    public static function moderate_guest_comment( $approved, $data ) {
        if ( ! is_wp_error( $approved ) && 'analyst_post' === get_post_type( $data['comment_post_ID'] ?? 0 ) && empty( $data['user_id'] ) && ! in_array( $approved, array( 'spam', 'trash' ), true ) ) { return 0; }
        return $approved;
    }

}
