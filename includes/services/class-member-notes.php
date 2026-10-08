<?php
/** Owner-scoped, versioned note drafts and bounded editorial review. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Revayat_Companion_Member_Notes {
	public static function listing( $user_id, $page = 1, $search = '', $status = '' ) {
		$args = array( 'post_type' => 'analyst_post', 'post_status' => array( 'draft', 'pending', 'publish' ), 'author' => $user_id, 'posts_per_page' => 10, 'paged' => max( 1, absint( $page ) ), 's' => sanitize_text_field( $search ) );
		if ( in_array( $status, array( 'draft', 'pending', 'publish' ), true ) ) { $args['post_status'] = $status; }
		return new WP_Query( $args );
	}
	public static function get( $id, $user_id ) {
		$post = get_post( $id );
		return $post && 'analyst_post' === $post->post_type && (int) $post->post_author === (int) $user_id ? $post : null;
	}
	public static function save( $user_id, $input ) {
		if ( ! Revayat_Companion_Member_Policy::active( $user_id ) || ! user_can( $user_id, 'revayat_submit_analyst_post' ) ) { return new WP_Error( 'forbidden', 'ارسال یادداشت پس از تأیید تحلیلگری فعال می‌شود.' ); }
		$token = sanitize_text_field( $input['request_key'] ?? '' );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $token ) ) { return new WP_Error( 'invalid_request', 'شناسه فرم معتبر نیست؛ صفحه را تازه کنید.' ); }
		return Revayat_Companion_Workflow_Lock::run( 'note-save:' . $user_id, static function () use ( $user_id, $input, $token ) {
			$mode = sanitize_key( $input['mode'] ?? 'draft' );
			if ( ! in_array( $mode, array( 'draft', 'submit', 'withdraw' ), true ) ) { return new WP_Error( 'invalid_mode', 'عملیات معتبر نیست.' ); }
			$id = absint( $input['post_id'] ?? 0 );
			if ( ! $id ) { $id = (int) get_user_meta( $user_id, '_rv_note_request_' . $token, true ); }
			$post = $id ? self::get( $id, $user_id ) : null;
			if ( $id && ! $post ) { return new WP_Error( 'forbidden', 'یادداشت متعلق به این حساب نیست.' ); }
			if ( $post && $token === get_post_meta( $id, '_rv_last_request', true ) ) { return array( 'message' => 'این درخواست قبلاً ذخیره شده است.', 'id' => $id, 'version' => (int) get_post_meta( $id, '_rv_note_version', true ), 'request_key' => wp_generate_uuid4() ); }
			$version = $post ? (int) get_post_meta( $id, '_rv_note_version', true ) : 0;
			if ( $post && $version !== (int) ( $input['version'] ?? -1 ) ) { return new WP_Error( 'conflict', 'نسخه یادداشت در پنجره دیگری تغییر کرده است؛ پیش از ادامه متن خود را نگه دارید و صفحه را تازه کنید.' ); }
			$review = $post ? get_post_meta( $id, '_revayat_review_status', true ) : '';
			if ( 'withdraw' === $mode ) {
				if ( ! $post || 'pending' !== $post->post_status ) { return new WP_Error( 'invalid_state', 'فقط یادداشت در انتظار بررسی قابل بازپس‌گیری است.' ); }
				wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) ); update_post_meta( $id, '_revayat_review_status', 'draft' ); update_post_meta( $id, '_rv_note_version', $version + 1 );
				return array( 'message' => 'یادداشت به پیش‌نویس برگشت.', 'reload' => true );
			}
			if ( $post && ( 'draft' !== $post->post_status || ! in_array( $review, array( '', 'draft', 'changes_requested', 'rejected' ), true ) ) ) { return new WP_Error( 'locked', 'این یادداشت قفل است؛ ابتدا وضعیت بررسی آن را پیگیری کنید.' ); }
			$title = sanitize_text_field( $input['post_title'] ?? '' ); $content = wp_kses_post( $input['post_content'] ?? '' );
			if ( mb_strlen( $title ) < 5 || mb_strlen( wp_strip_all_tags( $content ) ) < ( 'submit' === $mode ? 50 : 1 ) || mb_strlen( $content ) > 200000 ) { return new WP_Error( 'invalid_post', 'عنوان حداقل ۵ نویسه و متن ارسالی حداقل ۵۰ نویسه لازم دارد.' ); }
			$person = absint( get_user_meta( $user_id, '_revayat_person_id', true ) );
			if ( ! $person || 'person' !== get_post_type( $person ) ) { return new WP_Error( 'missing_person', 'پروفایل حرفه‌ای حساب هنوز متصل نیست؛ با مدیریت تماس بگیرید.' ); }
			$term = Revayat_Companion_Person_Identity::ensure_term_for_person( $person ); if ( is_wp_error( $term ) ) { return $term; }
			// Always persist as private draft until the author relationship is secured.
			$result = wp_insert_post( array( 'ID' => $id, 'post_type' => 'analyst_post', 'post_status' => 'draft', 'post_author' => $user_id, 'post_title' => $title, 'post_content' => $content, 'post_excerpt' => sanitize_textarea_field( $input['post_excerpt'] ?? '' ), 'comment_status' => 'open' ), true );
			if ( is_wp_error( $result ) ) { return $result; }
			$assigned = wp_set_object_terms( $result, array( $term ), 'person_author' );
			if ( is_wp_error( $assigned ) ) { if ( ! $id ) { wp_delete_post( $result, true ); } return $assigned; }
			$field = absint( $input['analyst_field'] ?? 0 );
			if ( $field && term_exists( $field, 'analyst_field' ) ) { wp_set_object_terms( $result, array( $field ), 'analyst_field' ); }
			update_post_meta( $result, '_revayat_review_status', 'submit' === $mode ? 'pending' : 'draft' );
			update_post_meta( $result, '_rv_note_version', $version + 1 ); update_post_meta( $result, '_rv_last_request', $token );
			if ( ! $id ) { update_user_meta( $user_id, '_rv_note_request_' . $token, $result ); }
			if ( 'submit' === $mode ) { wp_update_post( array( 'ID' => $result, 'post_status' => 'pending' ) ); do_action( 'revayat_analyst_post_status_changed', $result, 'pending', 'note:' . $result . ':' . ( $version + 1 ) ); }
			return array( 'message' => 'submit' === $mode ? 'یادداشت برای بررسی ارسال شد.' : 'پیش‌نویس ذخیره شد.', 'id' => $result, 'version' => $version + 1, 'request_key' => wp_generate_uuid4(), 'redirect' => 'submit' === $mode ? home_url( '/dashboard/?view=notes' ) : '' );
		} );
	}
	public static function handle() {
		Revayat_Companion_Member_Profile::authorize( 'rv_member_note' );
		$result = self::save( get_current_user_id(), wp_unslash( $_POST ) );
		Revayat_Companion_Member_Profile::respond( $result, 'notes' );
	}
	public static function review_queue( $user_id ) {
		$admin = user_can( $user_id, 'edit_others_posts' );
		$args = array( 'post_type' => 'analyst_post', 'post_status' => 'pending', 'posts_per_page' => 30 );
		if ( ! $admin ) { $args['meta_query'] = array( array( 'key' => '_rv_reviewer', 'value' => $user_id, 'type' => 'NUMERIC' ) ); }
		return user_can( $user_id, 'revayat_review_notes' ) || $admin ? get_posts( $args ) : array();
	}
	public static function review( $reviewer, $input ) {
		$id = absint( $input['post_id'] ?? 0 );
		return Revayat_Companion_Workflow_Lock::run( 'note-save:' . (int) get_post_field( 'post_author', $id ), static function () use ( $reviewer, $input, $id ) {
			$post = get_post( $id ); $admin = user_can( $reviewer, 'edit_others_posts' );
			if ( ! Revayat_Companion_Member_Policy::active( $reviewer ) || ! $post || 'analyst_post' !== $post->post_type || 'pending' !== $post->post_status || ( ! $admin && ( ! user_can( $reviewer, 'revayat_review_notes' ) || (int) get_post_meta( $id, '_rv_reviewer', true ) !== (int) $reviewer ) ) || Revayat_Companion_Member_Policy::owns_note( $reviewer, $post ) ) { return new WP_Error( 'forbidden', 'مجوز بررسی این یادداشت را ندارید.' ); }
			$version = (int) get_post_meta( $id, '_rv_note_version', true );
			if ( $version !== (int) ( $input['version'] ?? -1 ) ) { return new WP_Error( 'conflict', 'نسخه یادداشت تغییر کرده است.' ); }
			$decision = sanitize_key( $input['decision'] ?? '' ); $reason = sanitize_textarea_field( $input['reason'] ?? '' );
			if ( ! in_array( $decision, array( 'approved', 'changes_requested', 'rejected', 'recommended' ), true ) || ( 'approved' === $decision && ! $admin ) || ( in_array( $decision, array( 'changes_requested', 'rejected' ), true ) && mb_strlen( $reason ) < 5 ) ) { return new WP_Error( 'invalid_decision', 'تصمیم و توضیح معتبر وارد کنید؛ انتشار نهایی با تحریریه است.' ); }
			add_post_meta( $id, '_rv_review_history', array( 'actor' => $reviewer, 'decision' => $decision, 'reason' => $reason, 'at' => time() ) );
			update_post_meta( $id, '_revayat_editorial_note', $reason ); update_post_meta( $id, '_rv_note_version', $version + 1 );
			if ( 'recommended' !== $decision ) { update_post_meta( $id, '_revayat_review_status', $decision ); wp_update_post( array( 'ID' => $id, 'post_status' => 'approved' === $decision ? 'publish' : 'draft' ) ); do_action( 'revayat_analyst_post_status_changed', $id, $decision, 'note:' . $id . ':' . ( $version + 1 ) ); }
			return array( 'message' => 'نتیجه بررسی ثبت شد.', 'reload' => true );
		} );
	}
	public static function handle_review() {
		Revayat_Companion_Member_Profile::authorize( 'rv_note_review' );
		Revayat_Companion_Member_Profile::respond( self::review( get_current_user_id(), wp_unslash( $_POST ) ), 'review' );
	}
}
