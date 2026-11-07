<?php
/**
 * Product from WooCommerce data (only real data: price, stock, ratings and reviews that exist).
 * The data source is injectable so it can be tested without WooCommerce.
 *
 * Provider result shape (all keys optional except name):
 *   name, description, sku, gtin, brand, image (URL), url, currency, price, min_price, max_price,
 *   availability ('instock'|'outofstock'|'onbackorder'), sale_ends (Y-m-d), rating_average (float),
 *   rating_count (int), review_count (int), reviews: [ [ author, rating, body, date ] ].
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Schema\Extractors;

use SEOConsole\Modules\Schema\Html;

defined( 'ABSPATH' ) || exit;

final class ProductExtractor extends AbstractExtractor {

	/** Max reviews copied into the node. */
	const MAX_REVIEWS = 5;

	/** @var callable|null */
	private $provider;

	/**
	 * @param callable|null $provider fn( \WP_Post ): ?array. Defaults to WooCommerce.
	 */
	public function __construct( ?callable $provider = null ) {
		$this->provider = $provider;
	}

	/** {@inheritDoc} */
	public function type(): string {
		return 'Product';
	}

	/** {@inheritDoc} */
	public function extract( \WP_Post $post ): array {
		$data = $this->provider ? call_user_func( $this->provider, $post ) : self::woocommerce_data( $post );
		if ( ! is_array( $data ) || empty( $data['name'] ) ) {
			return array();
		}
		$currency = isset( $data['currency'] ) ? strtoupper( (string) $data['currency'] ) : '';
		$node     = array(
			'@type'       => 'Product',
			'name'        => Html::to_text( (string) $data['name'] ),
			'description' => isset( $data['description'] ) ? Html::truncate( Html::to_text( (string) $data['description'] ), 1000 ) : '',
			'sku'         => isset( $data['sku'] ) ? (string) $data['sku'] : '',
			'gtin'        => isset( $data['gtin'] ) ? (string) $data['gtin'] : '',
			'image'       => isset( $data['image'] ) ? (string) $data['image'] : '',
			'url'         => isset( $data['url'] ) ? (string) $data['url'] : '',
		);
		if ( ! empty( $data['brand'] ) ) {
			$node['brand'] = array(
				'@type' => 'Brand',
				'name'  => (string) $data['brand'],
			);
		}

		$missing      = array();
		$availability = self::availability( isset( $data['availability'] ) ? (string) $data['availability'] : '' );
		if ( isset( $data['min_price'], $data['max_price'] ) && is_numeric( $data['min_price'] ) && is_numeric( $data['max_price'] ) && (float) $data['min_price'] !== (float) $data['max_price'] ) {
			$node['offers'] = array(
				'@type'         => 'AggregateOffer',
				'lowPrice'      => self::price( $data['min_price'] ),
				'highPrice'     => self::price( $data['max_price'] ),
				'priceCurrency' => $currency,
				'availability'  => $availability,
			);
		} elseif ( isset( $data['price'] ) && is_numeric( $data['price'] ) ) {
			$node['offers'] = array(
				'@type'           => 'Offer',
				'price'           => self::price( $data['price'] ),
				'priceCurrency'   => $currency,
				'availability'    => $availability,
				'url'             => $node['url'],
				'priceValidUntil' => isset( $data['sale_ends'] ) ? (string) $data['sale_ends'] : '',
			);
		} else {
			$missing[] = 'offers';
		}

		$rating_count = isset( $data['rating_count'] ) ? (int) $data['rating_count'] : 0;
		$review_count = isset( $data['review_count'] ) ? (int) $data['review_count'] : 0;
		$average      = isset( $data['rating_average'] ) ? (float) $data['rating_average'] : 0.0;
		if ( $average > 0 && ( $rating_count > 0 || $review_count > 0 ) ) {
			$node['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => round( $average, 2 ),
				'ratingCount' => $rating_count > 0 ? $rating_count : null,
				'reviewCount' => $review_count > 0 ? $review_count : null,
				'bestRating'  => 5,
				'worstRating' => 1,
			);
		}
		$reviews = array();
		foreach ( array_slice( isset( $data['reviews'] ) ? (array) $data['reviews'] : array(), 0, self::MAX_REVIEWS ) as $review ) {
			if ( empty( $review['author'] ) || empty( $review['rating'] ) ) {
				continue;
			}
			$reviews[] = array(
				'@type'         => 'Review',
				'author'        => array(
					'@type' => 'Person',
					'name'  => (string) $review['author'],
				),
				'reviewRating'  => array(
					'@type'       => 'Rating',
					'ratingValue' => (int) $review['rating'],
					'bestRating'  => 5,
					'worstRating' => 1,
				),
				'reviewBody'    => isset( $review['body'] ) ? Html::truncate( Html::to_text( (string) $review['body'] ), 1000 ) : '',
				'datePublished' => isset( $review['date'] ) ? (string) $review['date'] : '',
			);
		}
		if ( array() !== $reviews ) {
			$node['review'] = $reviews;
		}

		return array( $this->candidate( $node, __( 'Built from the WooCommerce product data (price, stock and reviews).', 'seo-console' ), $missing ) );
	}

	/**
	 * Reads a WooCommerce product. Returns null when WooCommerce is not active or the post is not a product.
	 *
	 * @param \WP_Post $post Post.
	 * @return array|null
	 */
	public static function woocommerce_data( \WP_Post $post ): ?array {
		if ( 'product' !== $post->post_type || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return null;
		}
		$data = array(
			'name'           => $product->get_name(),
			'description'    => $product->get_short_description() ? $product->get_short_description() : $product->get_description(),
			'sku'            => $product->get_sku(),
			'image'          => $product->get_image_id() ? (string) wp_get_attachment_url( $product->get_image_id() ) : '',
			'url'            => (string) get_permalink( $post ),
			'currency'       => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'price'          => $product->get_price(),
			'availability'   => $product->get_stock_status(),
			'rating_average' => (float) $product->get_average_rating(),
			'rating_count'   => (int) $product->get_rating_count(),
			'review_count'   => (int) $product->get_review_count(),
			'reviews'        => array(),
		);
		if ( method_exists( $product, 'get_global_unique_id' ) ) {
			$data['gtin'] = $product->get_global_unique_id();
		}
		if ( $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_price' ) ) {
			$data['min_price'] = $product->get_variation_price( 'min' );
			$data['max_price'] = $product->get_variation_price( 'max' );
		}
		$sale_to = $product->get_date_on_sale_to();
		if ( $sale_to ) {
			$data['sale_ends'] = $sale_to->date( 'Y-m-d' );
		}
		$comments = get_comments(
			array(
				'post_id' => $post->ID,
				'status'  => 'approve',
				'type'    => 'review',
				'number'  => self::MAX_REVIEWS,
			)
		);
		foreach ( $comments as $comment ) {
			$rating = (int) get_comment_meta( (int) $comment->comment_ID, 'rating', true );
			if ( $rating > 0 ) {
				$data['reviews'][] = array(
					'author' => $comment->comment_author,
					'rating' => $rating,
					'body'   => $comment->comment_content,
					'date'   => mysql2date( 'Y-m-d', $comment->comment_date_gmt ),
				);
			}
		}
		return $data;
	}

	/**
	 * Formats a price as a plain decimal string.
	 *
	 * @param mixed $value Price.
	 */
	private static function price( $value ): string {
		$number = (float) $value;
		return floor( $number ) === $number ? (string) (int) $number : rtrim( rtrim( number_format( $number, 4, '.', '' ), '0' ), '.' );
	}

	/**
	 * WooCommerce stock status → schema.org availability URL.
	 *
	 * @param string $status Stock status.
	 */
	private static function availability( string $status ): string {
		$map = array(
			'instock'     => 'https://schema.org/InStock',
			'outofstock'  => 'https://schema.org/OutOfStock',
			'onbackorder' => 'https://schema.org/BackOrder',
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : '';
	}
}
