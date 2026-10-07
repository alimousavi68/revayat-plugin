<?php
/** Native media picker and searchable published-content chooser. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Revayat_Companion_Dossier_Links_Admin {
    public static function register_metabox() {
        add_meta_box( 'revayat-dossier-links', 'اسناد و مطالب مرتبط پرونده', array( __CLASS__, 'render' ), 'special_dossier', 'normal', 'default' );
    }
    public static function enqueue( $hook ) {
        $screen = get_current_screen();
        if ( ! $screen || 'special_dossier' !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) { return; }
        wp_enqueue_media();
        $path = REVAYAT_COMPANION_PATH . 'assets/dossier-links.js';
        wp_enqueue_script( 'revayat-dossier-links', REVAYAT_COMPANION_URL . 'assets/dossier-links.js', array( 'jquery', 'media-views' ), filemtime( $path ), true );
        wp_enqueue_style( 'revayat-dossier-links-admin', REVAYAT_COMPANION_URL . 'assets/dossier-links.css', array(), filemtime( REVAYAT_COMPANION_PATH . 'assets/dossier-links.css' ) );
    }
    public static function render( $post ) {
        wp_nonce_field( 'revayat_save_dossier_links', 'revayat_dossier_links_nonce' );
        $data = Revayat_Companion_Dossier_Links::get( $post->ID, true );
        ?>
        <div class="rv-dossier-editor" data-post-id="<?php echo esc_attr( $post->ID ); ?>" data-search-nonce="<?php echo esc_attr( wp_create_nonce( 'revayat_search_dossier_content' ) ); ?>">
            <p>فقط موارد انتخاب‌شده این بخش شمرده می‌شوند؛ لینک و تصویر داخل متن خودکار شمرده نمی‌شود. هر بخش حداکثر ۱۰۰ مورد دارد.</p>
            <h3>اسناد عمومی</h3>
            <p>PDF، تصویر مدرک، فایل متنی، جدول یا فایل Word را از رسانه‌ها انتخاب کنید. ویدئو و صوت را به‌صورت مطلب چندرسانه‌ای در بخش بعد وصل کنید.</p>
            <button type="button" class="button rv-add-documents">انتخاب یا بارگذاری سند</button>
            <ul class="rv-selected-documents"><?php self::selected_list( $data['documents'], 'revayat_dossier_documents' ); ?></ul>
            <input type="hidden" name="revayat_dossier_documents_present" value="1">
            <h3>مطالب مرتبط</h3>
            <p>نوشته‌ها، یادداشت‌های تحلیلگران و مطالب چندرسانه‌ای منتشرشده را انتخاب کنید.</p>
            <label for="rv-dossier-search">جستجوی عنوان یا عبارت</label>
            <div class="rv-dossier-search"><input type="search" id="rv-dossier-search" maxlength="100"><button type="button" class="button rv-search-content">جستجو</button></div>
            <p class="rv-search-message" role="status" aria-live="polite"></p>
            <ul class="rv-search-results"></ul>
            <ul class="rv-selected-content"><?php self::selected_list( $data['content'], 'revayat_dossier_content' ); ?></ul>
            <input type="hidden" name="revayat_dossier_content_present" value="1">
            <p>بعد از انتخاب یا حذف موارد، «به‌روزرسانی» یا «انتشار» پرونده را بزنید. حذف اتصال، خود فایل یا مطلب را حذف نمی‌کند.</p>
        </div>
        <?php
    }
    private static function selected_list( array $items, string $name ) {
        foreach ( $items as $item ) {
            ?><li data-id="<?php echo esc_attr( $item['id'] ); ?>"><span><?php echo esc_html( $item['title'] ); ?></span> <button type="button" class="button-link-delete rv-remove-link" aria-label="<?php echo esc_attr( 'حذف اتصال ' . $item['title'] ); ?>">حذف اتصال</button><input type="hidden" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $item['id'] ); ?>"></li><?php
        }
    }
    public static function save( $post_id, $post ) {
        if ( ! $post instanceof WP_Post || 'special_dossier' !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) { return; }
        $nonce = $_POST['revayat_dossier_links_nonce'] ?? null;
        if ( ! is_string( $nonce ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), 'revayat_save_dossier_links' ) || ! current_user_can( 'edit_post', $post_id ) ) { return; }
        // No metabox payload (Quick Edit / REST / autosave) must never clear relations.
        foreach ( array( 'documents', 'content' ) as $kind ) {
            if ( ! isset( $_POST[ 'revayat_dossier_' . $kind . '_present' ] ) || '1' !== $_POST[ 'revayat_dossier_' . $kind . '_present' ] ) { return; }
            if ( isset( $_POST[ 'revayat_dossier_' . $kind ] ) && ! is_array( $_POST[ 'revayat_dossier_' . $kind ] ) ) { return; }
        }
        Revayat_Companion_Dossier_Links::save( $post_id, wp_unslash( $_POST['revayat_dossier_documents'] ?? array() ), wp_unslash( $_POST['revayat_dossier_content'] ?? array() ) );
    }
    public static function search() {
        check_ajax_referer( 'revayat_search_dossier_content', 'nonce' );
        $id = isset( $_POST['post_id'] ) && is_scalar( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( 'special_dossier' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) { wp_send_json_error( array( 'message' => 'اجازه ویرایش این پرونده را ندارید.' ), 403 ); }
        $query = $_POST['query'] ?? '';
        if ( ! is_string( $query ) || strlen( $query ) > 400 ) { wp_send_json_error( array( 'message' => 'عبارت جستجو نامعتبر است.' ), 400 ); }
        $query = sanitize_text_field( wp_unslash( $query ) );
        $posts = get_posts( array( 'post_type' => array( 'post', 'analyst_post', 'multimedia' ), 'post_status' => 'publish', 'posts_per_page' => 20, 's' => $query, 'orderby' => 'date', 'order' => 'DESC' ) );
        $items = array();
        $labels = array( 'post' => 'نوشته', 'analyst_post' => 'تحلیل', 'multimedia' => 'چندرسانه‌ای' );
        foreach ( $posts as $post ) {
            if ( Revayat_Companion_Dossier_Links::is_content( $post ) ) { $items[] = array( 'id' => $post->ID, 'title' => get_the_title( $post ), 'label' => $labels[ $post->post_type ] ); }
        }
        wp_send_json_success( $items );
    }
    public static function validate_documents() {
        check_ajax_referer( 'revayat_search_dossier_content', 'nonce' );
        $id = isset( $_POST['post_id'] ) && is_scalar( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( 'special_dossier' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) { wp_send_json_error( array( 'message' => 'اجازه ویرایش این پرونده را ندارید.' ), 403 ); }
        $requested = Revayat_Companion_Dossier_Links::ids( $_POST['ids'] ?? array() );
        $valid = Revayat_Companion_Dossier_Links::valid_ids( $requested, 'documents', $id );
        $items = array();
        foreach ( $valid as $document_id ) { $items[] = array( 'id' => $document_id, 'title' => get_the_title( $document_id ) ); }
        wp_send_json_success( array( 'items' => $items, 'rejected' => count( $requested ) - count( $valid ) ) );
    }
}
