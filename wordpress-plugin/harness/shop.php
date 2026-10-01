<?php
/**
 * A WordPress-shaped shop in MySQL, with the nasty rows in it.
 *
 * ============================================================================
 * THE ROWS ARE CHOSEN THE WAY tests/Fixtures/woo WAS CHOSEN
 * ============================================================================
 *
 * tests/Feature/WooImportTest.php's header: "The fixture in tests/Fixtures/woo
 * is deliberately nasty -- guests, missing fields, historical dates, two emails
 * differing only in case, malformed money, an order whose customer does not
 * exist, statuses this schema has never heard of -- because a fixture of clean
 * rows proves only that the happy path works, and the happy path was never the
 * risk."
 *
 * Same rule, one level upstream. Every row below is here because something
 * about it is the awkward case for the exporter or for the importer behind it:
 *
 *   4021  a price carrying fils (99.50), a Yoast template with an unresolvable
 *         token, a GTIN in wpseo_global_identifier_values, a gallery of two, and
 *         a description referencing an image at a SIZE (-300x300) rather than
 *         at full -- which is the only way media.csv's size resolution is
 *         exercised at all.
 *   4022  a thousands separator in the price (1,234.50) -- Money::fils()
 *         accepts it only where the grouping is unambiguous.
 *   4023  a VARIABLE product, with one live variation and one disabled
 *         (post_status private) one.
 *   4024  in the WordPress TRASH. ProductImporter refuses it by name; the
 *         export holds it back and says so.
 *   4025  hidden from the catalogue AND from search, which is the four-state
 *         visibility the export has to fold to a boolean without hiding
 *         something the owner had published.
 *
 *   10233 an ordinary completed order for a signed-in customer.
 *   10234 a GUEST order (customer_user = 0) with a coupon line, a fee line and
 *         a shipping line -- the three item types whose money lives nowhere
 *         else and which the exporter has to sum for itself.
 *   10235 an order with a PARTIAL refund, 10236, whose own line items live in
 *         the same table as real order lines and must not leak into
 *         order_items.csv.
 *
 *   8101  a review.
 *   8102  a review from before WooCommerce 3.0, with an EMPTY comment_type.
 *   8103  a product comment with no rating -- a customer question, which is not
 *         a review and must not be exported as five stars.
 *   8104  a reply to 8101.
 *
 * Seeded identically into both order storages so the two exports can be
 * compared against each other, which is what makes "both are supported" a
 * measurement rather than a claim.
 */

/**
 * @param PDO    $pdo
 * @param string $prefix
 * @param string $storage 'posts' or 'hpos'
 */
function kbb_harness_build( PDO $pdo, $prefix, $storage ) {
	kbb_harness_schema( $pdo, $prefix );
	kbb_harness_seed( $pdo, $prefix, $storage );
}

function kbb_harness_schema( PDO $pdo, $p ) {
	$tables = array(
		"{$p}options" => "(
			option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			option_name VARCHAR(191) NOT NULL DEFAULT '',
			option_value LONGTEXT NOT NULL,
			autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
			PRIMARY KEY (option_id), UNIQUE KEY option_name (option_name))",

		"{$p}posts" => "(
			ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_author BIGINT UNSIGNED NOT NULL DEFAULT 0,
			post_date DATETIME NOT NULL DEFAULT '1970-01-02 00:00:00',
			post_date_gmt DATETIME NOT NULL DEFAULT '1970-01-02 00:00:00',
			post_content LONGTEXT NOT NULL,
			post_title TEXT NOT NULL,
			post_excerpt TEXT NOT NULL,
			post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
			comment_status VARCHAR(20) NOT NULL DEFAULT 'open',
			post_name VARCHAR(200) NOT NULL DEFAULT '',
			post_modified DATETIME NOT NULL DEFAULT '1970-01-02 00:00:00',
			post_parent BIGINT UNSIGNED NOT NULL DEFAULT 0,
			menu_order INT NOT NULL DEFAULT 0,
			post_type VARCHAR(20) NOT NULL DEFAULT 'post',
			comment_count BIGINT NOT NULL DEFAULT 0,
			PRIMARY KEY (ID), KEY type_status_date (post_type, post_status, post_date, ID))",

		"{$p}postmeta" => "(
			meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			meta_key VARCHAR(255) DEFAULT NULL,
			meta_value LONGTEXT,
			PRIMARY KEY (meta_id), KEY post_id (post_id), KEY meta_key (meta_key(191)))",

		"{$p}users" => "(
			ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_login VARCHAR(60) NOT NULL DEFAULT '',
			user_pass VARCHAR(255) NOT NULL DEFAULT '',
			user_nicename VARCHAR(50) NOT NULL DEFAULT '',
			user_email VARCHAR(100) NOT NULL DEFAULT '',
			user_registered DATETIME NOT NULL DEFAULT '1970-01-02 00:00:00',
			display_name VARCHAR(250) NOT NULL DEFAULT '',
			PRIMARY KEY (ID))",

		"{$p}usermeta" => "(
			umeta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			meta_key VARCHAR(255) DEFAULT NULL,
			meta_value LONGTEXT,
			PRIMARY KEY (umeta_id), KEY user_id (user_id), KEY meta_key (meta_key(191)))",

		"{$p}terms" => "(
			term_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(200) NOT NULL DEFAULT '',
			slug VARCHAR(200) NOT NULL DEFAULT '',
			term_group BIGINT NOT NULL DEFAULT 0,
			PRIMARY KEY (term_id))",

		"{$p}term_taxonomy" => "(
			term_taxonomy_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			term_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			taxonomy VARCHAR(32) NOT NULL DEFAULT '',
			description LONGTEXT NOT NULL,
			parent BIGINT UNSIGNED NOT NULL DEFAULT 0,
			count BIGINT NOT NULL DEFAULT 0,
			PRIMARY KEY (term_taxonomy_id), UNIQUE KEY term_id_taxonomy (term_id, taxonomy))",

		"{$p}term_relationships" => '(
			object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			term_taxonomy_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			term_order INT NOT NULL DEFAULT 0,
			PRIMARY KEY (object_id, term_taxonomy_id))',

		"{$p}termmeta" => "(
			meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			term_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			meta_key VARCHAR(255) DEFAULT NULL,
			meta_value LONGTEXT,
			PRIMARY KEY (meta_id), KEY term_id (term_id))",

		"{$p}comments" => "(
			comment_ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			comment_post_ID BIGINT UNSIGNED NOT NULL DEFAULT 0,
			comment_author TINYTEXT NOT NULL,
			comment_author_email VARCHAR(100) NOT NULL DEFAULT '',
			comment_author_IP VARCHAR(100) NOT NULL DEFAULT '',
			comment_date DATETIME NOT NULL DEFAULT '1970-01-02 00:00:00',
			comment_date_gmt DATETIME NOT NULL DEFAULT '1970-01-02 00:00:00',
			comment_content TEXT NOT NULL,
			comment_approved VARCHAR(20) NOT NULL DEFAULT '1',
			comment_type VARCHAR(20) NOT NULL DEFAULT 'comment',
			comment_parent BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (comment_ID), KEY comment_post_ID (comment_post_ID))",

		"{$p}commentmeta" => "(
			meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			comment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			meta_key VARCHAR(255) DEFAULT NULL,
			meta_value LONGTEXT,
			PRIMARY KEY (meta_id), KEY comment_id (comment_id))",

		"{$p}woocommerce_order_items" => "(
			order_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_item_name TEXT NOT NULL,
			order_item_type VARCHAR(200) NOT NULL DEFAULT '',
			order_id BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY (order_item_id), KEY order_id (order_id))",

		"{$p}woocommerce_order_itemmeta" => '(
			meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_item_id BIGINT UNSIGNED NOT NULL,
			meta_key VARCHAR(255) DEFAULT NULL,
			meta_value LONGTEXT,
			PRIMARY KEY (meta_id), KEY order_item_id (order_item_id))',

		"{$p}woocommerce_attribute_taxonomies" => "(
			attribute_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			attribute_name VARCHAR(200) NOT NULL,
			attribute_label VARCHAR(200) DEFAULT NULL,
			attribute_type VARCHAR(20) NOT NULL,
			attribute_orderby VARCHAR(20) NOT NULL,
			attribute_public INT NOT NULL DEFAULT 1,
			PRIMARY KEY (attribute_id))",

		// ── HPOS ────────────────────────────────────────────────────────────
		"{$p}wc_orders" => "(
			id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) DEFAULT NULL,
			currency VARCHAR(10) DEFAULT NULL,
			type VARCHAR(20) DEFAULT NULL,
			tax_amount DECIMAL(26,8) DEFAULT NULL,
			total_amount DECIMAL(26,8) DEFAULT NULL,
			customer_id BIGINT UNSIGNED DEFAULT NULL,
			billing_email VARCHAR(320) DEFAULT NULL,
			date_created_gmt DATETIME DEFAULT NULL,
			date_updated_gmt DATETIME DEFAULT NULL,
			parent_order_id BIGINT UNSIGNED DEFAULT NULL,
			payment_method VARCHAR(100) DEFAULT NULL,
			payment_method_title TEXT DEFAULT NULL,
			transaction_id VARCHAR(100) DEFAULT NULL,
			customer_note TEXT DEFAULT NULL,
			PRIMARY KEY (id))",

		"{$p}wc_order_addresses" => "(
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			address_type VARCHAR(20) DEFAULT NULL,
			first_name TEXT DEFAULT NULL, last_name TEXT DEFAULT NULL,
			company TEXT DEFAULT NULL, address_1 TEXT DEFAULT NULL, address_2 TEXT DEFAULT NULL,
			city TEXT DEFAULT NULL, state TEXT DEFAULT NULL, postcode TEXT DEFAULT NULL,
			country TEXT DEFAULT NULL, email VARCHAR(320) DEFAULT NULL, phone VARCHAR(100) DEFAULT NULL,
			PRIMARY KEY (id), UNIQUE KEY address_type_order_id (address_type, order_id))",

		"{$p}wc_order_operational_data" => "(
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED DEFAULT NULL,
			created_via VARCHAR(100) DEFAULT NULL,
			order_key VARCHAR(100) DEFAULT NULL,
			date_paid_gmt DATETIME DEFAULT NULL,
			date_completed_gmt DATETIME DEFAULT NULL,
			discount_total_amount DECIMAL(26,8) DEFAULT NULL,
			discount_tax_amount DECIMAL(26,8) DEFAULT NULL,
			shipping_total_amount DECIMAL(26,8) DEFAULT NULL,
			shipping_tax_amount DECIMAL(26,8) DEFAULT NULL,
			cart_tax_amount DECIMAL(26,8) DEFAULT NULL,
			PRIMARY KEY (id), UNIQUE KEY order_id (order_id))",

		"{$p}wc_orders_meta" => '(
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED DEFAULT NULL,
			meta_key VARCHAR(255) DEFAULT NULL,
			meta_value TEXT DEFAULT NULL,
			PRIMARY KEY (id), KEY order_id (order_id))',
	);

	foreach ( array_keys( $tables ) as $table ) {
		$pdo->exec( "DROP TABLE IF EXISTS `{$table}`" );
	}

	foreach ( $tables as $table => $definition ) {
		$pdo->exec( "CREATE TABLE `{$table}` {$definition} ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
	}
}

