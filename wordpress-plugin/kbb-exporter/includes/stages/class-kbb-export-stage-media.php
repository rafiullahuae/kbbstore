<?php
/**
 * `media.csv` -- a **gap** file: every attachment URL the catalogue references.
 *
 * ============================================================================
 * WHY THIS FILE EXISTS, IN THE WORDS OF THE LANE THAT MEASURED IT
 * ============================================================================
 *
 * docs/GB-MEDIA-AND-REDIRECTS.md §4.1: "after a clean, fully verified import,
 * every product image on the new shop is served by WordPress and dies the day
 * it is switched off. MediaAudit counts them; nothing moved them."
 * docs/GD-MEDIA-SIDELOADER.md then built the downloader that moves them. What
 * neither has is a LIST -- the sideloader re-derives its work from the
 * catalogue every request, which is right, but it can only see the URLs the
 * import already wrote into a column. An image referenced from the middle of a
 * product description is not in a column, and nothing has ever fetched one.
 *
 * So this file is the complete answer to "what does the new shop have to
 * fetch", taken from the old shop while it is still up.
 *
 * ── ONE ROW PER (URL, REFERRER), NOT PER URL ────────────────────────────────
 *
 * Deduplicating across the whole export would mean holding every URL seen so
 * far in memory or in an option, across requests, for the length of the export
 * -- and this stage is the last one, on a shop with thousands of images. The
 * pair is also more useful: `url` alone says what to fetch, and `referenced_by`
 * says which product goes blank if the fetch fails, which is the thing an owner
 * actually wants to know. A consumer that wants the download list groups by
 * `url`; nothing is lost and nothing has to be remembered between requests.
 *
 * ── THE SIZES ARE THE ONES REFERENCED, WHICH IS THE POINT ───────────────────
 *
 * Attachment ids resolve to their FULL size, because that is what
 * wp_get_attachment_url() returns and therefore what products.csv carries. A
 * URL found inside HTML is whatever the editor inserted -- often `-300x300` --
 * and KBB_Export_Media_Index names which registered size that is by asking the
 * attachment's own metadata. What is NOT here is the other four thumbnails
 * WordPress generated and nothing ever asked for.
 *
 * ── AND `exists` IS CHECKED, BECAUSE A MISSING FILE IS THE USEFUL ROW ───────
 *
 * The same three-way answer App\Services\Import\MediaAudit gives, taken at the
 * source instead of at the destination: a file the media library names and the
 * disk does not have is one the new shop will never be able to fetch, and
 * finding that out now is worth one stat() per row.
 */

defined( 'ABSPATH' ) || exit;

class KBB_Export_Stage_Media extends KBB_Export_Stage {

	/** The things that can reference a picture, walked in this fixed order. */
	const SOURCE_STRIDE = 10000000000;

	/** @var array<int,string>|null */
	private $sources;

	public function file() {
		return 'media.csv';
	}

	public function columns() {
		return array(
			'url', 'attachment_id', 'size', 'path', 'exists', 'bytes',
			'referenced_by', 'referenced_id', 'field',
		);
	}

	/** @return array<int,string> */
	private function sources() {
		if ( null !== $this->sources ) {
			return $this->sources;
		}

		$this->sources = array( 'product', 'product_cat', 'brand', 'post', 'review' );

		return $this->sources;
	}

	/**
	 * One per referencing OBJECT, which is an UNDER-estimate of the rows.
	 *
	 * This stage writes one row per (url, referrer, field): the fixture's serum
	 * alone is four, and a real product is a featured image plus a gallery of
	 * four plus whatever the description embeds. The true count cannot be known
	 * without doing the work -- which would mean scanning every description
	 * twice, once to count and once to write.
	 *
	 * So the denominator is honestly wrong here, and the RUNNER is where that is
	 * made safe rather than here: progress() divides by max(total, written) and
	 * caps the percentage at 99 until the export is actually done, so 100% is a
	 * statement about finishing rather than about arithmetic. See the comment
	 * there; it was written after this stage's bar sat at 100% while it worked.
	 */
	public function total() {
		global $wpdb;

		$products = (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . "posts WHERE post_type = 'product'"
		);

		$categories = (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . "term_taxonomy WHERE taxonomy = 'product_cat'"
		);

		$brand  = KBB_Export_Wp::brand_taxonomy();
		$brands = '' === $brand ? 0 : (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'term_taxonomy WHERE taxonomy = ' . KBB_Export_Wp::quote( $brand )
		);

		$posts = (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . "posts WHERE post_type IN ('post','page') AND post_status <> 'auto-draft'"
		);

		$reviews = (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'comments c WHERE ' . self::review_where()
		);

		return $products + $categories + $brands + $posts + $reviews;
	}

