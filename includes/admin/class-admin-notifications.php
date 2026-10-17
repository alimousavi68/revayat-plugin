<?php
/** A shared action queue; read receipts belong to each reviewer and submission version. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Revayat_Companion_Admin_Notifications {
 private static $snapshots = array();
 public static function hooks() {
  add_action( 'revayat_application_transitioned', array( __CLASS__, 'transition' ), 10, 5 );
  add_action( 'updated_user_meta', array( __CLASS__, 'invalidate_legacy' ), 10, 4 );
  add_action( 'added_user_meta', array( __CLASS__, 'invalidate_legacy' ), 10, 4 );
  add_action( 'admin_menu', array( __CLASS__, 'badges' ), 100 );
  add_action( 'before_delete_post', array( __CLASS__, 'delete_receipts' ), 10, 2 );
  add_action( 'admin_init', array( __CLASS__, 'legacy_routes' ) );
  add_action( 'admin_bar_menu', array( __CLASS__, 'bar' ), 90 );
  add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard' ) );
  add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
  add_action( 'wp_ajax_revayat_admin_notification_read', array( __CLASS__, 'read_ajax' ) );
  add_action( 'admin_post_revayat_admin_legacy_review', array( __CLASS__, 'legacy_review' ) );
  add_filter( 'heartbeat_received', array( __CLASS__, 'heartbeat' ), 10, 2 );
  add_filter( 'posts_clauses', array( __CLASS__, 'query_order' ), 10, 2 );
 }
 public static function legacy_routes() {
  $types = array( 'revayat-analyst-approvals' => 'analyst', 'revayat-special-access' => 'situation' );
  $page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) );
  if ( isset( $types[ $page ] ) && self::permitted() ) { wp_safe_redirect( self::url( array( 'type' => $types[ $page ] ) ) ); exit; }
 }
 /** Require the administrator role as well as capabilities; delegated reviewers are excluded. */
 public static function can_manage( $user_id ) {
  $user = get_userdata( $user_id );
  return $user && in_array( 'administrator', (array) $user->roles, true ) && user_can( $user, 'manage_options' ) && user_can( $user, 'revayat_manage_approvals' ) && Revayat_Companion_Member_Policy::active( $user->ID );
 }
 public static function permitted() { return self::can_manage( get_current_user_id() ); }
 public static function delete_receipts( $id, $post ) {
  if ( 'rv_application' !== $post->post_type ) { return; }
  global $wpdb; $key = '_rv_admin_read_' . $id;
  $users = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM $wpdb->usermeta WHERE meta_key=%s", $key ) );
  $wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $key ), array( '%s' ) );
  foreach ( $users as $user ) { wp_cache_delete( (int) $user, 'user_meta' ); }
  self::$snapshots = array(); update_option( 'revayat_admin_queue_revision', wp_generate_uuid4(), false );
 }
 public static function invalidate_legacy( $meta_id, $user, $key, $value ) {
  if ( in_array( $key, array( '_revayat_approval_status', '_revayat_special_access_status', '_revayat_requested_role', '_revayat_special_access_requested_at' ), true ) ) { self::$snapshots = array(); update_option( 'revayat_admin_queue_revision', wp_generate_uuid4(), false ); }
 }
 public static function transition( $id, $before, $state, $version, $actor ) {
  self::$snapshots = array(); if ( $before !== $state && 'draft' !== $state ) { update_option( 'revayat_admin_queue_revision', wp_generate_uuid4(), false ); }
  if ( 'pending' === $state && $before !== $state ) { update_post_meta( $id, '_rv_submitted_at', time() ); }
 }
 public static function url( $args = array() ) { return add_query_arg( array_merge( array( 'page' => 'revayat-member-applications' ), $args ), admin_url( 'users.php' ) ); }
 private static function sql( $unread = false ) {
  global $wpdb;
  $sql = " FROM $wpdb->posts p INNER JOIN $wpdb->postmeta s ON s.post_id=p.ID AND s.meta_key='_rv_state' AND s.meta_value='pending' INNER JOIN $wpdb->postmeta t ON t.post_id=p.ID AND t.meta_key='_rv_type' AND t.meta_value IN ('analyst','situation') LEFT JOIN $wpdb->postmeta v ON v.post_id=p.ID AND v.meta_key='_rv_version' LEFT JOIN $wpdb->postmeta d ON d.post_id=p.ID AND d.meta_key='_rv_submitted_at' LEFT JOIN $wpdb->usermeta r ON r.user_id=%d AND r.meta_key=CONCAT('_rv_admin_read_',p.ID) WHERE p.post_type='rv_application' AND p.post_status='private'";
  if ( $unread ) { $sql .= ' AND COALESCE(CAST(r.meta_value AS UNSIGNED),0)<GREATEST(COALESCE(CAST(v.meta_value AS UNSIGNED),1),1)'; }
  return $wpdb->prepare( $sql, get_current_user_id() );
 }
 public static function query_order( $clauses, $query ) {
  if ( ! $query->get( 'rv_action_queue' ) ) { return $clauses; }
  global $wpdb;
  $clauses['join'] .= " LEFT JOIN $wpdb->postmeta rvqueue_date ON rvqueue_date.post_id=$wpdb->posts.ID AND rvqueue_date.meta_key='_rv_submitted_at'";
  $date = "COALESCE(CAST(NULLIF(rvqueue_date.meta_value,'') AS UNSIGNED),UNIX_TIMESTAMP($wpdb->posts.post_date_gmt))";
  $clauses['orderby'] = $date . ( 'DESC' === $query->get( 'order' ) ? ' DESC' : ' ASC' ) . ",$wpdb->posts.ID " . ( 'DESC' === $query->get( 'order' ) ? 'DESC' : 'ASC' );
  $days = (int) $query->get( 'rv_action_days' );
  if ( in_array( $days, array( 7, 30 ), true ) ) { $clauses['where'] .= $wpdb->prepare( " AND $date >= %d", time() - $days * DAY_IN_SECONDS ); }
  return $clauses;
 }
 public static function unread_ids() { global $wpdb; return self::permitted() ? array_map( 'intval', $wpdb->get_col( 'SELECT DISTINCT p.ID' . self::sql( true ) ) ) : array(); }
 public static function snapshot() {
  if ( ! self::permitted() ) { return array(); }
  global $wpdb;
  $user = get_current_user_id(); if ( isset( self::$snapshots[ $user ] ) ) { return self::$snapshots[ $user ]; }
  $rows = $wpdb->get_results( "SELECT t.meta_value AS type,COUNT(DISTINCT p.ID) AS pending,COUNT(DISTINCT CASE WHEN COALESCE(CAST(r.meta_value AS UNSIGNED),0)<GREATEST(COALESCE(CAST(v.meta_value AS UNSIGNED),1),1) THEN p.ID END) AS unread" . self::sql() . ' GROUP BY t.meta_value', ARRAY_A );
  $out = array( 'pending' => 0, 'unread' => 0, 'analyst' => 0, 'situation' => 0, 'items' => array(), 'oldest' => '', 'revision' => get_option( 'revayat_admin_queue_revision', 'initial' ) );
  foreach ( $rows as $row ) { $out[ $row['type'] ] = (int) $row['pending']; $out['pending'] += (int) $row['pending']; $out['unread'] += (int) $row['unread']; }
  $items = $wpdb->get_results( 'SELECT DISTINCT p.ID,p.post_author,p.post_date_gmt,t.meta_value AS type,v.meta_value AS version,d.meta_value AS submitted,r.meta_value AS seen' . self::sql() . " ORDER BY COALESCE(CAST(NULLIF(d.meta_value,'') AS UNSIGNED),UNIX_TIMESTAMP(p.post_date_gmt)) DESC,p.ID DESC LIMIT 5", ARRAY_A );
  foreach ( $items as $item ) {
   $at = (int) $item['submitted'] ?: strtotime( $item['post_date_gmt'] . ' UTC' );
   $author = get_userdata( $item['post_author'] );
   $out['items'][] = array( 'id' => (int) $item['ID'], 'type' => $item['type'], 'name' => $author ? $author->display_name : 'عضو', 'at' => $at, 'age' => human_time_diff( $at, time() ), 'unread' => (int) $item['seen'] < max( 1, (int) $item['version'] ), 'url' => self::url( array( 'request_id' => $item['ID'], 'state' => 'all' ) ) );
  }
  $oldest = $wpdb->get_var( "SELECT MIN(COALESCE(CAST(NULLIF(d.meta_value,'') AS UNSIGNED),UNIX_TIMESTAMP(p.post_date_gmt)))" . self::sql() );
  if ( $oldest ) { $out['oldest'] = human_time_diff( (int) $oldest, time() ); }
  foreach ( self::legacy_items() as $legacy ) {
   $out[ $legacy['type'] ]++; $out['pending']++; if ( $legacy['unread'] ) { $out['unread']++; }
   $out['items'][] = $legacy;
   if ( ! $oldest || $legacy['at'] < (int) $oldest ) { $oldest = $legacy['at']; $out['oldest'] = $legacy['age']; }
  }
  usort( $out['items'], static function ( $a, $b ) { return $b['at'] <=> $a['at']; } ); $out['items'] = array_slice( $out['items'], 0, 5 );
  self::$snapshots[ $user ] = $out; return $out;
 }
 public static function legacy_items() {
  if ( ! self::permitted() ) { return array(); }
  global $wpdb; $items = array();
  foreach ( array( 'analyst' => '_revayat_approval_status', 'situation' => '_revayat_special_access_status' ) as $type => $key ) {
   $rows = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT u.ID,u.display_name,u.user_registered FROM $wpdb->users u JOIN $wpdb->usermeta s ON s.user_id=u.ID AND s.meta_key=%s AND s.meta_value='pending' WHERE NOT EXISTS(SELECT 1 FROM $wpdb->posts p JOIN $wpdb->postmeta t ON t.post_id=p.ID AND t.meta_key='_rv_type' AND t.meta_value=%s WHERE p.post_type='rv_application' AND p.post_status='private' AND p.post_author=u.ID)", $key, $type ), ARRAY_A );
   update_meta_cache( 'user', array_map( 'intval', array_column( $rows, 'ID' ) ) );
   foreach ( $rows as $row ) {
    if ( 'analyst' === $type && ! in_array( get_user_meta( $row['ID'], '_revayat_requested_role', true ), array( 'analyst', 'voter' ), true ) ) { continue; }
    $at = 'situation' === $type ? get_user_meta( $row['ID'], '_revayat_special_access_requested_at', true ) : '';
    $version = strtotime( ( $at ?: $row['user_registered'] ) . ' UTC' ) ?: 1;
    $items[] = array( 'id' => (int) $row['ID'], 'type' => $type, 'name' => $row['display_name'], 'version' => $version, 'at' => $version, 'age' => human_time_diff( $version, time() ), 'unread' => (int) get_user_meta( get_current_user_id(), '_rv_admin_legacy_' . $type . '_' . $row['ID'], true ) !== $version, 'url' => self::url( array( 'legacy_user' => $row['ID'], 'type' => $type ) ) );
   }
  }
  return $items;
 }
 public static function legacy_row( $user, $type ) { foreach ( self::legacy_items() as $item ) { if ( $item['id'] === $user && $item['type'] === $type ) { return $item; } } return null; }
 public static function mark_legacy_read( $user, $type, $version ) {
  $item = self::legacy_row( $user, $type );
  if ( ! $item || $item['version'] !== $version ) { return new WP_Error( 'changed', 'درخواست قدیمی تغییر کرده است.', array( 'status' => 409 ) ); }
  update_user_meta( get_current_user_id(), '_rv_admin_legacy_' . $type . '_' . $user, $version ); unset( self::$snapshots[ get_current_user_id() ] ); return true;
 }
 public static function legacy_list( $type, $unread, $filter, $days ) {
  if ( ! in_array( $filter, array( 'all', 'pending' ), true ) ) { return false; }
  $items = array_filter( self::legacy_items(), static function ( $item ) use ( $type, $unread, $days ) { return ( 'all' === $type || $item['type'] === $type ) && ( ! $unread || $item['unread'] ) && ( ! $days || $item['at'] >= time() - $days * DAY_IN_SECONDS ); } );
  if ( ! $items ) { return false; }
  foreach ( $items as $item ) {
   echo '<tr><td>' . esc_html( $item['name'] ) . ( $item['unread'] ? ' <strong>· جدید</strong>' : '' ) . '</td><td>' . esc_html( 'analyst' === $item['type'] ? 'تحلیلگری' : 'اتاق وضعیت' ) . '</td><td>در انتظار بررسی</td><td>' . esc_html( wp_date( 'Y/m/d H:i', $item['at'] ) ) . '</td><td><a class="button" href="' . esc_url( $item['url'] ) . '">بررسی درخواست</a></td></tr>';
  }
  return true;
 }
 public static function legacy_detail( $user, $type ) {
  $item = self::legacy_row( $user, $type );
  if ( ! $item ) { echo '<div class="wrap"><h1>درخواست یافت نشد یا بررسی شده است.</h1></div>'; return; }
  ?>
  <div class="wrap"><h1>بررسی درخواست قبلی</h1><section class="rv-request-detail" data-rv-legacy-user="<?php echo esc_attr( $user ); ?>" data-rv-type="<?php echo esc_attr( $type ); ?>" data-rv-version="<?php echo esc_attr( $item['version'] ); ?>"><h2><?php echo esc_html( $item['name'] ); ?></h2><p><?php echo esc_html( ( 'analyst' === $type ? 'درخواست عضویت شبکه تحلیلگران' : 'دسترسی اتاق وضعیت' ) . ' · ' . $item['age'] . ' پیش' ); ?></p><p>این درخواست در روند قبلی ثبت شده است. اطلاعات و مدارک جدید برای آن فرض نمی‌شود.</p><?php if ( 'analyst' === $type ) : ?><p>عضویت درخواستی: <?php echo 'voter' === get_user_meta( $user, '_revayat_requested_role', true ) ? 'عضو ارزیاب' : 'تحلیلگر'; ?></p><?php endif; ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'rv_admin_legacy_review' ); ?><input type="hidden" name="action" value="revayat_admin_legacy_review"><input type="hidden" name="user_id" value="<?php echo esc_attr( $user ); ?>"><input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>"><input type="hidden" name="version" value="<?php echo esc_attr( $item['version'] ); ?>"><label>تصمیم <select name="decision"><option value="approve">تأیید</option><option value="reject">رد</option></select></label> <button class="button button-primary" <?php disabled( $user, get_current_user_id() ); ?>>ثبت تصمیم</button></form><?php if ( $user === get_current_user_id() ) : ?><p>درخواست شما باید توسط اپراتور دیگری بررسی شود.</p><?php endif; ?></section><a href="<?php echo esc_url( self::url() ); ?>">بازگشت به کارتابل</a></div>
  <?php
 }
 public static function legacy_review() {
  if ( ! self::permitted() ) { wp_die( 'مجوز بررسی ندارید.', '', array( 'response' => 403 ) ); }
  check_admin_referer( 'rv_admin_legacy_review' );
  $user = absint( $_POST['user_id'] ?? 0 ); $type = sanitize_key( wp_unslash( $_POST['type'] ?? '' ) ); $version = absint( $_POST['version'] ?? 0 );
  if ( $user === get_current_user_id() ) { wp_die( 'درخواست خود را نمی‌توانید بررسی کنید.', '', array( 'response' => 403 ) ); }
  $result = Revayat_Companion_Workflow_Lock::run( 'legacy-application:' . $type . ':' . $user, static function () use ( $user, $type, $version ) {
   $item = self::legacy_row( $user, $type ); if ( ! $item || $item['version'] !== $version || ! Revayat_Companion_Member_Policy::active( $user ) ) { return new WP_Error( 'changed', 'درخواست تغییر کرده یا حساب فعال نیست.' ); }
   $decision = sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) );
   return 'analyst' === $type ? Revayat_Companion_User_Portal::review_membership( $user, $decision, get_current_user_id() ) : Revayat_Companion_User_Portal::review_special_access( $user, $decision );
  } );
  if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 409, 'back_link' => true ) ); }
  wp_safe_redirect( self::url( array( 'reviewed' => 1 ) ) ); exit;
 }
 public static function badge( $count, $type ) { return '<span class="awaiting-mod rv-admin-count" data-rv-count="' . esc_attr( $type ) . '"' . ( $count ? '' : ' hidden' ) . '><span class="pending-count">' . esc_html( number_format_i18n( $count ) ) . '</span></span>'; }
 public static function badges() {
  if ( ! self::permitted() ) { return; }
  global $submenu, $menu;
  $s = self::snapshot(); $targets = array( 'revayat-member-applications' => 'pending', 'revayat-analyst-approvals' => 'analyst', 'revayat-special-access' => 'situation' );
  foreach ( $submenu['users.php'] ?? array() as $key => $item ) { if ( isset( $targets[ $item[2] ] ) ) { $type = $targets[ $item[2] ]; $submenu['users.php'][ $key ][0] .= ' ' . self::badge( $s[ $type ], $type ); } }
  foreach ( $menu as $key => $item ) { if ( 'users.php' === $item[2] ) { $menu[ $key ][0] .= ' ' . self::badge( $s['pending'], 'pending' ); break; } }
 }
 public static function bar( $bar ) {
  if ( ! is_admin() || ! self::permitted() ) { return; }
  $s = self::snapshot();
  $bar->add_node( array( 'id' => 'rv-admin-notifications', 'title' => '<span class="ab-icon dashicons dashicons-bell" aria-hidden="true"></span><span class="ab-label">اعلان‌ها ' . self::badge( $s['unread'], 'unread' ) . '</span>', 'href' => self::url( array( 'state' => 'pending', 'unread' => 1 ) ), 'meta' => array( 'title' => 'اعلان‌های مدیریتی خوانده‌نشده' ) ) );
  $bar->add_node( array( 'id' => 'rv-admin-notifications-preview', 'parent' => 'rv-admin-notifications', 'title' => '<div data-rv-notification-preview>' . self::preview_html( $s ) . '</div>', 'meta' => array( 'class' => 'rv-notification-preview' ) ) );
  $bar->add_node( array( 'id' => 'rv-admin-notifications-all', 'parent' => 'rv-admin-notifications', 'title' => 'مشاهدهٔ همهٔ درخواست‌ها', 'href' => self::url() ) );
 }
 public static function preview_html( $s ) {
  $html = '<div class="rv-preview-heading">درخواست‌های جدید <span>' . esc_html( number_format_i18n( $s['unread'] ) ) . ' خوانده‌نشده</span></div>';
  $items = array_filter( $s['items'], static function ( $item ) { return $item['unread']; } );
  foreach ( $items as $item ) { $html .= '<a class="rv-preview-request" href="' . esc_url( $item['url'] ) . '"><strong>' . esc_html( 'analyst' === $item['type'] ? 'درخواست تحلیلگری' : 'درخواست دسترسی به اتاق وضعیت' ) . '</strong><span>' . esc_html( $item['name'] . ' · ' . $item['age'] . ' پیش' ) . '</span><span class="rv-preview-action">بررسی درخواست ←</span></a>'; }
  if ( ! $items ) { $html .= '<p>درخواست خوانده‌نشده‌ای در فهرست اخیر نیست.</p>'; }
  return $html;
 }
 public static function dashboard() { if ( self::permitted() ) { wp_add_dashboard_widget( 'rv_action_queue', 'نیازمند اقدام — درخواست‌های اعضا', array( __CLASS__, 'widget' ) ); } }
 public static function widget() { if ( ! self::permitted() ) { return; } echo '<div data-rv-queue>' . self::widget_html( self::snapshot() ) . '</div>'; }
 public static function widget_html( $s ) {
  ob_start(); ?>
  <div class="rv-queue-stats"><?php foreach ( array( 'analyst' => 'تحلیلگری', 'situation' => 'اتاق وضعیت' ) as $type => $label ) : ?><a href="<?php echo esc_url( self::url( array( 'type' => $type ) ) ); ?>"><strong><?php echo esc_html( number_format_i18n( $s[ $type ] ) ); ?></strong><?php echo esc_html( $label ); ?></a><?php endforeach; ?></div>
  <p><?php echo esc_html( number_format_i18n( $s['pending'] ) . ' درخواست در انتظار بررسی · ' . number_format_i18n( $s['unread'] ) . ' خوانده‌نشده' ); ?></p>
  <?php if ( $s['oldest'] ) : ?><p>قدیمی‌ترین درخواست: <?php echo esc_html( $s['oldest'] ); ?> پیش</p><?php endif; ?>
  <ul class="rv-queue-items"><?php foreach ( $s['items'] as $item ) : ?><li><a href="<?php echo esc_url( $item['url'] ); ?>"><strong><?php echo esc_html( $item['name'] ); ?></strong><span><?php echo esc_html( ( 'analyst' === $item['type'] ? 'تحلیلگری' : 'اتاق وضعیت' ) . ' · ' . $item['age'] . ' پیش' ); ?></span><?php if ( $item['unread'] ) : ?><em>جدید</em><?php endif; ?></a></li><?php endforeach; ?></ul>
  <?php if ( ! $s['pending'] ) : ?><p>درخواست در انتظار بررسی ندارید.</p><?php endif; ?>
  <a class="button" href="<?php echo esc_url( self::url() ); ?>">مشاهده کارتابل</a> <a class="button" href="<?php echo esc_url( self::url( array( 'unread' => 1 ) ) ); ?>">خوانده‌نشده‌ها</a>
  <?php return ob_get_clean();
 }
 public static function mark_read( $id, $version ) {
  if ( ! self::permitted() ) { return new WP_Error( 'forbidden', 'مجوز مشاهده اعلان را ندارید.', array( 'status' => 403 ) ); }
  $p = get_post( $id );
  if ( ! $p || 'rv_application' !== $p->post_type || 'private' !== $p->post_status || (int) get_post_meta( $id, '_rv_version', true ) !== $version ) { return new WP_Error( 'changed', 'درخواست تغییر کرده است؛ صفحه را تازه کنید.', array( 'status' => 409 ) ); }
  update_user_meta( get_current_user_id(), '_rv_admin_read_' . $id, $version ); unset( self::$snapshots[ get_current_user_id() ] ); return true;
 }
 public static function read_ajax() {
  if ( ! self::permitted() || ! check_ajax_referer( 'rv_admin_notifications', 'nonce', false ) ) { wp_send_json_error( array( 'message' => 'مجوز درخواست معتبر نیست.' ), 403 ); }
  $r = ! empty( $_POST['legacy_user'] ) ? self::mark_legacy_read( absint( $_POST['legacy_user'] ), sanitize_key( wp_unslash( $_POST['type'] ?? '' ) ), absint( $_POST['version'] ?? 0 ) ) : self::mark_read( absint( $_POST['id'] ?? 0 ), absint( $_POST['version'] ?? 0 ) );
  if ( is_wp_error( $r ) ) { wp_send_json_error( array( 'message' => $r->get_error_message() ), $r->get_error_data()['status'] ); }
  wp_send_json_success( self::payload() );
 }
 public static function payload() { if ( ! self::permitted() ) { return array(); } $s = self::snapshot(); return array( 'counts' => array_intersect_key( $s, array_flip( array( 'pending', 'unread', 'analyst', 'situation' ) ) ), 'preview' => self::preview_html( $s ), 'widget' => self::widget_html( $s ), 'revision' => $s['revision'] ); }
 public static function heartbeat( $response, $data ) { if ( self::permitted() && ! empty( $data['rv_admin_notifications'] ) ) { $response['rv_admin_notifications'] = self::payload(); } return $response; }
 public static function assets() {
  if ( ! self::permitted() ) { return; }
  $base = plugin_dir_url( dirname( __DIR__, 2 ) . '/revayat-companion.php' );
  wp_enqueue_style( 'rv-admin-notifications', $base . 'assets/admin-notifications.css', array(), filemtime( dirname( __DIR__, 2 ) . '/assets/admin-notifications.css' ) );
  wp_enqueue_script( 'rv-admin-notifications', $base . 'assets/admin-notifications.js', array( 'jquery', 'heartbeat' ), filemtime( dirname( __DIR__, 2 ) . '/assets/admin-notifications.js' ), true );
  wp_localize_script( 'rv-admin-notifications', 'rvAdminNotifications', array( 'nonce' => wp_create_nonce( 'rv_admin_notifications' ), 'endpoint' => admin_url( 'admin-ajax.php' ), 'counts' => self::payload()['counts'], 'revision' => self::snapshot()['revision'] ) );
 }
}
