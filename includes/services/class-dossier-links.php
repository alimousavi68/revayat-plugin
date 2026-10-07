<?php
/** Explicit, public dossier relations; no content copies or inferred body links. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Revayat_Companion_Dossier_Links {
    const DOCUMENTS = '_revayat_dossier_documents';
    const CONTENT = '_revayat_dossier_content';
    const LIMIT = 100;

    public static function register_meta() {
        foreach ( array( self::DOCUMENTS, self::CONTENT ) as $key ) {
            register_post_meta( 'special_dossier', $key, array( 'type' => 'array', 'single' => true, 'show_in_rest' => false, 'sanitize_callback' => array( __CLASS__, 'ids' ), 'auth_callback' => static function ( $allowed, $key, $id ) { return current_user_can( 'edit_post', $id ); } ) );
        }
    }

    public static function ids( $values ): array {
        if ( ! is_array( $values ) ) { return array(); }
        $ids = array();
        foreach ( array_slice( $values, 0, self::LIMIT * 2 ) as $value ) {
            if ( ( is_int( $value ) && $value > 0 ) || ( is_string( $value ) && ctype_digit( $value ) && (int) $value > 0 ) ) {
                $ids[] = (int) $value;
            }
        }
        return array_slice( array_values( array_unique( $ids ) ), 0, self::LIMIT );
    }

    public static function is_document( $post, int $editing_dossier = 0 ): bool {
        if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type || 'inherit' !== $post->post_status ) { return false; }
        $mime = get_post_mime_type( $post );
        $mimes = array( 'application/pdf', 'text/plain', 'text/csv', 'application/msword', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
        if ( ! in_array( $mime, $mimes, true ) && ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) ) { return false; }
        if ( $post->post_parent ) {
            $parent = get_post( $post->post_parent );
            $own_draft = $parent && $parent->ID === $editing_dossier && 'special_dossier' === $parent->post_type && current_user_can( 'edit_post', $editing_dossier ) && ! in_array( $parent->post_status, array( 'trash', 'auto-draft' ), true );
            if ( ! $own_draft && ( ! $parent || 'publish' !== $parent->post_status || ! empty( $parent->post_password ) || ! in_array( $parent->post_type, array( 'post', 'analyst_post', 'multimedia', 'special_dossier' ), true ) ) ) { return false; }
        }
        return (bool) wp_get_attachment_url( $post->ID ) && is_file( (string) get_attached_file( $post->ID ) );
    }

    public static function is_content( $post ): bool {
        return $post instanceof WP_Post && 'publish' === $post->post_status && empty( $post->post_password ) && in_array( $post->post_type, array( 'post', 'analyst_post', 'multimedia' ), true );
    }

    public static function valid_ids( $values, string $kind, int $editing_dossier = 0 ): array {
        $valid = array();
        foreach ( self::ids( $values ) as $id ) {
            $post = get_post( $id );
            if ( 'documents' === $kind ? self::is_document( $post, $editing_dossier ) : self::is_content( $post ) ) { $valid[] = $id; }
        }
        return $valid;
    }

    public static function get( int $dossier_id, bool $editing = false ): array {
        $result = array( 'documents' => array(), 'content' => array() );
        $result['counts'] = array( 'documents' => 0, 'content' => 0 );
        $dossier = get_post( $dossier_id );
        if ( ! $dossier || 'special_dossier' !== $dossier->post_type || ( 'publish' !== $dossier->post_status && ! current_user_can( 'edit_post', $dossier_id ) ) || ( post_password_required( $dossier_id ) && ! ( $editing && current_user_can( 'edit_post', $dossier_id ) ) ) ) { return $result; }
        foreach ( array( 'documents' => self::DOCUMENTS, 'content' => self::CONTENT ) as $kind => $key ) {
            $ids = self::ids( get_post_meta( $dossier_id, $key, true ) );
            if ( $ids ) {
                // Prime the cache in one query; retain editorial selection order below.
                get_posts( array( 'post_type' => array( 'attachment', 'post', 'analyst_post', 'multimedia' ), 'post_status' => array( 'publish', 'inherit' ), 'post__in' => $ids, 'posts_per_page' => count( $ids ), 'update_post_meta_cache' => true ) );
            }
            foreach ( self::valid_ids( $ids, $kind, $editing && current_user_can( 'edit_post', $dossier_id ) ? $dossier_id : 0 ) as $id ) {
                $post = get_post( $id );
                $result[ $kind ][] = array( 'id' => $id, 'title' => get_the_title( $id ), 'url' => 'documents' === $kind ? wp_get_attachment_url( $id ) : get_permalink( $id ), 'type' => $post->post_type );
            }
        }
        $result['counts'] = array( 'documents' => count( $result['documents'] ), 'content' => count( $result['content'] ) );
        return $result;
    }

    /** Public parent dossiers from explicit selections, never browser/referrer input. */
    public static function parents( int $content_id ): array {
        if (!self::is_content(get_post($content_id))) { return []; }
        $candidates = get_posts([
            'post_type' => 'special_dossier', 'post_status' => 'publish', 'posts_per_page' => -1,
            'orderby' => 'date', 'order' => 'DESC',
            'meta_query' => [
                'relation' => 'OR',
                ['key' => self::CONTENT, 'value' => 'i:' . $content_id . ';', 'compare' => 'LIKE'],
                ['key' => self::CONTENT, 'value' => '"' . $content_id . '"', 'compare' => 'LIKE'],
            ],
        ]);
        $parents = [];
        foreach ($candidates as $dossier) {
            if (!empty($dossier->post_password) || !in_array($content_id, self::ids(get_post_meta($dossier->ID, self::CONTENT, true)), true)) { continue; }
            $parents[] = ['id' => $dossier->ID, 'title' => get_the_title($dossier->ID), 'url' => get_permalink($dossier->ID)];
        }
        return $parents;
    }

    public static function save( int $dossier_id, $documents, $content ) {
        update_post_meta( $dossier_id, self::DOCUMENTS, self::valid_ids( $documents, 'documents', $dossier_id ) );
        update_post_meta( $dossier_id, self::CONTENT, self::valid_ids( $content, 'content' ) );
    }
}