	public function batch( $cursor, $limit ) {
		$sources = $this->sources();

		$cursor = (int) $cursor;
		$index  = intdiv( $cursor, self::SOURCE_STRIDE );
		$inner  = $cursor % self::SOURCE_STRIDE;

		while ( $index < count( $sources ) ) {
			$method = 'from_' . $sources[ $index ];
			$result = $this->$method( $inner, $limit );

			if ( ! empty( $result['rows'] ) || $result['cursor'] > $inner ) {
				$next = $result['exhausted']
					? ( $index + 1 ) * self::SOURCE_STRIDE
					: $index * self::SOURCE_STRIDE + (int) $result['cursor'];

				return array(
					'rows'   => $result['rows'],
					'cursor' => $next,
					'done'   => $result['exhausted'] && $index + 1 >= count( $sources ),
				);
			}

			$index++;
			$inner = 0;
		}

		return array( 'rows' => array(), 'cursor' => $cursor, 'done' => true );
	}

	/** @return array{rows: array<int,array<string,mixed>>, cursor: int, exhausted: bool} */
	private function from_product( $cursor, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT ID, post_content, post_excerpt FROM ' . $wpdb->prefix . "posts
			 WHERE post_type = 'product' AND post_status <> 'auto-draft' AND ID > " . (int) $cursor . '
			 ORDER BY ID
			 LIMIT ' . (int) $limit,
			ARRAY_A
		);

		$rows = (array) $rows;

		if ( empty( $rows ) ) {
			return array( 'rows' => array(), 'cursor' => (int) $cursor, 'exhausted' => true );
		}

		$ids  = $this->ids_of( $rows );
		$meta = KBB_Export_Wp::post_meta( $ids, array( '_thumbnail_id', '_product_image_gallery' ) );

		$references = array();

		foreach ( $rows as $row ) {
			$id = (int) $row['ID'];
			$m  = isset( $meta[ $id ] ) ? $meta[ $id ] : array();

			if ( isset( $m['_thumbnail_id'] ) ) {
				$this->add_by_id( $references, $m['_thumbnail_id'], 'product', $id, 'image' );
			}

			if ( isset( $m['_product_image_gallery'] ) ) {
				foreach ( array_filter( array_map( 'intval', explode( ',', (string) $m['_product_image_gallery'] ) ) ) as $attachment ) {
					$this->add_by_id( $references, $attachment, 'product', $id, 'images' );
				}
			}

			foreach ( KBB_Export_Media_Index::urls_in_html( $row['post_content'] ) as $url ) {
				$references[] = array( 'url' => $url, 'by' => 'product', 'id' => $id, 'field' => 'description' );
			}

			foreach ( KBB_Export_Media_Index::urls_in_html( $row['post_excerpt'] ) as $url ) {
				$references[] = array( 'url' => $url, 'by' => 'product', 'id' => $id, 'field' => 'short_description' );
			}
		}

