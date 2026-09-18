<?php
/**
 * `posts.csv` -- a **gap** file: the blog, the pages, and any other public post
 * type this shop has rows for.
 *
 * docs/FV-IMPORT-AT-VOLUME.md §11 names two empty tables after a complete
 * import: "`posts` | the blog | 0 rows after the import" and "`pages` |
 * WordPress pages | 7 rows after the import -- all of them this application's
 * own, none from WordPress."
 *
 * ── ONE FILE FOR BOTH, WITH A `type` COLUMN ────────────────────────────────
 *
 * A post and a page differ in WordPress by one column. Two files would need two
 * importers, two orders and two chances to import half of it; one file with the
 * type on the row is the same information and cannot be half-imported.
 *
 * ── WHICH TYPES ─────────────────────────────────────────────────────────────
 *
 * Everything in `wp_posts` that is not already carried by another file and is
 * not WordPress's own machinery. The denylist below is explicit rather than an
 * allowlist of `post` and `page`, because a shop that ran a page builder, a
 * testimonial plugin or a landing-page tool has content in a post type nobody
 * remembers the name of -- and an allowlist would drop it silently, which is
 * the one outcome this whole export is arranged to avoid. Anything caught by
 * the denylist is counted in the manifest notes with its type name, so the
 * owner can see what was left behind and ask for it.
 */

defined( 'ABSPATH' ) || exit;

class KBB_Export_Stage_Posts extends KBB_Export_Stage {

	/**
	 * Post types another file carries, or that are WordPress's own plumbing.
	 *
	 * `attachment` is here because media.csv carries the attachments the
	 * catalogue REFERENCES, which is a smaller and more useful set than every
	 * row WordPress ever made -- see that stage for why.
	 */
	const NOT_CONTENT = array(
		'product', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_coupon',
		'shop_order_placehold', 'shop_webhook', 'shop_subscription',
		'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset',
		'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part',
		'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face',
		'scheduled-action', 'action-scheduler-action',
	);

	/** Statuses that are content rather than WordPress's working copies. */
	const STATUSES = array( 'publish', 'draft', 'private', 'pending', 'future' );

	public function file() {
		return 'posts.csv';
	}

	public function columns() {
		return array(
			'id', 'type', 'slug', 'status', 'title', 'excerpt', 'content',
			'author_id', 'author_name', 'author_email',
			'date_created', 'date_created_gmt', 'date_modified',
			'parent_id', 'position', 'image', 'categories', 'tags', 'comment_status',
		);
	}

	private function type_filter() {
		$denied = implode( ',', array_map( array( 'KBB_Export_Wp', 'quote' ), self::NOT_CONTENT ) );
		$states = implode( ',', array_map( array( 'KBB_Export_Wp', 'quote' ), self::STATUSES ) );

		return 'post_type NOT IN (' . $denied . ') AND post_status IN (' . $states . ')';
	}

	public function total() {
		global $wpdb;

		return (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'posts WHERE ' . $this->type_filter()
		);
	}

	public function batch( $cursor, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT ID, post_type, post_name, post_status, post_title, post_excerpt, post_content,
			        post_author, post_date, post_date_gmt, post_modified, post_parent, menu_order, comment_status
			 FROM ' . $wpdb->prefix . 'posts
			 WHERE ' . $this->type_filter() . ' AND ID > ' . (int) $cursor . '
			 ORDER BY ID
			 LIMIT ' . (int) $limit,
			ARRAY_A
		);

		$rows = (array) $rows;

		if ( empty( $rows ) ) {
			$this->report_skipped_types();

			return array( 'rows' => array(), 'cursor' => (int) $cursor, 'done' => true );
		}

		$ids     = $this->ids_of( $rows );
		$meta    = KBB_Export_Wp::post_meta( $ids, array( '_thumbnail_id' ) );
		$terms   = KBB_Export_Wp::terms_for( $ids, array( 'category', 'post_tag' ) );
		$authors = $this->authors( $rows );

