# Etsy WooCommerce AI Importer - Class Documentation

## Overview

Version 1.1.0 introduces a clean OOP architecture with separate classes for different responsibilities.

## Architecture

```
etsy-woocommerce-ai-importer/
├── etsy-woocommerce-ai-importer.php  # Main plugin file
└── includes/
    ├── class-autoloader.php          # PSR-4 autoloader
    ├── class-image-sync.php          # Image synchronization
    ├── class-product-importer.php    # Product import/update
    └── class-category-manager.php    # Category management
```

## Classes

### Autoloader

**File:** `class-autoloader.php`
**Namespace:** `EtsyWooCommerceAIImporter`

Automatically loads plugin classes as needed using PSR-4 naming conventions.

```php
// Automatically loaded via:
EtsyWooCommerceAIImporter\Autoloader::register();
```

### ImageSync

**File:** `class-image-sync.php`
**Namespace:** `EtsyWooCommerceAIImporter\ImageSync`

Handles all image-related operations including:
- Comparing current product images with Etsy URLs
- Detecting changes in image URLs
- Synchronizing images for existing products
- Queuing background image imports via Action Scheduler

**Key Methods:**

```php
// Compare images between product and Etsy
$comparison = $image_sync->compare_images( $product_id, $new_image_urls );
// Returns: array with 'needs_update', 'added', 'removed', 'unchanged'

// Sync images for existing product
$result = $image_sync->sync_images( $product_id, $new_image_urls, $queue_background = true );
// Returns: array with 'updated' (bool) and 'queued' (int)

// Get sync status
$status = $image_sync->get_sync_status( $product_id );
// Returns: array with 'stored_urls', 'last_sync_date', 'current_urls'

// Process single image (called by Action Scheduler)
$image_sync->process_single_image( $product_id, $image_url, $is_featured );
```

**Image Comparison Logic:**

The `compare_images()` method:
1. Gets current product images (featured + gallery)
2. Normalizes URLs by extracting filenames and removing WordPress size suffixes
3. Compares normalized URLs to detect additions/removals
4. Returns detailed comparison results

**Meta Fields:**

- `_etsy_image_urls` - Stores the expected Etsy image URLs (array)
- `_etsy_image_sync_date` - Last sync timestamp

### ProductImporter

**File:** `class-product-importer.php`
**Namespace:** `EtsyWooCommerceAIImporter\ProductImporter`

Manages product creation and updates with dependency injection for ImageSync and CategoryManager.

**Dependencies:**
- `ImageSync` - For image synchronization
- `CategoryManager` - For category assignment

**Key Methods:**

```php
// Create or update a product
$result = $product_importer->import_product( $data, $options );
// Returns: array with 'product_id', 'images_queued', 'updated_existing', 'images_synced'
```

**Import Flow:**

1. **Find existing product** by SKU or title
2. **If exists:**
   - Update categories
   - Sync images (NEW in 1.1.0)
   - Update Etsy URL
   - Return with `updated_existing = true`
3. **If new:**
   - Create WooCommerce product
   - Assign categories
   - Queue images for import
   - Return with `product_id`

**Existing Product Updates:**

When re-importing an existing product:
- Categories are updated based on new taxonomy path or AI categorization
- Images are synchronized if they've changed on Etsy
- Product metadata (Etsy URL, AI categorization flags) are updated
- Images are queued for background processing if differences detected

### CategoryManager

**File:** `class-category-manager.php`
**Namespace:** `EtsyWooCommerceAIImporter\CategoryManager`

Handles product categorization using keyword matching and Etsy taxonomy path processing.

**Key Methods:**

```php
// Match category by keywords
$result = $category_manager->match_category_keywords( $tags, $title );
// Returns: array with 'category' (name) and 'log' (messages)

// Process Etsy taxonomy path (e.g., "Home & Living > Furniture > Chairs")
$category_ids = $category_manager->process_taxonomy_path( $taxonomy_path, $create_missing = true );
// Returns: array of WooCommerce category term IDs
```

**Keyword Matching Algorithm:**

1. Extracts all existing WooCommerce categories
2. Builds keyword index from category names
3. Scores matches based on:
   - Exact category name in title/tags (+10 points)
   - Individual words from category (+2 points each)
   - Tag matches category name (+15 points)
4. Returns best match if score >= 2

**Taxonomy Path Processing:**

1. Parses hierarchical path (supports " > ", ">", " / " separators)
2. Creates categories in hierarchy if missing
3. Returns the deepest category ID to avoid duplication

## Usage Examples

### Basic Product Import with Image Sync

```php
// Initialize classes
$image_sync = new EtsyWooCommerceAIImporter\ImageSync();
$category_manager = new EtsyWooCommerceAIImporter\CategoryManager();
$product_importer = new EtsyWooCommerceAIImporter\ProductImporter(
    $image_sync,
    $category_manager
);

// Import product data
$data = array(
    'title' => 'Vintage Wedding Invitation',
    'sku' => 'ETSY-12345',
    'price' => 5.99,
    'description' => 'Beautiful wedding invitation template',
    'tags' => array( 'wedding', 'invitation', 'vintage' ),
    'images' => array(
        'https://i.etsystatic.com/..../il_fullxfull.1234.jpg',
        'https://i.etsystatic.com/..../il_fullxfull.5678.jpg',
    ),
    'taxonomy_path' => 'Paper & Party Supplies > Invitations',
    'etsy_url' => 'https://www.etsy.com/listing/12345',
);

$options = array(
    'import_images' => true,
    'import_categories' => true,
    'create_categories' => true,
    'mark_digital' => true,
    'draft_status' => false,
    'default_category' => 0,
);

$result = $product_importer->import_product( $data, $options );

if ( $result['product_id'] ) {
    echo "Created product ID: {$result['product_id']}\n";
    echo "Images queued: {$result['images_queued']}\n";
} elseif ( $result['updated_existing'] ) {
    echo "Updated existing product\n";
    if ( $result['images_synced'] ) {
        echo "Images synchronized: {$result['images_queued']} queued\n";
    }
}
```

