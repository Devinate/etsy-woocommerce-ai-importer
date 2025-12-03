# Etsy WooCommerce AI Importer

Import digital products from Etsy CSV exports into WooCommerce with AI-powered category matching.

## Features

- **CSV Import**: Import products directly from Etsy's CSV export format
- **AI Category Matching**: Uses Hugging Face's free zero-shot classification to automatically match products to your existing WooCommerce categories
- **Real-time Progress**: See import progress with live streaming logs
- **Background Image Import**: Images are downloaded in the background so imports complete quickly
- **Smart Duplicate Detection**: Skips products that already exist (by SKU or title)
- **Digital Product Support**: Automatically marks products as virtual/downloadable

## Requirements

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 7.4+
- (Optional) Hugging Face API token for AI category matching

## Installation

1. Download the plugin zip file
2. Go to WordPress Admin > Plugins > Add New > Upload Plugin
3. Upload the zip file and click "Install Now"
4. Activate the plugin

## Usage

### Basic Import

1. Go to WooCommerce > Etsy Importer
2. Export your listings from Etsy (Shop Manager > Settings > Options > Download data)
3. Upload the CSV file
4. Configure import options
5. Click "Start Import"

### AI Category Matching (Optional - FREE)

For automatic category matching using AI:

1. Create a free account at [huggingface.co/join](https://huggingface.co/join)
2. Go to [Settings > Access Tokens > Create new token](https://huggingface.co/settings/tokens/new?tokenType=fineGrained)
3. Select "Fine-grained" token type
4. Enable "Make calls to Inference Providers" permission
5. Copy the token and paste it in the plugin settings
6. Check "Use AI for category matching"

The free tier includes ~30,000 API requests per month - more than enough for most imports.

## CSV Field Mapping

| Etsy Field | WooCommerce Field |
|------------|-------------------|
| TITLE | Product Name |
| DESCRIPTION | Product Description |
| PRICE | Regular Price |
| TAGS | Product Tags |
| IMAGE1-10 | Product Gallery Images |
| SECTION or TAGS | Product Categories |
| SKU | SKU |

## Import Options

- **Import product images**: Download and attach images from Etsy (processed in background)
- **Mark as digital/downloadable**: Set products as virtual and downloadable
- **Import as drafts**: Create products as drafts for review before publishing
- **Import categories**: Match products to WooCommerce categories
- **Create missing categories**: Auto-create categories that don't exist
- **Default category**: Fallback category when no match is found

## How AI Categorization Works

The plugin uses Facebook's BART-MNLI model via Hugging Face's Inference API for zero-shot classification. It analyzes product titles and tags to determine the best matching category from your existing WooCommerce product categories.

The AI provides confidence scores for each potential category, and selects the best match above a 20% confidence threshold. You can see the AI's reasoning in the import log.

## Changelog

### 1.0.0
- Initial public release
- Real-time streaming import progress
- AI-powered category matching with Hugging Face
- Background image processing
- Detailed import logging

## License

GPL v2 or later

## Credits

Developed by [Devinate](https://devinate.com)

AI categorization powered by [Hugging Face](https://huggingface.co) using the [facebook/bart-large-mnli](https://huggingface.co/facebook/bart-large-mnli) model.
