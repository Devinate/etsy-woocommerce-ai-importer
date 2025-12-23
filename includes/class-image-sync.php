<?php
/**
 * Image Synchronization Class
 *
 * Handles comparison and synchronization of product images from Etsy.
 *
 * @package EtsyWooCommerceAIImporter
 * @since 1.1.0
 */

namespace EtsyWooCommerceAIImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ImageSync class
 *
 * Manages product image synchronization between Etsy and WooCommerce.
 */
class ImageSync {
	/**
	 * Compare current product images with new image URLs from Etsy.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $new_image_urls New image URLs from Etsy CSV.
	 * @return array {
	 *     Comparison results.
	 *
	 *     @type bool  $needs_update Whether images need updating.
	 *     @type array $current_urls Current image URLs on the product.
	 *     @type array $new_urls     New image URLs from Etsy.
	 *     @type array $added        URLs that need to be added.
	 *     @type array $removed      URLs that need to be removed.
	 *     @type array $unchanged    URLs that are the same.
	 * }
	 */
	public function compare_images( $product_id, $new_image_urls ) {
		$current_urls = $this->get_current_image_urls( $product_id );

		// Normalize URLs for comparison (remove query strings, protocols, etc).
		$current_normalized = $this->normalize_urls( $current_urls );
		$new_normalized     = $this->normalize_urls( $new_image_urls );

		$added     = array_diff( $new_normalized, $current_normalized );
		$removed   = array_diff( $current_normalized, $new_normalized );
		$unchanged = array_intersect( $current_normalized, $new_normalized );

		return array(
			'needs_update' => ! empty( $added ) || ! empty( $removed ),
			'current_urls' => $current_urls,
			'new_urls'     => $new_image_urls,
			'added'        => array_values( $added ),
			'removed'      => array_values( $removed ),
			'unchanged'    => array_values( $unchanged ),
		);
	}

	/**
	 * Get current image URLs for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return array Array of image URLs (featured image + gallery images).
	 */
	private function get_current_image_urls( $product_id ) {
		$urls = array();

		// Get featured image.
		$thumbnail_id = get_post_thumbnail_id( $product_id );
		if ( $thumbnail_id ) {
			$url = wp_get_attachment_url( $thumbnail_id );
			if ( $url ) {
				$urls[] = $url;
			}
		}

		// Get gallery images.
		$product = wc_get_product( $product_id );
		if ( $product ) {
			$gallery_ids = $product->get_gallery_image_ids();
			foreach ( $gallery_ids as $image_id ) {
				$url = wp_get_attachment_url( $image_id );
				if ( $url ) {
					$urls[] = $url;
				}
			}
		}

		return $urls;
	}

	/**
	 * Normalize URLs for comparison.
	 *
	 * Removes protocol, query strings, and trailing slashes to ensure consistent comparison.
	 *
	 * @param array $urls Array of URLs.
	 * @return array Normalized URLs.
	 */
	private function normalize_urls( $urls ) {
		return array_map(
			function ( $url ) {
				// Parse URL and extract path.
				$parsed = wp_parse_url( $url );
				$path   = $parsed['path'] ?? '';

				// Get the filename (everything after the last /).
				$filename = basename( $path );

				// Remove dimensions from WordPress image URLs (e.g., -150x150).
				$filename = preg_replace( '/-\d+x\d+(\.[a-z]+)$/i', '$1', $filename );

				// Store just the filename for comparison.
				return strtolower( trim( $filename ) );
			},
			$urls
		);
	}

	/**
	 * Sync images for an existing product.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $new_image_urls New image URLs from Etsy.
	 * @param bool  $queue_background Whether to queue images for background processing.
	 * @return array {
	 *     Sync results.
	 *
	 *     @type bool $updated Whether images were updated.
	 *     @type int  $queued  Number of images queued for import.
	 * }
	 */
	public function sync_images( $product_id, $new_image_urls, $queue_background = true ) {
		$comparison = $this->compare_images( $product_id, $new_image_urls );

		$result = array(
			'updated' => false,
			'queued'  => 0,
		);

		// If no changes needed, return early.
		if ( ! $comparison['needs_update'] ) {
			return $result;
		}

		// Store the expected image URLs in product meta.
		update_post_meta( $product_id, '_etsy_image_urls', $new_image_urls );
		update_post_meta( $product_id, '_etsy_image_sync_date', current_time( 'mysql' ) );

		if ( $queue_background ) {
			// Clear existing images.
			$this->clear_product_images( $product_id );

			// Queue new images for background import.
			$this->queue_image_imports( $product_id, $new_image_urls );

			$result['updated'] = true;
			$result['queued']  = count( $new_image_urls );
		}

		return $result;
	}