function kbb_harness_seed( PDO $pdo, $p, $storage ) {
	$insert = function ( $table, array $row ) use ( $pdo, $p ) {
		$columns = array_keys( $row );
		$holders = array_fill( 0, count( $columns ), '?' );

		$statement = $pdo->prepare(
			'INSERT INTO `' . $p . $table . '` (`' . implode( '`,`', $columns ) . '`) VALUES (' . implode( ',', $holders ) . ')'
		);

		$statement->execute( array_values( $row ) );
	};

	$meta = function ( $table, $owner_column, $owner, array $pairs ) use ( $insert ) {
		foreach ( $pairs as $key => $value ) {
			$insert( $table, array( $owner_column => $owner, 'meta_key' => $key, 'meta_value' => $value ) );
		}
	};

	// ── Options ─────────────────────────────────────────────────────────────
	//
	// `category_base` is an EMPTY STRING, which is the setting that makes
	// WooCommerce serve category archives flat at the site root -- the shape
	// App\Support\LegacyCategoryUrls describes from the live navigation. The
	// harness reproduces the SETTING, so permalinks.csv's answer is derived the
	// way the live site's would be rather than written into the fixture.
	$options = array(
		'siteurl'         => 'https://kbeautybliss.com',
		'home'            => 'https://kbeautybliss.com',
		'blogname'        => 'K Beauty Bliss',
		'timezone_string' => 'Asia/Dubai',
		'gmt_offset'      => '4',
		'permalink_structure' => '/%postname%/',
		'woocommerce_version' => '8.7.0',
		'kbb_wp_version'      => '6.5.2',
		'woocommerce_permalinks' => serialize( array(
			'product_base'   => '/product',
			'category_base'  => '',
			'tag_base'       => 'product-tag',
			'attribute_base' => '',
		) ),
		'woocommerce_custom_orders_table_enabled' => 'hpos' === $storage ? 'yes' : 'no',
	);

	foreach ( $options as $name => $value ) {
		$insert( 'options', array( 'option_name' => $name, 'option_value' => $value ) );
	}

	// ── Attribute definitions ───────────────────────────────────────────────
	//
	// Both `attribute_public = 0`, which is WooCommerce's default and which is
	// what makes get_term_link() answer "no archive" for them. That is the
	// evidence for "brand archives may never have existed", produced by the
	// shop's own setting rather than assumed.
	$insert( 'woocommerce_attribute_taxonomies', array(
		'attribute_id' => 1, 'attribute_name' => 'brands', 'attribute_label' => 'Brands',
		'attribute_type' => 'select', 'attribute_orderby' => 'menu_order', 'attribute_public' => 0,
	) );
	$insert( 'woocommerce_attribute_taxonomies', array(
		'attribute_id' => 2, 'attribute_name' => 'size', 'attribute_label' => 'Size',
		'attribute_type' => 'select', 'attribute_orderby' => 'menu_order', 'attribute_public' => 0,
	) );

	// ── Terms ───────────────────────────────────────────────────────────────
	$terms = array(
		array( 15, 'Skincare', 'skincare', 'product_cat', 0, 'Everything for the face' ),
		array( 22, 'Face Cleansers', 'face-cleansers', 'product_cat', 15, '' ),
		array( 31, 'Toners', 'toners', 'product_cat', 0, '' ),
		array( 501, 'COSRX', 'cosrx', 'pa_brands', 0, '' ),
		array( 502, 'Beauty of Joseon', 'beauty-of-joseon', 'pa_brands', 0, 'Hanbang skincare' ),
		array( 601, 'K-Beauty', 'k-beauty', 'product_tag', 0, '' ),
		array( 701, '50ml', '50ml', 'pa_size', 0, '' ),
		array( 702, '100ml', '100ml', 'pa_size', 0, '' ),
		array( 801, 'News', 'news', 'category', 0, '' ),
		array( 901, 'simple', 'simple', 'product_type', 0, '' ),
		array( 902, 'variable', 'variable', 'product_type', 0, '' ),
		array( 911, 'exclude-from-catalog', 'exclude-from-catalog', 'product_visibility', 0, '' ),
		array( 912, 'exclude-from-search', 'exclude-from-search', 'product_visibility', 0, '' ),
		array( 913, 'featured', 'featured', 'product_visibility', 0, '' ),
	);

	$counts = array( 15 => 1, 22 => 2, 31 => 3, 501 => 2, 502 => 1, 601 => 1, 701 => 1, 702 => 1, 801 => 1 );

	$tt = array();

	foreach ( $terms as $index => $term ) {
		list( $id, $name, $slug, $taxonomy, $parent, $description ) = $term;

		$insert( 'terms', array( 'term_id' => $id, 'name' => $name, 'slug' => $slug ) );
		$insert( 'term_taxonomy', array(
			'term_taxonomy_id' => $index + 1, 'term_id' => $id, 'taxonomy' => $taxonomy,
			'description' => $description, 'parent' => $parent,
			// WordPress maintains this; a fixture of zeroes would make every
			// permalinks.csv row read `empty`, which is a real state and not
			// the one these terms are in.
			'count' => isset( $counts[ $id ] ) ? $counts[ $id ] : 0,
		) );

		$tt[ $id ] = $index + 1;
	}

	$meta( 'termmeta', 'term_id', 15, array( 'order' => '1', 'thumbnail_id' => '9003' ) );
	$meta( 'termmeta', 'term_id', 502, array( 'order' => '2', 'thumbnail_id' => '9004' ) );

	// ── Attachments ─────────────────────────────────────────────────────────
	$attachments = array(
		9001 => '2019/03/ginseng-serum.jpg',
		9002 => '2019/03/ginseng-serum-2.jpg',
		// A COMMA IN THE FILENAME. This is the row that makes the gallery
		// separator a measurement: split on a comma, this one file becomes two
		// broken URLs, which is the exact defect ProductImporter's own comment
		// records having shipped once.
		9006 => '2019/03/ginseng-serum-3,-detail.jpg',
		9003 => '2020/01/skincare-category.jpg',
		9004 => '2020/01/boj-logo.png',
		9005 => '2021/05/first-post.jpg',
	);

	foreach ( $attachments as $id => $file ) {
		$insert( 'posts', array(
			'ID' => $id, 'post_author' => 1, 'post_type' => 'attachment', 'post_status' => 'inherit',
			'post_title' => basename( $file ), 'post_name' => basename( $file, '.jpg' ),
			'post_content' => '', 'post_excerpt' => '',
			'post_date' => '2019-03-04 10:00:00', 'post_date_gmt' => '2019-03-04 06:00:00',
			'post_modified' => '2019-03-04 10:00:00',
		) );

		$meta( 'postmeta', 'post_id', $id, array( '_wp_attached_file' => $file ) );
	}

	// 9001 has the registered sizes a real attachment carries. The `-300x300`
	// variant is the one the product description references, and it is the only
	// way media.csv's size resolution is exercised at all.
	$meta( 'postmeta', 'post_id', 9001, array(
		'_wp_attachment_metadata' => serialize( array(
			'width'  => 1200,
			'height' => 1200,
			'file'   => '2019/03/ginseng-serum.jpg',
			'sizes'  => array(
				'thumbnail' => array( 'file' => 'ginseng-serum-150x150.jpg', 'width' => 150, 'height' => 150 ),
				'medium'    => array( 'file' => 'ginseng-serum-300x300.jpg', 'width' => 300, 'height' => 300 ),
				'large'     => array( 'file' => 'ginseng-serum-1024x1024.jpg', 'width' => 1024, 'height' => 1024 ),
			),
		) ),
	) );

	// ── Products ────────────────────────────────────────────────────────────
	$uploads = 'https://kbeautybliss.com/wp-content/uploads';

	$insert( 'posts', array(
		'ID' => 4021, 'post_author' => 1, 'post_type' => 'product', 'post_status' => 'publish',
		'post_title' => 'Ginseng Serum', 'post_name' => 'serum-4021',
		'post_content' => 'A serum. <img src="' . $uploads . '/2019/03/ginseng-serum-300x300.jpg" alt="">',
		'post_excerpt' => 'Hanbang ginseng, in a bottle.',
		'post_date' => '2019-03-04 10:00:00', 'post_date_gmt' => '2019-03-04 06:00:00',
		'post_modified' => '2024-01-02 09:00:00', 'menu_order' => 1,
		// Advanced tab, "Enable reviews", unticked. WordPress keeps it here and
		// not in meta, which is why products.csv had never carried it.
		'comment_status' => 'closed',
	) );

	$meta( 'postmeta', 'post_id', 4021, array(
		'_sku' => 'KBB-4021', '_regular_price' => '99.50', '_sale_price' => '89.00',
		'_sale_price_dates_from' => '1551657600', '_sale_price_dates_to' => '1554249600',
		'_stock' => '12', '_stock_status' => 'instock', '_manage_stock' => 'yes',
				// TWO gallery images, so the pipe separator is exercised rather than
		// merely chosen: a one-element list comes out identical under either
		// separator and proves nothing.
		'_thumbnail_id' => '9001', '_product_image_gallery' => '9002,9006',
		'total_sales' => '37', '_weight' => '0.25', '_tax_status' => 'taxable',
		// Inventory tab, "Limit purchases to 1 item per order". Set on exactly
		// one product so the export shows a yes and a no rather than one value
		// repeated, which proves nothing about the column being read.
		'_sold_individually' => 'yes',
		'_yoast_wpseo_title' => 'Ginseng Serum %%sep%% %%sitename%% %%currentyear%%',
		'_yoast_wpseo_metadesc' => 'A ginseng serum, exported from the old shop\'s Yoast settings.',
		// The leaf, not the parent. Without it products.csv would name 15
		// (Skincare) first purely because it has the lower term id, and
		// ProductImporter would file the serum one level too high.
		'_yoast_wpseo_primary_product_cat' => '22',
		'_yoast_wpseo_focuskw' => 'ginseng serum',
		'_yoast_wpseo_linkdex' => '71',
		'_yoast_wpseo_opengraph-image' => $uploads . '/2019/03/ginseng-og.jpg',
		// The GTIN, in the key that does NOT start with an underscore.
		// docs/FX-YOAST-TIER-CENSUS.md §2: "IT DOES NOT START WITH `_yoast_`."
		'wpseo_global_identifier_values' => serialize( array( 'gtin13' => '8809453510003' ) ),
		/*
		 * EXTRA PRODUCT TABS, as Custom Product Tabs for WooCommerce (YIKES)
		 * stores them. (Lane PI-A) The owner's product page showed "Major
		 * Ingredients" beside Description, and the export carried neither the
		 * key nor the tab. Two real tabs -- classic-editor content with bare
		 * newlines and a list, and a script that must not survive the import --
		 * and one EMPTY tab, which WooCommerce does not draw and the export must
		 * leave out.
		 */
		'yikes_woo_products_tabs' => serialize( array(
			array(
				'title' => 'Major Ingredients',
				'id' => 'major-ingredients',
				'content' => "<strong>Ginseng Root Extract</strong>\r\nNourishes and firms.\r\n\r\n<ul>\r\n<li>Niacinamide</li>\r\n<li>Adenosine</li>\r\n</ul>"
					. '<script>alert(1)</script>',
			),
			array( 'title' => 'How to Use', 'id' => 'how-to-use', 'content' => "Two drops, morning and night.\r\nPat in." ),
			array( 'title' => 'Empty', 'id' => 'empty', 'content' => '  ' ),
		) ),
	) );

	$insert( 'posts', array(
		'ID' => 4022, 'post_author' => 1, 'post_type' => 'product', 'post_status' => 'publish',
		'post_title' => 'Rice Toner', 'post_name' => 'toner-4022',
		'post_content' => 'A toner.', 'post_excerpt' => '',
		'post_date' => '2020-06-01 09:00:00', 'post_date_gmt' => '2020-06-01 05:00:00',
		'post_modified' => '2020-06-01 09:00:00', 'menu_order' => 2,
	) );

	$meta( 'postmeta', 'post_id', 4022, array(
		'_sku' => 'KBB-4022',
		// A thousands separator. Money::fils() accepts it only because the
		// grouping is unambiguous alongside a decimal point; "99,50" would be
		// refused, and correctly.
		'_regular_price' => '1,234.50',
		'_stock' => '4', '_stock_status' => 'instock', '_manage_stock' => 'yes',
		'_yoast_wpseo_metadesc' => 'Only a description was ever written for this one.',
		// A theme's single custom tab (Flatsome's pair), so the second storage
		// shape is exercised, and a tab key no listed plugin writes, which the
		// export must NAME in manifest.json rather than skip in silence.
		'_custom_tab_title' => 'Shipping',
		'_custom_tab' => 'Ships in two days.',
		'_kbb_unknown_tab_plugin' => 'a:0:{}',
	) );

	$insert( 'posts', array(
		'ID' => 4023, 'post_author' => 1, 'post_type' => 'product', 'post_status' => 'publish',
		'post_title' => 'Rice Cleanser', 'post_name' => 'cleanser-4023',
		'post_content' => 'Two sizes.', 'post_excerpt' => '',
		'post_date' => '2021-02-01 09:00:00', 'post_date_gmt' => '2021-02-01 05:00:00',
		'post_modified' => '2021-02-01 09:00:00', 'menu_order' => 3,
	) );

	$meta( 'postmeta', 'post_id', 4023, array(
		'_sku' => 'KBB-4023', '_stock_status' => 'instock',
		'_product_attributes' => serialize( array(
			'pa_size' => array( 'name' => 'pa_size', 'value' => '', 'is_taxonomy' => 1, 'is_variation' => 1 ),
			'Scent'   => array( 'name' => 'Scent', 'value' => 'Unscented', 'is_taxonomy' => 0, 'is_variation' => 0 ),
		) ),
		/*
		 * "Default Form Values" -- the Variations tab's own dropdowns, which
		 * decide WHICH SIZE this product's page opens on.
		 *
		 * MODELLED HERE BECAUSE THE CENSUS IS ONLY AS WIDE AS THIS FILE. The
		 * meta-key half of GqMigrationCensusTest reads DISTINCT meta_key out of
		 * this shop and insists every key is classified, so a field this harness
		 * does not hold is a field that half of the census cannot ask about --
		 * and `_default_attributes` was in the exporter's META_KEYS, fetched on
		 * every batch, emitted by no column, and invisible to every test for
		 * exactly that reason. A shop with variations and no default on any of
		 * them is not the shop being migrated.
		 */
		'_default_attributes' => serialize( array( 'pa_size' => '50ml' ) ),
	) );

	foreach ( array(
		array( 4101, '50ml', '60.00', 'publish', 701 ),
		// `private` is how WooCommerce disables one size of a variable product.
		array( 4102, '100ml', '100.00', 'private', 702 ),
	) as $variation ) {
		list( $id, $label, $price, $status, $term ) = $variation;

		$insert( 'posts', array(
			'ID' => $id, 'post_author' => 1, 'post_type' => 'product_variation', 'post_status' => $status,
			'post_title' => 'Rice Cleanser - ' . $label, 'post_name' => 'cleanser-4023-' . $id,
			'post_content' => '', 'post_excerpt' => '', 'post_parent' => 4023,
			'post_date' => '2021-02-01 09:00:00', 'post_date_gmt' => '2021-02-01 05:00:00',
			'post_modified' => '2021-02-01 09:00:00', 'menu_order' => 1,
		) );

		$meta( 'postmeta', 'post_id', $id, array(
			'_sku' => 'KBB-4023-' . $label, '_regular_price' => $price,
			'_stock_status' => 'instock', 'attribute_pa_size' => strtolower( $label ),
		) );
	}

	// The trashed product. ProductImporter refuses it by name.
	$insert( 'posts', array(
		'ID' => 4024, 'post_author' => 1, 'post_type' => 'product', 'post_status' => 'trash',
		'post_title' => 'Discontinued Sheet Mask', 'post_name' => 'mask-4024__trashed',
		'post_content' => '', 'post_excerpt' => '',
		'post_date' => '2018-01-01 09:00:00', 'post_date_gmt' => '2018-01-01 05:00:00',
		'post_modified' => '2023-01-01 09:00:00',
	) );

	$meta( 'postmeta', 'post_id', 4024, array( '_sku' => 'KBB-4024', '_regular_price' => '25.00', '_stock_status' => 'outofstock' ) );

	// Hidden from catalogue AND search.
	$insert( 'posts', array(
		'ID' => 4025, 'post_author' => 1, 'post_type' => 'product', 'post_status' => 'publish',
		'post_title' => 'Sample Sachet', 'post_name' => 'sachet-4025',
		'post_content' => '', 'post_excerpt' => '',
		'post_date' => '2022-01-01 09:00:00', 'post_date_gmt' => '2022-01-01 05:00:00',
		'post_modified' => '2022-01-01 09:00:00',
	) );

	$meta( 'postmeta', 'post_id', 4025, array( '_sku' => 'KBB-4025', '_regular_price' => '0.00', '_stock_status' => 'instock' ) );

	$relationships = array(
		array( 4021, $tt[22] ), array( 4021, $tt[15] ), array( 4021, $tt[502] ), array( 4021, $tt[601] ), array( 4021, $tt[901] ), array( 4021, $tt[913] ),
		array( 4022, $tt[31] ), array( 4022, $tt[501] ), array( 4022, $tt[901] ),
		array( 4023, $tt[22] ), array( 4023, $tt[501] ), array( 4023, $tt[902] ), array( 4023, $tt[701] ), array( 4023, $tt[702] ),
		array( 4024, $tt[31] ), array( 4024, $tt[901] ),
		array( 4025, $tt[31] ), array( 4025, $tt[901] ), array( 4025, $tt[911] ), array( 4025, $tt[912] ),
		array( 7001, $tt[801] ),
	);

	foreach ( $relationships as $pair ) {
		$insert( 'term_relationships', array( 'object_id' => $pair[0], 'term_taxonomy_id' => $pair[1] ) );
	}

	// ── Coupons ─────────────────────────────────────────────────────────────
	$insert( 'posts', array(
		'ID' => 9101, 'post_author' => 1, 'post_type' => 'shop_coupon', 'post_status' => 'publish',
		'post_title' => 'WELCOME20', 'post_name' => 'welcome20',
		'post_content' => '', 'post_excerpt' => 'Twenty per cent off',
		'post_date' => '2022-01-01 09:00:00', 'post_date_gmt' => '2022-01-01 05:00:00',
		'post_modified' => '2022-01-01 09:00:00',
	) );

	$meta( 'postmeta', 'post_id', 9101, array(
		'discount_type' => 'percent', 'coupon_amount' => '20',
		// Midnight, SITE time, on 1 January 2027 -- which is what WooCommerce
		// stores here, and the case the exporter must emit as a BARE DATE so
		// CouponImporter's inclusive-day correction can fire.
		'date_expires' => (string) strtotime( '2027-01-01 00:00:00 +04:00' ),
		'usage_count' => '462', 'usage_limit' => '1000', 'usage_limit_per_user' => '1',
		'free_shipping' => 'no', 'individual_use' => 'no', 'exclude_sale_items' => 'no',
		'product_categories' => serialize( array( 15, 22 ) ),
	) );

	$insert( 'postmeta', array( 'post_id' => 9101, 'meta_key' => '_used_by', 'meta_value' => 'buyer@example.test' ) );
	$insert( 'postmeta', array( 'post_id' => 9101, 'meta_key' => '_used_by', 'meta_value' => '412' ) );

	// A withdrawn code. CouponImporter refuses it; the export holds it back.
	$insert( 'posts', array(
		'ID' => 9102, 'post_author' => 1, 'post_type' => 'shop_coupon', 'post_status' => 'draft',
		'post_title' => 'OLDCODE', 'post_name' => 'oldcode',
		'post_content' => '', 'post_excerpt' => '',
		'post_date' => '2021-01-01 09:00:00', 'post_date_gmt' => '2021-01-01 05:00:00',
		'post_modified' => '2021-01-01 09:00:00',
	) );

	$meta( 'postmeta', 'post_id', 9102, array( 'discount_type' => 'fixed_cart', 'coupon_amount' => '50' ) );

	// ── Users ───────────────────────────────────────────────────────────────
	$insert( 'users', array(
		'ID' => 1, 'user_login' => 'rafi', 'user_pass' => '$P$Badminhashadminhashadminhash0',
		'user_nicename' => 'rafi', 'user_email' => 'owner@kbeautybliss.com',
		'user_registered' => '2018-01-01 08:00:00', 'display_name' => 'Rafi',
	) );

	$insert( 'users', array(
		'ID' => 412, 'user_login' => 'layla', 'user_pass' => '$P$Bxxxxxxxxxxxxxxxxxxxxxxxxxxxxx0',
		'user_nicename' => 'layla', 'user_email' => 'buyer@example.test',
		'user_registered' => '2019-01-05 08:00:00', 'display_name' => 'Layla Hassan',
	) );

	$insert( 'users', array(
		'ID' => 413, 'user_login' => 'omar', 'user_pass' => '$P$Byyyyyyyyyyyyyyyyyyyyyyyyyyyyy0',
		'user_nicename' => 'omar', 'user_email' => 'omar@example.test',
		'user_registered' => '2020-02-02 08:00:00', 'display_name' => 'Omar Saleh',
	) );

	$meta( 'usermeta', 'user_id', 1, array( $p . 'capabilities' => serialize( array( 'administrator' => true ) ), 'first_name' => 'Rafi', 'last_name' => '' ) );

	$meta( 'usermeta', 'user_id', 412, array(
		$p . 'capabilities' => serialize( array( 'customer' => true ) ),
		'first_name' => 'Layla', 'last_name' => 'Hassan',
		'billing_first_name' => 'Layla', 'billing_last_name' => 'Hassan',
		'billing_address_1' => '12 Marina Walk', 'billing_city' => 'Dubai',
		'billing_state' => 'Dubai', 'billing_postcode' => '00000', 'billing_country' => 'AE',
		'billing_phone' => '+971500000001',
		'shipping_first_name' => 'Layla', 'shipping_last_name' => 'Hassan',
		'shipping_address_1' => '12 Marina Walk', 'shipping_city' => 'Dubai',
		'shipping_state' => 'Dubai', 'shipping_country' => 'AE',
	) );

	$meta( 'usermeta', 'user_id', 413, array(
		$p . 'capabilities' => serialize( array( 'customer' => true ) ),
		'first_name' => 'Omar', 'last_name' => 'Saleh',
		'billing_first_name' => 'Omar', 'billing_last_name' => 'Saleh',
		'billing_address_1' => '3 Al Wasl Road', 'billing_city' => 'Dubai',
		'billing_state' => 'Dubai', 'billing_country' => 'AE', 'billing_phone' => '+971500000010',
	) );

	// ── Orders ──────────────────────────────────────────────────────────────
	$orders = kbb_harness_orders();

	foreach ( $orders as $order ) {
		if ( 'hpos' === $storage ) {
			kbb_harness_insert_hpos( $insert, $order );
		} else {
			kbb_harness_insert_legacy( $insert, $meta, $order );
		}
	}

	// Line items are the SAME table under both storages -- HPOS moved the order
	// header and nothing else.
	foreach ( kbb_harness_items() as $item ) {
		$insert( 'woocommerce_order_items', array(
			'order_item_id' => $item['id'], 'order_item_name' => $item['name'],
			'order_item_type' => $item['type'], 'order_id' => $item['order'],
		) );

		$meta( 'woocommerce_order_itemmeta', 'order_item_id', $item['id'], $item['meta'] );
	}

	// An order note, which HPOS also left in wp_comments.
	$insert( 'comments', array(
		'comment_ID' => 8201, 'comment_post_ID' => 10233, 'comment_author' => 'WooCommerce',
		'comment_author_email' => 'woocommerce@kbeautybliss.com', 'comment_author_IP' => '',
		'comment_date' => '2019-03-06 09:05:00', 'comment_date_gmt' => '2019-03-06 05:05:00',
		'comment_content' => 'Order status changed from Processing to Completed.',
		'comment_approved' => '1', 'comment_type' => 'order_note', 'user_id' => 0,
	) );

	$insert( 'commentmeta', array( 'comment_id' => 8201, 'meta_key' => 'is_customer_note', 'meta_value' => '0' ) );

	// ── Reviews, and the two things that are not reviews ────────────────────
	$insert( 'comments', array(
		'comment_ID' => 8101, 'comment_post_ID' => 4021, 'comment_author' => 'Layla',
		'comment_author_email' => 'buyer@example.test', 'comment_author_IP' => '203.0.113.9',
		'comment_date' => '2024-03-01 10:00:00', 'comment_date_gmt' => '2024-03-01 06:00:00',
		'comment_content' => 'Cleared my skin in a week', 'comment_approved' => '1',
		'comment_type' => 'review', 'user_id' => 412,
	) );

	$meta( 'commentmeta', 'comment_id', 8101, array( 'rating' => '5', 'verified' => '1' ) );

	// HER PHOTOGRAPHS. Two rows under ONE key, which is how the review-photo
	// plugins store a multi-image upload and the shape a meta pivot keeping
	// "the last value for this key" silently reduces to one picture.
	$meta( 'commentmeta', 'comment_id', 8101, array( 'reviews-images' => '9001' ) );
	$meta( 'commentmeta', 'comment_id', 8101, array( 'reviews-images' => '9002' ) );

	// HER OWN FACE, and it is referenced by NOTHING ELSE on this shop.
	//
	// The two rows above are attachments 9001 and 9002 -- the serum's own
	// product photographs, which every other stage already exports. That made
	// the review-photo path look safe when it was not: media.csv carried those
	// two URLs anyway, as product images, so nothing noticed that media.csv had
	// no idea reviews existed.
	//
	// A customer's photograph of herself is the real shape and the one that
	// cannot be re-created. Referenced by no product, no category, no brand and
	// no article, so it appears in media.csv if and only if the review source
	// put it there.
	$meta( 'commentmeta', 'comment_id', 8101, array( 'reviews-images' => $uploads . '/2019/03/layla-selfie.jpg' ) );

	// A key that is NOT a photograph. It is here so the export has something to
	// leave behind and NAME, rather than a fixture in which everything happens
	// to be a picture and the unused-key report never fires.
	$meta( 'commentmeta', 'comment_id', 8101, array( 'wc_review_helpful' => '7' ) );

	// AND SOMEBODY ELSE'S SERVER. A meta value holding an offsite image is the
	// case that turns "any URL ending in .jpg" into this shop hotlinking a
	// stranger's file from a product page, so it must NOT come across.
	$meta( 'commentmeta', 'comment_id', 8101, array( 'imported_avatar' => 'https://evil.test/trophy.jpg' ) );

	// Pre-WooCommerce-3.0: a product review with an EMPTY comment_type.
	$insert( 'comments', array(
		'comment_ID' => 8102, 'comment_post_ID' => 4021, 'comment_author' => '',
		'comment_author_email' => 'anon@example.test', 'comment_author_IP' => '198.51.100.4',
		'comment_date' => '2019-04-02 10:00:00', 'comment_date_gmt' => '2019-04-02 06:00:00',
		'comment_content' => 'Good enough', 'comment_approved' => '1',
		'comment_type' => '', 'user_id' => 0,
	) );

	$meta( 'commentmeta', 'comment_id', 8102, array( 'rating' => '4' ) );

	// The OTHER storage shape: a PHP-serialised array of uploads URLs rather
	// than attachment ids, written http:// before this shop moved to https.
	$meta( 'commentmeta', 'comment_id', 8102, array(
		'cr_photos' => serialize( array(
			'http://kbeautybliss.com/wp-content/uploads/2020/01/skincare-category.jpg',
		) ),
	) );

	// THE THIRD STORAGE SHAPE: a path RELATIVE TO THE UPLOADS ROOT, with no
	// host, no scheme and no leading slash.
	//
	// This is one of the exact two shapes the export's own manifest note used
	// to name as ones it could not read -- "a bare filename, or a path relative
	// to the uploads root" -- and then hand to the owner as homework. It is a
	// photograph if and only if the uploads directory actually holds the file,
	// which run-export.php puts there.
	$meta( 'commentmeta', 'comment_id', 8102, array( 'rp_photo_paths' => '2020/01/layla-review-2.jpg' ) );

	// AND THE SAME SHAPE NAMING A FILE THAT IS NOT THERE. A plugin's
	// bookkeeping value that happens to end in .jpg must NOT become a broken
	// <img> on the new shop, so the stat() is load-bearing in both directions.
	$meta( 'commentmeta', 'comment_id', 8102, array( 'rp_missing' => '2020/01/never-uploaded.jpg' ) );

	// A customer QUESTION: a product comment with no rating. Not a review.
	$insert( 'comments', array(
		'comment_ID' => 8103, 'comment_post_ID' => 4021, 'comment_author' => 'Sara',
		'comment_author_email' => 'sara@example.test', 'comment_author_IP' => '198.51.100.9',
		'comment_date' => '2024-04-02 10:00:00', 'comment_date_gmt' => '2024-04-02 06:00:00',
		'comment_content' => 'Is this suitable for oily skin?', 'comment_approved' => '1',
		'comment_type' => '', 'user_id' => 0,
	) );

	// The shop's reply to 8101, which has no rating of its own.
	$insert( 'comments', array(
		'comment_ID' => 8104, 'comment_post_ID' => 4021, 'comment_author' => 'K Beauty Bliss',
		'comment_author_email' => 'owner@kbeautybliss.com', 'comment_author_IP' => '',
		'comment_date' => '2024-03-02 10:00:00', 'comment_date_gmt' => '2024-03-02 06:00:00',
		'comment_content' => 'Thank you Layla!', 'comment_approved' => '1',
		'comment_type' => 'comment', 'comment_parent' => 8101, 'user_id' => 1,
	) );

	// ── The blog ────────────────────────────────────────────────────────────
	$insert( 'posts', array(
		'ID' => 7001, 'post_author' => 1, 'post_type' => 'post', 'post_status' => 'publish',
		'post_title' => 'How to layer a K-beauty routine', 'post_name' => 'how-to-layer-a-k-beauty-routine',
		'post_content' => 'Start with the thinnest. <img src="' . $uploads . '/2021/05/first-post.jpg">',
		'post_excerpt' => 'Thinnest first.',
		'post_date' => '2021-05-04 09:00:00', 'post_date_gmt' => '2021-05-04 05:00:00',
		'post_modified' => '2021-05-04 09:00:00',
	) );

	$meta( 'postmeta', 'post_id', 7001, array( '_thumbnail_id' => '9005' ) );

	$insert( 'posts', array(
		'ID' => 7002, 'post_author' => 1, 'post_type' => 'page', 'post_status' => 'publish',
		'post_title' => 'About us', 'post_name' => 'about-us',
		'post_content' => 'We sell Korean skincare.', 'post_excerpt' => '',
		'post_date' => '2019-01-01 09:00:00', 'post_date_gmt' => '2019-01-01 05:00:00',
		'post_modified' => '2019-01-01 09:00:00',
	) );

	// A CHILD page, which exists for one reason: its address is /about-us/team/
	// and not /team/. An old-slug row for it is the case that catches a
	// reconstruction which reassembles the URL from a base instead of editing
	// the address WordPress actually reports.
	$insert( 'posts', array(
		'ID' => 7003, 'post_author' => 1, 'post_type' => 'page', 'post_status' => 'publish',
		'post_parent' => 7002,
		'post_title' => 'Our team', 'post_name' => 'team',
		'post_content' => 'Six people and a cat.', 'post_excerpt' => '',
		'post_date' => '2019-01-02 09:00:00', 'post_date_gmt' => '2019-01-02 05:00:00',
		'post_modified' => '2019-01-02 09:00:00',
	) );

	// ── The navigation menu ─────────────────────────────────────────────────
	//
	// WordPress keeps a menu as a `nav_menu` TAXONOMY term whose members are
	// `nav_menu_item` POSTS, each carrying its target in postmeta.
	//
	// THE FIXTURE HAD NO MENU until Lane IE, so the note that said the
	// navigation was lost had never fired and nobody had ever read it. It then
	// had a FLAT menu of three, which proved the note and nothing else. This is
	// the shape the exporter and the importer actually have to survive:
	//
	//   two LEVELS, because `menu_items.parent_id` is a self-referencing key
	//     and a flat menu never exercises it;
	//   a CATEGORY, a BRAND, a PRODUCT and an ARTICLE, because those are four
	//     different tables on the new shop and four different address shapes;
	//   a CUSTOM link, which is the only kind carrying its own URL;
	//   an item pointing at a WordPress PAGE, which this shop REFUSES by name
	//     (PostImporter: it ships its own /about/), so the importer has to
	//     answer for a pointer whose target was deliberately never imported;
	//   items with an EMPTY post_title, because WordPress only writes that
	//     column when the owner types a label OVER the default -- leave the box
	//     alone and the theme prints the target's own name. Most real items are
	//     this shape, and an export that emitted post_title would carry an
	//     empty label for nearly the whole menu.
	$insert( 'terms', array( 'term_id' => 950, 'name' => 'Main menu', 'slug' => 'main-menu' ) );
	$insert( 'term_taxonomy', array(
		'term_taxonomy_id' => 950, 'term_id' => 950, 'taxonomy' => 'nav_menu',
		'description' => 'The header, as the theme draws it.', 'parent' => 0, 'count' => 7,
	) );

	// The theme's own slot assignment. `nav_menu_locations` inside
	// theme_mods_<stylesheet> is what makes one of several menus THE header,
	// and it is the only place that fact is written down.
	$insert( 'options', array(
		'option_name'  => 'theme_mods_kbeautybliss',
		'option_value' => serialize( array(
			'nav_menu_locations' => array( 'primary' => 950, 'handheld' => 950 ),
		) ),
	) );

	$menu_items = array(
		array( 'id' => 7501, 'title' => 'Skincare', 'order' => 1, 'parent' => 0,
			'type' => 'taxonomy', 'object' => 'product_cat', 'object_id' => 15, 'url' => '' ),

		// Empty title: the label has to come off the term.
		array( 'id' => 7504, 'title' => '', 'order' => 1, 'parent' => 7501,
			'type' => 'taxonomy', 'object' => 'product_cat', 'object_id' => 22, 'url' => '' ),

		// A BRAND, which on this shop is a term of the `pa_brands` ATTRIBUTE
		// taxonomy rather than of a brands taxonomy -- BrandImporter's header
		// records that production is shaped this way.
		array( 'id' => 7505, 'title' => '', 'order' => 2, 'parent' => 7501,
			'type' => 'taxonomy', 'object' => 'pa_brands', 'object_id' => 502, 'url' => '' ),

		// A PRODUCT, under a label the owner typed over the product's own name.
		array( 'id' => 7506, 'title' => 'Our hero serum', 'order' => 3, 'parent' => 7501,
			'type' => 'post_type', 'object' => 'product', 'object_id' => 4021, 'url' => '' ),

		// THE ONE THIS SHOP REFUSES. `page` 7002 is /about-us/, and
		// PostImporter declines every WordPress page by name because this shop
		// ships its own /about/. The pointer is valid, the target is
		// deliberately absent, and the importer has to have an answer that is
		// neither "drop it" nor "leave a 404 in the header".
		array( 'id' => 7502, 'title' => 'About us', 'order' => 2, 'parent' => 0,
			'type' => 'post_type', 'object' => 'page', 'object_id' => 7002, 'url' => '' ),

		// An ARTICLE, label off the post title.
		array( 'id' => 7507, 'title' => '', 'order' => 3, 'parent' => 0,
			'type' => 'post_type', 'object' => 'post', 'object_id' => 7001, 'url' => '' ),

		// A hand-typed URL, opening in a new tab, with a CSS class on it.
		array( 'id' => 7503, 'title' => 'Sale', 'order' => 4, 'parent' => 0,
			'type' => 'custom', 'object' => 'custom', 'object_id' => 0,
			'url' => 'https://kbeautybliss.com/super-sale/', 'target' => '_blank',
			'classes' => array( 'menu-sale', 'menu-highlight' ) ),
	);

	foreach ( $menu_items as $item ) {
		$insert( 'posts', array(
			'ID' => $item['id'], 'post_author' => 1, 'post_type' => 'nav_menu_item', 'post_status' => 'publish',
			'post_title' => $item['title'], 'post_name' => (string) $item['id'],
			'post_content' => '', 'post_excerpt' => '',
			'post_date' => '2019-02-01 09:00:00', 'post_date_gmt' => '2019-02-01 05:00:00',
			'post_modified' => '2019-02-01 09:00:00', 'menu_order' => $item['order'],
		) );

		$meta( 'postmeta', 'post_id', $item['id'], array(
			'_menu_item_type'             => $item['type'],
			'_menu_item_object'           => $item['object'],
			'_menu_item_object_id'        => (string) $item['object_id'],
			'_menu_item_menu_item_parent' => (string) $item['parent'],
			'_menu_item_url'              => $item['url'],
			'_menu_item_target'           => isset( $item['target'] ) ? $item['target'] : '',
			// A serialised array of one empty string is what WordPress writes
			// for an item with no classes, and it is the common case.
			'_menu_item_classes'          => serialize( isset( $item['classes'] ) ? $item['classes'] : array( '' ) ),
		) );

		$insert( 'term_relationships', array(
			'object_id' => $item['id'], 'term_taxonomy_id' => 950, 'term_order' => 0,
		) );
	}

	// AN ORPHAN: a menu item whose term relationship was deleted and whose post
	// row was not. Real, and common on a shop somebody has rebuilt a menu on.
	// WordPress renders it nowhere, so neither does the export carry it -- and
	// the count in the manifest note says how many were left behind, because a
	// row silently absent is the thing this whole export is arranged against.
	$insert( 'posts', array(
		'ID' => 7599, 'post_author' => 1, 'post_type' => 'nav_menu_item', 'post_status' => 'publish',
		'post_title' => 'Orphaned item', 'post_name' => '7599',
		'post_content' => '', 'post_excerpt' => '',
		'post_date' => '2019-02-01 09:00:00', 'post_date_gmt' => '2019-02-01 05:00:00',
		'post_modified' => '2019-02-01 09:00:00', 'menu_order' => 9,
	) );

	$meta( 'postmeta', 'post_id', 7599, array(
		'_menu_item_type'             => 'custom',
		'_menu_item_object'           => 'custom',
		'_menu_item_object_id'        => '0',
		'_menu_item_menu_item_parent' => '0',
		'_menu_item_url'              => 'https://kbeautybliss.com/gone/',
	) );

	// ── Previous addresses ──────────────────────────────────────────────────
	//
	// `_wp_old_slug` is what WordPress writes when a PUBLISHED post's slug
	// changes, and wp_old_slug_redirect() -- core, on every front-end 404 --
	// has been 301ing these addresses ever since. They are the addresses Google
	// holds and nothing in this migration had ever read them. A real six-year-
	// old shop has hundreds; the fixture carries the four shapes that matter.
	//
	// meta_id order is the export's cursor, so these are inserted in the order
	// the rows come out.
	$meta( 'postmeta', 'post_id', 4021, array( '_wp_old_slug' => 'vitamin-c-serum' ) );

	// A SECOND rename of the same product. One row per rename, not per post --
	// a reconstruction keyed on the post id would emit one of these and lose
	// the other.
	$meta( 'postmeta', 'post_id', 4021, array( '_wp_old_slug' => 'ginseng-elixir' ) );

	// An article. Its address has no base at all under /%postname%/, which is
	// the structure this shop runs.
	$meta( 'postmeta', 'post_id', 7001, array( '_wp_old_slug' => 'k-beauty-layering' ) );

	// The child page: /about-us/our-team/, NOT /our-team/.
	$meta( 'postmeta', 'post_id', 7003, array( '_wp_old_slug' => 'our-team' ) );

	// And one on an ATTACHMENT, which must NOT appear: `attachment` is in
	// KBB_Export_Stage_Posts::NOT_CONTENT, an attachment address is a media
	// URL rather than a page this shop serves, and emitting it would propose a
	// redirect from an image to a product.
	$meta( 'postmeta', 'post_id', 9001, array( '_wp_old_slug' => 'ginseng-serum-photo-old' ) );
}

