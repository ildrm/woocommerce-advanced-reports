<?php
namespace WCAR\Repository;

use WCAR\Query\ReportFilter;

final class ProductRepository {
    public function iterate( ReportFilter $filter, array $extra = array() ): \Generator {
        $settings = wp_parse_args( (array) get_option( 'wcar_settings', array() ), \WCAR\Installer::default_settings() );
        $batch = min( 500, max( 25, absint( $settings['batch_size'] ?? 200 ) ) );
        $types = array_values( array_unique( array_merge( array_keys( wc_get_product_types() ), array( 'variation' ) ) ) );
        $include = array();
        foreach ( $filter->product_ids as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) { continue; }
            $include[] = (int) $product->get_id();
            if ( $product->is_type( 'variable' ) ) { $include = array_merge( $include, array_map( 'intval', $product->get_children() ) ); }
        }
        if ( $filter->product_ids && ! $include ) { return; }
        $page = 1;
        $category_cache = array();
        do {
            $args = wp_parse_args( $extra, array(
                'limit'   => $batch,
                'page'    => $page,
                'paginate'=> true,
                'status'  => array( 'publish', 'private', 'draft' ),
                'return'  => 'objects',
                'orderby' => 'ID',
                'order'   => 'ASC',
                'type'    => $types,
            ) );
            if ( $include ) { $args['include'] = array_values( array_unique( $include ) ); }
            $args['page'] = $page;
            $result = wc_get_products( $args );
            $products = is_object( $result ) && isset( $result->products ) ? $result->products : (array) $result;
            foreach ( $products as $product ) {
                if ( $filter->category_ids ) {
                    $parent_id = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
                    if ( ! isset( $category_cache[ $parent_id ] ) ) {
                        $terms = wp_get_post_terms( $parent_id, 'product_cat', array( 'fields' => 'ids' ) );
                        $category_cache[ $parent_id ] = is_wp_error( $terms ) ? array() : array_map( 'intval', $terms );
                    }
                    if ( ! array_intersect( $filter->category_ids_with_children(), $category_cache[ $parent_id ] ) ) { continue; }
                }
                yield $product;
            }
            $max = is_object( $result ) && isset( $result->max_num_pages ) ? (int) $result->max_num_pages : 1;
            ++$page;
        } while ( $page <= $max );
    }

    public function categories_for_product( int $product_id ): string {
        $terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );
        return is_wp_error( $terms ) ? '' : implode( ', ', $terms );
    }
}
