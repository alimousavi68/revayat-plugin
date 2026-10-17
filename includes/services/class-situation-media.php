<?php
/** Room uploads stay outside the document root; every read checks the owning bulletin. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Revayat_Companion_Situation_Media {
	private static $upload_post = 0;
	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'init', array( __CLASS__, 'inactive_account' ), 19 );
		add_action( 'admin_init', array( __CLASS__, 'settings' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rest_upload_context' ), 5, 3 );
		add_filter( 'upload_dir', array( __CLASS__, 'upload_directory' ), 99 );
		add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'upload_check' ) );
		add_filter( 'wp_handle_sideload_prefilter', array( __CLASS__, 'upload_check' ) );
		add_action( 'add_attachment', array( __CLASS__, 'register_upload' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'delete_files' ) );
		add_action( 'updated_post_meta', array( __CLASS__, 'keep_absolute_path' ), 10, 4 );
		add_filter( 'wp_update_attachment_metadata', array( __CLASS__, 'secure_variants' ), 99, 2 );
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'attachment_url' ), 99, 2 );
		add_filter( 'wp_get_original_image_url', array( __CLASS__, 'original_url' ), 99, 2 );
		add_filter( 'image_downsize', array( __CLASS__, 'image_size' ), 99, 3 );
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'srcset' ), 999, 5 );
		add_filter( 'wp_prepare_attachment_for_js', array( __CLASS__, 'prepare_attachment' ), 99, 3 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'protect_rest' ), 99, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'protect_attachment_page' ), 0 );
		add_filter( 'the_content', array( __CLASS__, 'content' ), 30 );
		add_action( 'admin_post_revayat_room_media', array( __CLASS__, 'serve' ) );
		add_action( 'admin_post_nopriv_revayat_room_media', array( __CLASS__, 'serve' ) );
		add_action( 'add_meta_boxes_situation_room', array( __CLASS__, 'meta_box' ) );
		add_action( 'admin_post_revayat_room_media_migrate', array( __CLASS__, 'migrate_action' ) );
		add_filter( 'ajax_query_attachments_args', array( __CLASS__, 'media_query' ) );
		add_filter( 'rest_attachment_query', array( __CLASS__, 'media_query' ), 10, 2 );
	}
	public static function directory( $create = false ) {
		return self::check_directory( get_option( 'revayat_room_media_path', '' ), $create );
	}
	private static function check_directory( $path, $create ) {
		$r = Revayat_Companion_Private_Documents::check_directory( $path, false );
		$docs = Revayat_Companion_Private_Documents::directory();
		$normalized = is_wp_error( $r ) ? wp_normalize_path( rtrim( $path, '/' ) ) : $r;
		if ( $docs && ( $docs === $normalized || 0 === strpos( $normalized . '/', trailingslashit( $docs ) ) || 0 === strpos( $docs . '/', trailingslashit( $normalized ) ) ) ) { return new WP_Error( 'separate_storage', 'یک پوشه جدا از فضای مدارک هویتی انتخاب کنید.' ); }
		return Revayat_Companion_Private_Documents::check_directory( $path, $create );
	}
	public static function menu() { add_submenu_page( 'edit.php?post_type=situation_room', 'رسانه‌های خصوصی', 'رسانه‌های خصوصی', 'manage_options', 'revayat-room-media', array( __CLASS__, 'render' ) ); }
	public static function settings() { register_setting( 'revayat_room_media', 'revayat_room_media_path', array( 'sanitize_callback' => array( __CLASS__, 'save_path' ) ) ); }
	public static function save_path( $path ) {
		if ( ! current_user_can( 'manage_options' ) ) { return get_option( 'revayat_room_media_path', '' ); }
		$path = sanitize_text_field( $path );
		// Moving an occupied storage root requires an explicit filesystem migration.
		$old = get_option( 'revayat_room_media_path', '' );
		if ( $old && $old !== $path && get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_rv_room_media_post', 'posts_per_page' => 1, 'fields' => 'ids' ) ) ) {
			add_settings_error( 'revayat_room_media', 'occupied', 'فضای فعلی رسانه دارد؛ پیش از تغییر مسیر باید فایل‌ها منتقل شوند. مسیر فعلی حفظ شد.' ); return $old;
		}
		$r = self::check_directory( $path, true );
		if ( is_wp_error( $r ) ) { add_settings_error( 'revayat_room_media', $r->get_error_code(), $r->get_error_message() ); }
		return $path;
	}
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$r = self::directory(); ?>
		<div class="wrap"><h1>رسانه‌های خصوصی اتاق وضعیت</h1><?php settings_errors( 'revayat_room_media' ); ?>
		<p>فایل اصلی و نسخه‌های کوچک‌تر رسانه‌های جدید، خارج از ریشه عمومی وب ذخیره می‌شوند. نمایش فقط با حساب فعال، مجوز اتاق وضعیت و مجوز همان بولتن ممکن است.</p>
		<form action="options.php" method="post"><?php settings_fields( 'revayat_room_media' ); ?><label>مسیر مطلق پوشه اختصاصی رسانه‌ها <input class="large-text" dir="ltr" name="revayat_room_media_path" value="<?php echo esc_attr( get_option( 'revayat_room_media_path', '' ) ); ?>"></label><p>این پوشه را داخل public_html نسازید و به وب‌سرور Alias نکنید. مثال: پوشهٔ private-room-media در کنار public_html، با مسیر کامل حساب هاست خودتان. فضای رسانه‌ها را از مدارک هویتی جدا نگه دارید.</p><?php submit_button(); ?></form>
		<p><?php echo is_wp_error( $r ) ? esc_html( $r->get_error_message() ) : 'ذخیره خصوصی آماده است. رسانه را از داخل ویرایش همان بولتن بارگذاری کنید.'; ?></p>
		<p>فایل‌های قدیمی با تغییر تنظیمات خصوصی نمی‌شوند. در ویرایش هر بولتن، بخش «امنیت رسانه‌ها» فایل‌های نیازمند انتقال را نشان می‌دهد. نسخه‌های قبلی CDN و کش هاست هم باید پس از انتقال پاک شوند.</p></div><?php
	}
	private static function context() {
		$id = self::$upload_post;
		$upload = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ( 'upload-attachment' === ( $_REQUEST['action'] ?? '' ) || 'async-upload.php' === wp_basename( $_SERVER['PHP_SELF'] ?? '' ) );
		if ( ! $id && ( $upload || ( defined( 'WP_CLI' ) && WP_CLI ) ) && 'revayat_room_media_migrate' !== ( $_REQUEST['action'] ?? '' ) ) { $id = absint( $_REQUEST['post_id'] ?? 0 ); }
		return 'situation_room' === get_post_type( $id ) ? $id : 0;
	}
	public static function rest_upload_context( $result, $server, $request ) {
		self::$upload_post = ( '/wp/v2/media' === $request->get_route() && 'POST' === $request->get_method() ) ? absint( $request->get_param( 'post' ) ) : 0;
		return $result;
	}
	public static function upload_check( $file ) {
		$id = self::context(); if ( ! $id ) { return $file; }
		if ( ! current_user_can( 'edit_post', $id ) ) { $file['error'] = 'مجوز بارگذاری برای این بولتن را ندارید.'; return $file; }
		$r = self::directory(); if ( is_wp_error( $r ) ) { $file['error'] = 'فضای خصوصی رسانه‌های اتاق وضعیت آماده نیست؛ مدیر باید تنظیمات رسانه‌های خصوصی را تکمیل کند.'; }
		return $file;
	}
	public static function upload_directory( $dirs ) {
		$id = self::context(); if ( ! $id ) { return $dirs; }
		$r = self::directory();
		if ( is_wp_error( $r ) || ! current_user_can( 'edit_post', $id ) ) { $dirs['error'] = 'فضای خصوصی یا مجوز بارگذاری بولتن آماده نیست.'; return $dirs; }
		$path = $r . '/post-' . $id;
		if ( ! is_dir( $path ) && ! @mkdir( $path, 0700 ) && ! is_dir( $path ) ) { $dirs['error'] = 'ساخت پوشه خصوصی بولتن انجام نشد.'; return $dirs; }
		if ( 0 !== strpos( wp_normalize_path( realpath( $path ) ), trailingslashit( $r ) ) ) { $dirs['error'] = 'پوشه بولتن خارج از فضای خصوصی است.'; return $dirs; }
		@chmod( $path, 0700 );
		return array( 'path' => $path, 'url' => home_url( '/private-room-media/' . $id ), 'subdir' => '/post-' . $id, 'basedir' => $r, 'baseurl' => home_url( '/private-room-media' ), 'error' => false );
	}
	public static function owner( $id ) { return absint( get_post_meta( $id, '_rv_room_media_post', true ) ); }
	public static function keep_absolute_path( $meta_id, $id, $key, $value ) {
		if ( '_wp_attached_file' !== $key || ! self::owner( $id ) || preg_match( '#^(?:/|[A-Za-z]:/)#', $value ) ) { return; }
		$root = self::directory(); if ( is_wp_error( $root ) ) { return; }
		$real = realpath( $root . '/' . $value );
		if ( $real && 0 === strpos( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $root ) ) ) ) { update_post_meta( $id, $key, $real ); }
	}
	public static function register_upload( $id ) {
		$post = self::context(); if ( ! $post ) { return; }
		$r = self::directory(); if ( is_wp_error( $r ) ) { return; }
		$relative = get_post_meta( $id, '_wp_attached_file', true );
		$file = realpath( $relative ) ?: realpath( $r . '/' . ltrim( $relative, '/' ) );
		if ( $file && is_file( $file ) && 0 === strpos( wp_normalize_path( $file ), trailingslashit( wp_normalize_path( $r ) ) ) ) { update_post_meta( $id, '_wp_attached_file', $file ); update_post_meta( $id, '_rv_room_media_post', $post ); }
	}
	private static function variants( $id, $meta = null ) {
		$meta = null === $meta ? wp_get_attachment_metadata( $id ) : $meta;
		$files = array( 'full' => wp_basename( get_attached_file( $id ) ) );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $key => $size ) { $files[ $key ] = wp_basename( $size['file'] ); }
		foreach ( array( 'original' => 'original_image', 'source' => 'source_image', 'animated-video' => 'animated_video', 'animated-poster' => 'animated_video_poster', 'legacy-thumb' => 'thumb' ) as $variant => $key ) { if ( ! empty( $meta[ $key ] ) && is_string( $meta[ $key ] ) ) { $files[ $variant ] = wp_basename( $meta[ $key ] ); } }
		foreach ( (array) ( get_post_meta( $id, '_wp_attachment_backup_sizes', true ) ?: array() ) as $key => $size ) { $files[ 'backup-' . $key ] = wp_basename( $size['file'] ); }
		return $files;
	}
	public static function delete_files( $id ) {
		if ( ! self::owner( $id ) ) { return; }
		// Core refuses to delete an original outside the public uploads root.
		foreach ( self::variants( $id ) as $variant => $name ) { $path = self::path( $id, $variant ); if ( $path ) { wp_delete_file( $path ); } }
	}
	public static function secure_variants( $metadata, $id ) {
		if ( ! self::owner( $id ) ) { return $metadata; }
		$file = get_attached_file( $id ); $root = self::directory(); if ( is_wp_error( $root ) || ! $file ) { return $metadata; }
		$files = self::variants( $id, $metadata );
		$urls = (array) ( get_post_meta( $id, '_rv_room_media_old_urls', true ) ?: array() );
		foreach ( $files as $key => $name ) {
			$path = realpath( dirname( $file ) . '/' . $name );
			if ( $path && 0 === strpos( $path, trailingslashit( $root ) ) ) { @chmod( $path, 0600 ); }
			if ( ! isset( $urls[ $key ] ) ) { $urls[ $key ] = home_url( '/private-room-media/' . self::owner( $id ) . '/' . $name ); }
		}
		update_post_meta( $id, '_rv_room_media_old_urls', $urls );
		return $metadata;
	}
	public static function allowed( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'situation_room' !== $post->post_type || ! Revayat_Companion_Member_Policy::active( get_current_user_id() ) || ! Revayat_Companion_Member_Policy::can_read_room( get_current_user_id() ) ) { return false; }
		if ( 'publish' !== $post->post_status ) { return 'trash' !== $post->post_status && current_user_can( 'edit_post', $post_id ); }
		$access = Revayat_Companion_Content_Service::evaluate_bulletin_access( $post );
		return ! empty( $access['can_view_content'] ) && ( ! $post->post_password || ! post_password_required( $post ) );
	}
	public static function url( $id, $variant = 'full' ) {
		$post = self::owner( $id ); if ( ! $post || ! self::allowed( $post ) ) { return ''; }
		return wp_nonce_url( add_query_arg( array( 'action' => 'revayat_room_media', 'media_id' => $id, 'variant' => $variant ), admin_url( 'admin-post.php' ) ), 'rv_room_media_' . $id );
	}
	public static function attachment_url( $url, $id ) { if ( self::owner( $id ) ) { return self::url( $id ); } return 'situation_room' === get_post_type( wp_get_post_parent_id( $id ) ) ? '' : $url; }
	public static function original_url( $url, $id ) { return self::owner( $id ) ? self::url( $id, empty( wp_get_attachment_metadata( $id )['original_image'] ) ? 'full' : 'original' ) : self::attachment_url( $url, $id ); }
	public static function reference_url( $id, $url, $variant = 'full' ) {
		if ( ! $id ) { $args = array(); parse_str( (string) wp_parse_url( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ), PHP_URL_QUERY ), $args ); $id = absint( $args['media_id'] ?? 0 ) ?: attachment_url_to_postid( $url ); }
		return self::owner( $id ) ? self::url( $id, $variant ) : '';
	}
	public static function image_size( $result, $id, $size ) {
		if ( ! self::owner( $id ) ) { return 'situation_room' === get_post_type( wp_get_post_parent_id( $id ) ) ? array( '', 0, 0, false ) : $result; }
		$meta = wp_get_attachment_metadata( $id ); $variant = 'full'; $m = $meta;
		if ( 'full' !== $size && isset( $meta['sizes']['revayat-web-full'] ) ) { $variant = 'revayat-web-full'; $m = $meta['sizes'][ $variant ]; }
		if ( is_string( $size ) && isset( $meta['sizes'][ $size ] ) ) { $variant = $size; $m = $meta['sizes'][ $size ]; }
		elseif ( is_array( $size ) ) { foreach ( (array) ( $meta['sizes'] ?? array() ) as $key => $candidate ) { if ( $candidate['width'] >= $size[0] && $candidate['height'] >= $size[1] ) { $variant = $key; $m = $candidate; break; } } }
		return array( self::url( $id, $variant ), (int) ( $m['width'] ?? 0 ), (int) ( $m['height'] ?? 0 ), 'full' !== $variant );
	}
	public static function srcset( $sources, $size, $src, $meta, $id ) { return self::owner( $id ) ? false : $sources; }
	public static function prepare_attachment( $data, $post, $meta ) {
		if ( ! self::owner( $post->ID ) ) { return $data; }
		$data['url'] = self::url( $post->ID ); $data['link'] = '';
		foreach ( (array) ( $data['sizes'] ?? array() ) as $key => $item ) { $data['sizes'][ $key ]['url'] = self::url( $post->ID, $key ); }
		return $data;
	}
	public static function media_query( $args, $request = null ) {
		if ( current_user_can( 'manage_options' ) ) { return $args; }
		$rule = array( 'key' => '_rv_room_media_post', 'compare' => 'NOT EXISTS' );
		$id = absint( $_REQUEST['post_id'] ?? ( $_REQUEST['query']['post_parent'] ?? 0 ) ); if ( $request instanceof WP_REST_Request ) { $parent = $request->get_param( 'parent' ); if ( is_array( $parent ) && 1 === count( $parent ) ) { $id = absint( reset( $parent ) ); } }
		if ( $id && self::allowed( $id ) && current_user_can( 'edit_post', $id ) ) { $rule = array( 'relation' => 'OR', $rule, array( 'key' => '_rv_room_media_post', 'value' => $id ) ); }
		$args['meta_query'][] = $rule;
		return $args;
	}
	public static function protect_rest( $result, $server, $request ) {
		if ( preg_match( '#^/wp/v2/media/(\d+)(?:/|$)#', $request->get_route(), $m ) && self::owner( (int) $m[1] ) && ! self::allowed( self::owner( (int) $m[1] ) ) ) { return new WP_Error( 'private_media', 'دسترسی به رسانه مجاز نیست.', array( 'status' => 403 ) ); }
		return $result;
	}
	public static function protect_attachment_page() {
		if ( is_singular( 'situation_room' ) || is_post_type_archive( 'situation_room' ) ) { if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); } do_action( 'litespeed_control_set_nocache', 'Private situation room' ); nocache_headers(); header( 'X-Robots-Tag: noindex, nofollow, noarchive' ); }
		if ( is_attachment() && self::owner( get_queried_object_id() ) ) { nocache_headers(); header( 'X-Robots-Tag: noindex, nofollow, noarchive' ); if ( ! self::allowed( self::owner( get_queried_object_id() ) ) ) { wp_die( 'دسترسی مجاز نیست.', '', array( 'response' => 403 ) ); } wp_safe_redirect( get_permalink( self::owner( get_queried_object_id() ) ) ); exit; }
	}
	public static function path( $id, $variant ) {
		$root = self::directory(); if ( is_wp_error( $root ) ) { return ''; }
		$files = self::variants( $id ); if ( ! isset( $files[ $variant ] ) ) { return ''; }
		$file = dirname( get_attached_file( $id ) ) . '/' . $files[ $variant ];
		$real = realpath( $file );
		return $real && is_file( $real ) && is_readable( $real ) && 0 === strpos( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $root ) ) ) ? $real : '';
	}
	public static function inactive_account() {
		// The portal normally redirects suspended sessions to login; media must return an error instead of login HTML.
		if ( 'revayat_room_media' === ( $_REQUEST['action'] ?? '' ) && is_user_logged_in() && ! Revayat_Companion_Member_Policy::active( get_current_user_id() ) ) {
			wp_logout(); nocache_headers(); header( 'X-Robots-Tag: noindex, nofollow, noarchive' ); wp_die( 'دسترسی به رسانه مجاز نیست.', '', array( 'response' => 403 ) );
		}
	}
	public static function serve() {
		nocache_headers(); header( 'Cache-Control: private, no-store, max-age=0' ); header( 'X-Robots-Tag: noindex, nofollow, noarchive' ); header( 'X-Content-Type-Options: nosniff' );
		if ( ! in_array( $_SERVER['REQUEST_METHOD'] ?? 'GET', array( 'GET', 'HEAD' ), true ) ) { header( 'Allow: GET, HEAD' ); status_header( 405 ); exit; }
		$id = absint( $_GET['media_id'] ?? 0 ); $post = self::owner( $id );
		if ( ! $post || ! self::allowed( $post ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'rv_room_media_' . $id ) ) { wp_die( 'دسترسی به رسانه مجاز نیست.', '', array( 'response' => 403 ) ); }
		$file = self::path( $id, sanitize_key( wp_unslash( $_GET['variant'] ?? 'full' ) ) );
		if ( ! $file ) { wp_die( 'رسانه موجود نیست.', '', array( 'response' => 404 ) ); }
		$mime = wp_check_filetype( $file )['type'] ?: 'application/octet-stream';
		$inline = preg_match( '#^(image/(jpeg|png|webp|gif|avif)|video/(mp4|webm)|audio/(mpeg|ogg|wav|mp4))$#', $mime );
		header( 'Content-Type: ' . ( $inline ? $mime : 'application/octet-stream' ) ); header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . sanitize_file_name( wp_basename( $file ) ) . '"' );
		$length = filesize( $file ); $start = 0; $end = $length - 1;
		header( 'Accept-Ranges: bytes' );
		if ( ! empty( $_SERVER['HTTP_RANGE'] ) ) {
			if ( ! preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $_SERVER['HTTP_RANGE'] ), $m ) || ( '' === $m[1] && '' === $m[2] ) ) { status_header( 416 ); header( 'Content-Range: bytes */' . $length ); exit; }
			if ( '' === $m[1] ) { $start = max( 0, $length - (int) $m[2] ); } else { $start = (int) $m[1]; if ( '' !== $m[2] ) { $end = min( $end, (int) $m[2] ); } }
			if ( $start > $end || $start >= $length ) { status_header( 416 ); header( 'Content-Range: bytes */' . $length ); exit; }
			status_header( 206 ); header( "Content-Range: bytes $start-$end/$length" );
		}
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		if ( 'HEAD' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { exit; }
		while ( ob_get_level() ) { ob_end_clean(); }
		$stream = fopen( $file, 'rb' ); if ( ! $stream ) { status_header( 404 ); exit; } fseek( $stream, $start ); $remaining = $end - $start + 1;
		while ( $remaining > 0 && ! feof( $stream ) && ! connection_aborted() ) { $chunk = fread( $stream, min( 65536, $remaining ) ); if ( false === $chunk || '' === $chunk ) { break; } echo $chunk; $remaining -= strlen( $chunk ); }
		fclose( $stream ); exit;
	}
	public static function content( $content ) {
		if ( 'situation_room' !== get_post_type() ) { return $content; }
		$items = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_rv_room_media_post', 'meta_value' => get_the_ID(), 'posts_per_page' => -1 ) );
		foreach ( $items as $item ) {
			foreach ( (array) ( get_post_meta( $item->ID, '_rv_room_media_old_urls', true ) ?: array() ) as $variant => $old ) { if ( $old ) { $content = str_replace( $old, esc_url( self::url( $item->ID, $variant ) ), $content ); } }
		}
		// URLs stored by the editor contain the uploader's nonce; renew them for each reader.
		$html = new WP_HTML_Tag_Processor( $content );
		while ( $html->next_tag() ) {
			$html->remove_attribute( 'srcset' );
			foreach ( array( 'src', 'href', 'poster', 'data-src' ) as $attribute ) {
				$url = $html->get_attribute( $attribute ); if ( ! is_string( $url ) || '' === $url ) { continue; }
				$args = array(); parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
				if ( 'revayat_room_media' === ( $args['action'] ?? '' ) ) { $private = self::url( absint( $args['media_id'] ?? 0 ), sanitize_key( $args['variant'] ?? 'full' ) ); }
				else {
					if ( 'href' === $attribute && ! preg_match( '/\.(?:pdf|zip|docx?|xlsx?|pptx?|mp4|webm|mp3|ogg|wav|jpe?g|png|webp|gif|avif)(?:[?#]|$)/i', $url ) ) { continue; }
					$private = self::reference_url( 0, $url );
				}
				if ( $private ) { $html->set_attribute( $attribute, html_entity_decode( $private, ENT_QUOTES, 'UTF-8' ) ); } else { $html->remove_attribute( $attribute ); }
			}
		}
		$content = $html->get_updated_html();
		$content = preg_replace_callback( '/url\(([^)]+)\)/i', static function ( $m ) { return 'url("' . esc_url( self::reference_url( 0, html_entity_decode( trim( $m[1], "\"' " ), ENT_QUOTES, 'UTF-8' ) ) ) . '")'; }, $content );
		return $content;
	}
	public static function references( $post ) {
		$post = get_post( $post ); if ( ! $post ) { return array(); }
		$ids = array_keys( get_attached_media( '', $post ) ); $ids[] = get_post_thumbnail_id( $post );
		preg_match_all( '/(?:wp-image-|"id"\s*:\s*)(\d+)/', $post->post_content, $matches ); $ids = array_merge( $ids, $matches[1] );
		preg_match_all( '/\[gallery[^\]]*\bids=["\x27]([^"\x27]+)/i', $post->post_content, $galleries );
		foreach ( $galleries[1] as $list ) { $ids = array_merge( $ids, explode( ',', $list ) ); }
		foreach ( array( '_revayat_sr_attachments', '_revayat_sr_sensor_gallery' ) as $key ) {
			foreach ( (array) get_post_meta( $post->ID, $key, true ) as $item ) { if ( is_array( $item ) ) { $ids[] = absint( $item['id'] ?? 0 ); } }
		}
		preg_match_all( '/(?:src|href|poster)=["\x27]([^"\x27]+)["\x27]/i', $post->post_content, $matches );
		foreach ( $matches[1] as $url ) { $ids[] = attachment_url_to_postid( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) ); }
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}
	private static function remove_public_copies( $id ) {
		$pending = (array) ( get_post_meta( $id, '_rv_room_media_pending_delete', true ) ?: array() );
		$uploads = wp_get_upload_dir(); $public = realpath( $uploads['basedir'] ); $root = self::directory();
		foreach ( $pending as $source => $target ) {
			if ( ! file_exists( $source ) ) { unset( $pending[ $source ] ); continue; }
			$s = realpath( $source ); $t = realpath( $target );
			if ( ! $public || is_wp_error( $root ) || ! $s || ! $t || 0 !== strpos( $s, trailingslashit( $public ) ) || 0 !== strpos( $t, trailingslashit( $root ) ) || hash_file( 'sha256', $s ) !== hash_file( 'sha256', $t ) || ! @unlink( $s ) ) { continue; }
			unset( $pending[ $source ] );
		}
		update_post_meta( $id, '_rv_room_media_pending_delete', $pending );
		return $pending ? new WP_Error( 'delete', 'نسخه خصوصی آماده است، اما حذف نسخه عمومی کامل نشد؛ انتقال را دوباره اجرا کنید یا مدیر نسخه‌های عمومی را حذف کند.' ) : true;
	}
	public static function meta_box() { add_meta_box( 'rv-room-media-security', 'امنیت رسانه‌ها', array( __CLASS__, 'box' ), 'situation_room', 'side' ); }
	public static function box( $post ) {
		$r = self::directory(); echo '<p>' . esc_html( is_wp_error( $r ) ? 'بارگذاری رسانه بسته است؛ مدیر باید فضای خصوصی را تنظیم کند.' : 'بارگذاری از داخل این بولتن در فضای خصوصی انجام می‌شود.' ) . '</p>';
		$ids = self::references( $post );
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			if ( self::owner( $id ) && ! get_post_meta( $id, '_rv_room_media_pending_delete', true ) ) { echo '<p>خصوصی: ' . esc_html( get_the_title( $id ) ) . '</p>'; continue; }
			echo '<p>فایل عمومی نیازمند انتقال: ' . esc_html( get_the_title( $id ) ) . '</p>';
			if ( current_user_can( 'manage_options' ) ) { $url = wp_nonce_url( add_query_arg( array( 'action' => 'revayat_room_media_migrate', 'media_id' => $id, 'post_id' => $post->ID ), admin_url( 'admin-post.php' ) ), 'rv_room_migrate_' . $id ); echo '<p><a class="button" href="' . esc_url( $url ) . '">انتقال به فضای خصوصی و حذف نسخه عمومی</a></p>'; }
		}
		echo '<p>رسانه را از داخل ویرایش همین بولتن بارگذاری کنید؛ انتخاب فایل عمومی از کتابخانه آن را خصوصی نمی‌کند. رسانه خارجی یا فایل مشترک با صفحات عمومی را با رسانه خصوصی اختصاصی جایگزین کنید. انتقال فایل مشترک خودکار انجام نمی‌شود.</p>';
	}
	public static function migrate_action() {
		$id = absint( $_GET['media_id'] ?? 0 ); $post = absint( $_GET['post_id'] ?? 0 );
		check_admin_referer( 'rv_room_migrate_' . $id );
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post ) || 'situation_room' !== get_post_type( $post ) ) { wp_die( 'دسترسی مجاز نیست.', '', array( 'response' => 403 ) ); }
		$result = self::migrate( $id, $post );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'back_link' => true ) ); }
		wp_safe_redirect( get_edit_post_link( $post, 'raw' ) ); exit;
	}
	public static function migrate( $id, $post ) {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post ) || 'situation_room' !== get_post_type( $post ) ) { return new WP_Error( 'forbidden', 'مجوز انتقال رسانه را ندارید.' ); }
		if ( ! in_array( (int) $id, self::references( $post ), true ) && self::owner( $id ) !== (int) $post ) { return new WP_Error( 'unrelated_media', 'رسانه به این بولتن مرتبط نیست.' ); }
		return Revayat_Companion_Workflow_Lock::run( 'room-media:' . $id, static function () use ( $id, $post ) {
			global $wpdb;
			if ( self::owner( $id ) ) { return self::owner( $id ) === (int) $post ? self::remove_public_copies( $id ) : new WP_Error( 'different_owner', 'این فایل متعلق به بولتن دیگری است؛ نسخه اختصاصی بارگذاری کنید.' ); }
			$a = get_post( $id ); $root = self::directory(); $file = get_attached_file( $id ); $real = $file ? realpath( $file ) : false; $uploads = wp_get_upload_dir();
			if ( ! $a || 'attachment' !== $a->post_type || is_wp_error( $root ) || ! $real || 0 !== strpos( $real, trailingslashit( realpath( $uploads['basedir'] ) ) ) ) { return new WP_Error( 'invalid_file', 'فایل محلی معتبر یا فضای خصوصی آماده نیست.' ); }
			$shared = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_thumbnail_id' AND meta_value=%s AND post_id<>%d LIMIT 1", (string) $id, $post ) );
			$name = wp_basename( $real );
			$meta_shared = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE post_id NOT IN (%d,%d) AND (meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s) LIMIT 1", $post, $id, '%' . $wpdb->esc_like( $name ) . '%', '%i:' . $id . ';%', '%:"' . $id . '";%', '%"id":' . $id . '%'  ) );
			$mentioned = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE ID<>%d AND post_type NOT IN ('attachment','revision') AND (post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s) LIMIT 1", $post, '%' . $wpdb->esc_like( $name ) . '%', '%wp-image-' . $id . '%', '%"id":' . $id . '%'  ) );
			if ( $shared || $meta_shared || $mentioned || ( $a->post_parent && (int) $a->post_parent !== (int) $post ) ) { return new WP_Error( 'shared_media', 'این رسانه در محتوای دیگری استفاده شده است؛ برای بولتن نسخه اختصاصی تازه بارگذاری کنید.' ); }
			$files = self::variants( $id );
			$destination = $root . '/media-' . $id;
			if ( ! is_dir( $destination ) && ! @mkdir( $destination, 0700 ) ) { return new WP_Error( 'storage', 'ساخت پوشه خصوصی فایل انجام نشد.' ); }
			if ( 0 !== strpos( wp_normalize_path( realpath( $destination ) ), trailingslashit( $root ) ) ) { return new WP_Error( 'unsafe_destination', 'پوشه مقصد خارج از فضای خصوصی است.' ); }
			@chmod( $destination, 0700 );
			$urls = array(); $sources = array();
			foreach ( $files as $key => $name ) {
				$source = dirname( $real ) . '/' . $name; if ( ! is_file( $source ) ) { continue; }
				$source = realpath( $source ); if ( 0 !== strpos( $source, trailingslashit( realpath( $uploads['basedir'] ) ) ) ) { return new WP_Error( 'unsafe_variant', 'مسیر یکی از نسخه‌های فایل معتبر نیست؛ انتقال متوقف شد.' ); }
				$target = $destination . '/' . $name;
				if ( ! copy( $source, $target ) || hash_file( 'sha256', $source ) !== hash_file( 'sha256', $target ) ) { return new WP_Error( 'copy', 'کپی امن فایل کامل نشد؛ نسخه عمومی حذف نشده است.' ); }
				chmod( $target, 0600 ); $sources[ $source ] = $target;
				$urls[ $key ] = str_replace( wp_normalize_path( $uploads['basedir'] ), $uploads['baseurl'], wp_normalize_path( $source ) );
			}
			update_post_meta( $id, '_wp_attached_file', $destination . '/' . wp_basename( $real ) );
			update_post_meta( $id, '_rv_room_media_post', $post ); update_post_meta( $id, '_rv_room_media_old_urls', $urls );
			update_post_meta( $id, '_rv_room_media_pending_delete', $sources );
			return self::remove_public_copies( $id );
		} );
	}
}
