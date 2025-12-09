=== Etsy WooCommerce AI Importer ===
Contributors: devaborin
Tags: etsy, woocommerce, import, csv, ai, digital products
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import digital products from Etsy CSV exports into WooCommerce with AI-powered category matching.

== Description ==

Easily import your Etsy digital products into WooCommerce! This plugin reads Etsy's CSV export format and creates WooCommerce products with all the important details preserved.

**Key Features:**

* **CSV Import** - Import products directly from Etsy's CSV export format
* **AI Category Matching** - Uses Hugging Face's FREE zero-shot classification to automatically match products to your existing WooCommerce categories
* **Real-time Progress** - See import progress with live streaming logs
* **Background Image Import** - Images are downloaded in the background so imports complete quickly
* **Smart Duplicate Detection** - Skips products that already exist (by SKU or title)
* **Digital Product Support** - Automatically marks products as virtual/downloadable

**How AI Categorization Works**

The plugin uses Facebook's BART-MNLI model via Hugging Face's Inference API for zero-shot classification. It analyzes product titles and tags to determine the best matching category from your existing WooCommerce product categories.

The AI provides confidence scores for each potential category, and selects the best match above a 20% confidence threshold. You can see the AI's reasoning in the import log.

**No AI? No Problem!**

If you don't configure the AI feature, the plugin still works great! It uses intelligent keyword matching to find the best category based on your product titles and tags.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/etsy-woocommerce-ai-importer`, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to WooCommerce → Etsy Importer to start importing.

**Setting up AI Category Matching (Optional - FREE):**

1. Create a free account at [huggingface.co/join](https://huggingface.co/join)
2. Go to Settings → Access Tokens → Create new token
3. Select "Fine-grained" token type
4. Enable "Make calls to Inference Providers" permission
5. Copy the token and paste it in the plugin settings
6. Check "Use AI for category matching"

The free tier includes ~30,000 API requests per month - more than enough for most imports!

== Frequently Asked Questions ==

= What CSV format does this plugin support? =

This plugin supports Etsy's native CSV export format. To export from Etsy:
1. Go to Shop Manager → Settings → Options
2. Click "Download data"
3. Download your listings CSV

= Do I need the Hugging Face API for the plugin to work? =

No! The AI feature is optional. Without it, the plugin uses intelligent keyword matching to categorize your products based on titles and tags.

= What fields are imported from Etsy? =

* TITLE → Product Name
* DESCRIPTION → Product Description
* PRICE → Regular Price
* TAGS → Product Tags
* IMAGE1-10 → Product Gallery Images
* SECTION or TAGS → Product Categories
* SKU → SKU

= Will this plugin duplicate my products? =

No. The plugin checks for existing products by SKU and title before importing, and will skip duplicates.

= How long does the import take? =

The product data imports very quickly (usually under a minute for most catalogs). Images are downloaded in the background so you don't have to wait.

= Is this plugin compatible with my theme? =

Yes! This plugin only affects the import process and doesn't modify your frontend theme in any way.

== Screenshots ==

1. Main import interface with CSV upload and options
2. Real-time import progress with streaming logs
3. AI category matching settings
4. Import complete summary showing results

== Changelog ==

= 1.0.0 =
* Initial public release
* Real-time streaming import progress
* AI-powered category matching with Hugging Face
* Background image processing
* Detailed import logging
* Fallback keyword-based category matching

== Upgrade Notice ==

= 1.0.0 =
Initial release - start importing your Etsy products today!
