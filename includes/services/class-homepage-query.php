<?php
/** Registered homepage sources and taxonomy filter composition. No theme dependency. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Revayat_Companion_Homepage_Query {
    public static function default_type( string $section ): string {
        return array( 'hero' => 'post', 'daily-narrative' => 'post', 'news-monitoring' => 'post', 'situation-room' => 'situation_room', 'special-dossiers' => 'special_dossier', 'media-observatory' => 'media_observatory', 'analysts-network' => 'analyst_post', 'multimedia' => 'multimedia' )[ $section ] ?? 'post';
    }

    public static function catalog( string $section ): array {
        $types = array();
        foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $object ) {
            if ( 'attachment' === $name || ( 'situation_room' === $name && 'situation-room' !== $section ) ) { continue; }
            $types[ $name ] = $object->labels->singular_name;
        }
        $taxonomies = array();
        foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $name => $object ) {
            $attached = array_values( array_intersect( $object->object_type, array_keys( $types ) ) );
            if ( ! $attached ) { continue; }
            $taxonomies[ $name ] = array( 'label' => $object->labels->singular_name, 'types' => $attached, 'hierarchical' => $object->hierarchical );
        }
        return array( 'types' => $types, 'taxonomies' => $taxonomies );
    }

    public static function csv( $value ): array {
        return is_scalar( $value ) ? array_values( array_unique( array_filter( array_map( 'sanitize_key', explode( ',', (string) $value ) ) ) ) ) : array();
    }

    /** Legacy selections are interpreted only until the new setting is saved. */
    public static function filters( string $section ): array {
        $stored = get_theme_mod( 'revayat_' . $section . '_tax_filters', null );
        if ( null !== $stored ) {
            $decoded = is_string( $stored ) ? json_decode( $stored, true ) : null;
            return is_string( $stored ) && '[' === substr( ltrim( $stored ), 0, 1 ) && is_array( $decoded ) && array_values( $decoded ) === $decoded && count( $decoded ) <= 12 ? $decoded : array( array( 'taxonomy' => '__invalid' ) );
        }
        $legacy = get_theme_mod( 'revayat_' . $section . '_terms', '' );
        $terms = array_values( array_filter( array_map( 'absint', explode( ',', (string) $legacy ) ) ) );
        $taxonomy = array( 'hero' => 'category', 'daily-narrative' => 'category', 'news-monitoring' => 'news_source', 'situation-room' => 'security_level', 'special-dossiers' => 'dossier_topic', 'media-observatory' => 'observatory_badge', 'analysts-network' => 'analyst_field', 'multimedia' => 'media_format' )[ $section ] ?? '';
        return $terms ? array( array( 'taxonomy' => $taxonomy, 'include' => $terms, 'exclude' => array(), 'operator' => 'IN', 'children' => true ) ) : array();
    }

    public static function apply( string $section, array $args ): array {
        $catalog = self::catalog( $section );
        $default = self::default_type( $section );
        $read = static fn( $key, $fallback ) => get_theme_mod( 'revayat_' . $section . '_' . $key, $fallback );
        $types = 'all' === $read( 'source_mode', 'selected' ) ? array_keys( $catalog['types'] ) : self::csv( $read( 'post_types', $default ) );
        $types = array_values( array_diff( array_intersect( $types, array_keys( $catalog['types'] ) ), self::csv( $read( 'excluded_post_types', '' ) ) ) );
        $args['post_type'] = $types ?: array( $default );
        if ( ! $types ) { $args['post__in'] = array( 0 ); return $args; }

        if ( ! in_array( 'post', $types, true ) || ! $read( 'use_default_filter', true ) ) { unset( $args['tax_query'] ); }
        $positive = array();
        $negative = array();
        foreach ( self::filters( $section ) as $filter ) {
            if ( ! is_array( $filter ) || ! is_string( $filter['taxonomy'] ?? null ) ) { $args['post__in'] = array( 0 ); return $args; }
            foreach ( array( 'include', 'exclude' ) as $key ) {
                $ids = $filter[ $key ] ?? array();
                if ( ! is_array( $ids ) || count( $ids ) > 500 || array_filter( $ids, static fn( $id ) => ! is_scalar( $id ) || ! ctype_digit( (string) $id ) || (int) $id < 1 ) ) { $args['post__in'] = array( 0 ); return $args; }
            }
            $taxonomy = $filter['taxonomy'];
            if ( ! isset( $catalog['taxonomies'][ $taxonomy ] ) || ! array_intersect( $types, $catalog['taxonomies'][ $taxonomy ]['types'] ) ) {
                $args['post__in'] = array( 0 ); return $args;
            }
            $include = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $filter['include'] ?? array() ) ) ) ) );
            $exclude = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $filter['exclude'] ?? array() ) ) ) ) );
            $ids = array_unique( array_merge( $include, $exclude ) );
            if ( $ids ) {
                $found = get_terms( array( 'taxonomy' => $taxonomy, 'include' => $ids, 'hide_empty' => false, 'fields' => 'ids' ) );
                if ( is_wp_error( $found ) || count( $found ) !== count( $ids ) ) {
                    $args['post__in'] = array( 0 ); return $args;
                }
            }
            $base = array( 'taxonomy' => $taxonomy, 'field' => 'term_id', 'include_children' => ! empty( $filter['children'] ) );
            if ( $include ) { $positive[] = $base + array( 'terms' => $include, 'operator' => 'AND' === ( $filter['operator'] ?? '' ) ? 'AND' : 'IN' ); }
            if ( $exclude ) { $negative[] = $base + array( 'terms' => $exclude, 'operator' => 'NOT IN' ); }
        }
        $query = array( 'relation' => 'AND' );
        if ( ! empty( $args['tax_query'] ) ) { $query[] = $args['tax_query']; }
        if ( $positive ) { $query[] = array_merge( array( 'relation' => 'OR' === $read( 'tax_relation', 'AND' ) ? 'OR' : 'AND' ), $positive ); }
        foreach ( $negative as $clause ) { $query[] = $clause; }
        if ( count( $query ) > 1 ) { $args['tax_query'] = $query; }
        return $args;
    }
}
