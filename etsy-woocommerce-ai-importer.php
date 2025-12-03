<?php
/**
 * Plugin Name: Etsy WooCommerce AI Importer
 * Plugin URI: https://github.com/devinate/etsy-woocommerce-ai-importer
 * Description: Import digital products from Etsy CSV exports into WooCommerce with AI-powered category matching using Hugging Face.
 * Version: 1.0.0
 * Author: Devinate
 * Author URI: https://devinate.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: etsy-woocommerce-ai-importer
 */

if (!defined('ABSPATH')) {
    exit;
}

class Etsy_CSV_Importer {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_etsy_import_products', [$this, 'ajax_import_products']);
        add_action('wp_ajax_etsy_import_stream', [$this, 'ajax_import_stream']);
        add_action('wp_ajax_etsy_save_settings', [$this, 'ajax_save_settings']);

        // Background image import action (using Action Scheduler)
        add_action('etsy_import_product_image', [$this, 'process_single_image'], 10, 3);
    }

    /**
     * Save AI settings via AJAX
     */
    public function ajax_save_settings() {
        check_ajax_referer('etsy_import_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $api_token = isset($_POST['hf_api_token']) ? sanitize_text_field($_POST['hf_api_token']) : '';
        $use_ai = isset($_POST['use_ai_categorization']) && $_POST['use_ai_categorization'] === '1';

        update_option('etsy_importer_hf_api_token', $api_token);
        update_option('etsy_importer_use_ai', $use_ai);

        wp_send_json_success(['message' => 'Settings saved successfully']);
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Etsy CSV Importer',
            'Etsy Importer',
            'manage_woocommerce',
            'etsy-csv-importer',
            [$this, 'render_admin_page']
        );
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'woocommerce_page_etsy-csv-importer') {
            return;
        }

        wp_enqueue_style(
            'etsy-importer-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.css',
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'etsy-importer-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('etsy-importer-admin', 'etsyImporter', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('etsy_import_nonce'),
        ]);
    }

    public function render_admin_page() {
        // Count pending image imports
        $pending_images = 0;
        if (function_exists('as_get_scheduled_actions')) {
            $pending = as_get_scheduled_actions([
                'hook' => 'etsy_import_product_image',
                'status' => ActionScheduler_Store::STATUS_PENDING,
                'per_page' => -1,
            ]);
            $pending_images = count($pending);
        }
        ?>
        <div class="wrap etsy-importer-wrap">
            <h1>Etsy CSV Importer</h1>

            <?php if ($pending_images > 0): ?>
            <div class="notice notice-info">
                <p><strong>Background Processing:</strong> <?php echo $pending_images; ?> image(s) are being imported in the background. They will appear on products shortly.</p>
            </div>
            <?php endif; ?>

            <div class="etsy-importer-card">
                <h2>Import Products from Etsy</h2>
                <p>Upload your Etsy CSV export file to import products into WooCommerce.</p>

                <div class="etsy-importer-instructions">
                    <h3>How to export from Etsy:</h3>
                    <ol>
                        <li>Log into your Etsy account</li>
                        <li>Go to <strong>Shop Manager → Settings → Options</strong></li>
                        <li>Click the <strong>Download data</strong> tab</li>
                        <li>Click <strong>Download CSV</strong> to get your listings</li>
                    </ol>
                </div>

                <form id="etsy-import-form" enctype="multipart/form-data">
                    <div class="form-field">
                        <label for="etsy-csv-file">Select Etsy CSV File:</label>
                        <input type="file" id="etsy-csv-file" name="csv_file" accept=".csv" required>
                    </div>

                    <div class="form-field">
                        <label>
                            <input type="checkbox" name="import_images" value="1" checked>
                            Import product images from Etsy (processed in background)
                        </label>
                        <p class="description">Images are downloaded in the background so the import completes quickly.</p>
                    </div>

                    <div class="form-field">
                        <label>
                            <input type="checkbox" name="mark_digital" value="1" checked>
                            Mark all products as digital/downloadable
                        </label>
                    </div>

                    <div class="form-field">
                        <label>
                            <input type="checkbox" name="draft_status" value="1">
                            Import as drafts (recommended for review)
                        </label>
                    </div>

                    <div class="form-field">
                        <label>
                            <input type="checkbox" name="import_categories" value="1" checked>
                            Auto-assign to WooCommerce categories
                        </label>
                        <p class="description">Matches products to your existing categories based on product title and tags.</p>
                    </div>

                    <div class="form-field">
                        <label for="default-category">Default Category (fallback):</label>
                        <select id="default-category" name="default_category">
                            <option value="">— None —</option>
                            <?php
                            $categories = get_terms([
                                'taxonomy' => 'product_cat',
                                'hide_empty' => false,
                            ]);
                            foreach ($categories as $cat) {
                                echo '<option value="' . esc_attr($cat->term_id) . '">' . esc_html($cat->name) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <button type="submit" class="button button-primary button-large">
                        Start Import
                    </button>
                </form>

                <div id="import-progress" style="display: none;">
                    <h3>Import Progress</h3>
                    <div class="progress-bar-container">
                        <div class="progress-bar" style="width: 0%;"></div>
                    </div>
                    <p class="progress-text">Preparing import...</p>
                    <div id="import-log"></div>
                </div>

                <div id="import-results" style="display: none;">
                    <h3>Import Complete</h3>
                    <div class="results-summary"></div>
                </div>
            </div>

            <div class="etsy-importer-card">
                <h2>AI Category Matching (Optional - FREE)</h2>
                <p>Enable AI-powered category matching using Hugging Face's <strong>free API</strong> for more accurate categorization. The AI analyzes your product titles and tags to automatically select the best matching category from your existing WooCommerce categories. <strong>No credit card required!</strong></p>

                <div class="etsy-importer-instructions">
                    <h3>How to get a FREE Hugging Face API Token:</h3>
                    <ol>
                        <li>Go to <a href="https://huggingface.co/join" target="_blank"><strong>huggingface.co/join</strong></a> and create a <strong>free account</strong> (or sign in if you already have one)</li>
                        <li>Once logged in, go to <a href="https://huggingface.co/settings/tokens/new?tokenType=fineGrained" target="_blank"><strong>Settings &rarr; Access Tokens &rarr; Create new token</strong></a></li>
                        <li>Give it a name (e.g., "Etsy Importer")</li>
                        <li>For <strong>Token type</strong>, select <strong>"Fine-grained"</strong></li>
                        <li>Under <strong>Permissions</strong>, find and enable: <strong>"Make calls to Inference Providers"</strong> (under Inference)</li>
                        <li>You can leave all other permissions unchecked</li>
                        <li>Click <strong>"Create token"</strong> and copy the token (it starts with <code>hf_</code>)</li>
                        <li>Paste the token below and click "Save AI Settings"</li>
                    </ol>
                    <p style="margin-top: 12px; margin-bottom: 0;"><strong>Free tier includes:</strong> ~30,000 API requests per month - more than enough for most imports. No payment info needed!</p>
                </div>

                <form id="etsy-ai-settings-form">
                    <div class="form-field">
                        <label for="hf-api-token">Hugging Face API Token:</label>
                        <input type="password" id="hf-api-token" name="hf_api_token"
                               value="<?php echo esc_attr(get_option('etsy_importer_hf_api_token', '')); ?>"
                               style="width: 100%; max-width: 400px;"
                               placeholder="hf_xxxxxxxxxxxxxxxxxxxxxxxxxx">
                    </div>
                    <div class="form-field">
                        <label>
                            <input type="checkbox" name="use_ai_categorization" value="1"
                                   <?php checked(get_option('etsy_importer_use_ai', false)); ?>>
                            Use AI for category matching (when token is configured)
                        </label>
                        <p class="description">When enabled, the importer will use AI to suggest the best category based on product title and tags. You'll see the AI's confidence scores in the import log.</p>
                    </div>
                    <button type="submit" class="button">Save AI Settings</button>
                    <span id="ai-settings-status" style="margin-left: 10px;"></span>
                </form>
            </div>

            <div class="etsy-importer-card">
                <h2>Field Mapping</h2>
                <p>The importer automatically maps Etsy fields to WooCommerce:</p>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Etsy Field</th>
                            <th>WooCommerce Field</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>TITLE</td><td>Product Name</td></tr>
                        <tr><td>DESCRIPTION</td><td>Product Description</td></tr>
                        <tr><td>PRICE</td><td>Regular Price</td></tr>
                        <tr><td>TAGS</td><td>Product Tags</td></tr>
                        <tr><td>IMAGE1-10</td><td>Product Gallery Images</td></tr>
                        <tr><td>SECTION or TAGS</td><td>Product Categories (first tag used if no SECTION)</td></tr>
                        <tr><td>SKU</td><td>SKU</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    public function ajax_import_products() {
        check_ajax_referer('etsy_import_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        if (!isset($_FILES['csv_file'])) {
            wp_send_json_error(['message' => 'No file uploaded']);
        }

        $file = $_FILES['csv_file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => 'File upload error']);
        }

        // Save file to temp location for streaming import
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/etsy-imports';
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }

        $temp_file = $temp_dir . '/import-' . uniqid() . '.csv';
        move_uploaded_file($file['tmp_name'], $temp_file);

        $options = [
            'import_images' => isset($_POST['import_images']) && $_POST['import_images'] === '1',
            'mark_digital' => isset($_POST['mark_digital']) && $_POST['mark_digital'] === '1',
            'draft_status' => isset($_POST['draft_status']) && $_POST['draft_status'] === '1',
            'import_categories' => isset($_POST['import_categories']) && $_POST['import_categories'] === '1',
            'create_categories' => false, // Disabled - Etsy CSV doesn't contain category data
            'default_category' => isset($_POST['default_category']) ? intval($_POST['default_category']) : 0,
        ];

        // Store import session for streaming
        $import_id = uniqid('import_');
        set_transient('etsy_import_' . $import_id, [
            'file' => $temp_file,
            'options' => $options,
            'status' => 'pending',
        ], HOUR_IN_SECONDS);

        wp_send_json_success([
            'import_id' => $import_id,
            'stream_url' => admin_url('admin-ajax.php') . '?action=etsy_import_stream&import_id=' . $import_id . '&nonce=' . wp_create_nonce('etsy_import_nonce'),
        ]);
    }

    /**
     * Stream import progress using Server-Sent Events (SSE)
     */
    public function ajax_import_stream() {
        // Verify nonce via GET parameter
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'etsy_import_nonce')) {
            http_response_code(403);
            exit('Unauthorized');
        }

        if (!current_user_can('manage_woocommerce')) {
            http_response_code(403);
            exit('Permission denied');
        }

        $import_id = isset($_GET['import_id']) ? sanitize_text_field($_GET['import_id']) : '';
        if (empty($import_id)) {
            http_response_code(400);
            exit('Missing import ID');
        }

        $import_data = get_transient('etsy_import_' . $import_id);
        if (!$import_data) {
            http_response_code(404);
            exit('Import session not found');
        }

        // Set up SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Disable nginx buffering

        // Disable output buffering
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Increase time limit for large imports
        set_time_limit(0);

        $this->stream_import($import_data['file'], $import_data['options']);

        // Clean up
        if (file_exists($import_data['file'])) {
            unlink($import_data['file']);
        }
        delete_transient('etsy_import_' . $import_id);

        exit;
    }

    /**
     * Send an SSE event to the client
     */
    private function sse_send($event, $data) {
        echo "event: {$event}\n";
        echo "data: " . json_encode($data) . "\n\n";
        flush();
    }

    /**
     * Stream the CSV import with real-time progress
     */
    private function stream_import($file_path, $options) {
        $results = [
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
            'images_queued' => 0,
            'categories_created' => 0,
        ];

        // Track categories before import
        $categories_before = wp_count_terms(['taxonomy' => 'product_cat']);

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            $this->sse_send('error', ['message' => 'Could not open CSV file']);
            $this->sse_send('complete', $results);
            return;
        }

        // Read header row
        $headers = fgetcsv($handle);
        if (!$headers) {
            $this->sse_send('error', ['message' => 'Could not read CSV headers']);
            fclose($handle);
            $this->sse_send('complete', $results);
            return;
        }

        // Normalize headers
        $headers = array_map(function($h) {
            return strtoupper(trim($h));
        }, $headers);

        $header_map = array_flip($headers);

        $this->sse_send('log', ['type' => 'info', 'message' => 'CSV headers found: ' . implode(', ', $headers)]);

        if (!isset($header_map['TITLE'])) {
            $this->sse_send('error', ['message' => 'CSV must contain a TITLE column']);
            fclose($handle);
            $this->sse_send('complete', $results);
            return;
        }

        // Count total rows for progress
        $total_rows = 0;
        while (fgetcsv($handle) !== false) {
            $total_rows++;
        }
        rewind($handle);
        fgetcsv($handle); // Skip header again

        $this->sse_send('log', ['type' => 'info', 'message' => "Found {$total_rows} products to process"]);
        $this->sse_send('progress', ['current' => 0, 'total' => $total_rows, 'percent' => 0]);

        $row_num = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $row_num++;

            try {
                $product_data = $this->parse_row($row, $header_map, $options);

                if (empty($product_data['title'])) {
                    $results['skipped']++;
                    $this->sse_send('log', ['type' => 'warning', 'message' => "Row {$row_num}: Skipped (empty title)"]);
                    continue;
                }

                $this->sse_send('log', ['type' => 'info', 'message' => "Processing: {$product_data['title']}"]);

                // Send category matching logs
                if (!empty($product_data['category_log'])) {
                    foreach ($product_data['category_log'] as $log_entry) {
                        $this->sse_send('log', $log_entry);
                    }
                }

                $import_result = $this->create_product($product_data, $options);

                if ($import_result['product_id']) {
                    $results['imported']++;
                    $results['images_queued'] += $import_result['images_queued'];
                    $this->sse_send('log', ['type' => 'success', 'message' => "Created product: {$product_data['title']}"]);
                    if ($import_result['images_queued'] > 0) {
                        $this->sse_send('log', ['type' => 'info', 'message' => "  Queued {$import_result['images_queued']} images for background import"]);
                    }
                } elseif ($import_result['updated_existing']) {
                    $results['updated']++;
                    $this->sse_send('log', ['type' => 'info', 'message' => "Updated existing product: {$product_data['title']}"]);
                } else {
                    $results['skipped']++;
                    $this->sse_send('log', ['type' => 'warning', 'message' => "Skipped: {$product_data['title']}"]);
                }

                // Send progress update
                $percent = round(($row_num / $total_rows) * 100);
                $this->sse_send('progress', ['current' => $row_num, 'total' => $total_rows, 'percent' => $percent]);

            } catch (Exception $e) {
                $results['errors'][] = "Row {$row_num}: " . $e->getMessage();
                $results['skipped']++;
                $this->sse_send('log', ['type' => 'error', 'message' => "Row {$row_num}: " . $e->getMessage()]);
            }
        }

        fclose($handle);

        // Count new categories
        $categories_after = wp_count_terms(['taxonomy' => 'product_cat']);
        $results['categories_created'] = max(0, $categories_after - $categories_before);

        $this->sse_send('log', ['type' => 'success', 'message' => 'Import completed!']);
        $this->sse_send('log', ['type' => 'info', 'message' => "Imported: {$results['imported']}, Updated: {$results['updated']}, Skipped: {$results['skipped']}"]);

        if ($results['categories_created'] > 0) {
            $this->sse_send('log', ['type' => 'success', 'message' => "Created {$results['categories_created']} new categories"]);
        }

        if ($results['images_queued'] > 0) {
            $this->sse_send('log', ['type' => 'info', 'message' => "{$results['images_queued']} images queued for background processing"]);
        }

        $this->sse_send('complete', $results);
    }

    private function process_csv($file_path, $options) {
        $results = [
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
            'products' => [],
            'images_queued' => 0,
            'categories_created' => 0,
            'logs' => [], // Detailed per-product logs
        ];

        // Track categories before import to count new ones
        $categories_before = wp_count_terms(['taxonomy' => 'product_cat']);

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            $results['errors'][] = 'Could not open CSV file';
            return $results;
        }

        // Read header row
        $headers = fgetcsv($handle);
        if (!$headers) {
            $results['errors'][] = 'Could not read CSV headers';
            fclose($handle);
            return $results;
        }

        // Normalize headers (Etsy uses various formats)
        $headers = array_map(function($h) {
            return strtoupper(trim($h));
        }, $headers);

        $header_map = array_flip($headers);

        // Log available headers for debugging
        $results['debug_headers'] = $headers;

        // Required fields check
        if (!isset($header_map['TITLE'])) {
            $results['errors'][] = 'CSV must contain a TITLE column';
            fclose($handle);
            return $results;
        }

        $row_num = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $row_num++;

            try {
                $product_data = $this->parse_row($row, $header_map, $options);

                if (empty($product_data['title'])) {
                    $results['skipped']++;
                    continue;
                }

                // Add product processing log entry
                $product_log = [
                    'title' => $product_data['title'],
                    'entries' => [],
                ];

                // Add category matching logs
                if (!empty($product_data['category_log'])) {
                    foreach ($product_data['category_log'] as $log_entry) {
                        $product_log['entries'][] = $log_entry;
                    }
                }

                $import_result = $this->create_product($product_data, $options);

                if ($import_result['product_id']) {
                    $results['imported']++;
                    $results['images_queued'] += $import_result['images_queued'];
                    $results['products'][] = [
                        'id' => $import_result['product_id'],
                        'title' => $product_data['title'],
                    ];
                    $product_log['entries'][] = ['type' => 'success', 'message' => 'Product created successfully'];
                } elseif ($import_result['updated_existing']) {
                    $results['updated']++;
                    $product_log['entries'][] = ['type' => 'info', 'message' => 'Existing product updated with new category'];
                } else {
                    $results['skipped']++;
                    $product_log['entries'][] = ['type' => 'warning', 'message' => 'Product skipped'];
                }

                $results['logs'][] = $product_log;

            } catch (Exception $e) {
                $results['errors'][] = "Row {$row_num}: " . $e->getMessage();
                $results['skipped']++;
            }
        }

        fclose($handle);

        // Count new categories created
        $categories_after = wp_count_terms(['taxonomy' => 'product_cat']);
        $results['categories_created'] = max(0, $categories_after - $categories_before);

        return $results;
    }

    private function parse_row($row, $header_map, $options) {
        $get_field = function($name) use ($row, $header_map) {
            $key = strtoupper($name);
            if (isset($header_map[$key]) && isset($row[$header_map[$key]])) {
                return trim($row[$header_map[$key]]);
            }
            return '';
        };

        // Parse tags first as we'll use them for category matching
        $tags = $this->parse_tags($get_field('TAGS'));
        $title = $get_field('TITLE');

        // Try to find matching category from tags or title
        $category_result = $this->match_category_from_content($tags, $title);
        $matched_category = $category_result['category'] ?? '';
        $category_log = $category_result['log'] ?? [];

        $data = [
            'title' => $title,
            'description' => $get_field('DESCRIPTION'),
            'price' => $this->parse_price($get_field('PRICE')),
            'sku' => $get_field('SKU'),
            'tags' => $tags,
            'images' => [],
            // Use matched category, or SECTION field if available
            'taxonomy_path' => $get_field('SECTION') ?: $matched_category,
            'quantity' => $get_field('QUANTITY'),
            'category_log' => $category_log, // Log of category matching process
        ];

        // Collect all image URLs (Etsy exports IMAGE1 through IMAGE10)
        for ($i = 1; $i <= 10; $i++) {
            $image_url = $get_field("IMAGE{$i}");
            if (!empty($image_url) && filter_var($image_url, FILTER_VALIDATE_URL)) {
                $data['images'][] = $image_url;
            }
        }

        // Also check for PHOTOS field (some Etsy exports use this)
        $photos = $get_field('PHOTOS');
        if (!empty($photos)) {
            $photo_urls = explode(',', $photos);
            foreach ($photo_urls as $url) {
                $url = trim($url);
                if (!empty($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                    $data['images'][] = $url;
                }
            }
        }

        return $data;
    }

    private function parse_price($price_string) {
        // Remove currency symbols and normalize
        $price = preg_replace('/[^0-9.,]/', '', $price_string);
        $price = str_replace(',', '.', $price);
        return floatval($price);
    }

    private function parse_tags($tags_string) {
        if (empty($tags_string)) {
            return [];
        }

        // Etsy uses comma-separated tags
        $tags = explode(',', $tags_string);
        return array_map('trim', array_filter($tags));
    }

    private function create_product($data, $options) {
        $result = [
            'product_id' => null,
            'images_queued' => 0,
            'updated_existing' => false,
        ];

        $existing_product_id = null;

        // Check for existing product by SKU first
        if (!empty($data['sku'])) {
            $existing_id = wc_get_product_id_by_sku($data['sku']);
            if ($existing_id) {
                $existing_product_id = $existing_id;
            }
        }

        // Check for existing product by title if not found by SKU
        if (!$existing_product_id) {
            $existing_products = get_posts([
                'post_type' => 'product',
                'title' => $data['title'],
                'post_status' => 'any',
                'numberposts' => 1,
                'fields' => 'ids',
            ]);

            if (!empty($existing_products)) {
                $existing_product_id = $existing_products[0];
            }
        }

        // If product exists, update its categories and return
        if ($existing_product_id) {
            $this->update_product_categories($existing_product_id, $data, $options);
            $result['updated_existing'] = true;
            return $result;
        }

        $product = new WC_Product_Simple();

        $product->set_name($data['title']);
        $product->set_description($data['description']);
        $product->set_short_description(wp_trim_words($data['description'], 30));

        if ($data['price'] > 0) {
            $product->set_regular_price($data['price']);
        }

        if (!empty($data['sku'])) {
            $product->set_sku($data['sku']);
        }

        // Set status
        $product->set_status($options['draft_status'] ? 'draft' : 'publish');

        // Mark as digital/downloadable
        if ($options['mark_digital']) {
            $product->set_virtual(true);
            $product->set_downloadable(true);
        }

        // Digital products should not track stock (unlimited inventory)
        if ($options['mark_digital']) {
            $product->set_manage_stock(false);
            $product->set_stock_status('instock');
        } elseif (!empty($data['quantity']) && is_numeric($data['quantity'])) {
            // Only track stock for physical products
            $product->set_manage_stock(true);
            $product->set_stock_quantity(intval($data['quantity']));
        }

        // Save to get ID
        $product_id = $product->save();

        // Set categories from Etsy taxonomy or fallback to default
        $category_ids = [];

        if ($options['import_categories'] && !empty($data['taxonomy_path'])) {
            $category_ids = $this->process_taxonomy_path($data['taxonomy_path'], $options['create_categories']);
        }

        // Fallback to default category if no categories were assigned
        if (empty($category_ids) && $options['default_category'] > 0) {
            $category_ids = [$options['default_category']];
        }

        if (!empty($category_ids)) {
            wp_set_object_terms($product_id, $category_ids, 'product_cat');
        }

        // Set tags
        if (!empty($data['tags'])) {
            wp_set_object_terms($product_id, $data['tags'], 'product_tag');
        }

        // Queue all images for background import
        if ($options['import_images'] && !empty($data['images'])) {
            $this->queue_image_imports($product_id, $data['images']);
            $result['images_queued'] = count($data['images']);
        }

        $result['product_id'] = $product_id;
        return $result;
    }

    /**
     * Update categories for an existing product
     */
    private function update_product_categories($product_id, $data, $options) {
        $category_ids = [];

        if ($options['import_categories'] && !empty($data['taxonomy_path'])) {
            $category_ids = $this->process_taxonomy_path($data['taxonomy_path'], $options['create_categories']);
        }

        // Fallback to default category if no categories were found
        if (empty($category_ids) && $options['default_category'] > 0) {
            $category_ids = [$options['default_category']];
        }

        if (!empty($category_ids)) {
            wp_set_object_terms($product_id, $category_ids, 'product_cat');
        }
    }

    /**
     * Match tags and title to existing WooCommerce categories
     * Returns array with category name and log details
     */
    private function match_category_from_content($tags, $title) {
        $log = [];

        // Try AI categorization first if enabled
        $ai_result = $this->ai_categorize_product($tags, $title, true);

        if (!empty($ai_result['category'])) {
            $log[] = [
                'type' => 'ai',
                'message' => 'AI analyzed product tags and title',
            ];
            $log[] = [
                'type' => 'ai',
                'message' => 'AI scores: ' . implode(', ', $ai_result['all_scores']),
            ];
            $log[] = [
                'type' => 'success',
                'message' => 'Selected category: ' . $ai_result['category'] . ' (' . round($ai_result['score'] * 100) . '% confidence)',
            ];
            return ['category' => $ai_result['category'], 'log' => $log];
        }

        // Check if AI was attempted but no good match
        if (!empty($ai_result['all_scores'])) {
            $log[] = [
                'type' => 'ai',
                'message' => 'AI scores too low: ' . implode(', ', $ai_result['all_scores']),
            ];
        }

        // Fallback to simple keyword-based matching (no AI)
        // Get all existing product categories
        $categories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ]);

        if (empty($categories) || is_wp_error($categories)) {
            return '';
        }

        // Build a list of category names and keywords for matching
        $category_keywords = [];
        foreach ($categories as $cat) {
            $name_lower = strtolower($cat->name);
            // Skip uncategorized
            if ($cat->slug === 'uncategorized') {
                continue;
            }

            $category_keywords[$cat->name] = [
                'name' => $name_lower,
                'words' => preg_split('/[\s&,]+/', $name_lower), // Split on spaces, &, commas
            ];
        }

        // Extract individual words from tags (e.g., "bridal_games_bundle" -> "bridal", "games", "bundle")
        $tag_words = [];
        foreach ($tags as $tag) {
            // Split on underscores, hyphens, and spaces
            $parts = preg_split('/[_\-\s]+/', strtolower($tag));
            $tag_words = array_merge($tag_words, $parts);
        }
        $tag_words = array_unique($tag_words);

        $search_text = strtolower($title . ' ' . implode(' ', $tags) . ' ' . implode(' ', $tag_words));

        $best_match = '';
        $best_score = 0;

        foreach ($category_keywords as $cat_name => $cat_data) {
            $score = 0;

            // Check if category name appears in search text
            if (strpos($search_text, $cat_data['name']) !== false) {
                $score += 10; // High score for exact name match
            }

            // Check individual words from category name
            foreach ($cat_data['words'] as $word) {
                if (strlen($word) >= 3 && strpos($search_text, $word) !== false) {
                    $score += 2;
                }
            }

            // Check if any tag matches category name closely
            foreach ($tags as $tag) {
                $tag_lower = strtolower($tag);
                if ($tag_lower === $cat_data['name']) {
                    $score += 15; // Exact tag match
                } elseif (strpos($tag_lower, $cat_data['name']) !== false || strpos($cat_data['name'], $tag_lower) !== false) {
                    $score += 5; // Partial match
                }
            }

            if ($score > $best_score) {
                $best_score = $score;
                $best_match = $cat_name;
            }
        }

        // Return result with log
        if ($best_score >= 2) {
            $log[] = [
                'type' => 'info',
                'message' => 'Keyword matching found: ' . $best_match . ' (score: ' . $best_score . ')',
            ];
            return ['category' => $best_match, 'log' => $log];
        }

        $log[] = [
            'type' => 'warning',
            'message' => 'No category match found',
        ];
        return ['category' => '', 'log' => $log];
    }

    /**
     * Use Hugging Face AI to suggest the best category for a product
     * Uses zero-shot classification to match product to existing categories
     * Returns array with category name and details, or empty array on failure
     */
    private function ai_categorize_product($tags, $title, $return_details = false) {
        $api_token = get_option('etsy_importer_hf_api_token', '');
        $use_ai = get_option('etsy_importer_use_ai', false);

        if (empty($api_token) || !$use_ai) {
            return '';
        }

        // Get all existing product categories
        $categories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ]);

        if (empty($categories) || is_wp_error($categories)) {
            return '';
        }

        // Build list of category names (excluding uncategorized)
        $category_names = [];
        foreach ($categories as $cat) {
            if ($cat->slug !== 'uncategorized') {
                $category_names[] = $cat->name;
            }
        }

        if (empty($category_names)) {
            return '';
        }

        // Extract words from tags for better context
        $tag_words = [];
        foreach ($tags as $tag) {
            $parts = preg_split('/[_\-\s]+/', $tag);
            $tag_words = array_merge($tag_words, $parts);
        }
        $tag_words = array_unique(array_filter($tag_words));

        // Build a rich product description for the AI
        // "Classify this product:" framing gives best zero-shot classification results
        $product_text = 'Classify this product: ' . $title . ' (' . implode(', ', $tag_words) . ')';

        // Use Hugging Face's zero-shot classification model (via router endpoint)
        $response = wp_remote_post('https://router.huggingface.co/hf-inference/models/facebook/bart-large-mnli', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_token,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'inputs' => $product_text,
                'parameters' => [
                    'candidate_labels' => $category_names, // Use original names for cleaner matching
                    'multi_label' => false,
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            error_log('Etsy Importer AI: API request failed - ' . $response->get_error_message());
            return '';
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            error_log('Etsy Importer AI: API returned status ' . $status_code . ' - ' . $body);
            return '';
        }

        $result = json_decode($body, true);

        // Response is an array of {label, score} objects sorted by score descending
        if (!$result || !is_array($result) || empty($result[0])) {
            error_log('Etsy Importer AI: Invalid response format - ' . $body);
            return $return_details ? ['category' => '', 'score' => 0, 'all_scores' => []] : '';
        }

        // Get the highest scoring category (first item)
        $best_label = $result[0]['label'] ?? '';
        $best_score = $result[0]['score'] ?? 0;

        // Build all scores for logging
        $all_scores = [];
        foreach (array_slice($result, 0, 3) as $item) {
            $all_scores[] = $item['label'] . ': ' . round($item['score'] * 100) . '%';
        }

        if ($best_score >= 0.2) {
            error_log("Etsy Importer AI: Matched '{$title}' to '{$best_label}' with score {$best_score}");

            if ($return_details) {
                return [
                    'category' => $best_label,
                    'score' => $best_score,
                    'all_scores' => $all_scores,
                ];
            }
            return $best_label;
        }

        return $return_details ? ['category' => '', 'score' => 0, 'all_scores' => $all_scores] : '';
    }

    /**
     * Process Etsy taxonomy path and return category IDs
     * Handles both simple names ("Bridal Shower Games") and hierarchical ("Parent > Child")
     */
    private function process_taxonomy_path($taxonomy_path, $create_missing = true) {
        if (empty($taxonomy_path)) {
            return [];
        }

        $path = trim($taxonomy_path);

        // Check if it contains hierarchy separators
        if (strpos($path, ' > ') !== false || strpos($path, '>') !== false || strpos($path, ' / ') !== false) {
            // Etsy uses " > " as separator for hierarchy
            // Also handle variations like " / " or just ">"
            $path = str_replace(' / ', ' > ', $path);
            $path = str_replace('>', ' > ', $path);
            $categories = array_map('trim', explode(' > ', $path));
        } else {
            // Simple single category name
            $categories = [$path];
        }

        $categories = array_filter($categories); // Remove empty values

        if (empty($categories)) {
            return [];
        }

        $category_ids = [];
        $parent_id = 0;

        foreach ($categories as $category_name) {
            // Clean up category name
            $category_name = trim($category_name);
            if (empty($category_name)) {
                continue;
            }

            // Look for existing category with this name and parent
            $existing_term = get_term_by('name', $category_name, 'product_cat');

            // If we need to match the hierarchy, check parent too
            if ($existing_term && $parent_id > 0) {
                // Verify the parent matches
                if ($existing_term->parent !== $parent_id) {
                    // Try to find one with the correct parent
                    $terms = get_terms([
                        'taxonomy' => 'product_cat',
                        'name' => $category_name,
                        'parent' => $parent_id,
                        'hide_empty' => false,
                    ]);
                    $existing_term = !empty($terms) ? $terms[0] : null;
                }
            }

            if ($existing_term) {
                $parent_id = $existing_term->term_id;
                $category_ids[] = $existing_term->term_id;
            } elseif ($create_missing) {
                // Create the category
                $result = wp_insert_term($category_name, 'product_cat', [
                    'parent' => $parent_id,
                    'slug' => sanitize_title($category_name),
                ]);

                if (!is_wp_error($result)) {
                    $parent_id = $result['term_id'];
                    $category_ids[] = $result['term_id'];
                } else {
                    // If term exists error (race condition), try to get it
                    if ($result->get_error_code() === 'term_exists') {
                        $term_id = $result->get_error_data();
                        $parent_id = $term_id;
                        $category_ids[] = $term_id;
                    } else {
                        error_log("Etsy Importer: Failed to create category '{$category_name}': " . $result->get_error_message());
                    }
                }
            }
        }

        // Return only the deepest category (last one) to avoid duplication
        // Or return all if you want products in all levels
        return !empty($category_ids) ? [end($category_ids)] : [];
    }

    /**
     * Queue images for background processing using Action Scheduler
     */
    private function queue_image_imports($product_id, $image_urls) {
        foreach ($image_urls as $index => $url) {
            // Schedule each image import with a slight delay to avoid overwhelming the server
            $delay = $index * 5; // 5 seconds between each image

            if (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(
                    time() + $delay,
                    'etsy_import_product_image',
                    [
                        'product_id' => $product_id,
                        'image_url' => $url,
                        'is_featured' => ($index === 0),
                    ],
                    'etsy-csv-importer'
                );
            } else {
                // Fallback: use wp_schedule_single_event if Action Scheduler not available
                wp_schedule_single_event(
                    time() + $delay,
                    'etsy_import_product_image',
                    [$product_id, $url, ($index === 0)]
                );
            }
        }
    }

    /**
     * Process a single image import (called by Action Scheduler)
     */
    public function process_single_image($product_id, $image_url, $is_featured = false) {
        // Verify product still exists
        $product = wc_get_product($product_id);
        if (!$product) {
            error_log("Etsy Importer: Product {$product_id} no longer exists, skipping image import");
            return;
        }

        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        try {
            $attachment_id = $this->sideload_image($image_url, $product_id);

            if ($attachment_id && !is_wp_error($attachment_id)) {
                if ($is_featured) {
                    // Set as featured image
                    set_post_thumbnail($product_id, $attachment_id);
                } else {
                    // Add to gallery
                    $gallery = get_post_meta($product_id, '_product_image_gallery', true);
                    $gallery_ids = $gallery ? explode(',', $gallery) : [];
                    $gallery_ids[] = $attachment_id;
                    update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
                }

                error_log("Etsy Importer: Successfully imported image for product {$product_id}");
            }
        } catch (Exception $e) {
            error_log("Etsy Importer: Failed to import image {$image_url} for product {$product_id}: " . $e->getMessage());
        }
    }

    private function sideload_image($url, $post_id) {
        // Get the file extension
        $parsed_url = parse_url($url);
        $path = $parsed_url['path'] ?? '';
        $ext = pathinfo($path, PATHINFO_EXTENSION);

        if (empty($ext) || !in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $ext = 'jpg'; // Default extension
        }

        // Download with a reasonable timeout (60 seconds per image)
        $tmp = download_url($url, 60);

        if (is_wp_error($tmp)) {
            error_log("Etsy Importer: Failed to download {$url}: " . $tmp->get_error_message());
            return $tmp;
        }

        $file_array = [
            'name' => sanitize_file_name('etsy-import-' . uniqid() . '.' . $ext),
            'tmp_name' => $tmp,
        ];

        // Sideload the image (with thumbnail generation)
        $attachment_id = media_handle_sideload($file_array, $post_id);

        // Clean up temp file if sideload failed
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            error_log("Etsy Importer: Failed to sideload {$url}: " . $attachment_id->get_error_message());
            return $attachment_id;
        }

        return $attachment_id;
    }
}

// Initialize the plugin
add_action('plugins_loaded', function() {
    if (class_exists('WooCommerce')) {
        Etsy_CSV_Importer::get_instance();
    }
});