	/**
	 * Clear all images from a product (featured + gallery).
	 *
	 * @param int $product_id Product ID.
	 */
	private function clear_product_images( $product_id ) {
		// Remove featured image.
		delete_post_thumbnail( $product_id );

		// Clear gallery.
		delete_post_meta( $product_id, '_product_image_gallery' );
	}

	/**
	 * Queue images for background import via Action Scheduler.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $image_urls Array of image URLs.
	 */
	private function queue_image_imports( $product_id, $image_urls ) {
		foreach ( $image_urls as $index => $url ) {
			// Schedule each image import with a slight delay to avoid overwhelming the server.
			$delay = $index * 5; // 5 seconds between each image.

			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action(
					time() + $delay,
					'etsy_import_product_image',
					array(
						'product_id'  => $product_id,
						'image_url'   => $url,
						'is_featured' => ( 0 === $index ),
					),
					'etsy-csv-importer'
				);
			} else {
				// Fallback: use wp_schedule_single_event if Action Scheduler not available.
				wp_schedule_single_event(
					time() + $delay,
					'etsy_import_product_image',
					array( $product_id, $url, ( 0 === $index ) )
				);
			}
		}
	}

	/**
	 * Process a single image import (called by Action Scheduler).
	 *
	 * @param int    $product_id  Product ID.
	 * @param string $image_url   Image URL.
	 * @param bool   $is_featured Whether this is the featured image.
	 */
	public function process_single_image( $product_id, $image_url, $is_featured = false ) {
		// Verify product still exists.
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "Etsy Importer: Product {$product_id} no longer exists, skipping image import" );
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		try {
			$attachment_id = $this->sideload_image( $image_url, $product_id );

			if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
				if ( $is_featured ) {
					// Set as featured image.
					set_post_thumbnail( $product_id, $attachment_id );
				} else {
					// Add to gallery.
					$gallery     = get_post_meta( $product_id, '_product_image_gallery', true );
					$gallery_ids = $gallery ? explode( ',', $gallery ) : array();
					$gallery_ids[] = $attachment_id;
					update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
				}

				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( "Etsy Importer: Successfully imported image for product {$product_id}" );
			}
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "Etsy Importer: Failed to import image {$image_url} for product {$product_id}: " . $e->getMessage() );
		}
	}

	/**
	 * Sideload an image from URL.
	 *
	 * @param string $url     Image URL.
	 * @param int    $post_id Post ID to attach the image to.
	 * @return int|\WP_Error Attachment ID or WP_Error on failure.
	 */
	private function sideload_image( $url, $post_id ) {
		// Get the file extension.
		$parsed_url = wp_parse_url( $url );
		$path       = $parsed_url['path'] ?? '';
		$ext        = pathinfo( $path, PATHINFO_EXTENSION );

		if ( empty( $ext ) || ! in_array( strtolower( $ext ), array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) ) {
			$ext = 'jpg'; // Default extension.
		}

		// Download with a reasonable timeout (60 seconds per image).
		$tmp = download_url( $url, 60 );

		if ( is_wp_error( $tmp ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "Etsy Importer: Failed to download {$url}: " . $tmp->get_error_message() );
			return $tmp;
		}

		$file_array = array(
			'name'     => sanitize_file_name( 'etsy-import-' . uniqid() . '.' . $ext ),
			'tmp_name' => $tmp,
		);

		// Sideload the image.
		$attachment_id = media_handle_sideload( $file_array, $post_id );

		// Clean up temp file.
		if ( file_exists( $tmp ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $tmp );
		}

		return $attachment_id;
	}

	/**
	 * Get image sync status for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return array {
	 *     Sync status information.
	 *
	 *     @type array|null  $stored_urls    Stored Etsy image URLs.
	 *     @type string|null $last_sync_date Last sync date.
	 *     @type array       $current_urls   Current image URLs on product.
	 * }
	 */
	public function get_sync_status( $product_id ) {
		return array(
			'stored_urls'    => get_post_meta( $product_id, '_etsy_image_urls', true ),
			'last_sync_date' => get_post_meta( $product_id, '_etsy_image_sync_date', true ),
			'current_urls'   => $this->get_current_image_urls( $product_id ),
		);
	}
}
