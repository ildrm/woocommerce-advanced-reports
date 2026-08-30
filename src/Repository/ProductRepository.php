<?php
namespace WCAR\Repository;

use WCAR\Query\ReportFilter;

final class ProductRepository {
    public function iterate( ReportFilter $filter, array $extra = array() ): \Generator {
        $page = 1;
        do {
            $args = wp_parse_args( $extra, array(
                'limit'   => 200,
                'page'    => $page,
                'paginate'=> true,
                'status'  => array( 'publish', 'private', 'draft' ),
                'return'  => 'objects',
                'orderby' => 'ID',
                'order'   => 'ASC',
            ) );
            if ( $filter->product_ids ) {
                $args['include'] = $filter->product_ids;
            }
            if ( $filter->category_ids ) {
                $args['category'] = array_map(
                    static function ( int $term_id ): string {
                        $term = get_term( $term_id, 'product_cat' );
                        return $term && ! is_wp_error( $term ) ? $term->slug : '__missing__';
                    },
                    $filter->category_ids
                );
            }
            $args['page'] = $page;
            $result = wc_get_products( $args );
            $products = is_object( $result ) && isset( $result->products ) ? $result->products : (array) $result;
            foreach ( $products as $product ) {
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