/**
 * The three orders and the refund, storage-independent.
 *
 * @return array<int,array<string,mixed>>
 */
function kbb_harness_orders() {
	return array(
		array(
			'id' => 10233, 'parent' => 0, 'type' => 'shop_order', 'status' => 'wc-completed',
			'currency' => 'AED', 'customer' => 412, 'email' => 'buyer@example.test',
			'created_gmt' => '2019-03-04 07:22:33', 'updated_gmt' => '2019-03-06 05:00:00',
			'paid_gmt' => '2019-03-04 07:25:00', 'completed_gmt' => '2019-03-08 10:00:00',
			// 298.50 for three serums plus 60.00 for one 50ml cleanser. The
			// order total and the sum of the lines AGREE, deliberately: a
			// fixture where they disagree cannot tell a real arithmetic bug
			// from itself.
			'total' => '358.50', 'tax' => '0.00', 'shipping' => '0.00', 'discount' => '0.00',
			'payment' => 'cod', 'payment_title' => 'Cash on delivery', 'transaction' => '',
			'note' => 'Leave at reception', 'created_via' => 'checkout', 'order_key' => 'wc_order_aaa',
			'number' => 'KBB-1001',
			'billing' => array( 'first_name' => 'Layla', 'last_name' => 'Hassan', 'address_1' => '12 Marina Walk', 'city' => 'Dubai', 'state' => 'Dubai', 'postcode' => '00000', 'country' => 'AE', 'phone' => '+971500000001' ),
			'shipping_address' => array( 'first_name' => 'Layla', 'last_name' => 'Hassan', 'address_1' => '12 Marina Walk', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE' ),
		),
		array(
			// A GUEST: customer_user is 0, which Row::id() reads as null.
			'id' => 10234, 'parent' => 0, 'type' => 'shop_order', 'status' => 'wc-processing',
			'currency' => 'AED', 'customer' => 0, 'email' => 'guest@example.test',
			'created_gmt' => '2021-07-14 15:45:00', 'updated_gmt' => '2021-07-14 15:45:00',
			'paid_gmt' => '2021-07-14 15:46:00', 'completed_gmt' => null,
			'total' => '1242.00', 'tax' => '0.00', 'shipping' => '10.00', 'discount' => '5.00',
			'payment' => 'stripe', 'payment_title' => 'Card', 'transaction' => 'pi_123',
			'note' => '', 'created_via' => 'checkout', 'order_key' => 'wc_order_bbb',
			'number' => 'KBB-1002',
			'billing' => array( 'first_name' => 'Omar', 'last_name' => 'Saleh', 'address_1' => '3 Al Wasl Road', 'city' => 'Dubai', 'state' => 'Dubai', 'postcode' => '00000', 'country' => 'AE', 'phone' => '+971500000010' ),
			'shipping_address' => array( 'first_name' => 'Omar', 'last_name' => 'Saleh', 'address_1' => '3 Al Wasl Road', 'city' => 'Dubai', 'country' => 'AE' ),
		),
		array(
			'id' => 10235, 'parent' => 0, 'type' => 'shop_order', 'status' => 'wc-refunded',
			'currency' => 'AED', 'customer' => 413, 'email' => 'omar@example.test',
			'created_gmt' => '2023-09-01 06:00:00', 'updated_gmt' => '2023-09-05 06:00:00',
			'paid_gmt' => '2023-09-01 06:01:00', 'completed_gmt' => '2023-09-02 06:00:00',
			'total' => '199.00', 'tax' => '0.00', 'shipping' => '0.00', 'discount' => '0.00',
			'payment' => 'stripe', 'payment_title' => 'Card', 'transaction' => 'pi_456',
			'note' => '', 'created_via' => 'checkout', 'order_key' => 'wc_order_ccc',
			'number' => 'KBB-1003',
			'billing' => array( 'first_name' => 'Omar', 'last_name' => 'Saleh', 'address_1' => '3 Al Wasl Road', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971500000010' ),
			'shipping_address' => array(),
		),
		array(
			// A PARTIAL refund: a child order whose own lines live in the same
			// table as real order lines.
			'id' => 10236, 'parent' => 10235, 'type' => 'shop_order_refund', 'status' => 'wc-completed',
			'currency' => 'AED', 'customer' => 0, 'email' => '',
			'created_gmt' => '2023-09-05 06:00:00', 'updated_gmt' => '2023-09-05 06:00:00',
			'paid_gmt' => null, 'completed_gmt' => null,
			'total' => '-99.50', 'tax' => '0.00', 'shipping' => '0.00', 'discount' => '0.00',
			'payment' => '', 'payment_title' => '', 'transaction' => '',
			'note' => '', 'created_via' => '', 'order_key' => 'wc_order_ddd', 'number' => '',
			'refund_amount' => '99.50', 'refund_reason' => 'One bottle came back', 'refunded_by' => '1',
			'billing' => array(), 'shipping_address' => array(),
		),
	);
}

/** @return array<int,array<string,mixed>> */
function kbb_harness_items() {
	return array(
		array( 'id' => 5501, 'order' => 10233, 'type' => 'line_item', 'name' => 'Ginseng Serum', 'meta' => array(
			'_product_id' => '4021', '_variation_id' => '0', '_qty' => '3',
			'_line_subtotal' => '298.50', '_line_total' => '298.50', '_line_tax' => '0.00',
		) ),
		array( 'id' => 5502, 'order' => 10234, 'type' => 'line_item', 'name' => 'Rice Toner', 'meta' => array(
			'_product_id' => '4022', '_variation_id' => '0', '_qty' => '1',
			'_line_subtotal' => '1234.50', '_line_total' => '1229.50', '_line_tax' => '0.00',
		) ),
		array( 'id' => 5503, 'order' => 10234, 'type' => 'shipping', 'name' => 'Express', 'meta' => array(
			'_line_total' => '10.00',
		) ),
		array( 'id' => 5504, 'order' => 10234, 'type' => 'coupon', 'name' => 'welcome20', 'meta' => array(
			'discount_amount' => '5.00',
		) ),
		array( 'id' => 5505, 'order' => 10234, 'type' => 'fee', 'name' => 'Gift wrap', 'meta' => array(
			'_line_total' => '2.50',
		) ),
		array( 'id' => 5506, 'order' => 10235, 'type' => 'line_item', 'name' => 'Ginseng Serum', 'meta' => array(
			'_product_id' => '4021', '_variation_id' => '0', '_qty' => '2',
			'_line_subtotal' => '199.00', '_line_total' => '199.00', '_line_tax' => '0.00',
		) ),
		// THE REFUND LINE. Same table, negative quantity, order_id pointing at
		// the refund. It must not appear in order_items.csv.
		array( 'id' => 5507, 'order' => 10236, 'type' => 'line_item', 'name' => 'Ginseng Serum', 'meta' => array(
			'_product_id' => '4021', '_variation_id' => '0', '_qty' => '-1',
			'_line_subtotal' => '-99.50', '_line_total' => '-99.50', '_line_tax' => '0.00',
			'_refunded_item_id' => '5506',
		) ),
		// A VARIATION line, so the parent/variation choice is exercised.
		array( 'id' => 5508, 'order' => 10233, 'type' => 'line_item', 'name' => 'Rice Cleanser - 50ml', 'meta' => array(
			'_product_id' => '4023', '_variation_id' => '4101', '_qty' => '1',
			'_line_subtotal' => '60.00', '_line_total' => '60.00', '_line_tax' => '0.00',
		) ),
	);
}

function kbb_harness_insert_legacy( callable $insert, callable $meta, array $order ) {
	$local = static function ( $gmt ) {
		return null === $gmt ? '' : gmdate( 'Y-m-d H:i:s', strtotime( $gmt . ' UTC' ) + 4 * 3600 );
	};

	$insert( 'posts', array(
		'ID' => $order['id'], 'post_author' => 1, 'post_type' => $order['type'],
		'post_status' => $order['status'], 'post_title' => 'Order',
		'post_name' => 'order-' . $order['id'], 'post_content' => '',
		'post_excerpt' => $order['note'], 'post_parent' => $order['parent'],
		'post_date' => $local( $order['created_gmt'] ), 'post_date_gmt' => $order['created_gmt'],
		'post_modified' => $local( $order['updated_gmt'] ),
	) );

	$pairs = array(
		'_order_currency' => $order['currency'],
		'_customer_user'  => (string) $order['customer'],
		'_billing_email'  => $order['email'],
		'_order_total'    => $order['total'],
		'_order_tax'      => $order['tax'],
		'_order_shipping' => $order['shipping'],
		'_cart_discount'  => $order['discount'],
		'_payment_method' => $order['payment'],
		'_payment_method_title' => $order['payment_title'],
		'_transaction_id' => $order['transaction'],
		'_created_via'    => $order['created_via'],
		'_order_key'      => $order['order_key'],
	);

	if ( '' !== $order['number'] ) {
		$pairs['_order_number'] = $order['number'];
	}

	if ( null !== $order['paid_gmt'] ) {
		$pairs['_date_paid'] = (string) strtotime( $order['paid_gmt'] . ' UTC' );
	}

	if ( null !== $order['completed_gmt'] ) {
		$pairs['_date_completed'] = (string) strtotime( $order['completed_gmt'] . ' UTC' );
	}

	foreach ( array( 'refund_amount', 'refund_reason', 'refunded_by' ) as $key ) {
		if ( isset( $order[ $key ] ) ) {
			$pairs[ '_' . $key ] = (string) $order[ $key ];
		}
	}

	foreach ( array( 'billing' => 'billing', 'shipping_address' => 'shipping' ) as $source => $prefix ) {
		foreach ( $order[ $source ] as $field => $value ) {
			$pairs[ '_' . $prefix . '_' . $field ] = $value;
		}
	}

	$meta( 'postmeta', 'post_id', $order['id'], $pairs );
}

function kbb_harness_insert_hpos( callable $insert, array $order ) {
	$insert( 'wc_orders', array(
		'id' => $order['id'], 'status' => $order['status'], 'currency' => $order['currency'],
		'type' => $order['type'], 'tax_amount' => $order['tax'], 'total_amount' => $order['total'],
		'customer_id' => $order['customer'], 'billing_email' => $order['email'],
		'date_created_gmt' => $order['created_gmt'], 'date_updated_gmt' => $order['updated_gmt'],
		'parent_order_id' => $order['parent'], 'payment_method' => $order['payment'],
		'payment_method_title' => $order['payment_title'], 'transaction_id' => $order['transaction'],
		'customer_note' => $order['note'],
	) );

	$insert( 'wc_order_operational_data', array(
		'order_id' => $order['id'], 'created_via' => $order['created_via'], 'order_key' => $order['order_key'],
		'date_paid_gmt' => $order['paid_gmt'], 'date_completed_gmt' => $order['completed_gmt'],
		'discount_total_amount' => $order['discount'], 'discount_tax_amount' => '0.00',
		'shipping_total_amount' => $order['shipping'], 'shipping_tax_amount' => '0.00',
		'cart_tax_amount' => '0.00',
	) );

	foreach ( array( 'billing' => 'billing', 'shipping_address' => 'shipping' ) as $source => $type ) {
		if ( empty( $order[ $source ] ) ) {
			continue;
		}

		$insert( 'wc_order_addresses', array_merge(
			array( 'order_id' => $order['id'], 'address_type' => $type ),
			$order[ $source ]
		) );
	}

	if ( '' !== $order['number'] ) {
		$insert( 'wc_orders_meta', array( 'order_id' => $order['id'], 'meta_key' => '_order_number', 'meta_value' => $order['number'] ) );
	}

	foreach ( array( 'refund_amount', 'refund_reason', 'refunded_by' ) as $key ) {
		if ( isset( $order[ $key ] ) ) {
			$insert( 'wc_orders_meta', array( 'order_id' => $order['id'], 'meta_key' => '_' . $key, 'meta_value' => (string) $order[ $key ] ) );
		}
	}
}
