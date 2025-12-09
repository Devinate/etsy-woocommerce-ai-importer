<?php
/**
 * Plugin Name: Etsy WooCommerce AI Importer
 * Plugin URI: https://wordpress.org/plugins/etsy-woocommerce-ai-importer/
 * Description: Import digital products from Etsy CSV exports into WooCommerce with AI-powered category matching using Hugging Face.
 * Version: 1.0.0
 * Author: Devinate
 * Author URI: https://devinate.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: etsy-woocommerce-ai-importer
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 9.4
 *
 * @package Etsy_WooCommerce_AI_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Etsy_CSV_Importer {

    private static $instance = null;

    /**
     * Get the singleton instance.
     *
     * @return Etsy_CSV_Importer
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_action( 'wp_ajax_etsy_import_products', array( $this, 'ajax_import_products' ) );
        add_action( 'wp_ajax_etsy_import_stream', array( $this, 'ajax_import_stream' ) );
        add_action( 'wp_ajax_etsy_save_settings', array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_etsy_bulk_update_urls', array( $this, 'ajax_bulk_update_urls' ) );

        // Add Etsy meta box to product edit page.
        add_action( 'add_meta_boxes', array( $this, 'add_etsy_meta_box' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_etsy_meta_box' ) );

        // Background image import action (using Action Scheduler).
        add_action( 'etsy_import_product_image', array( $this, 'process_single_image' ), 10, 3 );

        // Register GraphQL field for WPGraphQL integration.
        add_action( 'graphql_register_types', array( $this, 'register_graphql_fields' ) );
    }

    /**
     * Register etsyUrl field with WPGraphQL.
     */
    public function register_graphql_fields() {
        if ( ! function_exists( 'register_graphql_field' ) ) {
            return;
        }

        // Register for all product types.
        $product_types = array(
            'SimpleProduct',
            'VariableProduct',
            'ExternalProduct',
            'GroupProduct',
            'Product',
        );

        foreach ( $product_types as $type ) {
            register_graphql_field(
                $type,
                'etsyUrl',
                array(
                    'type'        => 'String',
                    'description' => __( 'The Etsy listing URL for this product', 'etsy-woocommerce-ai-importer' ),
                    'resolve'     => function ( $product ) {
                        $product_id = $product->databaseId ?? $product->ID ?? null;
                        if ( ! $product_id ) {
                            return null;
                        }
                        return get_post_meta( $product_id, '_etsy_listing_url', true ) ?: null;
                    },
                )
            );
        }
    }

    /**
     * Add Etsy meta box to product edit page.
     */
    public function add_etsy_meta_box() {
        add_meta_box(
            'etsy_product_data',
            __( 'Etsy Data', 'etsy-woocommerce-ai-importer' ),
            array( $this, 'render_etsy_meta_box' ),
            'product',
            'side',
            'default'
        );
    }

    /**
     * Render the Etsy meta box.
     *
     * @param WP_Post $post Current post object.
     */
    public function render_etsy_meta_box( $post ) {
        $etsy_url       = get_post_meta( $post->ID, '_etsy_listing_url', true );
        $ai_categorized = get_post_meta( $post->ID, '_etsy_ai_categorized', true );
        $ai_date        = get_post_meta( $post->ID, '_etsy_ai_categorized_date', true );

        wp_nonce_field( 'etsy_meta_box_nonce', 'etsy_meta_box_nonce_field' );
        ?>
        <p>
            <label for="etsy_listing_url"><strong><?php esc_html_e( 'Etsy Listing URL', 'etsy-woocommerce-ai-importer' ); ?></strong></label><br>
            <input type="url" id="etsy_listing_url" name="etsy_listing_url" value="<?php echo esc_url( $etsy_url ); ?>" class="widefat" placeholder="https://www.etsy.com/listing/..." />
        </p>
        <?php if ( $etsy_url ) : ?>
            <p>
                <a href="<?php echo esc_url( $etsy_url ); ?>" target="_blank" class="button button-small">
                    <?php esc_html_e( 'View on Etsy', 'etsy-woocommerce-ai-importer' ); ?> &rarr;
                </a>
            </p>
        <?php endif; ?>
        <?php if ( $ai_categorized ) : ?>
            <p style="color: #666; font-size: 12px;">
                <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
                <?php esc_html_e( 'AI categorized', 'etsy-woocommerce-ai-importer' ); ?>
                <?php if ( $ai_date ) : ?>
                    <br><small><?php echo esc_html( $ai_date ); ?></small>
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <?php
    }

    /**
     * Save the Etsy meta box data.
     *
     * @param int $post_id Post ID.
     */
    public function save_etsy_meta_box( $post_id ) {
        if ( ! isset( $_POST['etsy_meta_box_nonce_field'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['etsy_meta_box_nonce_field'] ) ), 'etsy_meta_box_nonce' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( isset( $_POST['etsy_listing_url'] ) ) {
            $etsy_url = esc_url_raw( wp_unslash( $_POST['etsy_listing_url'] ) );
            if ( ! empty( $etsy_url ) ) {
                update_post_meta( $post_id, '_etsy_listing_url', $etsy_url );
            } else {
                delete_post_meta( $post_id, '_etsy_listing_url' );
            }
        }
    }

    /**
     * Save AI settings via AJAX
     */
    public function ajax_save_settings() {
        check_ajax_referer( 'etsy_import_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Permission denied', 'etsy-woocommerce-ai-importer' ) ) );
        }

        $api_token = isset( $_POST['hf_api_token'] ) ? sanitize_text_field( wp_unslash( $_POST['hf_api_token'] ) ) : '';
        $use_ai    = isset( $_POST['use_ai_categorization'] ) && '1' === $_POST['use_ai_categorization'];

        update_option( 'etsy_importer_hf_api_token', $api_token );
        update_option( 'etsy_importer_use_ai', $use_ai );

        wp_send_json_success( array( 'message' => esc_html__( 'Settings saved successfully', 'etsy-woocommerce-ai-importer' ) ) );
    }

    /**
     * Bulk update Etsy URLs via AJAX.
     *
     * Accepts a CSV with TITLE and LISTING_ID (or URL) columns and matches to existing products.
     */
    public function ajax_bulk_update_urls() {
        check_ajax_referer( 'etsy_import_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Permission denied', 'etsy-woocommerce-ai-importer' ) ) );
        }

        // Save shop name if provided.
        if ( isset( $_POST['etsy_shop_name'] ) ) {
            $shop_name = sanitize_text_field( wp_unslash( $_POST['etsy_shop_name'] ) );
            update_option( 'etsy_importer_shop_name', $shop_name );
        }

        // Check if a file was uploaded.
        if ( ! isset( $_FILES['url_csv_file'] ) || UPLOAD_ERR_OK !== $_FILES['url_csv_file']['error'] ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Please upload a CSV file with TITLE and LISTING_ID columns.', 'etsy-woocommerce-ai-importer' ) ) );
        }

        $file = $_FILES['url_csv_file'];

        // Read the CSV file.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $csv_content = file_get_contents( $file['tmp_name'] );
        if ( empty( $csv_content ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Could not read CSV file.', 'etsy-woocommerce-ai-importer' ) ) );
        }

        // Parse CSV.
        $lines = preg_split( '/\r\n|\r|\n/', $csv_content );
        if ( count( $lines ) < 2 ) {
            wp_send_json_error( array( 'message' => esc_html__( 'CSV file is empty or has no data rows.', 'etsy-woocommerce-ai-importer' ) ) );
        }

        // Parse header row.
        $header = str_getcsv( $lines[0] );
        $header_map = array();
        foreach ( $header as $index => $col ) {
            $header_map[ strtoupper( trim( $col ) ) ] = $index;
        }

        // Find required columns.
        $title_col = null;
        $url_col   = null;
        $id_col    = null;

        foreach ( array( 'TITLE', 'NAME', 'PRODUCT_NAME', 'PRODUCT NAME' ) as $col_name ) {
            if ( isset( $header_map[ $col_name ] ) ) {
                $title_col = $header_map[ $col_name ];
                break;
            }
        }

        foreach ( array( 'URL', 'ETSY_URL', 'LISTING_URL', 'ETSY URL' ) as $col_name ) {
            if ( isset( $header_map[ $col_name ] ) ) {
                $url_col = $header_map[ $col_name ];
                break;
            }
        }

        foreach ( array( 'LISTING_ID', 'LISTINGID', 'LISTING ID', 'ID' ) as $col_name ) {
            if ( isset( $header_map[ $col_name ] ) ) {
                $id_col = $header_map[ $col_name ];
                break;
            }
        }

        if ( null === $title_col ) {
            wp_send_json_error( array( 'message' => esc_html__( 'CSV must have a TITLE column to match products.', 'etsy-woocommerce-ai-importer' ) ) );
        }

        if ( null === $url_col && null === $id_col ) {
            wp_send_json_error( array( 'message' => esc_html__( 'CSV must have a URL or LISTING_ID column.', 'etsy-woocommerce-ai-importer' ) ) );
        }

        // Get all products for matching.
        $all_products = get_posts(
            array(
                'post_type'      => 'product',
                'posts_per_page' => -1,
                'post_status'    => 'any',
            )
        );

        // Build a map of normalized titles to product IDs.
        $product_map = array();
        foreach ( $all_products as $product ) {
            $normalized = $this->normalize_title_for_matching( $product->post_title );
            $product_map[ $normalized ] = $product->ID;
        }

        $updated  = 0;
        $skipped  = 0;
        $notfound = 0;
        $errors   = array();

        // Process data rows.
        for ( $i = 1; $i < count( $lines ); $i++ ) {
            $line = trim( $lines[ $i ] );
            if ( empty( $line ) ) {
                continue;
            }

            $row = str_getcsv( $line );
            if ( count( $row ) <= max( $title_col, $url_col ?? 0, $id_col ?? 0 ) ) {
                continue;
            }

            $title = trim( $row[ $title_col ] );
            if ( empty( $title ) ) {
                continue;
            }

            // Get the URL (either directly or build from listing ID).
            $etsy_url = '';
            if ( null !== $url_col && ! empty( $row[ $url_col ] ) ) {
                $etsy_url = trim( $row[ $url_col ] );
            } elseif ( null !== $id_col && ! empty( $row[ $id_col ] ) ) {
                $listing_id = trim( $row[ $id_col ] );
                $etsy_url = 'https://www.etsy.com/listing/' . $listing_id;
            }

            if ( empty( $etsy_url ) ) {
                $skipped++;
                continue;
            }

            // Find matching product.
            $normalized_title = $this->normalize_title_for_matching( $title );
            if ( isset( $product_map[ $normalized_title ] ) ) {
                $product_id = $product_map[ $normalized_title ];

                // Check if product already has a URL.
                $existing_url = get_post_meta( $product_id, '_etsy_listing_url', true );
                if ( ! empty( $existing_url ) ) {
                    $skipped++;
                    continue;
                }

                // Update the Etsy URL.
                update_post_meta( $product_id, '_etsy_listing_url', esc_url_raw( $etsy_url ) );
                $updated++;
            } else {
                $notfound++;
                if ( count( $errors ) < 5 ) {
                    $errors[] = sprintf(
                        /* translators: %s: product title */
                        esc_html__( 'No match found for: %s', 'etsy-woocommerce-ai-importer' ),
                        $title
                    );
                }
            }
        }

        $message = sprintf(
            /* translators: 1: updated count, 2: skipped count, 3: not found count */
            esc_html__( 'Updated %1$d products, skipped %2$d (already had URLs or empty), %3$d not matched.', 'etsy-woocommerce-ai-importer' ),
            $updated,
            $skipped,
            $notfound
        );

        wp_send_json_success(
            array(
                'message'  => $message,
                'updated'  => $updated,
                'skipped'  => $skipped,
                'notfound' => $notfound,
                'errors'   => $errors,
            )
        );
    }

    /**
     * Normalize a product title for matching.
     *
     * @param string $title Product title.
     * @return string Normalized title.
     */
    private function normalize_title_for_matching( $title ) {
        // Convert to lowercase.
        $title = strtolower( $title );
        // Remove special characters but keep alphanumeric and spaces.
        $title = preg_replace( '/[^a-z0-9\s]/', '', $title );
        // Collapse multiple spaces.
        $title = preg_replace( '/\s+/', ' ', $title );
        // Trim.
        return trim( $title );
    }

    /**
     * Add admin menu page.
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            esc_html__( 'Etsy CSV Importer', 'etsy-woocommerce-ai-importer' ),
            esc_html__( 'Etsy Importer', 'etsy-woocommerce-ai-importer' ),
            'manage_woocommerce',
            'etsy-csv-importer',
            array( $this, 'render_admin_page' )
        );
    }

    /**
     * Enqueue admin scripts and styles.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_admin_scripts( $hook ) {
        if ( 'woocommerce_page_etsy-csv-importer' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'etsy-importer-admin',
            plugin_dir_url( __FILE__ ) . 'assets/admin.css',
            array(),
            '1.0.0'
        );

        wp_enqueue_script(
            'etsy-importer-admin',
            plugin_dir_url( __FILE__ ) . 'assets/admin.js',
            array( 'jquery' ),
            '1.0.0',
            true
        );

        wp_localize_script(
            'etsy-importer-admin',
            'etsyImporter',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'etsy_import_nonce' ),
            )
        );
    }

    /**
     * Render the admin page.
     */
    public function render_admin_page() {
        // Count pending image imports.
        $pending_images = 0;
        if ( function_exists( 'as_get_scheduled_actions' ) ) {
            $pending = as_get_scheduled_actions(
                array(
                    'hook'     => 'etsy_import_product_image',
                    'status'   => ActionScheduler_Store::STATUS_PENDING,
                    'per_page' => -1,
                )
            );
            $pending_images = count( $pending );
        }
        ?>
        <div class="wrap etsy-importer-wrap">
            <h1><?php esc_html_e( 'Etsy CSV Importer', 'etsy-woocommerce-ai-importer' ); ?></h1>

            <?php if ( $pending_images > 0 ) : ?>
            <div class="notice notice-info">
                <p>
                    <strong><?php esc_html_e( 'Background Processing:', 'etsy-woocommerce-ai-importer' ); ?></strong>
                    <?php
                    printf(
                        /* translators: %d: number of images */
                        esc_html( _n( '%d image is being imported in the background.', '%d images are being imported in the background.', $pending_images, 'etsy-woocommerce-ai-importer' ) ),
                        (int) $pending_images
                    );
                    ?>
                    <?php esc_html_e( 'They will appear on products shortly.', 'etsy-woocommerce-ai-importer' ); ?>
                </p>
            </div>
            <?php endif; ?>

            <div class="etsy-importer-card">
                <h2><?php esc_html_e( 'Import Products from Etsy', 'etsy-woocommerce-ai-importer' ); ?></h2>
                <p><?php esc_html_e( 'Upload your Etsy CSV export file to import products into WooCommerce.', 'etsy-woocommerce-ai-importer' ); ?></p>

                <div class="etsy-importer-instructions">
                    <h3><?php esc_html_e( 'How to export from Etsy:', 'etsy-woocommerce-ai-importer' ); ?></h3>
                    <ol>
                        <li><?php esc_html_e( 'Log into your Etsy account', 'etsy-woocommerce-ai-importer' ); ?></li>
                        <li><?php echo wp_kses( __( 'Go to <strong>Shop Manager → Settings → Options</strong>', 'etsy-woocommerce-ai-importer' ), array( 'strong' => array() ) ); ?></li>
                        <li><?php echo wp_kses( __( 'Click the <strong>Download data</strong> tab', 'etsy-woocommerce-ai-importer' ), array( 'strong' => array() ) ); ?></li>
                        <li><?php echo wp_kses( __( 'Click <strong>Download CSV</strong> to get your listings', 'etsy-woocommerce-ai-importer' ), array( 'strong' => array() ) ); ?></li>
                    </ol>
                </div>

                <form id="etsy-import-form" enctype="multipart/form-data">
                    <div class="form-field">
                        <label for="etsy-csv-file"><?php esc_html_e( 'Select Etsy CSV File:', 'etsy-woocommerce-ai-importer' ); ?></label>
                        <input type="file" id="etsy-csv-file" name="csv_file" accept=".csv" required>
                    </div>

                    <div class="form-field">
                        <label>
                            <input type="checkbox" name="import_images" value="1" checked>
                            <?php esc_html_e( 'Import product images from Etsy (processed in background)', 'etsy-woocommerce-ai-importer' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Images are downloaded in the background so the import completes quickly.', 'etsy-woocommerce-ai-importer' ); ?></p>
                    </div>

                    <div class="form-field">
                        <label>
                            <input type="checkbox" name="mark_digital" value="1" checked>
                            <?php esc_html_e( 'Mark all products as digital/downloadable', 'etsy-woocommerce-ai-importer' ); ?>
                        </label>
                    </div>

                    <div class="form-field">
                        <label>
                            <input type="checkbox" name="draft_status" value="1">
                            <?php esc_html_e( 'Import as drafts (recommended for review)', 'etsy-woocommerce-ai-importer' ); ?>
                        </label>
                    </div>

                    <div class="form-field">
                        <label>
                            <input type="checkbox" name="import_categories" value="1" checked>
                            <?php esc_html_e( 'Auto-assign to WooCommerce categories', 'etsy-woocommerce-ai-importer' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Matches products to your existing categories based on product title and tags.', 'etsy-woocommerce-ai-importer' ); ?></p>
                    </div>

                    <div class="form-field">
                        <label>
                            <input type="checkbox" name="skip_ai_categorized" value="1">
                            <?php esc_html_e( 'Skip products already categorized by AI', 'etsy-woocommerce-ai-importer' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'If a product was previously imported and already had AI select its category, skip re-evaluating it.', 'etsy-woocommerce-ai-importer' ); ?></p>
                    </div>

                    <div class="form-field">
                        <label for="default-category"><?php esc_html_e( 'Default Category (fallback):', 'etsy-woocommerce-ai-importer' ); ?></label>
                        <select id="default-category" name="default_category">
                            <option value=""><?php esc_html_e( '— None —', 'etsy-woocommerce-ai-importer' ); ?></option>
                            <?php
                            $categories = get_terms(
                                array(
                                    'taxonomy'   => 'product_cat',
                                    'hide_empty' => false,
                                )
                            );
                            foreach ( $categories as $cat ) {
                                echo '<option value="' . esc_attr( $cat->term_id ) . '">' . esc_html( $cat->name ) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <button type="submit" class="button button-primary button-large">
                        <?php esc_html_e( 'Start Import', 'etsy-woocommerce-ai-importer' ); ?>
                    </button>

                    <?php if ( get_option( 'etsy_importer_use_ai', false ) && get_option( 'etsy_importer_hf_api_token', '' ) ) : ?>
                    <div class="ai-timing-warning">
                        <span class="dashicons dashicons-clock"></span>
                        <strong><?php esc_html_e( 'AI Categorization Enabled:', 'etsy-woocommerce-ai-importer' ); ?></strong>
                        <?php esc_html_e( 'Import may take longer as each product is analyzed by AI for optimal category matching. Products are processed in batches to improve performance.', 'etsy-woocommerce-ai-importer' ); ?>
                    </div>
                    <?php endif; ?>
                </form>

                <div id="import-progress" style="display: none;">
                    <h3><?php esc_html_e( 'Import Progress', 'etsy-woocommerce-ai-importer' ); ?></h3>
                    <div class="progress-bar-container">
                        <div class="progress-bar" style="width: 0%;"></div>
                    </div>
                    <p class="progress-text"><?php esc_html_e( 'Preparing import...', 'etsy-woocommerce-ai-importer' ); ?></p>
                    <div id="import-log"></div>
                </div>

                <div id="import-results" style="display: none;">
                    <h3><?php esc_html_e( 'Import Complete', 'etsy-woocommerce-ai-importer' ); ?></h3>
                    <div class="results-summary"></div>
                </div>
            </div>

            <div class="etsy-importer-card">
                <h2><?php esc_html_e( 'AI Category Matching (Optional - FREE)', 'etsy-woocommerce-ai-importer' ); ?></h2>
                <p><?php echo wp_kses( __( 'Enable AI-powered category matching using Hugging Face\'s <strong>free API</strong> for more accurate categorization. The AI analyzes your product titles and tags to automatically select the best matching category from your existing WooCommerce categories. <strong>No credit card required!</strong>', 'etsy-woocommerce-ai-importer' ), array( 'strong' => array() ) ); ?></p>

                <div class="etsy-importer-instructions">
                    <h3><?php esc_html_e( 'How to get a FREE Hugging Face API Token:', 'etsy-woocommerce-ai-importer' ); ?></h3>
                    <ol>
                        <li><?php echo wp_kses( __( 'Go to <a href="https://huggingface.co/join" target="_blank"><strong>huggingface.co/join</strong></a> and create a <strong>free account</strong> (or sign in if you already have one)', 'etsy-woocommerce-ai-importer' ), array( 'a' => array( 'href' => array(), 'target' => array() ), 'strong' => array() ) ); ?></li>
                        <li><?php echo wp_kses( __( 'Once logged in, go to <a href="https://huggingface.co/settings/tokens/new?tokenType=fineGrained" target="_blank"><strong>Settings &rarr; Access Tokens &rarr; Create new token</strong></a>', 'etsy-woocommerce-ai-importer' ), array( 'a' => array( 'href' => array(), 'target' => array() ), 'strong' => array() ) ); ?></li>
                        <li><?php esc_html_e( 'Give it a name (e.g., "Etsy Importer")', 'etsy-woocommerce-ai-importer' ); ?></li>
                        <li><?php echo wp_kses( __( 'For <strong>Token type</strong>, select <strong>"Fine-grained"</strong>', 'etsy-woocommerce-ai-importer' ), array( 'strong' => array() ) ); ?></li>
                        <li><?php echo wp_kses( __( 'Under <strong>Permissions</strong>, find and enable: <strong>"Make calls to Inference Providers"</strong> (under Inference)', 'etsy-woocommerce-ai-importer' ), array( 'strong' => array() ) ); ?></li>
                        <li><?php esc_html_e( 'You can leave all other permissions unchecked', 'etsy-woocommerce-ai-importer' ); ?></li>
                        <li><?php echo wp_kses( __( 'Click <strong>"Create token"</strong> and copy the token (it starts with <code>hf_</code>)', 'etsy-woocommerce-ai-importer' ), array( 'strong' => array(), 'code' => array() ) ); ?></li>
                        <li><?php esc_html_e( 'Paste the token below and click "Save AI Settings"', 'etsy-woocommerce-ai-importer' ); ?></li>
                    </ol>
                    <p style="margin-top: 12px; margin-bottom: 0;"><?php echo wp_kses( __( '<strong>Free tier includes:</strong> ~30,000 API requests per month - more than enough for most imports. No payment info needed!', 'etsy-woocommerce-ai-importer' ), array( 'strong' => array() ) ); ?></p>
                </div>

                <form id="etsy-ai-settings-form">
                    <div class="form-field">
                        <label for="hf-api-token"><?php esc_html_e( 'Hugging Face API Token:', 'etsy-woocommerce-ai-importer' ); ?></label>
                        <input type="password" id="hf-api-token" name="hf_api_token"
                               value="<?php echo esc_attr( get_option( 'etsy_importer_hf_api_token', '' ) ); ?>"
                               style="width: 100%; max-width: 400px;"
                               placeholder="hf_xxxxxxxxxxxxxxxxxxxxxxxxxx">
                    </div>
                    <div class="form-field">
                        <label>
                            <input type="checkbox" name="use_ai_categorization" value="1"
                                   <?php checked( get_option( 'etsy_importer_use_ai', false ) ); ?>>
                            <?php esc_html_e( 'Use AI for category matching (when token is configured)', 'etsy-woocommerce-ai-importer' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'When enabled, the importer will use AI to suggest the best category based on product title and tags. You\'ll see the AI\'s confidence scores in the import log.', 'etsy-woocommerce-ai-importer' ); ?></p>
                    </div>
                    <button type="submit" class="button"><?php esc_html_e( 'Save AI Settings', 'etsy-woocommerce-ai-importer' ); ?></button>
                    <span id="ai-settings-status" style="margin-left: 10px;"></span>
                </form>
            </div>

            <div class="etsy-importer-card">
                <h2><?php esc_html_e( 'Bulk Update Etsy URLs', 'etsy-woocommerce-ai-importer' ); ?></h2>
                <p><?php esc_html_e( 'Add Etsy listing URLs to products that are missing them. Etsy\'s standard CSV export doesn\'t include listing IDs, so you\'ll need to create a mapping CSV.', 'etsy-woocommerce-ai-importer' ); ?></p>

                <div class="etsy-importer-instructions">
                    <h3><?php esc_html_e( 'How to get listing URLs from Etsy:', 'etsy-woocommerce-ai-importer' ); ?></h3>

                    <p><strong style="color: #2271b1;"><?php esc_html_e( '⭐ Option 1: Use Browser Bookmarklet (Recommended - No API needed!)', 'etsy-woocommerce-ai-importer' ); ?></strong></p>
                    <ol>
                        <li><?php esc_html_e( 'Drag this bookmarklet to your browser\'s bookmarks bar:', 'etsy-woocommerce-ai-importer' ); ?>
                            <br><br>
                            <a href="javascript:(function(){var listings=[];var cards=document.querySelectorAll('[data-listing-id]');if(cards.length===0){cards=document.querySelectorAll('a[href*=&quot;/listing/&quot;]');}cards.forEach(function(el){var url=el.href||el.querySelector('a')?.href;var title=el.querySelector('h3,h2,.v2-listing-card__title')?.innerText||el.getAttribute('title')||'';var match=url?.match(/\/listing\/(\d+)/);if(match&&title){listings.push({id:match[1],title:title.trim(),url:'https://www.etsy.com/listing/'+match[1]});}});var seen={};listings=listings.filter(function(l){if(seen[l.id])return false;seen[l.id]=true;return true;});if(listings.length===0){alert('No listings found. Make sure you are on your Etsy shop page with listings visible.');return;}var csv='TITLE,URL,LISTING_ID\n';listings.forEach(function(l){csv+='&quot;'+l.title.replace(/&quot;/g,'&quot;&quot;')+'&quot;,'+l.url+','+l.id+'\n';});var blob=new Blob([csv],{type:'text/csv'});var a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='etsy_listings.csv';a.click();alert('Downloaded '+listings.length+' listings!');})()" class="button button-primary" style="cursor: move; display: inline-block; margin: 5px 0;" draggable="true" onclick="alert('Drag this to your bookmarks bar, then click it while on your Etsy shop page!'); return false;">
                                📥 <?php esc_html_e( 'Extract Etsy Listings', 'etsy-woocommerce-ai-importer' ); ?>
                            </a>
                        </li>
                        <li><?php echo wp_kses( __( 'Go to your <strong>Etsy Shop page</strong> (e.g., etsy.com/shop/YourShopName)', 'etsy-woocommerce-ai-importer' ), array( 'strong' => array() ) ); ?></li>
                        <li><?php esc_html_e( 'Scroll down to load all your listings (Etsy lazy-loads them)', 'etsy-woocommerce-ai-importer' ); ?></li>
                        <li><?php esc_html_e( 'Click the bookmarklet - it will download a CSV with all your listing URLs!', 'etsy-woocommerce-ai-importer' ); ?></li>
                        <li><?php esc_html_e( 'Upload that CSV below', 'etsy-woocommerce-ai-importer' ); ?></li>
                    </ol>

                    <p style="margin-top: 12px;"><strong><?php esc_html_e( 'Option 2: Use Etsy Order Export (if you have sales)', 'etsy-woocommerce-ai-importer' ); ?></strong></p>
                    <ol>
                        <li><?php echo wp_kses( __( 'Go to Etsy Shop Manager → <strong>Settings → Options → Download Data</strong>', 'etsy-woocommerce-ai-importer' ), array( 'strong' => array() ) ); ?></li>
                        <li><?php echo wp_kses( __( 'Click <strong>"Download Order Items CSV"</strong>', 'etsy-woocommerce-ai-importer' ), array( 'strong' => array() ) ); ?></li>
                        <li><?php esc_html_e( 'This includes LISTING_ID for items you\'ve sold', 'etsy-woocommerce-ai-importer' ); ?></li>
                    </ol>

                    <p style="margin-top: 12px;"><strong><?php esc_html_e( 'Option 3: Create a CSV manually', 'etsy-woocommerce-ai-importer' ); ?></strong></p>
                    <ol>
                        <li><?php esc_html_e( 'Click "Show/Hide List" below to see products missing URLs', 'etsy-woocommerce-ai-importer' ); ?></li>
                        <li><?php esc_html_e( 'Use "Find on Etsy" links to locate each product', 'etsy-woocommerce-ai-importer' ); ?></li>
                        <li><?php esc_html_e( 'Copy URLs into a spreadsheet with TITLE, URL columns', 'etsy-woocommerce-ai-importer' ); ?></li>
                    </ol>
                </div>

                <?php
                // Get products without Etsy URLs.
                $products_without_urls = get_posts(
                    array(
                        'post_type'      => 'product',
                        'posts_per_page' => -1,
                        'fields'         => 'ids',
                        'meta_query'     => array(
                            'relation' => 'OR',
                            array(
                                'key'     => '_etsy_listing_url',
                                'compare' => 'NOT EXISTS',
                            ),
                            array(
                                'key'   => '_etsy_listing_url',
                                'value' => '',
                            ),
                        ),
                    )
                );
                $missing_count = count( $products_without_urls );
                ?>

                <?php if ( $missing_count > 0 ) : ?>
                <div class="notice notice-warning inline" style="margin: 10px 0;">
                    <p>
                        <strong><?php esc_html_e( 'Missing Etsy URLs:', 'etsy-woocommerce-ai-importer' ); ?></strong>
                        <?php
                        printf(
                            /* translators: %d: number of products */
                            esc_html( _n( '%d product is missing its Etsy listing URL.', '%d products are missing their Etsy listing URLs.', $missing_count, 'etsy-woocommerce-ai-importer' ) ),
                            (int) $missing_count
                        );
                        ?>
                        <button type="button" id="download-titles-csv" class="button button-small" style="margin-left: 10px;">
                            <?php esc_html_e( 'Download Titles as CSV', 'etsy-woocommerce-ai-importer' ); ?>
                        </button>
                        <button type="button" id="toggle-missing-list" class="button button-small" style="margin-left: 5px;">
                            <?php esc_html_e( 'Show/Hide List', 'etsy-woocommerce-ai-importer' ); ?>
                        </button>
                    </p>
                </div>

                <?php
                // Get the actual product data for missing URLs
                $missing_products = get_posts(
                    array(
                        'post_type'      => 'product',
                        'posts_per_page' => 100, // Limit to 100 for display
                        'post_status'    => 'any',
                        'meta_query'     => array(
                            'relation' => 'OR',
                            array(
                                'key'     => '_etsy_listing_url',
                                'compare' => 'NOT EXISTS',
                            ),
                            array(
                                'key'   => '_etsy_listing_url',
                                'value' => '',
                            ),
                        ),
                    )
                );
                ?>

                <div id="missing-products-list" style="display: none; margin: 10px 0; max-height: 300px; overflow-y: auto; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; padding: 10px;">
                    <table class="widefat striped" style="margin: 0;">
                        <thead>
                            <tr>
                                <th style="width: 50%;"><?php esc_html_e( 'Product Title', 'etsy-woocommerce-ai-importer' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'etsy-woocommerce-ai-importer' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $missing_products as $product ) : ?>
                            <tr>
                                <td><code style="font-size: 11px;"><?php echo esc_html( $product->post_title ); ?></code></td>
                                <td>
                                    <a href="<?php echo esc_url( get_edit_post_link( $product->ID ) ); ?>" class="button button-small" target="_blank">
                                        <?php esc_html_e( 'Edit', 'etsy-woocommerce-ai-importer' ); ?>
                                    </a>
                                    <a href="https://www.etsy.com/search?q=<?php echo esc_attr( urlencode( $product->post_title ) ); ?>" class="button button-small" target="_blank">
                                        <?php esc_html_e( 'Find on Etsy', 'etsy-woocommerce-ai-importer' ); ?>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ( $missing_count > 100 ) : ?>
                    <p class="description" style="margin-top: 10px;">
                        <?php
                        printf(
                            /* translators: %d: remaining products */
                            esc_html__( 'Showing first 100 products. %d more not shown.', 'etsy-woocommerce-ai-importer' ),
                            $missing_count - 100
                        );
                        ?>
                    </p>
                    <?php endif; ?>
                </div>

                <?php else : ?>
                <div class="notice notice-success inline" style="margin: 10px 0;">
                    <p>
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e( 'All products have Etsy URLs configured!', 'etsy-woocommerce-ai-importer' ); ?>
                    </p>
                </div>
                <?php endif; ?>

                <form id="etsy-bulk-url-form">
                    <div class="form-field">
                        <label for="etsy-shop-name"><strong><?php esc_html_e( 'Your Etsy Shop Name:', 'etsy-woocommerce-ai-importer' ); ?></strong></label>
                        <input type="text" id="etsy-shop-name" name="etsy_shop_name" value="<?php echo esc_attr( get_option( 'etsy_importer_shop_name', '' ) ); ?>" class="regular-text" placeholder="YourShopName" />
                        <p class="description"><?php esc_html_e( 'Save your shop name to make it easier to build URLs manually.', 'etsy-woocommerce-ai-importer' ); ?></p>
                    </div>

                    <div class="form-field">
                        <label for="etsy-url-csv"><strong><?php esc_html_e( 'Upload URL Mapping CSV:', 'etsy-woocommerce-ai-importer' ); ?></strong></label>
                        <input type="file" id="etsy-url-csv" name="url_csv_file" accept=".csv" />
                        <p class="description">
                            <?php esc_html_e( 'CSV format: Two columns - "TITLE" (or product name) and "URL" (or "LISTING_ID"). The importer will match by title.', 'etsy-woocommerce-ai-importer' ); ?>
                            <br>
                            <?php esc_html_e( 'Example: Export your full listing data from Etsy Shop Manager → Settings → Options → Download Data', 'etsy-woocommerce-ai-importer' ); ?>
                        </p>
                    </div>

                    <button type="submit" class="button button-secondary">
                        <?php esc_html_e( 'Update Etsy URLs', 'etsy-woocommerce-ai-importer' ); ?>
                    </button>
                    <span id="bulk-url-status" style="margin-left: 10px;"></span>
                </form>

                <div id="bulk-url-results" style="display: none; margin-top: 15px;">
                    <div class="results-summary"></div>
                </div>
            </div>

            <div class="etsy-importer-card">
                <h2><?php esc_html_e( 'Field Mapping', 'etsy-woocommerce-ai-importer' ); ?></h2>
                <p><?php esc_html_e( 'The importer automatically maps Etsy fields to WooCommerce:', 'etsy-woocommerce-ai-importer' ); ?></p>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Etsy Field', 'etsy-woocommerce-ai-importer' ); ?></th>
                            <th><?php esc_html_e( 'WooCommerce Field', 'etsy-woocommerce-ai-importer' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td><?php esc_html_e( 'TITLE', 'etsy-woocommerce-ai-importer' ); ?></td><td><?php esc_html_e( 'Product Name', 'etsy-woocommerce-ai-importer' ); ?></td></tr>
                        <tr><td><?php esc_html_e( 'DESCRIPTION', 'etsy-woocommerce-ai-importer' ); ?></td><td><?php esc_html_e( 'Product Description', 'etsy-woocommerce-ai-importer' ); ?></td></tr>
                        <tr><td><?php esc_html_e( 'PRICE', 'etsy-woocommerce-ai-importer' ); ?></td><td><?php esc_html_e( 'Regular Price', 'etsy-woocommerce-ai-importer' ); ?></td></tr>
                        <tr><td><?php esc_html_e( 'TAGS', 'etsy-woocommerce-ai-importer' ); ?></td><td><?php esc_html_e( 'Product Tags', 'etsy-woocommerce-ai-importer' ); ?></td></tr>
                        <tr><td><?php esc_html_e( 'IMAGE1-10', 'etsy-woocommerce-ai-importer' ); ?></td><td><?php esc_html_e( 'Product Gallery Images', 'etsy-woocommerce-ai-importer' ); ?></td></tr>
                        <tr><td><?php esc_html_e( 'SECTION or TAGS', 'etsy-woocommerce-ai-importer' ); ?></td><td><?php esc_html_e( 'Product Categories (first tag used if no SECTION)', 'etsy-woocommerce-ai-importer' ); ?></td></tr>
                        <tr><td><?php esc_html_e( 'SKU', 'etsy-woocommerce-ai-importer' ); ?></td><td><?php esc_html_e( 'SKU', 'etsy-woocommerce-ai-importer' ); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    public function ajax_import_products() {
        check_ajax_referer( 'etsy_import_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Permission denied', 'etsy-woocommerce-ai-importer' ) ) );
        }

        if ( ! isset( $_FILES['csv_file'] ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'No file uploaded', 'etsy-woocommerce-ai-importer' ) ) );
        }

        $file = $_FILES['csv_file'];

        if ( UPLOAD_ERR_OK !== $file['error'] ) {
            wp_send_json_error( array( 'message' => esc_html__( 'File upload error', 'etsy-woocommerce-ai-importer' ) ) );
        }

        // Save file to temp location for streaming import.
        $upload_dir = wp_upload_dir();
        $temp_dir   = $upload_dir['basedir'] . '/etsy-imports';
        if ( ! file_exists( $temp_dir ) ) {
            wp_mkdir_p( $temp_dir );
        }

        $temp_file = $temp_dir . '/import-' . uniqid() . '.csv';

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( ! @move_uploaded_file( $file['tmp_name'], $temp_file ) ) {
            // Try copy as fallback (some server configurations).
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
            if ( ! copy( $file['tmp_name'], $temp_file ) ) {
                wp_send_json_error( array( 'message' => esc_html__( 'Could not save uploaded file. Check folder permissions.', 'etsy-woocommerce-ai-importer' ) ) );
            }
        }

        // Verify file was saved and is readable.
        if ( ! file_exists( $temp_file ) || ! is_readable( $temp_file ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'File was not saved correctly. Please try again.', 'etsy-woocommerce-ai-importer' ) ) );
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log( 'Etsy Importer: File saved to ' . $temp_file . ' - size: ' . filesize( $temp_file ) );

        $options = array(
            'import_images'       => isset( $_POST['import_images'] ) && '1' === $_POST['import_images'],
            'mark_digital'        => isset( $_POST['mark_digital'] ) && '1' === $_POST['mark_digital'],
            'draft_status'        => isset( $_POST['draft_status'] ) && '1' === $_POST['draft_status'],
            'import_categories'   => isset( $_POST['import_categories'] ) && '1' === $_POST['import_categories'],
            'skip_ai_categorized' => isset( $_POST['skip_ai_categorized'] ) && '1' === $_POST['skip_ai_categorized'],
            'create_categories'   => false,
            'default_category'    => isset( $_POST['default_category'] ) ? intval( $_POST['default_category'] ) : 0,
        );

        // Store import session for streaming.
        $import_id = uniqid( 'import_' );
        set_transient( 'etsy_import_' . $import_id, array(
            'file'    => $temp_file,
            'options' => $options,
            'status'  => 'pending',
        ), HOUR_IN_SECONDS );

        wp_send_json_success( array(
            'import_id'  => $import_id,
            'stream_url' => admin_url( 'admin-ajax.php' ) . '?action=etsy_import_stream&import_id=' . $import_id . '&nonce=' . wp_create_nonce( 'etsy_import_nonce' ),
        ) );
    }

    /**
     * Stream import progress using Server-Sent Events (SSE).
     */
    public function ajax_import_stream() {
        // Verify nonce via GET parameter.
        if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ) ), 'etsy_import_nonce' ) ) {
            http_response_code( 403 );
            exit( 'Unauthorized' );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            http_response_code( 403 );
            exit( 'Permission denied' );
        }

        $import_id = isset( $_GET['import_id'] ) ? sanitize_text_field( wp_unslash( $_GET['import_id'] ) ) : '';
        if ( empty( $import_id ) ) {
            http_response_code( 400 );
            exit( 'Missing import ID' );
        }

        $import_data = get_transient( 'etsy_import_' . $import_id );
        if ( ! $import_data ) {
            http_response_code( 404 );
            exit( 'Import session not found' );
        }

        // Set up SSE headers.
        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache' );
        header( 'Connection: keep-alive' );
        header( 'X-Accel-Buffering: no' );

        // Disable output buffering.
        if ( ob_get_level() ) {
            ob_end_clean();
        }

        // Increase time limit for large imports.
        set_time_limit( 0 );

        // Verify file exists before trying to import.
        if ( ! file_exists( $import_data['file'] ) ) {
            $this->sse_send( 'error', array( 'message' => 'CSV file not found. It may have been deleted or moved.' ) );
            $this->sse_send( 'complete', array( 'imported' => 0, 'errors' => array( 'File not found' ) ) );
            exit;
        }

        $this->stream_import( $import_data['file'], $import_data['options'] );

        // Clean up.
        if ( file_exists( $import_data['file'] ) ) {
            wp_delete_file( $import_data['file'] );
        }
        delete_transient( 'etsy_import_' . $import_id );

        exit;
    }

    /**
     * Send an SSE event to the client.
     *
     * @param string $event Event name.
     * @param array  $data  Event data.
     */
    private function sse_send( $event, $data ) {
        echo "event: {$event}\n";
        echo 'data: ' . wp_json_encode( $data ) . "\n\n";
        flush();
    }

    /**
     * Calculate optimal batch size based on total products.
     *
     * Using small batches (2) to avoid API timeouts with Hugging Face free tier.
     *
     * @param int $total_products Total number of products.
     * @return int Batch size.
     */
    private function calculate_batch_size( $total_products ) {
        // Keep batches very small (2) to avoid timeouts - the free tier can be slow.
        return 2;
    }

    /**
     * Stream the CSV import with real-time progress.
     *
     * @param string $file_path Path to the CSV file.
     * @param array  $options   Import options.
     */
    private function stream_import( $file_path, $options ) {
        $start_time = microtime( true );

        $results = array(
            'imported'           => 0,
            'updated'            => 0,
            'skipped'            => 0,
            'errors'             => array(),
            'images_queued'      => 0,
            'categories_created' => 0,
        );

        // Track categories before import.
        $categories_before = wp_count_terms( array( 'taxonomy' => 'product_cat' ) );

        // Log the file path for debugging.
        $this->sse_send( 'log', array( 'type' => 'info', 'message' => 'Opening file: ' . basename( $file_path ) ) );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( 'Etsy Importer: Could not open file: ' . $file_path . ' - exists: ' . ( file_exists( $file_path ) ? 'yes' : 'no' ) );
            $this->sse_send( 'error', array( 'message' => 'Could not open CSV file. File may be corrupted or unreadable.' ) );
            $this->sse_send( 'complete', $results );
            return;
        }

        // Read header row.
        $headers = fgetcsv( $handle );
        if ( ! $headers ) {
            $this->sse_send( 'error', array( 'message' => 'Could not read CSV headers' ) );
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            $this->sse_send( 'complete', $results );
            return;
        }

        // Normalize headers.
        $headers = array_map(
            function ( $h ) {
                return strtoupper( trim( $h ) );
            },
            $headers
        );

        $header_map = array_flip( $headers );

        $this->sse_send( 'log', array( 'type' => 'info', 'message' => 'CSV headers found: ' . implode( ', ', $headers ) ) );

        if ( ! isset( $header_map['TITLE'] ) ) {
            $this->sse_send( 'error', array( 'message' => 'CSV must contain a TITLE column' ) );
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            $this->sse_send( 'complete', $results );
            return;
        }

        // Read all rows into memory for batch processing.
        $all_rows = array();
        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            $all_rows[] = $row;
        }
        fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        $total_rows = count( $all_rows );
        $this->sse_send( 'log', array( 'type' => 'info', 'message' => "Found {$total_rows} products to process" ) );

        // Check if AI is enabled for batch processing.
        $use_ai    = get_option( 'etsy_importer_use_ai', false );
        $api_token = get_option( 'etsy_importer_hf_api_token', '' );
        $ai_enabled = $use_ai && ! empty( $api_token );

        if ( $ai_enabled ) {
            $batch_size   = $this->calculate_batch_size( $total_rows );
            $total_batches = ceil( $total_rows / $batch_size );
            $this->sse_send(
                'log',
                array(
                    'type'    => 'ai',
                    'message' => "AI categorization enabled: Processing in {$total_batches} batches of ~{$batch_size} products",
                )
            );
            $this->sse_send(
                'batch_info',
                array(
                    'enabled'       => true,
                    'batch_size'    => $batch_size,
                    'total_batches' => $total_batches,
                )
            );
        }

        $this->sse_send( 'progress', array( 'current' => 0, 'total' => $total_rows, 'percent' => 0 ) );

        // Pre-parse all rows to extract basic data without AI (for batching).
        $parsed_products = array();
        foreach ( $all_rows as $index => $row ) {
            $parsed_products[ $index ] = $this->parse_row_basic( $row, $header_map );
        }

        // Batch AI categorization if enabled.
        $ai_categories = array();
        if ( $ai_enabled ) {
            $ai_categories = $this->batch_ai_categorize( $parsed_products, $total_rows, $options );
        }

        // Now process each product.
        $row_num = 0;
        foreach ( $all_rows as $index => $row ) {
            $row_num++;

            try {
                // Get basic parsed data.
                $product_data = $parsed_products[ $index ];

                // Apply AI category if available, otherwise do keyword matching.
                $product_data['ai_categorized'] = false;
                if ( isset( $ai_categories[ $index ] ) && ! empty( $ai_categories[ $index ]['category'] ) ) {
                    $product_data['taxonomy_path']  = $ai_categories[ $index ]['category'];
                    $product_data['category_log']   = $ai_categories[ $index ]['log'];
                    $product_data['ai_categorized'] = ! isset( $ai_categories[ $index ]['skipped'] ); // New AI assignment.
                } elseif ( empty( $product_data['taxonomy_path'] ) ) {
                    // Fallback to keyword matching (no AI).
                    $category_result               = $this->match_category_keywords( $product_data['tags'], $product_data['title'] );
                    $product_data['taxonomy_path'] = $category_result['category'];
                    $product_data['category_log']  = $category_result['log'];
                }

                if ( empty( $product_data['title'] ) ) {
                    $results['skipped']++;
                    $this->sse_send( 'log', array( 'type' => 'warning', 'message' => "Row {$row_num}: Skipped (empty title)" ) );
                    continue;
                }

                $this->sse_send( 'log', array( 'type' => 'info', 'message' => "Processing: {$product_data['title']}" ) );

                // Send category matching logs.
                if ( ! empty( $product_data['category_log'] ) ) {
                    foreach ( $product_data['category_log'] as $log_entry ) {
                        $this->sse_send( 'log', $log_entry );
                    }
                }

                $import_result = $this->create_product( $product_data, $options );

                if ( $import_result['product_id'] ) {
                    $results['imported']++;
                    $results['images_queued'] += $import_result['images_queued'];
                    $this->sse_send( 'log', array( 'type' => 'success', 'message' => "Created product: {$product_data['title']}" ) );
                    if ( $import_result['images_queued'] > 0 ) {
                        $this->sse_send( 'log', array( 'type' => 'info', 'message' => "  Queued {$import_result['images_queued']} images for background import" ) );
                    }
                } elseif ( $import_result['updated_existing'] ) {
                    $results['updated']++;
                    $this->sse_send( 'log', array( 'type' => 'info', 'message' => "Updated existing product: {$product_data['title']}" ) );
                } else {
                    $results['skipped']++;
                    $this->sse_send( 'log', array( 'type' => 'warning', 'message' => "Skipped: {$product_data['title']}" ) );
                }

                // Send progress update.
                $percent = round( ( $row_num / $total_rows ) * 100 );
                $this->sse_send( 'progress', array( 'current' => $row_num, 'total' => $total_rows, 'percent' => $percent ) );

            } catch ( Exception $e ) {
                $results['errors'][] = "Row {$row_num}: " . $e->getMessage();
                $results['skipped']++;
                $this->sse_send( 'log', array( 'type' => 'error', 'message' => "Row {$row_num}: " . $e->getMessage() ) );
            }
        }

        // Count new categories.
        $categories_after            = wp_count_terms( array( 'taxonomy' => 'product_cat' ) );
        $results['categories_created'] = max( 0, $categories_after - $categories_before );

        // Calculate duration.
        $end_time     = microtime( true );
        $duration_sec = round( $end_time - $start_time, 1 );
        if ( $duration_sec >= 60 ) {
            $minutes      = floor( $duration_sec / 60 );
            $seconds      = round( $duration_sec - ( $minutes * 60 ) );
            $duration_str = "{$minutes}m {$seconds}s";
        } else {
            $duration_str = "{$duration_sec}s";
        }

        $this->sse_send( 'log', array( 'type' => 'success', 'message' => "Import completed in {$duration_str}!" ) );
        $this->sse_send( 'log', array( 'type' => 'info', 'message' => "Imported: {$results['imported']}, Updated: {$results['updated']}, Skipped: {$results['skipped']}" ) );

        if ( $results['categories_created'] > 0 ) {
            $this->sse_send( 'log', array( 'type' => 'success', 'message' => "Created {$results['categories_created']} new categories" ) );
        }

        if ( $results['images_queued'] > 0 ) {
            $this->sse_send( 'log', array( 'type' => 'info', 'message' => "{$results['images_queued']} images queued for background processing" ) );
        }

        $results['duration'] = $duration_str;
        $this->sse_send( 'complete', $results );
    }

    /**
     * Process CSV file and import products.
     *
     * @param string $file_path Path to the CSV file.
     * @param array  $options   Import options.
     * @return array Import results.
     */
    private function process_csv( $file_path, $options ) {
        $results = array(
            'imported'           => 0,
            'updated'            => 0,
            'skipped'            => 0,
            'errors'             => array(),
            'products'           => array(),
            'images_queued'      => 0,
            'categories_created' => 0,
            'logs'               => array(), // Detailed per-product logs.
        );

        // Track categories before import to count new ones.
        $categories_before = wp_count_terms( array( 'taxonomy' => 'product_cat' ) );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            $results['errors'][] = 'Could not open CSV file';
            return $results;
        }

        // Read header row.
        $headers = fgetcsv( $handle );
        if ( ! $headers ) {
            $results['errors'][] = 'Could not read CSV headers';
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            return $results;
        }

        // Normalize headers (Etsy uses various formats).
        $headers = array_map(
            function ( $h ) {
                return strtoupper( trim( $h ) );
            },
            $headers
        );

        $header_map = array_flip( $headers );

        // Log available headers for debugging.
        $results['debug_headers'] = $headers;

        // Required fields check.
        if ( ! isset( $header_map['TITLE'] ) ) {
            $results['errors'][] = 'CSV must contain a TITLE column';
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            return $results;
        }

        $row_num = 1;
        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            $row_num++;

            try {
                $product_data = $this->parse_row( $row, $header_map, $options );

                if ( empty( $product_data['title'] ) ) {
                    $results['skipped']++;
                    continue;
                }

                // Add product processing log entry.
                $product_log = array(
                    'title'   => $product_data['title'],
                    'entries' => array(),
                );

                // Add category matching logs.
                if ( ! empty( $product_data['category_log'] ) ) {
                    foreach ( $product_data['category_log'] as $log_entry ) {
                        $product_log['entries'][] = $log_entry;
                    }
                }

                $import_result = $this->create_product( $product_data, $options );

                if ( $import_result['product_id'] ) {
                    $results['imported']++;
                    $results['images_queued'] += $import_result['images_queued'];
                    $results['products'][]     = array(
                        'id'    => $import_result['product_id'],
                        'title' => $product_data['title'],
                    );
                    $product_log['entries'][] = array( 'type' => 'success', 'message' => 'Product created successfully' );
                } elseif ( $import_result['updated_existing'] ) {
                    $results['updated']++;
                    $product_log['entries'][] = array( 'type' => 'info', 'message' => 'Existing product updated with new category' );
                } else {
                    $results['skipped']++;
                    $product_log['entries'][] = array( 'type' => 'warning', 'message' => 'Product skipped' );
                }

                $results['logs'][] = $product_log;

            } catch ( Exception $e ) {
                $results['errors'][] = "Row {$row_num}: " . $e->getMessage();
                $results['skipped']++;
            }
        }

        fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        // Count new categories created.
        $categories_after            = wp_count_terms( array( 'taxonomy' => 'product_cat' ) );
        $results['categories_created'] = max( 0, $categories_after - $categories_before );

        return $results;
    }

    /**
     * Parse a CSV row into product data.
     *
     * @param array $row        CSV row data.
     * @param array $header_map Header name to index map.
     * @param array $options    Import options.
     * @return array Parsed product data.
     */
    private function parse_row( $row, $header_map, $options ) {
        $get_field = function ( $name ) use ( $row, $header_map ) {
            $key = strtoupper( $name );
            if ( isset( $header_map[ $key ] ) && isset( $row[ $header_map[ $key ] ] ) ) {
                return trim( $row[ $header_map[ $key ] ] );
            }
            return '';
        };

        // Parse tags first as we'll use them for category matching.
        $tags  = $this->parse_tags( $get_field( 'TAGS' ) );
        $title = $get_field( 'TITLE' );

        // Try to find matching category from tags or title.
        $category_result  = $this->match_category_from_content( $tags, $title );
        $matched_category = $category_result['category'] ?? '';
        $category_log     = $category_result['log'] ?? array();

        // Build Etsy listing URL from LISTING_ID.
        $listing_id = $get_field( 'LISTING_ID' );
        $etsy_url   = '';
        if ( ! empty( $listing_id ) ) {
            $etsy_url = 'https://www.etsy.com/listing/' . $listing_id;
        }

        $data = array(
            'title'         => $title,
            'description'   => $get_field( 'DESCRIPTION' ),
            'price'         => $this->parse_price( $get_field( 'PRICE' ) ),
            'sku'           => $get_field( 'SKU' ),
            'tags'          => $tags,
            'images'        => array(),
            // Use matched category, or SECTION field if available.
            'taxonomy_path' => $get_field( 'SECTION' ) ? $get_field( 'SECTION' ) : $matched_category,
            'quantity'      => $get_field( 'QUANTITY' ),
            'category_log'  => $category_log, // Log of category matching process.
            'etsy_url'      => $etsy_url,
        );

        // Collect all image URLs (Etsy exports IMAGE1 through IMAGE10).
        for ( $i = 1; $i <= 10; $i++ ) {
            $image_url = $get_field( "IMAGE{$i}" );
            if ( ! empty( $image_url ) && filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
                $data['images'][] = $image_url;
            }
        }

        // Also check for PHOTOS field (some Etsy exports use this).
        $photos = $get_field( 'PHOTOS' );
        if ( ! empty( $photos ) ) {
            $photo_urls = explode( ',', $photos );
            foreach ( $photo_urls as $url ) {
                $url = trim( $url );
                if ( ! empty( $url ) && filter_var( $url, FILTER_VALIDATE_URL ) ) {
                    $data['images'][] = $url;
                }
            }
        }

        return $data;
    }

    /**
     * Parse a CSV row into basic product data (without AI categorization).
     *
     * This is used for batch processing - AI categorization is done separately in batches.
     *
     * @param array $row        CSV row data.
     * @param array $header_map Header name to index map.
     * @return array Parsed product data.
     */
    private function parse_row_basic( $row, $header_map ) {
        $get_field = function ( $name ) use ( $row, $header_map ) {
            $key = strtoupper( $name );
            if ( isset( $header_map[ $key ] ) && isset( $row[ $header_map[ $key ] ] ) ) {
                return trim( $row[ $header_map[ $key ] ] );
            }
            return '';
        };

        // Parse tags - will be used for AI categorization.
        $tags  = $this->parse_tags( $get_field( 'TAGS' ) );
        $title = $get_field( 'TITLE' );

        // Build Etsy listing URL from LISTING_ID.
        $listing_id = $get_field( 'LISTING_ID' );
        $etsy_url   = '';
        if ( ! empty( $listing_id ) ) {
            $etsy_url = 'https://www.etsy.com/listing/' . $listing_id;
        }

        $data = array(
            'title'         => $title,
            'description'   => $get_field( 'DESCRIPTION' ),
            'price'         => $this->parse_price( $get_field( 'PRICE' ) ),
            'sku'           => $get_field( 'SKU' ),
            'tags'          => $tags,
            'images'        => array(),
            // Use SECTION field if available, otherwise leave empty for AI/keyword matching.
            'taxonomy_path' => $get_field( 'SECTION' ),
            'quantity'      => $get_field( 'QUANTITY' ),
            'category_log'  => array(),
            'etsy_url'      => $etsy_url,
        );

        // Collect all image URLs (Etsy exports IMAGE1 through IMAGE10).
        for ( $i = 1; $i <= 10; $i++ ) {
            $image_url = $get_field( "IMAGE{$i}" );
            if ( ! empty( $image_url ) && filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
                $data['images'][] = $image_url;
            }
        }

        // Also check for PHOTOS field (some Etsy exports use this).
        $photos = $get_field( 'PHOTOS' );
        if ( ! empty( $photos ) ) {
            $photo_urls = explode( ',', $photos );
            foreach ( $photo_urls as $url ) {
                $url = trim( $url );
                if ( ! empty( $url ) && filter_var( $url, FILTER_VALIDATE_URL ) ) {
                    $data['images'][] = $url;
                }
            }
        }

        return $data;
    }

    /**
     * Batch AI categorization for multiple products.
     *
     * Processes products in batches to reduce API calls and show batch progress.
     *
     * @param array $parsed_products Array of parsed product data.
     * @param int   $total_products  Total number of products.
     * @param array $options         Import options.
     * @return array Associative array of index => category result.
     */
    private function batch_ai_categorize( $parsed_products, $total_products, $options = array() ) {
        $api_token           = get_option( 'etsy_importer_hf_api_token', '' );
        $skip_ai_categorized = ! empty( $options['skip_ai_categorized'] );
        $results             = array();

        if ( empty( $api_token ) ) {
            return $results;
        }

        // Get all existing product categories.
        $categories = get_terms(
            array(
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
            )
        );

        if ( empty( $categories ) || is_wp_error( $categories ) ) {
            $this->sse_send( 'log', array( 'type' => 'warning', 'message' => 'No WooCommerce categories found for AI matching' ) );
            return $results;
        }

        // Build list of category names (excluding uncategorized).
        $category_names = array();
        foreach ( $categories as $cat ) {
            if ( 'uncategorized' !== $cat->slug ) {
                $category_names[] = $cat->name;
            }
        }

        if ( empty( $category_names ) ) {
            return $results;
        }

        $batch_size    = $this->calculate_batch_size( $total_products );
        $total_batches = ceil( $total_products / $batch_size );
        $batch_num     = 0;

        // Warm up the AI model before processing to avoid cold-start timeouts.
        $this->warmup_ai_model( $api_token, $category_names );

        // Process products in batches.
        foreach ( array_chunk( $parsed_products, $batch_size, true ) as $batch ) {
            $batch_num++;
            $this->sse_send(
                'log',
                array(
                    'type'    => 'ai',
                    'message' => "AI Batch {$batch_num}/{$total_batches}: Categorizing " . count( $batch ) . ' products...',
                )
            );
            $this->sse_send(
                'batch_progress',
                array(
                    'current_batch' => $batch_num,
                    'total_batches' => $total_batches,
                    'batch_size'    => count( $batch ),
                )
            );

            foreach ( $batch as $index => $product ) {
                // Skip if already has a category from SECTION field.
                if ( ! empty( $product['taxonomy_path'] ) ) {
                    continue;
                }

                // Check if product already exists and was AI-categorized (skip option).
                if ( $skip_ai_categorized ) {
                    $existing_product_id = $this->find_existing_product( $product['sku'], $product['title'] );
                    if ( $existing_product_id ) {
                        $ai_categorized = get_post_meta( $existing_product_id, '_etsy_ai_categorized', true );
                        if ( $ai_categorized ) {
                            $this->sse_send(
                                'log',
                                array(
                                    'type'    => 'info',
                                    'message' => "Skipping AI for '{$product['title']}' - already categorized by AI",
                                )
                            );
                            // Mark as skipped with existing category info.
                            $existing_cats = wp_get_object_terms( $existing_product_id, 'product_cat', array( 'fields' => 'names' ) );
                            if ( ! empty( $existing_cats ) && ! is_wp_error( $existing_cats ) ) {
                                $results[ $index ] = array(
                                    'category' => $existing_cats[0],
                                    'score'    => 1,
                                    'log'      => array(
                                        array(
                                            'type'    => 'info',
                                            'message' => 'Using existing AI category: ' . $existing_cats[0],
                                        ),
                                    ),
                                    'skipped'  => true,
                                );
                            }
                            continue;
                        }
                    }
                }

                // Extract words from tags for better context.
                $tag_words = array();
                foreach ( $product['tags'] as $tag ) {
                    $parts     = preg_split( '/[_\-\s]+/', $tag );
                    $tag_words = array_merge( $tag_words, $parts );
                }
                $tag_words = array_unique( array_filter( $tag_words ) );

                // Build product description for AI.
                $product_text = 'Classify this product: ' . $product['title'] . ' (' . implode( ', ', $tag_words ) . ')';

                // Make API call with retry logic.
                $max_retries = 2;
                $retry_count = 0;
                $response    = null;
                $body        = '';
                $status_code = 0;

                while ( $retry_count <= $max_retries ) {
                    $response = wp_remote_post(
                        'https://router.huggingface.co/hf-inference/models/facebook/bart-large-mnli',
                        array(
                            'timeout' => 60, // Increased timeout for cold starts.
                            'headers' => array(
                                'Authorization' => 'Bearer ' . $api_token,
                                'Content-Type'  => 'application/json',
                            ),
                            'body'    => wp_json_encode(
                                array(
                                    'inputs'     => $product_text,
                                    'parameters' => array(
                                        'candidate_labels' => $category_names,
                                        'multi_label'      => false,
                                    ),
                                )
                            ),
                        )
                    );

                    if ( is_wp_error( $response ) ) {
                        $retry_count++;
                        if ( $retry_count <= $max_retries ) {
                            $this->sse_send(
                                'log',
                                array(
                                    'type'    => 'warning',
                                    'message' => "AI timeout for '{$product['title']}', retrying ({$retry_count}/{$max_retries})...",
                                )
                            );
                            sleep( 2 ); // Wait before retry.
                            continue;
                        }
                        $this->sse_send(
                            'log',
                            array(
                                'type'    => 'warning',
                                'message' => "AI failed for '{$product['title']}' after {$max_retries} retries - using keyword matching",
                            )
                        );
                        break;
                    }

                    $status_code = wp_remote_retrieve_response_code( $response );
                    $body        = wp_remote_retrieve_body( $response );

                    // Check for 503 (model loading) - retry.
                    if ( 503 === $status_code ) {
                        $retry_count++;
                        if ( $retry_count <= $max_retries ) {
                            $this->sse_send(
                                'log',
                                array(
                                    'type'    => 'info',
                                    'message' => 'AI model warming up, waiting 5 seconds...',
                                )
                            );
                            sleep( 5 );
                            continue;
                        }
                    }

                    break; // Success or non-retryable error.
                }

                if ( is_wp_error( $response ) ) {
                    continue; // Already logged above.
                }

                if ( 200 !== $status_code ) {
                    $this->sse_send(
                        'log',
                        array(
                            'type'    => 'warning',
                            'message' => "AI API error ({$status_code}) for '{$product['title']}'",
                        )
                    );
                    continue;
                }

                $result = json_decode( $body, true );

                if ( ! $result || ! is_array( $result ) || empty( $result[0] ) ) {
                    continue;
                }

                $best_label = $result[0]['label'] ?? '';
                $best_score = $result[0]['score'] ?? 0;

                // Build all scores for logging.
                $all_scores = array();
                foreach ( array_slice( $result, 0, 3 ) as $item ) {
                    $all_scores[] = $item['label'] . ': ' . round( $item['score'] * 100 ) . '%';
                }

                if ( $best_score >= 0.2 ) {
                    $results[ $index ] = array(
                        'category' => $best_label,
                        'score'    => $best_score,
                        'log'      => array(
                            array(
                                'type'    => 'ai',
                                'message' => 'AI analyzed product tags and title',
                            ),
                            array(
                                'type'    => 'ai',
                                'message' => 'AI scores: ' . implode( ', ', $all_scores ),
                            ),
                            array(
                                'type'    => 'success',
                                'message' => 'Selected category: ' . $best_label . ' (' . round( $best_score * 100 ) . '% confidence)',
                            ),
                        ),
                    );
                } else {
                    // Log low confidence for this product.
                    $results[ $index ] = array(
                        'category' => '',
                        'score'    => 0,
                        'log'      => array(
                            array(
                                'type'    => 'ai',
                                'message' => 'AI scores too low: ' . implode( ', ', $all_scores ),
                            ),
                        ),
                    );
                }

                // Delay between API calls to avoid rate limiting and timeouts.
                usleep( 500000 ); // 500ms between each product.
            }

            $this->sse_send(
                'log',
                array(
                    'type'    => 'success',
                    'message' => "AI Batch {$batch_num}/{$total_batches} complete",
                )
            );

            // Small pause between batches to let the API recover.
            if ( $batch_num < $total_batches ) {
                sleep( 1 );
            }
        }

        return $results;
    }

    /**
     * Warm up the AI model before batch processing.
     *
     * Sends a simple test request to load the model into memory,
     * waiting for it to be ready before actual batch processing begins.
     *
     * @param string $api_token      Hugging Face API token.
     * @param array  $category_names Array of category names for the test.
     */
    private function warmup_ai_model( $api_token, $category_names ) {
        $this->sse_send(
            'log',
            array(
                'type'    => 'ai',
                'message' => 'Warming up AI model (this may take up to 30 seconds on first use)...',
            )
        );

        // Use a minimal test input to warm up the model.
        $test_input = 'Classify this product: Test Product (printable, digital)';

        // Only use first 3 categories for warmup to reduce payload size.
        $test_categories = array_slice( $category_names, 0, 3 );
        if ( empty( $test_categories ) ) {
            $test_categories = array( 'General' );
        }

        $max_attempts = 5;
        $attempt      = 0;
        $model_ready  = false;

        while ( $attempt < $max_attempts && ! $model_ready ) {
            $attempt++;

            $response = wp_remote_post(
                'https://router.huggingface.co/hf-inference/models/facebook/bart-large-mnli',
                array(
                    'timeout' => 90, // Longer timeout for cold start warmup.
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $api_token,
                        'Content-Type'  => 'application/json',
                    ),
                    'body'    => wp_json_encode(
                        array(
                            'inputs'     => $test_input,
                            'parameters' => array(
                                'candidate_labels' => $test_categories,
                                'multi_label'      => false,
                            ),
                        )
                    ),
                )
            );

            if ( is_wp_error( $response ) ) {
                $error_message = $response->get_error_message();
                $this->sse_send(
                    'log',
                    array(
                        'type'    => 'warning',
                        'message' => "Warmup attempt {$attempt}/{$max_attempts}: {$error_message}",
                    )
                );
                sleep( 3 );
                continue;
            }

            $status_code = wp_remote_retrieve_response_code( $response );
            $body        = wp_remote_retrieve_body( $response );

            if ( 200 === $status_code ) {
                $model_ready = true;
                $this->sse_send(
                    'log',
                    array(
                        'type'    => 'success',
                        'message' => 'AI model is ready!',
                    )
                );
            } elseif ( 503 === $status_code ) {
                // Model is loading - this is expected during cold start.
                $response_data    = json_decode( $body, true );
                $estimated_time   = isset( $response_data['estimated_time'] ) ? $response_data['estimated_time'] : 20;
                $wait_time        = min( (int) $estimated_time, 30 ); // Cap at 30 seconds.

                $this->sse_send(
                    'log',
                    array(
                        'type'    => 'info',
                        'message' => "AI model is loading (attempt {$attempt}/{$max_attempts}), waiting {$wait_time} seconds...",
                    )
                );

                sleep( $wait_time );
            } else {
                // Other error - log and retry.
                $this->sse_send(
                    'log',
                    array(
                        'type'    => 'warning',
                        'message' => "Warmup attempt {$attempt}/{$max_attempts}: HTTP {$status_code}",
                    )
                );
                sleep( 3 );
            }
        }

        if ( ! $model_ready ) {
            $this->sse_send(
                'log',
                array(
                    'type'    => 'warning',
                    'message' => 'AI model warmup incomplete - proceeding anyway (may experience slower responses)',
                )
            );
        }
    }

    /**
     * Find an existing product by SKU or title.
     *
     * @param string $sku   Product SKU.
     * @param string $title Product title.
     * @return int|null Product ID or null if not found.
     */
    private function find_existing_product( $sku, $title ) {
        // Check by SKU first.
        if ( ! empty( $sku ) ) {
            $existing_id = wc_get_product_id_by_sku( $sku );
            if ( $existing_id ) {
                return $existing_id;
            }
        }

        // Check by title.
        if ( ! empty( $title ) ) {
            $existing_products = get_posts(
                array(
                    'post_type'   => 'product',
                    'title'       => $title,
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
     * Match tags and title to existing categories using keyword matching only (no AI).
     *
     * @param array  $tags  Product tags.
     * @param string $title Product title.
     * @return array Array with category name and log details.
     */
    private function match_category_keywords( $tags, $title ) {
        $log = array();

        // Get all existing product categories.
        $categories = get_terms(
            array(
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
            )
        );

        if ( empty( $categories ) || is_wp_error( $categories ) ) {
            return array( 'category' => '', 'log' => $log );
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
            return array( 'category' => $best_match, 'log' => $log );
        }

        $log[] = array(
            'type'    => 'warning',
            'message' => 'No category match found',
        );
        return array( 'category' => '', 'log' => $log );
    }

    /**
     * Parse price string to float.
     *
     * @param string $price_string Price string from CSV.
     * @return float Parsed price.
     */
    private function parse_price( $price_string ) {
        // Remove currency symbols and normalize.
        $price = preg_replace( '/[^0-9.,]/', '', $price_string );
        $price = str_replace( ',', '.', $price );
        return floatval( $price );
    }

    /**
     * Parse tags string to array.
     *
     * @param string $tags_string Comma-separated tags.
     * @return array Array of tags.
     */
    private function parse_tags( $tags_string ) {
        if ( empty( $tags_string ) ) {
            return array();
        }

        // Etsy uses comma-separated tags.
        $tags = explode( ',', $tags_string );
        return array_map( 'trim', array_filter( $tags ) );
    }

    /**
     * Create a WooCommerce product from parsed data.
     *
     * @param array $data    Product data.
     * @param array $options Import options.
     * @return array Result with product_id, images_queued, and updated_existing.
     */
    private function create_product( $data, $options ) {
        $result = array(
            'product_id'       => null,
            'images_queued'    => 0,
            'updated_existing' => false,
        );

        $existing_product_id = null;

        // Check for existing product by SKU first.
        if ( ! empty( $data['sku'] ) ) {
            $existing_id = wc_get_product_id_by_sku( $data['sku'] );
            if ( $existing_id ) {
                $existing_product_id = $existing_id;
            }
        }

        // Check for existing product by title if not found by SKU.
        if ( ! $existing_product_id ) {
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
                $existing_product_id = $existing_products[0];
            }
        }

        // If product exists, update its categories and return.
        if ( $existing_product_id ) {
            $this->update_product_categories( $existing_product_id, $data, $options );
            $result['updated_existing'] = true;
            return $result;
        }

        $product = new WC_Product_Simple();

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

        // Set categories from Etsy taxonomy or fallback to default.
        $category_ids = array();

        if ( $options['import_categories'] && ! empty( $data['taxonomy_path'] ) ) {
            $category_ids = $this->process_taxonomy_path( $data['taxonomy_path'], $options['create_categories'] );
        }

        // Fallback to default category if no categories were assigned.
        if ( empty( $category_ids ) && $options['default_category'] > 0 ) {
            $category_ids = array( $options['default_category'] );
        }

        if ( ! empty( $category_ids ) ) {
            wp_set_object_terms( $product_id, $category_ids, 'product_cat' );
        }

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

        // Queue all images for background import.
        if ( $options['import_images'] && ! empty( $data['images'] ) ) {
            $this->queue_image_imports( $product_id, $data['images'] );
            $result['images_queued'] = count( $data['images'] );
        }

        $result['product_id'] = $product_id;
        return $result;
    }

    /**
     * Update categories for an existing product.
     *
     * @param int   $product_id Product ID.
     * @param array $data       Product data.
     * @param array $options    Import options.
     */
    private function update_product_categories( $product_id, $data, $options ) {
        $category_ids = array();

        if ( $options['import_categories'] && ! empty( $data['taxonomy_path'] ) ) {
            $category_ids = $this->process_taxonomy_path( $data['taxonomy_path'], $options['create_categories'] );
        }

        // Fallback to default category if no categories were found.
        if ( empty( $category_ids ) && $options['default_category'] > 0 ) {
            $category_ids = array( $options['default_category'] );
        }

        if ( ! empty( $category_ids ) ) {
            wp_set_object_terms( $product_id, $category_ids, 'product_cat' );
        }

        // Flag if AI was used for categorization.
        if ( ! empty( $data['ai_categorized'] ) ) {
            update_post_meta( $product_id, '_etsy_ai_categorized', '1' );
            update_post_meta( $product_id, '_etsy_ai_categorized_date', current_time( 'mysql' ) );
        }

        // Save Etsy listing URL (update even for existing products).
        if ( ! empty( $data['etsy_url'] ) ) {
            update_post_meta( $product_id, '_etsy_listing_url', esc_url_raw( $data['etsy_url'] ) );
        }
    }

    /**
     * Match tags and title to existing WooCommerce categories.
     *
     * @param array  $tags  Product tags.
     * @param string $title Product title.
     * @return array Array with category name and log details.
     */
    private function match_category_from_content( $tags, $title ) {
        $log = array();

        // Try AI categorization first if enabled.
        $ai_result = $this->ai_categorize_product( $tags, $title, true );

        if ( ! empty( $ai_result['category'] ) ) {
            $log[] = array(
                'type'    => 'ai',
                'message' => 'AI analyzed product tags and title',
            );
            $log[] = array(
                'type'    => 'ai',
                'message' => 'AI scores: ' . implode( ', ', $ai_result['all_scores'] ),
            );
            $log[] = array(
                'type'    => 'success',
                'message' => 'Selected category: ' . $ai_result['category'] . ' (' . round( $ai_result['score'] * 100 ) . '% confidence)',
            );
            return array( 'category' => $ai_result['category'], 'log' => $log );
        }

        // Check if AI was attempted but no good match.
        if ( ! empty( $ai_result['all_scores'] ) ) {
            $log[] = array(
                'type'    => 'ai',
                'message' => 'AI scores too low: ' . implode( ', ', $ai_result['all_scores'] ),
            );
        }

        // Fallback to simple keyword-based matching (no AI).
        // Get all existing product categories.
        $categories = get_terms(
            array(
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
            )
        );

        if ( empty( $categories ) || is_wp_error( $categories ) ) {
            return '';
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
                'words' => preg_split( '/[\s&,]+/', $name_lower ), // Split on spaces, &, commas.
            );
        }

        // Extract individual words from tags (e.g., "bridal_games_bundle" -> "bridal", "games", "bundle").
        $tag_words = array();
        foreach ( $tags as $tag ) {
            // Split on underscores, hyphens, and spaces.
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
                $score += 10; // High score for exact name match.
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
                    $score += 15; // Exact tag match.
                } elseif ( strpos( $tag_lower, $cat_data['name'] ) !== false || strpos( $cat_data['name'], $tag_lower ) !== false ) {
                    $score += 5; // Partial match.
                }
            }

            if ( $score > $best_score ) {
                $best_score = $score;
                $best_match = $cat_name;
            }
        }

        // Return result with log.
        if ( $best_score >= 2 ) {
            $log[] = array(
                'type'    => 'info',
                'message' => 'Keyword matching found: ' . $best_match . ' (score: ' . $best_score . ')',
            );
            return array( 'category' => $best_match, 'log' => $log );
        }

        $log[] = array(
            'type'    => 'warning',
            'message' => 'No category match found',
        );
        return array( 'category' => '', 'log' => $log );
    }

    /**
     * Use Hugging Face AI to suggest the best category for a product.
     *
     * Uses zero-shot classification to match product to existing categories.
     *
     * @param array  $tags           Product tags.
     * @param string $title          Product title.
     * @param bool   $return_details Whether to return detailed results.
     * @return mixed Array with category name and details, or empty string on failure.
     */
    private function ai_categorize_product( $tags, $title, $return_details = false ) {
        $api_token = get_option( 'etsy_importer_hf_api_token', '' );
        $use_ai    = get_option( 'etsy_importer_use_ai', false );

        if ( empty( $api_token ) || ! $use_ai ) {
            return '';
        }

        // Get all existing product categories.
        $categories = get_terms(
            array(
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
            )
        );

        if ( empty( $categories ) || is_wp_error( $categories ) ) {
            return '';
        }

        // Build list of category names (excluding uncategorized).
        $category_names = array();
        foreach ( $categories as $cat ) {
            if ( 'uncategorized' !== $cat->slug ) {
                $category_names[] = $cat->name;
            }
        }

        if ( empty( $category_names ) ) {
            return '';
        }

        // Extract words from tags for better context.
        $tag_words = array();
        foreach ( $tags as $tag ) {
            $parts     = preg_split( '/[_\-\s]+/', $tag );
            $tag_words = array_merge( $tag_words, $parts );
        }
        $tag_words = array_unique( array_filter( $tag_words ) );

        // Build a rich product description for the AI.
        // "Classify this product:" framing gives best zero-shot classification results.
        $product_text = 'Classify this product: ' . $title . ' (' . implode( ', ', $tag_words ) . ')';

        // Use Hugging Face's zero-shot classification model (via router endpoint).
        $response = wp_remote_post(
            'https://router.huggingface.co/hf-inference/models/facebook/bart-large-mnli',
            array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_token,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode(
                    array(
                        'inputs'     => $product_text,
                        'parameters' => array(
                            'candidate_labels' => $category_names, // Use original names for cleaner matching.
                            'multi_label'      => false,
                        ),
                    )
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( 'Etsy Importer AI: API request failed - ' . $response->get_error_message() );
            return '';
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );

        if ( 200 !== $status_code ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( 'Etsy Importer AI: API returned status ' . $status_code . ' - ' . $body );
            return '';
        }

        $result = json_decode( $body, true );

        // Response is an array of {label, score} objects sorted by score descending.
        if ( ! $result || ! is_array( $result ) || empty( $result[0] ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( 'Etsy Importer AI: Invalid response format - ' . $body );
            return $return_details ? array( 'category' => '', 'score' => 0, 'all_scores' => array() ) : '';
        }

        // Get the highest scoring category (first item).
        $best_label = $result[0]['label'] ?? '';
        $best_score = $result[0]['score'] ?? 0;

        // Build all scores for logging.
        $all_scores = array();
        foreach ( array_slice( $result, 0, 3 ) as $item ) {
            $all_scores[] = $item['label'] . ': ' . round( $item['score'] * 100 ) . '%';
        }

        if ( $best_score >= 0.2 ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( "Etsy Importer AI: Matched '{$title}' to '{$best_label}' with score {$best_score}" );

            if ( $return_details ) {
                return array(
                    'category'   => $best_label,
                    'score'      => $best_score,
                    'all_scores' => $all_scores,
                );
            }
            return $best_label;
        }

        return $return_details ? array( 'category' => '', 'score' => 0, 'all_scores' => $all_scores ) : '';
    }

    /**
     * Process Etsy taxonomy path and return category IDs.
     *
     * Handles both simple names ("Bridal Shower Games") and hierarchical ("Parent > Child").
     *
     * @param string $taxonomy_path  Category path string.
     * @param bool   $create_missing Whether to create missing categories.
     * @return array Array of category IDs.
     */
    private function process_taxonomy_path( $taxonomy_path, $create_missing = true ) {
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

    /**
     * Queue images for background processing using Action Scheduler.
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
        } catch ( Exception $e ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( "Etsy Importer: Failed to import image {$image_url} for product {$product_id}: " . $e->getMessage() );
        }
    }

    /**
     * Sideload an image from URL.
     *
     * @param string $url     Image URL.
     * @param int    $post_id Post ID to attach the image to.
     * @return int|WP_Error Attachment ID or WP_Error on failure.
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

        // Sideload the image (with thumbnail generation).
        $attachment_id = media_handle_sideload( $file_array, $post_id );

        // Clean up temp file if sideload failed.
        if ( is_wp_error( $attachment_id ) ) {
            wp_delete_file( $tmp );
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( "Etsy Importer: Failed to sideload {$url}: " . $attachment_id->get_error_message() );
            return $attachment_id;
        }

        return $attachment_id;
    }
}

// Initialize the plugin.
add_action(
    'plugins_loaded',
    function () {
        if ( class_exists( 'WooCommerce' ) ) {
            Etsy_CSV_Importer::get_instance();
        }
    }
);