		return array(
			'rows'      => $this->rows_for( $references ),
			'cursor'    => (int) end( $ids ),
			'exhausted' => count( $rows ) < (int) $limit,
		);
	}

	/** @return array{rows: array<int,array<string,mixed>>, cursor: int, exhausted: bool} */
	private function from_product_cat( $cursor, $limit ) {
		return $this->from_terms( 'product_cat', 'category', 'image', $cursor, $limit );
	}

	/** @return array{rows: array<int,array<string,mixed>>, cursor: int, exhausted: bool} */
	private function from_brand( $cursor, $limit ) {
		$taxonomy = KBB_Export_Wp::brand_taxonomy();

		if ( '' === $taxonomy ) {
			return array( 'rows' => array(), 'cursor' => (int) $cursor, 'exhausted' => true );
		}

		return $this->from_terms( $taxonomy, 'brand', 'logo', $cursor, $limit );
	}

	/** @return array{rows: array<int,array<string,mixed>>, cursor: int, exhausted: bool} */
	private function from_terms( $taxonomy, $label, $field, $cursor, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT t.term_id FROM ' . $wpdb->prefix . 'term_taxonomy tt
			 JOIN ' . $wpdb->prefix . 'terms t ON t.term_id = tt.term_id
			 WHERE tt.taxonomy = ' . KBB_Export_Wp::quote( $taxonomy ) . ' AND t.term_id > ' . (int) $cursor . '
			 ORDER BY t.term_id
			 LIMIT ' . (int) $limit,
			ARRAY_A
		);

		$rows = (array) $rows;

		if ( empty( $rows ) ) {
			return array( 'rows' => array(), 'cursor' => (int) $cursor, 'exhausted' => true );
		}

		$ids  = $this->ids_of( $rows, 'term_id' );
		$meta = KBB_Export_Wp::term_meta( $ids, array( 'thumbnail_id' ) );

		$references = array();

		foreach ( $ids as $id ) {
			if ( isset( $meta[ $id ]['thumbnail_id'] ) ) {
				$this->add_by_id( $references, $meta[ $id ]['thumbnail_id'], $label, $id, $field );
			}
		}

		return array(
			'rows'      => $this->rows_for( $references ),
			'cursor'    => (int) end( $ids ),
			'exhausted' => count( $rows ) < (int) $limit,
		);
	}

	/** @return array{rows: array<int,array<string,mixed>>, cursor: int, exhausted: bool} */
	private function from_post( $cursor, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT ID, post_type, post_content FROM ' . $wpdb->prefix . "posts
			 WHERE post_type IN ('post','page') AND post_status <> 'auto-draft' AND ID > " . (int) $cursor . '
			 ORDER BY ID
			 LIMIT ' . (int) $limit,
			ARRAY_A
		);

		$rows = (array) $rows;

		if ( empty( $rows ) ) {
			return array( 'rows' => array(), 'cursor' => (int) $cursor, 'exhausted' => true );
		}

		$ids  = $this->ids_of( $rows );
		$meta = KBB_Export_Wp::post_meta( $ids, array( '_thumbnail_id' ) );

		$references = array();

		foreach ( $rows as $row ) {
			$id = (int) $row['ID'];

			if ( isset( $meta[ $id ]['_thumbnail_id'] ) ) {
				$this->add_by_id( $references, $meta[ $id ]['_thumbnail_id'], $row['post_type'], $id, 'image' );
			}

			foreach ( KBB_Export_Media_Index::urls_in_html( $row['post_content'] ) as $url ) {
				$references[] = array( 'url' => $url, 'by' => $row['post_type'], 'id' => $id, 'field' => 'content' );
			}
		}

		return array(
			'rows'      => $this->rows_for( $references ),
			'cursor'    => (int) end( $ids ),
			'exhausted' => count( $rows ) < (int) $limit,
		);
	}

	/**
	 * The definition of a product review, copied from the reviews stage.
	 *
	 * A comment on a product post carrying a non-empty `rating` meta. It is a
	 * constant here and a private method there rather than one shared thing,
	 * because the two ask it of different tables at different points in the
	 * run -- but it is the same sentence, and if the two ever disagree it is
	 * this file that goes quiet rather than loud: media.csv would stop listing
	 * the photographs of reviews reviews.csv is still exporting.
	 * GeWpExporterTest asserts the two agree on the fixture.
	 */
	const REVIEW_WHERE = "c.comment_post_ID IN (SELECT ID FROM %1\$sposts WHERE post_type = 'product')
			AND EXISTS (SELECT 1 FROM %1\$scommentmeta cm WHERE cm.comment_id = c.comment_ID AND cm.meta_key = 'rating' AND cm.meta_value <> '')";

	/** REVIEW_WHERE with this install's table prefix in it. */
	private static function review_where() {
		global $wpdb;

		return sprintf( self::REVIEW_WHERE, $wpdb->prefix );
	}

	/**
	 * THE CUSTOMERS' OWN PHOTOGRAPHS, AND THE ONLY FILE THAT SAYS "FETCH THIS".
	 *
	 * ========================================================================
	 * THE DEFECT THIS SOURCE EXISTS FOR
	 * ========================================================================
	 *
	 * reviews.csv has carried an `images` column since the photographs were
	 * first exported. It names the ADDRESSES of a shopper's pictures on the old
	 * site -- and this file, the list of files the new shop has to fetch before
	 * the old one is switched off, walked products, categories, brands and
	 * articles and NOT reviews.
	 *
	 * On the fixture that looked harmless, because the fixture's review photos
	 * happen to be the product's photos too. On a real shop they are not: a
	 * customer's photograph of her own face is referenced by NOTHING else, so
	 * it was in no row of media.csv, `App\Services\Import\MediaAudit` on the
	 * other side did not read `reviews.images` either, and the number the
	 * runbook tells the owner to watch reached zero with every customer
	 * photograph still served by the site he was about to switch off.
	 *
	 * A review photograph is the one picture on a shop that cannot be
	 * re-created. The owner can retype a review; he cannot retype a customer's
	 * picture of her own face, and she is not going to send it again.
	 *
	 * ── A `field` OF ITS OWN, WHICH IS WHAT THE OWNER ACTS ON ───────────────
	 *
	 * `referenced_by` is `review` and `referenced_id` is the comment id, which
	 * is `reviews.source_id` on the new shop. So a fetch that fails names the
	 * review that goes blank, in the vocabulary the import report already uses.
	 *
	 * @return array{rows: array<int,array<string,mixed>>, cursor: int, exhausted: bool}
	 */
	private function from_review( $cursor, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT c.comment_ID FROM ' . $wpdb->prefix . 'comments c
			 WHERE ' . self::review_where() . ' AND c.comment_ID > ' . (int) $cursor . '
			 ORDER BY c.comment_ID
			 LIMIT ' . (int) $limit,
			ARRAY_A
		);

		$rows = (array) $rows;

		if ( empty( $rows ) ) {
			return array( 'rows' => array(), 'cursor' => (int) $cursor, 'exhausted' => true );
		}

		$ids        = $this->ids_of( $rows, 'comment_ID' );
		$photos     = KBB_Export_Review_Photos::for_comments( $ids );
		$references = array();

		foreach ( $ids as $id ) {
			if ( ! isset( $photos[ $id ] ) ) {
				continue;
			}

			foreach ( $photos[ $id ] as $url ) {
				$references[] = array( 'url' => $url, 'by' => 'review', 'id' => $id, 'field' => 'images' );
			}
		}

		return array(
			'rows'      => $this->rows_for( $references ),
			'cursor'    => (int) end( $ids ),
			'exhausted' => count( $rows ) < (int) $limit,
		);
	}

	private function add_by_id( array &$references, $attachment_id, $by, $id, $field ) {
		$url = KBB_Export_Wp::attachment_url( $attachment_id );

		if ( '' === $url ) {
			return;
		}

		$references[] = array( 'url' => $url, 'by' => $by, 'id' => $id, 'field' => $field );
	}

	/**
	 * @param array<int,array<string,mixed>> $references
	 * @return array<int,array<string,mixed>>
	 */
	private function rows_for( array $references ) {
		if ( empty( $references ) ) {
			return array();
		}

		$urls     = array();
		$seen     = array();
		$deduped  = array();

		foreach ( $references as $reference ) {
			$key = $reference['url'] . '|' . $reference['by'] . '|' . $reference['id'] . '|' . $reference['field'];

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$deduped[]    = $reference;
			$urls[]       = $reference['url'];
		}

		$resolved = KBB_Export_Media_Index::resolve( array_values( array_unique( $urls ) ) );
		$basedir  = KBB_Export_Wp::uploads_dir();

		$out = array();

		foreach ( $deduped as $reference ) {
			$url  = $reference['url'];
			$info = isset( $resolved[ $url ] ) ? $resolved[ $url ] : array( 'attachment_id' => 0, 'size' => 'unknown', 'path' => '' );

			$absolute = '' !== $info['path'] && '' !== $basedir ? $basedir . '/' . $info['path'] : '';
			$exists   = '' !== $absolute && file_exists( $absolute );

			$out[] = array(
				'url'           => $url,
				'attachment_id' => $info['attachment_id'] > 0 ? $info['attachment_id'] : '',
				'size'          => $info['size'],
				'path'          => $info['path'],
				'exists'        => $exists ? 'yes' : 'no',
				'bytes'         => $exists ? (string) filesize( $absolute ) : '',
				'referenced_by' => $reference['by'],
				'referenced_id' => $reference['id'],
				'field'         => $reference['field'],
			);
		}

		return $out;
	}
}
