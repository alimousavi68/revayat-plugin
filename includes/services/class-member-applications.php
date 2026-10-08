<?php
/** Private applications with explicit, versioned state transitions. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Revayat_Companion_Member_Applications {
	public static function register() {
		register_post_type( 'rv_application', array( 'label' => 'درخواست‌های اعضا', 'public' => false, 'show_ui' => false, 'show_in_rest' => false, 'rewrite' => false, 'query_var' => false, 'exclude_from_search' => true, 'supports' => array() ) );
		if ( (int) get_option( 'revayat_document_retention_days', 0 ) && ! wp_next_scheduled( 'revayat_private_document_cleanup' ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'revayat_private_document_cleanup' ); }
	}
	public static function labels() { return array( 'none' => 'هنوز درخواستی ثبت نشده', 'draft' => 'پیش‌نویس', 'pending' => 'در انتظار بررسی', 'needs_changes' => 'نیازمند اصلاح', 'approved' => 'تأییدشده', 'rejected' => 'تأیید نشده', 'withdrawn' => 'انصراف داده‌شده', 'revoked' => 'دسترسی لغو شده' ); }
	public static function latest( $user_id, $type ) {
		$items = get_posts( array( 'post_type' => 'rv_application', 'post_status' => 'private', 'author' => absint( $user_id ), 'posts_per_page' => 1, 'orderby' => 'ID', 'order' => 'DESC', 'meta_key' => '_rv_type', 'meta_value' => $type ) );
		return $items ? $items[0] : null;
	}
	public static function state( $post ) { return $post ? ( get_post_meta( $post->ID, '_rv_state', true ) ?: 'draft' ) : 'none'; }
	public static function valid_national_id( $value ) {
		if ( ! preg_match( '/^\d{10}$/', $value ) || preg_match( '/^(\d)\1{9}$/', $value ) ) { return false; }
		$sum = 0; for ( $i = 0; $i < 9; $i++ ) { $sum += (int) $value[ $i ] * ( 10 - $i ); }
		$mod = $sum % 11; return (int) $value[9] === ( $mod < 2 ? $mod : 11 - $mod );
	}
	public static function save( $user_id, $input ) {
		$type = sanitize_key( $input['type'] ?? '' );
		if ( ! Revayat_Companion_Member_Policy::active( $user_id ) || ! in_array( $type, array( 'analyst', 'situation' ), true ) ) { return new WP_Error( 'forbidden', 'درخواست معتبر نیست.' ); }
		return Revayat_Companion_Workflow_Lock::run( 'application:' . $user_id . ':' . $type, static function () use ( $user_id, $type, $input ) {
			$post = self::latest( $user_id, $type ); $state = self::state( $post );
			$mode = sanitize_key( $input['mode'] ?? 'draft' );
			if ( ! in_array( $mode, array( 'draft', 'submit', 'withdraw' ), true ) ) { return new WP_Error( 'invalid_mode', 'عملیات معتبر نیست.' ); }
			if ( $post && (int) get_post_meta( $post->ID, '_rv_version', true ) !== (int) ( $input['version'] ?? -1 ) ) { return new WP_Error( 'conflict', 'وضعیت درخواست تغییر کرده؛ صفحه را تازه کنید.' ); }
			if ( 'withdraw' === $mode ) {
				if ( ! in_array( $state, array( 'pending', 'needs_changes', 'draft' ), true ) ) { return new WP_Error( 'invalid_state', 'امکان انصراف از این درخواست وجود ندارد.' ); }
				self::transition( $post, 'withdrawn', $user_id, 'انصراف متقاضی' ); return array( 'message' => 'انصراف ثبت شد.', 'reload' => true );
			}
			if ( in_array( $state, array( 'pending', 'approved' ), true ) ) { return new WP_Error( 'invalid_state', 'این درخواست در حال بررسی است یا قبلاً تأیید شده است.' ); }
			if ( 'situation' === $type && ! Revayat_Companion_Private_Documents::ready() ) { return new WP_Error( 'documents_unavailable', 'دریافت مدارک پس از تعیین سیاست نگهداری و آماده‌شدن فضای خصوصی فعال می‌شود.' ); }
			$data = array();
			foreach ( array( 'full_name', 'role_title', 'organization', 'expertise', 'bio', 'sample_url', 'reason' ) as $field ) { $data[ $field ] = sanitize_textarea_field( $input[ $field ] ?? '' ); if ( mb_strlen( $data[ $field ] ) > ( 'bio' === $field ? 3000 : 500 ) ) { return new WP_Error( 'too_long', 'طول یکی از فیلدها بیش از حد مجاز است.' ); } }
			$data['sample_url'] = esc_url_raw( $data['sample_url'], array( 'http', 'https' ) );
			if ( 'submit' === $mode ) {
				if ( mb_strlen( $data['full_name'] ) < 3 || empty( $input['consent'] ) ) { return new WP_Error( 'incomplete', 'نام کامل و تأیید شرایط لازم است.' ); }
				if ( 'analyst' === $type && ( ! $data['expertise'] || mb_strlen( $data['bio'] ) < 30 ) ) { return new WP_Error( 'incomplete', 'حوزه تخصصی و معرفی حرفه‌ای حداقل ۳۰ نویسه را تکمیل کنید.' ); }
			}
			$new_attempt = in_array( $state, array( 'rejected', 'withdrawn', 'revoked' ), true );
			$documents = $post && ! $new_attempt ? (array) get_post_meta( $post->ID, '_rv_documents', true ) : array();
			$uploaded = array(); $national = '';
			if ( 'situation' === $type ) {
				$national = preg_replace( '/\s+/', '', Revayat_Companion_OTP_Service::digits( $input['national_id'] ?? '' ) );
				if ( ! $national && $post && ! $new_attempt ) { $national = Revayat_Companion_Private_Documents::decrypt( get_post_meta( $post->ID, '_rv_national_id', true ) ) ?: ''; }
				if ( ( $national && ! self::valid_national_id( $national ) ) || ( 'submit' === $mode && ! $national ) ) { return new WP_Error( 'national_invalid', 'کد ملی ده‌رقمی معتبر وارد کنید.' ); }
				foreach ( array( 'portrait', 'national_card' ) as $field ) {
					$result = Revayat_Companion_Private_Documents::upload( $field );
					if ( is_wp_error( $result ) ) { foreach ( $uploaded as $token ) { Revayat_Companion_Private_Documents::delete( $token ); } return $result; }
					if ( $result ) { $uploaded[ $field ] = $result; }
				}
				if ( 'submit' === $mode && ( empty( $uploaded['portrait'] ) && empty( $documents['portrait'] ) || empty( $uploaded['national_card'] ) && empty( $documents['national_card'] ) ) ) { foreach ( $uploaded as $token ) { Revayat_Companion_Private_Documents::delete( $token ); } return new WP_Error( 'documents_missing', 'عکس پرسنلی و تصویر کارت ملی لازم است.' ); }
			}
			if ( ! $post || $new_attempt ) {
				$id = wp_insert_post( array( 'post_type' => 'rv_application', 'post_status' => 'private', 'post_author' => $user_id, 'post_title' => 'درخواست عضو ' . $user_id ), true );
				if ( is_wp_error( $id ) ) { foreach ( $uploaded as $token ) { Revayat_Companion_Private_Documents::delete( $token ); } return $id; }
				$post = get_post( $id ); update_post_meta( $id, '_rv_type', $type );
			}
			foreach ( $uploaded as $field => $token ) { if ( ! empty( $documents[ $field ] ) ) { Revayat_Companion_Private_Documents::delete( $documents[ $field ] ); } $documents[ $field ] = $token; }
			update_post_meta( $post->ID, '_rv_payload', $data );
			if ( 'situation' === $type ) { update_post_meta( $post->ID, '_rv_documents', $documents ); update_post_meta( $post->ID, '_rv_national_id', Revayat_Companion_Private_Documents::encrypt( $national ) ); }
			self::transition( $post, 'submit' === $mode ? 'pending' : 'draft', $user_id, '' );
			return array( 'message' => 'submit' === $mode ? 'درخواست برای بررسی ارسال شد.' : 'پیش‌نویس درخواست ذخیره شد.', 'reload' => true );
		} );
	}
	private static function transition( $post, $state, $actor, $reason ) {
		$id = $post->ID; $before = self::state( $post ); $type = get_post_meta( $id, '_rv_type', true );
		$version = (int) get_post_meta( $id, '_rv_version', true ) + 1;
		update_post_meta( $id, '_rv_state', $state ); update_post_meta( $id, '_rv_version', $version );
		$event = $id . ':' . $version;
		add_post_meta( $id, '_rv_history', array( 'from' => $before, 'to' => $state, 'actor' => $actor, 'at' => current_time( 'mysql', true ), 'reason' => $reason, 'event' => $event ) );
		if ( $reason ) { update_post_meta( $id, '_rv_feedback', $reason ); }
		if ( 'situation' === $type ) {
			update_user_meta( $post->post_author, '_revayat_special_access_status', $state );
			$days = 'draft' === $state ? (int) get_option( 'revayat_document_draft_days', 0 ) : ( in_array( $state, array( 'approved', 'rejected', 'withdrawn', 'revoked' ), true ) ? (int) get_option( 'revayat_document_retention_days', 0 ) : 0 );
			if ( $days && (int) get_option( 'revayat_document_retention_days', 0 ) > 0 ) { update_post_meta( $id, '_rv_purge_at', time() + $days * DAY_IN_SECONDS ); } else { delete_post_meta( $id, '_rv_purge_at' ); }
		}
		if ( 'draft' !== $state && $state !== $before ) {
			$label = self::labels()[ $state ];
			$items = get_user_meta( $post->post_author, '_revayat_portal_notifications', true ); $items = is_array( $items ) ? $items : array();
			array_unshift( $items, array( 'id' => $event, 'post_id' => 0, 'status' => $state, 'message' => 'درخواست ' . ( 'analyst' === $type ? 'تحلیلگری' : 'اتاق وضعیت' ) . ': ' . $label . ( $reason ? ' — ' . $reason : '' ), 'created_at' => current_time( 'mysql', true ), 'read' => false ) );
			update_user_meta( $post->post_author, '_revayat_portal_notifications', array_slice( $items, 0, 30 ) );
			do_action( 'revayat_access_status_changed', (int) $post->post_author, 'analyst' === $type ? 'تحلیلگری' : 'اتاق وضعیت', $state, $event );
		}
	}
	public static function review( $id, $state, $reason, $version, $reviewer ) {
		if ( ! user_can( $reviewer, 'revayat_manage_approvals' ) || ! Revayat_Companion_Member_Policy::active( $reviewer ) ) { return new WP_Error( 'forbidden', 'مجوز بررسی ندارید.' ); }
		$post = get_post( $id );
		if ( ! $post || 'rv_application' !== $post->post_type || (int) $post->post_author === (int) $reviewer ) { return new WP_Error( 'not_found', 'درخواست یافت نشد.' ); }
		$type = get_post_meta( $id, '_rv_type', true );
		return Revayat_Companion_Workflow_Lock::run( 'application:' . $post->post_author . ':' . $type, static function () use ( $post, $id, $state, $reason, $version, $reviewer, $type ) {
			$current = self::state( $post );
			if ( (int) get_post_meta( $id, '_rv_version', true ) !== (int) $version || ! ( 'pending' === $current && in_array( $state, array( 'approved', 'rejected', 'needs_changes' ), true ) || 'approved' === $current && 'revoked' === $state ) ) { return new WP_Error( 'invalid_transition', 'وضعیت تغییر کرده یا این تصمیم مجاز نیست.' ); }
			$reason = sanitize_textarea_field( $reason );
			if ( 'approved' !== $state && mb_strlen( $reason ) < 5 ) { return new WP_Error( 'reason_required', 'دلیل روشن برای این تصمیم بنویسید.' ); }
			$user = get_user_by( 'id', $post->post_author );
			if ( ! $user || ! Revayat_Companion_Member_Policy::active( $user->ID ) ) { return new WP_Error( 'inactive', 'حساب متقاضی فعال نیست.' ); }
			if ( 'approved' === $state && 'analyst' === $type ) {
				$person = Revayat_Companion_User_Portal::ensure_person_profile( $user->ID );
				if ( is_wp_error( $person ) ) { return $person; }
				$data = (array) get_post_meta( $id, '_rv_payload', true );
				wp_update_post( array( 'ID' => $person, 'post_content' => $data['bio'] ?? '' ) );
				foreach ( array( 'role_title', 'organization', 'expertise' ) as $field ) { update_post_meta( $person, '_revayat_' . $field, $data[ $field ] ?? '' ); }
				$user->add_role( 'analyst' ); $user->add_cap( 'revayat_submit_analyst_post', true );
			} elseif ( 'revoked' === $state && 'analyst' === $type ) { $user->remove_role( 'analyst' ); $user->add_cap( 'revayat_submit_analyst_post', false ); }
			if ( 'situation' === $type ) { $user->add_cap( 'revayat_read_situation_room', 'approved' === $state ); }
			self::transition( $post, $state, $reviewer, $reason );
			return array( 'message' => 'تصمیم ثبت شد.' );
		} );
	}
	public static function handle() {
		Revayat_Companion_Member_Profile::authorize( 'rv_application' );
		$input = wp_unslash( $_POST );
		Revayat_Companion_Member_Profile::respond( self::save( get_current_user_id(), $input ), 'analyst' === ( $input['type'] ?? '' ) ? 'analyst-request' : 'situation-access' );
	}
}
