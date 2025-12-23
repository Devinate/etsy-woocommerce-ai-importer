<?php
/**
 * Product Importer Class
 *
 * Handles creation and updating of WooCommerce products from Etsy data.
 *
 * @package EtsyWooCommerceAIImporter
 * @since 1.1.0
 */

namespace EtsyWooCommerceAIImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ProductImporter class
 *
 * Manages product import and update operations.
 */
class ProductImporter {
	/**
	 * ImageSync instance.
	 *
	 * @var ImageSync
	 */
	private $image_sync;

	/**
	 * CategoryManager instance.
	 *
	 * @var CategoryManager
	 */
	private $category_manager;

	/**
	 * Constructor.
	 *
	 * @param ImageSync       $image_sync       ImageSync instance.
	 * @param CategoryManager $category_manager CategoryManager instance.
	 */
	public function __construct( ImageSync $image_sync, CategoryManager $category_manager ) {
		$this->image_sync       = $image_sync;
		$this->category_manager = $category_manager;
	}

	/**
	 * Create or update a product from Etsy data.
	 *
	 * @param array $data    Product data from CSV.
	 * @param array $options Import options.
	 * @return array {
	 *     Import result.
	 *
	 *     @type int|null $product_id       Product ID (null if not created).
	 *     @type int      $images_queued    Number of images queued.
	 *     @type bool     $updated_existing Whether an existing product was updated.
	 *     @type bool     $images_synced    Whether images were synchronized.
	 * }
	 */
	public function import_product( $data, $options ) {
		$result = array(
			'product_id'       => null,
			'images_queued'    => 0,
			'updated_existing' => false,
			'images_synced'    => false,
		);

		$existing_product_id = $this->find_existing_product( $data );

		// If product exists, update it instead of creating new.
		if ( $existing_product_id ) {
			$update_result = $this->update_existing_product( $existing_product_id, $data, $options );
			$result['updated_existing'] = true;
			$result['images_queued']    = $update_result['images_queued'];
			$result['images_synced']    = $update_result['images_synced'];
			return $result;
		}

		// Create new product.
		$product_id = $this->create_new_product( $data, $options );

		if ( $product_id ) {
			$result['product_id']    = $product_id;
			$result['images_queued'] = isset( $data['images'] ) && $options['import_images'] ? count( $data['images'] ) : 0;
		}

		return $result;
	}

	/**
	 * Find existing product by SKU or title.
	 *
	 * @param array $data Product data.
	 * @return int|null Product ID if found, null otherwise.
	 */
	private function find_existing_product( $data ) {
		// Check for existing product by SKU first.
		if ( ! empty( $data['sku'] ) ) {
			$existing_id = wc_get_product_id_by_sku( $data['sku'] );
			if ( $existing_id ) {
				return $existing_id;
			}
		}

		// Check for existing product by title if not found by SKU.
		if ( ! empty( $data['title'] ) ) {
			$existing_products = get_posts(
				array(
					'post_type'   => 'product',
					'title'       => $data['title'],
					'post_status' => 'any',
					'numberposts' => 1,
					'fields'      => 'ids',
				)
			);

			if ( ! empty( $existing_products ) ) {
				return $existing_products[0];
			}
		}

		return null;
	}

