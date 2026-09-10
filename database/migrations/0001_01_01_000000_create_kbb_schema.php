<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KBB core schema — Phase 0.
 *
 * REPLACES the original file of the same name. Rewritten rather than patched with
 * ALTER migrations because the Laravel app has never been deployed and holds no
 * data (confirmed 26 Aug 2026), so there is nothing to preserve and a clean
 * schema is worth more than a migration history of corrections.
 *
 * Changes from the original:
 *  - `created_at` / `updated_at` were string columns throughout. Now real
 *    timestamps, so Eloquent date casting and date-range queries work. (F-13)
 *  - Added `source_*` identity columns so every migrated row keeps its WordPress
 *    identity, which is what makes full / delta / cutover imports idempotent. (D-51)
 *  - Products gain real foreign keys to categories and brands, which are now
 *    tables rather than string columns. (F-06, F-07)
 *  - Orders gain tax, invoice number, currency and coupon fields that the
 *    production data actually carries. (C1, C7)
 *  - Reviews restructured to match the Dream Code Reviews schema, which is the
 *    authoritative review system on production. (D-59)
 *  - No country is hard-coded anywhere. Production ships to the UAE plus five
 *    Gulf countries. (D-63)
 *
 * Money is stored as integer fils (AED × 100) throughout, preserved from the
 * original design because it avoids float drift. (LO-01)
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ------------------------------------------------------------------
         | Catalogue
         |------------------------------------------------------------------*/

        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('name');
            $t->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $t->text('description')->nullable();
            $t->string('image')->nullable();
            $t->unsignedInteger('position')->default(0);
            // Production nests product_cat four levels deep; depth is cached to
            // avoid recursive queries when rendering the URL path.
            $t->unsignedTinyInteger('depth')->default(0);
            $t->string('path')->nullable()->index();   // e.g. skincare/face-cleansers/makeup-removers
            $t->unsignedBigInteger('source_term_id')->nullable()->unique();
            $t->timestamps();
        });

        Schema::create('brands', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('name');
            $t->string('logo')->nullable();
            $t->text('description')->nullable();
            $t->unsignedInteger('position')->default(0);
            // Production stores brands as the pa_brands attribute, 93 terms. (C from survey)
            $t->unsignedBigInteger('source_term_id')->nullable()->unique();
            $t->timestamps();
        });

        // Global attributes: pa_brands, pa_color, pa_size, pa_shades on production.
        Schema::create('attributes', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();          // color, size, shades
            $t->string('name');                     // Color, Size, Shades
            $t->string('query_var')->nullable();    // filter_color — preserved for the URL contract (U-05)
            $t->boolean('is_variation_axis')->default(false);
            $t->boolean('is_filterable')->default(true);
            $t->unsignedInteger('position')->default(0);
            $t->timestamps();
        });

        Schema::create('attribute_values', function (Blueprint $t) {
            $t->id();
            $t->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $t->string('slug');
            $t->string('name');
            $t->string('swatch_color')->nullable();  // Rey stored colour swatches in termmeta
            $t->string('swatch_image')->nullable();
            $t->unsignedInteger('position')->default(0);
            $t->unsignedBigInteger('source_term_id')->nullable()->index();
            $t->timestamps();
            $t->unique(['attribute_id', 'slug']);
        });

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            // The WooCommerce post ID. Public identifier — ?add-to-cart={id} links
            // are live in the wild, so this is a URL contract, not just a key. (D-46)
            $t->unsignedBigInteger('wc_id')->nullable()->unique();

            $t->string('slug')->unique();
            $t->string('name');
            $t->string('sku')->nullable()->index();

            $t->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('category_id')->nullable()->constrained()->nullOnDelete(); // primary category

            $t->string('type')->default('simple');          // simple | variable
            $t->string('status')->default('publish');       // publish | draft | private
            $t->boolean('is_visible')->default(true);       // catalogue visibility

            $t->integer('price')->nullable();               // fils
            $t->integer('sale_price')->nullable();          // fils
            $t->timestamp('sale_starts_at')->nullable();
            $t->timestamp('sale_ends_at')->nullable();

            $t->boolean('manage_stock')->default(false);
            $t->integer('stock')->nullable();
            $t->string('stock_status')->default('instock'); // instock | outofstock | onbackorder

            $t->text('short_description')->nullable();
            $t->longText('description')->nullable();

            $t->string('image')->nullable();
            $t->json('images')->nullable();

            $t->float('rating')->default(0);
            $t->unsignedInteger('review_count')->default(0);
            $t->unsignedInteger('total_sales')->default(0);

            $t->boolean('featured')->default(false);
            // Production keeps curated order in wp_rwpp_product_order (2,266 rows),
            // NOT in rwpp_sortorder postmeta, which has zero rows. (C5)
            $t->integer('position')->default(0);

            $t->json('seo')->nullable();          // Yoast import target
            $t->json('meta_feed')->nullable();    // fb_* keys for the Meta catalogue (D-60)
            $t->json('custom_tabs')->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->index(['status', 'is_visible']);
            $t->index(['position']);
            $t->index(['price']);
        });

        Schema::create('product_variants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('wc_id')->nullable()->unique();
            $t->string('sku')->nullable()->index();
            $t->integer('price')->nullable();
            $t->integer('sale_price')->nullable();
            $t->boolean('manage_stock')->default(false);
            $t->integer('stock')->nullable();
            $t->string('stock_status')->default('instock');
            $t->string('image')->nullable();
            $t->unsignedInteger('position')->default(0);
            $t->timestamps();
        });

        // Which attribute values a variant is defined by, and which a product offers.
        Schema::create('product_attribute_value', function (Blueprint $t) {
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('attribute_value_id')->constrained()->cascadeOnDelete();
            $t->primary(['product_id', 'attribute_value_id']);
        });

        Schema::create('product_variant_attribute_value', function (Blueprint $t) {
            $t->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('attribute_value_id')->constrained()->cascadeOnDelete();
            $t->primary(['product_variant_id', 'attribute_value_id'], 'pvav_primary');
        });

        Schema::create('category_product', function (Blueprint $t) {
            $t->foreignId('category_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->primary(['category_id', 'product_id']);
        });

        Schema::create('tags', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('name');
            $t->unsignedBigInteger('source_term_id')->nullable()->unique();
            $t->timestamps();
        });

        Schema::create('product_tag', function (Blueprint $t) {
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $t->primary(['product_id', 'tag_id']);
        });

        /* ------------------------------------------------------------------
         | Customers
         |------------------------------------------------------------------*/

        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('wp_user_id')->nullable()->unique();
            $t->string('name')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->unique();
            $t->timestamp('email_verified_at')->nullable();

            $t->string('password')->nullable();
            // WordPress phpass hash, kept so imported customers can sign in with
            // their existing password and are silently upgraded to bcrypt on first
            // successful login. Avoids a forced password reset for 3,712 customers.
            $t->string('legacy_password')->nullable();
            $t->rememberToken();

            $t->string('phone')->nullable();
            $t->boolean('whatsapp_optin')->default(false);
            $t->text('notes')->nullable();

            $t->unsignedInteger('orders_count')->default(0);
            $t->unsignedBigInteger('total_spent')->default(0); // fils
            $t->timestamp('last_order_at')->nullable();

            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('addresses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->string('type')->default('billing');   // billing | shipping
            $t->boolean('is_default')->default(false);
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('company')->nullable();
            $t->string('line1')->nullable();
            $t->string('line2')->nullable();
            $t->string('city')->nullable();
            $t->string('state')->nullable();          // Emirate
            $t->string('postcode')->nullable();
            $t->string('country', 2)->default('AE');  // default only — never enforced (D-63)
            $t->string('phone')->nullable();
            $t->timestamps();
        });

        /* ------------------------------------------------------------------
         | Cart — the thing that did not exist. (F-01, F-02)
         |------------------------------------------------------------------*/

        Schema::create('carts', function (Blueprint $t) {
            $t->id();
            $t->uuid('token')->unique();                      // cookie handle for guests
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->string('currency', 3)->default('AED');
            $t->foreignId('coupon_id')->nullable();
            $t->string('shipping_country', 2)->nullable();
            $t->string('shipping_state')->nullable();
            $t->unsignedBigInteger('shipping_method_id')->nullable();
            $t->string('status')->default('active');          // active | converted | abandoned
            $t->timestamp('last_activity_at')->nullable()->index();
            $t->timestamp('converted_at')->nullable();
            $t->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $t->unsignedInteger('quantity')->default(1);
            // Snapshot so a price change mid-session cannot silently alter the cart.
            $t->integer('unit_price')->default(0);            // fils
            $t->timestamps();
        });

        /* ------------------------------------------------------------------
         | Coupons (F-03)
         |------------------------------------------------------------------*/

        Schema::create('coupons', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('wc_id')->nullable()->unique();
            $t->string('code')->unique();
            $t->string('type')->default('percent');           // percent | fixed_cart | fixed_product
            $t->integer('amount')->default(0);                // percent ×100, or fils
            $t->text('description')->nullable();

            $t->integer('minimum_amount')->nullable();        // fils
            $t->integer('maximum_amount')->nullable();        // fils
            $t->boolean('free_shipping')->default(false);
            $t->boolean('individual_use')->default(false);
            $t->boolean('exclude_sale_items')->default(false);

            $t->unsignedInteger('usage_limit')->nullable();
            $t->unsignedInteger('usage_limit_per_user')->nullable();
            // Migrated verbatim, so an exhausted code cannot be re-used after cutover. (VM-06)
            $t->unsignedInteger('usage_count')->default(0);

            $t->json('product_ids')->nullable();
            $t->json('excluded_product_ids')->nullable();
            $t->json('category_ids')->nullable();
            $t->json('excluded_category_ids')->nullable();
            $t->json('allowed_emails')->nullable();

            $t->timestamp('starts_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });

        Schema::create('coupon_redemptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('order_id')->nullable()->index();
            $t->string('email')->nullable()->index();
            $t->integer('amount')->default(0);                // fils actually discounted
            $t->timestamps();
        });

        /* ------------------------------------------------------------------
         | Shipping (F-05) — two live zones on production: UAE and Gulf Countries
         |------------------------------------------------------------------*/

        Schema::create('shipping_zones', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->unsignedInteger('position')->default(0);
            $t->unsignedBigInteger('source_zone_id')->nullable()->unique();
            $t->timestamps();
        });

        Schema::create('shipping_zone_locations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shipping_zone_id')->constrained()->cascadeOnDelete();
            $t->string('type')->default('country');           // country | state | postcode | continent
            $t->string('code');
            $t->timestamps();
            $t->index(['type', 'code']);
        });

        Schema::create('shipping_methods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shipping_zone_id')->constrained()->cascadeOnDelete();
            $t->string('type')->default('flat_rate');         // flat_rate | free_shipping | local_pickup
            $t->string('title');
            $t->boolean('enabled')->default(true);
            $t->integer('cost')->default(0);                  // fils
            // The free-shipping threshold lives here, never as a constant.
            // Production: AED 199 for the UAE zone, AED 1,600 for Gulf Countries.
            $t->integer('min_amount')->nullable();            // fils
            $t->unsignedInteger('position')->default(0);
            $t->json('settings')->nullable();
            $t->timestamps();
        });

        /* ------------------------------------------------------------------
         | Tax (F-04) — DORMANT for now.
         |
         | Decision (D-64): VAT is a display line only. The checkout shows that
         | 5% VAT is included; it never alters the total, and no tax is charged,
         | calculated or stored against an order. A real tax engine is deferred
         | to a later phase.
         |
         | This table is created now but nothing reads it yet, so that the proper
         | VAT module has somewhere to land without a schema change later. The
         | rendered figure comes from settings, not from here.
         |------------------------------------------------------------------*/

        Schema::create('tax_rates', function (Blueprint $t) {
            $t->id();
            $t->string('name')->default('VAT');
            $t->string('country', 2)->nullable();             // null = applies everywhere
            $t->string('state')->nullable();
            $t->decimal('rate', 6, 3)->default(5.000);        // percent
            // true  = price already contains the tax; total is unchanged and the
            //         tax portion is reported as amount × rate ÷ (100 + rate).
            // false = tax is added on top of the price.
            $t->boolean('is_inclusive')->default(true);
            $t->boolean('applies_to_shipping')->default(false);
            $t->unsignedInteger('priority')->default(1);
            $t->timestamps();
        });

        /* ------------------------------------------------------------------
         | Orders
         |------------------------------------------------------------------*/

        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('wc_order_id')->nullable()->unique();
            // Human-facing number, preserved exactly from WooCommerce.
            $t->string('order_number')->unique();
            // WebToffee invoice sequence continues from MAX(wf_invoice_number). (D-61)
            $t->unsignedBigInteger('invoice_number')->nullable()->unique();
            $t->timestamp('invoiced_at')->nullable();

            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->string('email')->index();
            $t->string('phone')->nullable();

            // Free-form so imported custom statuses survive: production carries
            // wc-shipped and wc-tamara-p-failed alongside the core set.
            $t->string('status')->default('pending')->index();
            $t->string('currency', 3)->default('AED');

            $t->json('billing_address')->nullable();
            $t->json('shipping_address')->nullable();

            $t->integer('subtotal')->default(0);              // fils
            $t->integer('discount_total')->default(0);
            $t->integer('shipping_total')->default(0);
            $t->integer('fee_total')->default(0);             // COD fee lands here
            // Informational only under D-64 — stays 0 while VAT is display-only.
            // Present so the later tax engine has a column to populate without a
            // migration, and so imported orders (which carry no tax) map cleanly.
            $t->integer('tax_total')->default(0);
            $t->integer('total')->default(0);

            $t->string('shipping_method')->nullable();
            $t->string('payment_method')->nullable();
            $t->string('payment_method_title')->nullable();
            $t->string('transaction_id')->nullable();

            $t->string('coupon_code')->nullable();
            $t->boolean('whatsapp_optin')->default(false);
            $t->text('customer_note')->nullable();
            $t->string('origin')->nullable();

            $t->timestamp('paid_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();

            $t->index(['status', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            // Snapshots, so an order still reads correctly after a product is renamed or deleted.
            $t->string('name');
            $t->string('brand')->nullable();
            $t->string('sku')->nullable();
            $t->json('variant_attributes')->nullable();
            $t->unsignedInteger('quantity')->default(1);
            $t->integer('unit_price')->default(0);            // fils
            $t->integer('subtotal')->default(0);
            $t->integer('total')->default(0);
            $t->integer('tax_total')->default(0);
            $t->timestamps();
        });

        Schema::create('order_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('author')->nullable();
            $t->boolean('is_customer_note')->default(false);
            $t->text('content');
            $t->unsignedBigInteger('source_comment_id')->nullable()->unique();
            $t->timestamps();
        });

        Schema::create('refunds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('wc_refund_id')->nullable()->unique();
            $t->integer('amount')->default(0);                // fils
            $t->text('reason')->nullable();
            $t->string('refunded_by')->nullable();
            $t->timestamps();
        });

        /* ------------------------------------------------------------------
         | Payments — preserved from the original design (LO-03)
         |------------------------------------------------------------------*/

        Schema::create('payment_providers', function (Blueprint $t) {
            $t->string('id')->primary();                      // cod | stripe | tabby | tamara
            $t->string('title')->nullable();
            $t->boolean('enabled')->default(false);
            $t->string('mode')->default('test');              // test | live
            $t->unsignedInteger('position')->default(0);
            $t->json('config')->nullable();                   // encrypted in the app layer
            $t->timestamps();
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->string('provider')->nullable()->index();
            $t->string('provider_ref')->nullable()->index();
            $t->integer('amount')->nullable();
            $t->string('currency', 3)->nullable();
            $t->string('status')->nullable();
            $t->string('failure_code')->nullable();
            $t->timestamps();
        });

        Schema::create('payment_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('payment_id')->nullable()->index();
            $t->string('type')->nullable();
            $t->json('payload')->nullable();
            $t->timestamp('received_at')->nullable();
        });

        /* ------------------------------------------------------------------
         | Reviews — Dream Code Reviews schema (D-59)
         | product_id 0 in WordPress meant a business review; here that is null.
         |------------------------------------------------------------------*/

        Schema::create('reviews', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('source_id')->nullable()->index();
            $t->string('source')->default('sorina');          // sorina | wp_comment
            $t->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->string('author_name')->default('');
            $t->string('author_email')->default('');
            $t->unsignedTinyInteger('rating')->default(5);
            $t->string('title')->default('');
            $t->text('content')->nullable();
            $t->json('images')->nullable();
            $t->string('status')->default('pending')->index(); // pending | approved | spam
            $t->boolean('verified')->default(false);
            $t->unsignedInteger('helpful')->default(0);
            $t->text('reply')->nullable();
            $t->string('ip', 60)->default('');
            $t->timestamps();
        });

        /* ------------------------------------------------------------------
         | Settings & modules (F-11, F-12)
         |------------------------------------------------------------------*/

        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->longText('value')->nullable();
            $t->boolean('autoload')->default(true);
            $t->timestamps();
        });

        Schema::create('module_toggles', function (Blueprint $t) {
            $t->string('module')->primary();                  // 29 ids from the registry
            $t->boolean('enabled')->default(false);
            $t->timestamps();
        });

        Schema::create('module_settings', function (Blueprint $t) {
            $t->id();
            $t->string('module')->index();
            $t->string('key');
            $t->longText('value')->nullable();
            $t->timestamps();
            $t->unique(['module', 'key']);
        });

        /* ------------------------------------------------------------------
         | Content & navigation
         |------------------------------------------------------------------*/

        Schema::create('posts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('source_post_id')->nullable()->unique();
            $t->string('slug')->unique();
            $t->string('title');
            $t->text('excerpt')->nullable();
            $t->longText('body')->nullable();
            $t->string('cover')->nullable();
            $t->string('tag')->nullable();
            $t->string('author')->default('K-Beauty Bliss');
            $t->string('status')->default('published')->index();
            $t->json('seo')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });

        Schema::create('pages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('source_post_id')->nullable()->unique();
            $t->string('slug')->unique();
            $t->string('title');
            $t->longText('content')->nullable();
            $t->longText('doc_json')->nullable();             // page builder tree
            $t->longText('css')->nullable();
            $t->string('template')->nullable();
            $t->string('status')->default('draft')->index();
            $t->json('seo')->nullable();
            $t->timestamps();
        });

        Schema::create('menus', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('name');
            $t->string('location')->nullable()->index();      // header | footer | mobile
            $t->unsignedBigInteger('source_term_id')->nullable()->unique();
            $t->timestamps();
        });

        Schema::create('menu_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('menu_id')->constrained()->cascadeOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('menu_items')->cascadeOnDelete();
            $t->string('label');
            $t->string('url')->nullable();
            $t->string('target_type')->nullable();            // category | brand | page | product | custom
            $t->unsignedBigInteger('target_id')->nullable();
            $t->string('icon')->nullable();
            $t->string('badge')->nullable();
            $t->unsignedInteger('position')->default(0);
            $t->unsignedBigInteger('source_post_id')->nullable()->unique();
            $t->timestamps();
        });

        Schema::create('media', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('source_attachment_id')->nullable()->unique();
            $t->string('filename');
            // Site-relative and kept under /wp-content/uploads/ so no existing
            // image URL breaks anywhere it has been shared. (D-45)
            $t->string('path')->index();
            $t->string('mime')->nullable();
            $t->unsignedBigInteger('size')->nullable();
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->string('alt')->default('');
            $t->json('sizes')->nullable();                    // generated variants
            $t->timestamps();
        });

        Schema::create('redirects', function (Blueprint $t) {
            $t->id();
            $t->string('source')->unique();
            $t->string('target');
            $t->unsignedSmallInteger('code')->default(301);
            $t->boolean('enabled')->default(true);
            $t->unsignedBigInteger('hits')->default(0);
            $t->timestamp('last_hit_at')->nullable();
            $t->timestamps();
        });

        /* ------------------------------------------------------------------
         | Quiz — Laravel-only feature, preserved (LO / D-37)
         |------------------------------------------------------------------*/

        Schema::create('quiz_submissions', function (Blueprint $t) {
            $t->id();
            $t->string('status')->default('new')->index();
            $t->string('skin_type')->nullable();
            $t->text('concerns')->nullable();
            $t->string('age')->nullable();
            $t->string('routine_depth')->nullable();
            $t->string('budget')->nullable();
            $t->string('name')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable()->index();
            $t->json('recommended_routines')->nullable();
            $t->boolean('expert_requested')->default(false);
            $t->text('expert_message')->nullable();
            $t->timestamp('expert_requested_at')->nullable();
            $t->text('source_url')->nullable();
            $t->json('utm')->nullable();
            $t->boolean('consent')->default(false);
            $t->timestamp('consent_at')->nullable();
            $t->text('admin_notes')->nullable();
            $t->string('assigned_to')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        // Reverse dependency order.
        foreach ([
            'quiz_submissions', 'redirects', 'media', 'menu_items', 'menus',
            'pages', 'posts', 'module_settings', 'module_toggles', 'settings',
            'reviews', 'payment_events', 'payments', 'payment_providers',
            'refunds', 'order_notes', 'order_items', 'orders',
            'tax_rates', 'shipping_methods', 'shipping_zone_locations', 'shipping_zones',
            'coupon_redemptions', 'coupons', 'cart_items', 'carts',
            'addresses', 'customers',
            'product_tag', 'tags', 'category_product',
            'product_variant_attribute_value', 'product_attribute_value',
            'product_variants', 'products',
            'attribute_values', 'attributes', 'brands', 'categories',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