		$out = array();

		foreach ( $rows as $row ) {
			$id     = (int) $row['ID'];
			$author = (int) $row['post_author'];
			$t      = isset( $terms[ $id ] ) ? $terms[ $id ] : array();

			$out[] = array(
				'id'      => $id,
				'type'    => $row['post_type'],
				'slug'    => $row['post_name'],
				'status'  => $row['post_status'],
				'title'   => $row['post_title'],
				'excerpt' => $row['post_excerpt'],
				'content' => $row['post_content'],
				'author_id'    => $author,
				'author_name'  => isset( $authors[ $author ]['display_name'] ) ? $authors[ $author ]['display_name'] : '',
				'author_email' => isset( $authors[ $author ]['user_email'] ) ? $authors[ $author ]['user_email'] : '',
				'date_created'     => $row['post_date'],
				'date_created_gmt' => $row['post_date_gmt'],
				'date_modified'    => $row['post_modified'],
				'parent_id' => (int) $row['post_parent'],
				'position'  => (int) $row['menu_order'],
				'image'     => isset( $meta[ $id ]['_thumbnail_id'] ) ? KBB_Export_Wp::attachment_url( $meta[ $id ]['_thumbnail_id'] ) : '',
				'categories' => $this->slugs( $t, 'category' ),
				'tags'       => $this->slugs( $t, 'post_tag' ),
				'comment_status' => $row['comment_status'],
			);
		}

		$done = count( $rows ) < (int) $limit;

		if ( $done ) {
			$this->report_skipped_types();
		}

		return array( 'rows' => $out, 'cursor' => (int) end( $ids ), 'done' => $done );
	}

	private function slugs( array $terms, $taxonomy ) {
		if ( empty( $terms[ $taxonomy ] ) ) {
			return '';
		}

		$out = array();

		foreach ( $terms[ $taxonomy ] as $term ) {
			$out[] = $term['slug'];
		}

		return $this->commas( $out );
	}

	/** @return array<int,array<string,string>> */
	private function authors( array $rows ) {
		global $wpdb;

		$ids = array();

		foreach ( $rows as $row ) {
			$ids[] = (int) $row['post_author'];
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$found = $wpdb->get_results(
			'SELECT ID, display_name, user_email FROM ' . $wpdb->prefix . 'users
			 WHERE ID IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')',
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $found as $row ) {
			$out[ (int) $row['ID'] ] = $row;
		}

		return $out;
	}

	/**
	 * What was left behind, by type and with its count.
	 *
	 * Deliberately excludes the types another file carries -- naming `product`
	 * here would be a lie, since products.csv has them -- and deliberately
	 * includes `attachment`, because "media.csv carries the ones the catalogue
	 * references" and "every attachment WordPress ever made is in this export"
	 * are different claims and only the first is true.
	 */
	private function report_skipped_types() {
		$carried = array( 'product', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_coupon' );
		$parts   = array();

		foreach ( KBB_Export_Wp::post_types_in_database() as $type => $by_status ) {
			if ( ! in_array( $type, self::NOT_CONTENT, true ) || in_array( $type, $carried, true ) ) {
				continue;
			}

			$total = 0;

			foreach ( $by_status as $status => $count ) {
				if ( in_array( $status, self::STATUSES, true ) || 'inherit' === $status ) {
					$total += $count;
				}
			}

			if ( $total > 0 ) {
				$parts[] = $total . ' ' . $type;
			}
		}

		if ( ! empty( $parts ) ) {
			$this->note(
				'posts.csv does not carry these WordPress post types, which are either another file\'s job or '
					. 'WordPress\'s own machinery: ' . implode( ', ', $parts ) . '. `attachment` in that list is '
					. 'the whole media library; media.csv carries the attachments the catalogue actually '
					. 'references, which is a smaller set and the one the new shop has to fetch.'
			);
		}
	}
}
