<?php
/** Simple editorial status control, using the existing dossier meta. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Revayat_Companion_Dossier_Status {
	public static function register_metabox() {
		add_meta_box( 'revayat-dossier-status', 'وضعیت پرونده', array( __CLASS__, 'render_metabox' ), 'special_dossier', 'side', 'default' );
	}

	public static function render_metabox( $post ) {
		$is_live = (bool) get_post_meta( $post->ID, '_revayat_dossier_is_live', true );
		wp_nonce_field( 'revayat_save_dossier_status', 'revayat_dossier_status_nonce' );
		?>
		<p><label for="revayat-dossier-status-field"><?php esc_html_e( 'وضعیت پیگیری این موضوع', 'revayat-companion' ); ?></label></p>
		<select id="revayat-dossier-status-field" name="revayat_dossier_status">
			<option value="ongoing" <?php selected( $is_live, true ); ?>><?php esc_html_e( 'در حال پیگیری', 'revayat-companion' ); ?></option>
			<option value="completed" <?php selected( $is_live, false ); ?>><?php esc_html_e( 'تکمیل‌شده', 'revayat-companion' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'این گزینه فقط وضعیت پرونده را نشان می‌دهد و وضعیت انتشار آن را تغییر نمی‌دهد.', 'revayat-companion' ); ?></p>
		<?php
	}

	public static function save_status( $post_id, $post ) {
		if ( ! $post instanceof WP_Post || 'special_dossier' !== $post->post_type
			|| wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id )
			|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		$nonce  = $_POST['revayat_dossier_status_nonce'] ?? null;
		$status = $_POST['revayat_dossier_status'] ?? null;
		if ( ! is_string( $nonce ) || ! is_string( $status )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), 'revayat_save_dossier_status' )
			|| ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$status = wp_unslash( $status );
		if ( ! in_array( $status, array( 'ongoing', 'completed' ), true ) ) {
			return;
		}
		update_post_meta( $post_id, '_revayat_dossier_is_live', 'ongoing' === $status );
	}
}
