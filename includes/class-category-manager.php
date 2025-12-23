<?php
/**
 * Category Manager Class
 *
 * Handles product categorization including keyword matching and taxonomy processing.
 *
 * @package EtsyWooCommerceAIImporter
 * @since 1.1.0
 */

namespace EtsyWooCommerceAIImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CategoryManager class
 *
 * Manages product category assignment and creation.
 */
class CategoryManager {
	/**
	 * Match category based on keywords in product tags and title.
	 *
	 * @param array  $tags  Product tags.
	 * @param string $title Product title.
	 * @return array {
	 *     Matching result.
	 *
	 *     @type string $category Matched category name.
	 *     @type array  $log      Log messages from matching process.
	 * }
	 */
	public function match_category_keywords( $tags, $title ) {
		$log = array();

		// Get all existing product categories.
		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		if ( empty( $categories ) || is_wp_error( $categories ) ) {
			return array(
				'category' => '',
				'log'      => $log,
			);
		}

		// Build a list of category names and keywords for matching.
		$category_keywords = array();
		foreach ( $categories as $cat ) {
			$name_lower = strtolower( $cat->name );
			// Skip uncategorized.
			if ( 'uncategorized' === $cat->slug ) {
				continue;
			}

			$category_keywords[ $cat->name ] = array(
				'name'  => $name_lower,
				'words' => preg_split( '/[\s&,]+/', $name_lower ),
			);
		}

		// Extract individual words from tags.
		$tag_words = array();
		foreach ( $tags as $tag ) {
			$parts     = preg_split( '/[_\-\s]+/', strtolower( $tag ) );
			$tag_words = array_merge( $tag_words, $parts );
		}
		$tag_words = array_unique( $tag_words );

		$search_text = strtolower( $title . ' ' . implode( ' ', $tags ) . ' ' . implode( ' ', $tag_words ) );

		$best_match = '';
		$best_score = 0;

		foreach ( $category_keywords as $cat_name => $cat_data ) {
			$score = 0;

			// Check if category name appears in search text.
			if ( strpos( $search_text, $cat_data['name'] ) !== false ) {
				$score += 10;
			}

			// Check individual words from category name.
			foreach ( $cat_data['words'] as $word ) {
				if ( strlen( $word ) >= 3 && strpos( $search_text, $word ) !== false ) {
					$score += 2;
				}
			}

			// Check if any tag matches category name closely.
			foreach ( $tags as $tag ) {
				$tag_lower = strtolower( $tag );
				if ( $tag_lower === $cat_data['name'] ) {
					$score += 15;
				} elseif ( strpos( $tag_lower, $cat_data['name'] ) !== false || strpos( $cat_data['name'], $tag_lower ) !== false ) {
					$score += 5;
				}
			}

			if ( $score > $best_score ) {
				$best_score = $score;
				$best_match = $cat_name;
			}
		}

		if ( $best_score >= 2 ) {
			$log[] = array(
				'type'    => 'info',
				'message' => 'Keyword matching found: ' . $best_match . ' (score: ' . $best_score . ')',
			);
			return array(
				'category' => $best_match,
				'log'      => $log,
			);
		}

		$log[] = array(
			'type'    => 'warning',
			'message' => 'No category match found',
		);
		return array(
			'category' => '',
			'log'      => $log,
		);
	}

	/**
	 * Process an Etsy taxonomy path into WooCommerce category IDs.
	 *
	 * Handles hierarchical paths like "Home & Living > Furniture > Chairs".
	 *
	 * @param string $taxonomy_path  Etsy taxonomy path (e.g., "Parent > Child > Grandchild").
	 * @param bool   $create_missing Whether to create missing categories.
	 * @return array Array of category term IDs.
	 */
	public function process_taxonomy_path( $taxonomy_path, $create_missing = true ) {
		if ( empty( $taxonomy_path ) ) {
			return array();
		}

		$path = trim( $taxonomy_path );

		// Check if it contains hierarchy separators.
		if ( strpos( $path, ' > ' ) !== false || strpos( $path, '>' ) !== false || strpos( $path, ' / ' ) !== false ) {
			// Etsy uses " > " as separator for hierarchy.
			// Also handle variations like " / " or just ">".
			$path       = str_replace( ' / ', ' > ', $path );
			$path       = str_replace( '>', ' > ', $path );
			$categories = array_map( 'trim', explode( ' > ', $path ) );
		} else {
			// Simple single category name.
			$categories = array( $path );
		}

		$categories = array_filter( $categories ); // Remove empty values.

		if ( empty( $categories ) ) {
			return array();
		}

		$category_ids = array();
		$parent_id    = 0;

		foreach ( $categories as $category_name ) {
			// Clean up category name.
			$category_name = trim( $category_name );
			if ( empty( $category_name ) ) {
				continue;
			}

			// Look for existing category with this name and parent.
			$existing_term = get_term_by( 'name', $category_name, 'product_cat' );

			// If we need to match the hierarchy, check parent too.
			if ( $existing_term && $parent_id > 0 ) {
				// Verify the parent matches.
				if ( $existing_term->parent !== $parent_id ) {
					// Try to find one with the correct parent.
					$terms = get_terms(
						array(
							'taxonomy'   => 'product_cat',
							'name'       => $category_name,
							'parent'     => $parent_id,
							'hide_empty' => false,
						)
					);
					$existing_term = ! empty( $terms ) ? $terms[0] : null;
				}
			}

			if ( $existing_term ) {
				$parent_id      = $existing_term->term_id;
				$category_ids[] = $existing_term->term_id;
			} elseif ( $create_missing ) {
				// Create the category.
				$result = wp_insert_term(
					$category_name,
					'product_cat',
					array(
						'parent' => $parent_id,
						'slug'   => sanitize_title( $category_name ),
					)
				);

				if ( ! is_wp_error( $result ) ) {
					$parent_id      = $result['term_id'];
					$category_ids[] = $result['term_id'];
				} else {
					// If term exists error (race condition), try to get it.
					if ( 'term_exists' === $result->get_error_code() ) {
						$term_id        = $result->get_error_data();
						$parent_id      = $term_id;
						$category_ids[] = $term_id;
					} else {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log( "Etsy Importer: Failed to create category '{$category_name}': " . $result->get_error_message() );
					}
				}
			}
		}

		// Return only the deepest category (last one) to avoid duplication.
		// Or return all if you want products in all levels.
		return ! empty( $category_ids ) ? array( end( $category_ids ) ) : array();
	}
}