	/**
	 * Create a new WooCommerce product.
	 *
	 * @param array $data    Product data.
	 * @param array $options Import options.
	 * @return int|null Product ID if created, null on failure.
	 */
	private function create_new_product( $data, $options ) {
		$product = new \WC_Product_Simple();

		$product->set_name( $data['title'] );
		$product->set_description( $data['description'] );
		$product->set_short_description( wp_trim_words( $data['description'], 30 ) );

		if ( $data['price'] > 0 ) {
			$product->set_regular_price( $data['price'] );
		}

		if ( ! empty( $data['sku'] ) ) {
			$product->set_sku( $data['sku'] );
		}

		// Set status.
		$product->set_status( $options['draft_status'] ? 'draft' : 'publish' );

		// Mark as digital/downloadable.
		if ( $options['mark_digital'] ) {
			$product->set_virtual( true );
			$product->set_downloadable( true );
		}

		// Digital products should not track stock (unlimited inventory).
		if ( $options['mark_digital'] ) {
			$product->set_manage_stock( false );
			$product->set_stock_status( 'instock' );
		} elseif ( ! empty( $data['quantity'] ) && is_numeric( $data['quantity'] ) ) {
			// Only track stock for physical products.
			$product->set_manage_stock( true );
			$product->set_stock_quantity( intval( $data['quantity'] ) );
		}

		// Save to get ID.
		$product_id = $product->save();

		if ( ! $product_id ) {
			return null;
		}

		// Set categories.
		$this->assign_product_categories( $product_id, $data, $options );

		// Flag if AI was used for categorization.
		if ( ! empty( $data['ai_categorized'] ) ) {
			update_post_meta( $product_id, '_etsy_ai_categorized', '1' );
			update_post_meta( $product_id, '_etsy_ai_categorized_date', current_time( 'mysql' ) );
		}

		// Save Etsy listing URL.
		if ( ! empty( $data['etsy_url'] ) ) {
			update_post_meta( $product_id, '_etsy_listing_url', esc_url_raw( $data['etsy_url'] ) );
		}

		// Set tags.
		if ( ! empty( $data['tags'] ) ) {
			wp_set_object_terms( $product_id, $data['tags'], 'product_tag' );
		}

		// Queue images for background import.
		if ( $options['import_images'] && ! empty( $data['images'] ) ) {
			$this->image_sync->sync_images( $product_id, $data['images'], true );
		}

		return $product_id;
	}

	/**
	 * Update an existing product with new Etsy data.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $data       Product data.
	 * @param array $options    Import options.
	 * @return array {
	 *     Update result.
	 *
	 *     @type int  $images_queued Number of images queued.
	 *     @type bool $images_synced Whether images were synchronized.
	 * }
	 */
	private function update_existing_product( $product_id, $data, $options ) {
		$result = array(
			'images_queued' => 0,
			'images_synced' => false,
		);

		// Update categories.
		$this->assign_product_categories( $product_id, $data, $options );

		// Flag if AI was used for categorization.
		if ( ! empty( $data['ai_categorized'] ) ) {
			update_post_meta( $product_id, '_etsy_ai_categorized', '1' );
			update_post_meta( $product_id, '_etsy_ai_categorized_date', current_time( 'mysql' ) );
		}

		// Update Etsy listing URL.
		if ( ! empty( $data['etsy_url'] ) ) {
			update_post_meta( $product_id, '_etsy_listing_url', esc_url_raw( $data['etsy_url'] ) );
		}

		// Sync images if option is enabled.
		if ( $options['import_images'] && ! empty( $data['images'] ) ) {
			$sync_result = $this->image_sync->sync_images( $product_id, $data['images'], true );

			if ( $sync_result['updated'] ) {
				$result['images_queued'] = $sync_result['queued'];
				$result['images_synced'] = true;
			}
		}

		return $result;
	}

	/**
	 * Assign categories to a product.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $data       Product data.
	 * @param array $options    Import options.
	 */
	private function assign_product_categories( $product_id, $data, $options ) {
		$category_ids = array();

		if ( $options['import_categories'] && ! empty( $data['taxonomy_path'] ) ) {
			$category_ids = $this->category_manager->process_taxonomy_path(
				$data['taxonomy_path'],
				$options['create_categories']
			);
		}

		// Fallback to default category if no categories were assigned.
		if ( empty( $category_ids ) && $options['default_category'] > 0 ) {
			$category_ids = array( $options['default_category'] );
		}

		if ( ! empty( $category_ids ) ) {
			wp_set_object_terms( $product_id, $category_ids, 'product_cat' );
		}
	}
}