### Manual Image Sync Check

```php
$image_sync = new EtsyWooCommerceAIImporter\ImageSync();

// Check if product needs image updates
$product_id = 123;
$new_etsy_images = array(
    'https://i.etsystatic.com/..../il_fullxfull.9999.jpg', // New image
);

$comparison = $image_sync->compare_images( $product_id, $new_etsy_images );

if ( $comparison['needs_update'] ) {
    echo "Images need updating!\n";
    echo "Added: " . count( $comparison['added'] ) . "\n";
    echo "Removed: " . count( $comparison['removed'] ) . "\n";

    // Trigger sync
    $sync_result = $image_sync->sync_images( $product_id, $new_etsy_images );
    echo "Queued {$sync_result['queued']} images for import\n";
}
```

### Category Matching

```php
$category_manager = new EtsyWooCommerceAIImporter\CategoryManager();

// Match using keywords
$tags = array( 'baby', 'shower', 'game', 'printable' );
$title = 'Baby Shower Bingo Game';

$result = $category_manager->match_category_keywords( $tags, $title );

echo "Matched category: {$result['category']}\n";
foreach ( $result['log'] as $log_entry ) {
    echo "{$log_entry['type']}: {$log_entry['message']}\n";
}

// Process Etsy taxonomy
$taxonomy_path = 'Home & Living > Kitchen & Dining > Drinkware';
$category_ids = $category_manager->process_taxonomy_path( $taxonomy_path, true );

echo "Category IDs: " . implode( ', ', $category_ids ) . "\n";
```

## Image Sync Feature Details

### How It Works

1. **On Product Import/Update:**
   - ProductImporter checks if product exists
   - If exists, ImageSync compares current images with new Etsy URLs
   - If differences found, clears old images and queues new ones

2. **Image Comparison:**
   - Normalizes URLs by extracting filenames
   - Removes WordPress image size suffixes (-150x150, etc.)
   - Compares normalized filenames for accurate matching

3. **Background Processing:**
   - Uses Action Scheduler for async image imports
   - 5-second delay between images to avoid server overload
   - Handles featured image vs gallery images

4. **Metadata Tracking:**
   - Stores expected image URLs in `_etsy_image_urls`
   - Tracks last sync date in `_etsy_image_sync_date`
   - Allows future sync status checks

### Benefits

- **Automatic Updates**: Re-importing a product automatically updates images if changed on Etsy
- **Bandwidth Efficient**: Only updates when changes detected
- **Non-Destructive**: Preserves existing images if Etsy URLs unchanged
- **Background Processing**: Doesn't slow down import process
- **Trackable**: Meta fields allow monitoring of sync status

## Migration from 1.0.0

The refactoring is **backwards compatible**. The main `Etsy_CSV_Importer` class still exists and works exactly as before, but now delegates to the new classes:

```php
// Old code (still works):
$importer = Etsy_CSV_Importer::get_instance();
// Import process uses new classes internally

// New code (recommended):
$image_sync = new EtsyWooCommerceAIImporter\ImageSync();
$category_manager = new EtsyWooCommerceAIImporter\CategoryManager();
$product_importer = new EtsyWooCommerceAIImporter\ProductImporter(
    $image_sync,
    $category_manager
);
```

### What Changed

1. ✅ Image sync added for existing products
2. ✅ Code organized into separate class files
3. ✅ PSR-4 autoloader for clean dependency management
4. ✅ Dependency injection for better testing
5. ✅ Plugin version bumped to 1.1.0

### What Stayed the Same

1. ✅ Admin UI unchanged
2. ✅ CSV import process unchanged
3. ✅ AI categorization unchanged
4. ✅ WPGraphQL integration unchanged
5. ✅ All existing options/settings preserved

## Testing

To verify the refactoring worked:

```bash
# Check PHP syntax
php -l wordpress/wp-content/plugins/etsy-woocommerce-ai-importer/etsy-woocommerce-ai-importer.php
php -l wordpress/wp-content/plugins/etsy-woocommerce-ai-importer/includes/class-*.php

# In WordPress admin:
1. Go to WooCommerce > Etsy Importer
2. Upload a CSV with products
3. Import products
4. Re-upload the same CSV but with different images
5. Import again - should see "images synced" messages
```

## Future Enhancements

Potential improvements for future versions:

- [ ] Image sync admin UI (manual sync button)
- [ ] Bulk image sync for all products
- [ ] Image comparison by file hash instead of filename
- [ ] Configurable sync behavior (always/never/ask)
- [ ] Sync logs/history in admin
- [ ] Image optimization during import
- [ ] Support for video/multiple file types

## Support

For issues or questions about the refactored codebase:
- Check syntax errors with `php -l`
- Enable WordPress debug mode: `define( 'WP_DEBUG', true );`
- Check error logs in `wp-content/debug.log`
- Review Action Scheduler logs for background image processing
