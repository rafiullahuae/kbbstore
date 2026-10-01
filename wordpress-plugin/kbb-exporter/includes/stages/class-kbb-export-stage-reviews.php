<?php
/**
 * `reviews.csv` -- WordPress comments on products that carry a star rating.
 *
 * ── ONLY COMMENTS WITH A RATING, AND THAT IS THE IMPORTER'S RULE ────────────
 *
 * ReviewImporter refuses a row with no rating, and its header explains why at
 * length: "A WordPress comment on a product with no rating meta is a genuine
 * thing -- it is a QUESTION, or a reply to a review -- and importing it would
 * default it to FIVE STARS: a perfect score this shop then averages into the
 * product's rating and publishes as structured data. Nobody wrote that five."
 *
 * So a product comment with no `rating` meta is not exported as a review. It is
 * COUNTED and named in manifest.json, because the alternative is an export that
 * silently drops a customer question and an import report with nothing in it to
 * say so.
 *
 * ── AND `comment_type` IS NORMALISED, WHICH IS THE ONE VALUE THIS PLUGIN
 *    REWRITES ───────────────────────────────────────────────────────────────
 *
 * WooCommerce only started writing `comment_type = 'review'` in 3.0. Before
 * that a product review was a comment with an EMPTY type on a product post, and
 * a shop trading since 2019 has both. ReviewImporter::assertIsAReview() refuses
 * anything whose type is present and is not `review`:
 *
 *     throw RowRejected::because("comment_type '".$type."' is not a review...")
 *
 * -- and an empty string is present. So a straight copy would refuse every
 * pre-3.0 review in the shop with a message telling the owner to "export with
 * comment_type = 'review'", which is precisely what this is doing.
 *
 * The rewrite is safe because of what it is conditioned on: the row is a
 * comment, on a post of type `product`, carrying a `rating` meta. That is the
 * definition of a product review; there is no other thing it could be. It is
 * counted in the notes all the same, because a value this plugin changed is a
 * value the owner should be told about.
 *
 * ── THE REPLY ───────────────────────────────────────────────────────────────
 *
 * `reviews.reply` in the shop is the shop's answer to a review. In WordPress
 * that is a CHILD comment, and if it were not carried it would vanish -- it has
 * no rating of its own, so the rating filter above would drop it, and there is
 * no other file it belongs in. The first child comment's content is carried on
 * the parent's row.
 */

defined( 'ABSPATH' ) || exit;

class KBB_Export_Stage_Reviews extends KBB_Export_Stage {

	public function file() {
		return 'reviews.csv';
	}

	/** Exactly the aliases ReviewImporter reads, most canonical first. */
	public function columns() {
		return array(
			'comment_id', 'comment_post_id', 'comment_type', 'author', 'email', 'rating',
			'title', 'content', 'comment_approved', 'comment_date', 'comment_date_gmt',
			'verified', 'user_id', 'ip', 'reply', 'images', 'helpful', 'source',
		);
	}

	/**
	 * The `WHERE` that defines a review: a comment on a product, with a rating.
	 *
	 * The rating join is an EXISTS rather than an INNER JOIN so a comment with
	 * two `rating` rows -- which a badly behaved import plugin can leave behind
	 * -- is one review rather than two.
	 */
	private function where() {
		global $wpdb;

		return "c.comment_post_ID IN (SELECT ID FROM {$wpdb->prefix}posts WHERE post_type = 'product')
			AND EXISTS (SELECT 1 FROM {$wpdb->prefix}commentmeta cm WHERE cm.comment_id = c.comment_ID AND cm.meta_key = 'rating' AND cm.meta_value <> '')";
	}

	public function total() {
		global $wpdb;

		return (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'comments c WHERE ' . $this->where()
		) + $this->dream_count();
	}

	public function batch( $cursor, $limit ) {
		global $wpdb;

		// A negative cursor is the second phase: Dream Code Reviews' own table.
		// -1 is its start, -(id + 1) resumes after row `id`. See dream_batch().
		if ( (int) $cursor < 0 ) {
			return $this->dream_batch( -(int) $cursor - 1, $limit );
		}

		$rows = $wpdb->get_results(
			'SELECT c.comment_ID, c.comment_post_ID, c.comment_type, c.comment_author, c.comment_author_email,
			        c.comment_author_IP, c.comment_date, c.comment_date_gmt, c.comment_content,
			        c.comment_approved, c.user_id
			 FROM ' . $wpdb->prefix . 'comments c
			 WHERE ' . $this->where() . ' AND c.comment_ID > ' . (int) $cursor . '
			 ORDER BY c.comment_ID
			 LIMIT ' . (int) $limit,
			ARRAY_A
		);

		$rows = (array) $rows;

		if ( empty( $rows ) ) {
			$this->report_unrated();
			$this->report_photographs();

			if ( '' !== $this->dream_table() ) {
				return array( 'rows' => array(), 'cursor' => -1, 'done' => false );
			}

			return array( 'rows' => array(), 'cursor' => (int) $cursor, 'done' => true );
		}

		$ids = array();

		foreach ( $rows as $row ) {
			$ids[] = (int) $row['comment_ID'];
		}

		$meta    = KBB_Export_Wp::comment_meta( $ids, array( 'rating', 'verified', 'wcpr_vote_up_count' ) );
		$replies = $this->replies( $ids );
		$photos  = $this->photographs( $ids );
		$copies  = $this->dream_copies( $ids );

		$normalised = 0;
		$out        = array();

		foreach ( $rows as $row ) {
			$id   = (int) $row['comment_ID'];
			$m    = isset( $meta[ $id ] ) ? $meta[ $id ] : array();
			$type = (string) $row['comment_type'];

			if ( 'review' !== $type ) {
				$normalised++;
				$type = 'review';
			}

			// The Dream Code copy of this very review, if the plugin's "Sync
			// WooCommerce reviews" made one. That copy is what the old shop
			// SHOWED -- its moderation state, its likes, its photos -- so it
			// wins, and it is carried on this row rather than as a second
			// review of the same words.
			$copy   = isset( $copies[ $id ] ) ? $copies[ $id ] : null;
			$images = isset( $photos[ $id ] ) ? $photos[ $id ] : array();

			if ( null !== $copy ) {
				$images = array_values( array_unique( array_merge( $images, KBB_Export_Review_Photos::image_urls( (string) $copy['images'] ) ) ) );
			}

			$out[] = array(
				'comment_id'      => $id,
				'comment_post_id' => (int) $row['comment_post_ID'],
				'comment_type'    => $type,
				'author'          => $row['comment_author'],
				'email'           => $row['comment_author_email'],
				'rating'          => isset( $m['rating'] ) ? $m['rating'] : '',
				// WooCommerce reviews have no title of their own. The column is
				// emitted blank rather than omitted, because Row::text() reads
				// an absent column and a blank one the same way here and a file
				// whose header matches the fixture is one less thing to check.
				'title'           => null !== $copy ? (string) $copy['title'] : '',
				'content'         => $row['comment_content'],
				// '1' | '0' | 'spam' | 'trash', which is the exact vocabulary
				// ReviewImporter::STATUS_MAP folds -- including 'trash', which
				// it imports as `spam` with an adjustment saying so.
				'comment_approved' => null !== $copy ? $this->dream_status( (string) $copy['status'] ) : $row['comment_approved'],
				'comment_date'     => $row['comment_date'],
				'comment_date_gmt' => $row['comment_date_gmt'],
				'verified'         => null !== $copy
					? ( (int) $copy['verified'] ? 'yes' : 'no' )
					: ( isset( $m['verified'] ) ? $this->yesno( $m['verified'] ) : 'no' ),
				'user_id'          => (int) $row['user_id'] > 0 ? (int) $row['user_id'] : '',
				'ip'               => $row['comment_author_IP'],
				'reply'            => isset( $replies[ $id ] ) ? $replies[ $id ] : '',
				// Pipe-separated, which is the separator ProductImporter
				// already prefers for `images` on products -- one spelling of
				// "a list of pictures" in this pipe rather than two.
				'images'           => $images ? $this->pipes( $images ) : '',
				// Likes: the Dream Code copy's count when there is one, else
				// WooCommerce Photo Reviews' own vote count, which the export
				// used to name as unused meta and leave behind.
				'helpful'          => null !== $copy
					? (int) $copy['helpful']
					: ( isset( $m['wcpr_vote_up_count'] ) ? max( 0, (int) $m['wcpr_vote_up_count'] ) : 0 ),
				'source'           => 'wp_comment',
			);
		}

		if ( $normalised > 0 ) {
			$this->note(
				$normalised . ' review' . ( 1 === $normalised ? ' had' : 's had' ) . ' a comment_type that was not '
					. "'review' (WooCommerce before 3.0 left it empty) and it was written as 'review' in the export. "
					. 'Each is a comment on a product post carrying a star rating, which is the definition of a '
					. 'product review; left as it was, ReviewImporter would have refused every one of them.'
			);
		}

		$done = count( $rows ) < (int) $limit;

		if ( $done ) {
			$this->report_unrated();
			$this->report_photographs();

			if ( '' !== $this->dream_table() ) {
				return array( 'rows' => $out, 'cursor' => -1, 'done' => false );
			}
		}

		return array( 'rows' => $out, 'cursor' => (int) end( $ids ), 'done' => $done );
	}

	/* =====================================================================
	 * DREAM CODE REVIEWS -- the owner's own plugin, `wp_sorina_reviews`.
	 * =====================================================================
	 *
	 * His storefront did not show WooCommerce's reviews at all: Dream Code
	 * Reviews removes the reviews tab and prints its own table. That table is
	 * every review a shopper saw, including the copies he made with its
	 * Assign / Duplicate (a PHYSICAL copy per target product -- so the Booster
	 * Set's reviews are rows of their own, product_id = the set). Until this,
	 * the export read wp_comments only, and on 1 October 2026 the PDRN Glow
	 * Booster Set arrived with none of the reviews it showed on the old site.
	 *
	 * ONE REVIEW, NOT TWO. The plugin's "Sync WooCommerce reviews" copies each
	 * comment into its table byte for byte: same product, author (blank becomes
	 * 'Anonymous'), rating, content and date. Such a copy is folded INTO the
	 * comment's row above (dream_copies) and skipped here (the NOT EXISTS in
	 * dream_where), by the same predicate both ways, so the two phases can
	 * never disagree about which rows are the same review. Compared as bytes
	 * (CAST ... AS BINARY): the two tables can carry different collations, and
	 * the copy is exact.
	 *
	 * product_id 0 is the plugin's REVIEW OF THE BUSINESS; it is written with
	 * no product, which ReviewImporter imports as a business review.
	 */

	/** The table's name, or '' on a site without the plugin. */
	private function dream_table() {
		global $wpdb;

		// Per instance, not `static`: a static outlives the stage, and the
		// test harness runs many exports against different shops in one process.
		if ( null === $this->dream_table ) {
			$name  = $wpdb->prefix . 'sorina_reviews';
			// The name is the site's own prefix plus a constant -- configuration,
			// never input -- and the answer is compared EXACTLY below, so the
			// LIKE's `_` wildcard cannot let a different table through.
			$found = $wpdb->get_var( "SHOW TABLES LIKE '" . str_replace( array( "'", '\\' ), '', $name ) . "'" );

			$this->dream_table = ( $found === $name ) ? $name : '';
		}

		return $this->dream_table;
	}

	/** @var string|null */
	private $dream_table = null;

	/** True when comment `c` is an exported review that row `s` is a byte-for-byte copy of. */
	private function dream_same_as_comment() {
		global $wpdb;

		return "c.comment_post_ID = s.product_id
			AND CAST(c.comment_content AS BINARY) = CAST(s.content AS BINARY)
			AND c.comment_date = s.created_at
			AND CAST(IF(c.comment_author = '', 'Anonymous', c.comment_author) AS BINARY) = CAST(s.author_name AS BINARY)
			AND c.comment_post_ID IN (SELECT ID FROM {$wpdb->prefix}posts WHERE post_type = 'product')
			AND EXISTS (SELECT 1 FROM {$wpdb->prefix}commentmeta cm WHERE cm.comment_id = c.comment_ID
				AND cm.meta_key = 'rating' AND cm.meta_value <> '' AND CAST(cm.meta_value AS UNSIGNED) = s.rating)";
	}

	/** Rows of the table that are NOT a copy of an exported comment. */
	private function dream_where() {
		global $wpdb;

		return 'NOT EXISTS (SELECT 1 FROM ' . $wpdb->prefix . 'comments c WHERE ' . $this->dream_same_as_comment() . ')';
	}

	private function dream_count() {
		global $wpdb;

		$table = $this->dream_table();

		if ( '' === $table ) {
			return 0;
		}

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table . ' s WHERE ' . $this->dream_where() );
	}

	/**
	 * The Dream Code copy of each of these comments, keyed by comment id.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function dream_copies( array $ids ) {
		global $wpdb;

		$table = $this->dream_table();

		if ( '' === $table || empty( $ids ) ) {
			return array();
		}

		$rows = (array) $wpdb->get_results(
			'SELECT c.comment_ID AS cid, s.id, s.status, s.verified, s.helpful, s.title, s.images
			 FROM ' . $wpdb->prefix . 'comments c
			 JOIN ' . $table . ' s ON ' . $this->dream_same_as_comment() . '
			 WHERE c.comment_ID IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')
			 ORDER BY s.id',
			ARRAY_A
		);

		$out = array();

		foreach ( $rows as $row ) {
			$cid = (int) $row['cid'];

			// The lowest id if the sync ever ran twice over one comment.
			if ( ! isset( $out[ $cid ] ) ) {
				$out[ $cid ] = $row;
			}
		}

		return $out;
	}

	/** The plugin's status vocabulary onto WordPress's, which ReviewImporter folds. */
	private function dream_status( $status ) {
		switch ( strtolower( trim( $status ) ) ) {
			case 'approved':
				return '1';
			case 'spam':
				return 'spam';
			case 'trash':
				return 'trash';
			default:
				return '0';
		}
	}

	private function dream_batch( $after, $limit ) {
		global $wpdb;

		$table = $this->dream_table();

		if ( '' === $table ) {
			return array( 'rows' => array(), 'cursor' => -1, 'done' => true );
		}

		$rows = (array) $wpdb->get_results(
			'SELECT s.* FROM ' . $table . ' s
			 WHERE s.id > ' . (int) $after . ' AND ' . $this->dream_where() . '
			 ORDER BY s.id
			 LIMIT ' . (int) $limit,
			ARRAY_A
		);

		$out  = array();
		$last = (int) $after;

		foreach ( $rows as $row ) {
			$last    = (int) $row['id'];
			$product = (int) $row['product_id'];
			$images  = KBB_Export_Review_Photos::image_urls( (string) $row['images'] );

			$out[] = array(
				'comment_id'       => $last,
				// Blank, not 0, for the plugin's business reviews: a row that
				// names no product is ReviewImporter's review of the shop.
				'comment_post_id'  => $product > 0 ? $product : '',
				'comment_type'     => 'review',
				'author'           => (string) $row['author_name'],
				'email'            => (string) $row['author_email'],
				'rating'           => (int) $row['rating'],
				'title'            => (string) $row['title'],
				'content'          => (string) $row['content'],
				'comment_approved' => $this->dream_status( (string) $row['status'] ),
				'comment_date'     => (string) $row['created_at'],
				'comment_date_gmt' => '',
				'verified'         => (int) $row['verified'] ? 'yes' : 'no',
				'user_id'          => (int) $row['user_id'] > 0 ? (int) $row['user_id'] : '',
				'ip'               => (string) $row['ip'],
				'reply'            => '',
				'images'           => $images ? $this->pipes( $images ) : '',
				'helpful'          => max( 0, (int) $row['helpful'] ),
				'source'           => 'dream_code',
			);
		}

		$done = count( $rows ) < (int) $limit;

		if ( $done ) {
			$this->report_dream( $table );
		}

		return array( 'rows' => $out, 'cursor' => -( $last + 1 ), 'done' => $done );
	}

	private function report_dream( $table ) {
		global $wpdb;

		$all      = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table );
		$own      = $this->dream_count();
		$business = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table . ' s WHERE s.product_id = 0 AND ' . $this->dream_where() );

		$this->note(
			'Dream Code Reviews: ' . $all . ' review(s) in ' . $table . '. ' . $own . ' are written to reviews.csv '
				. 'with source = dream_code (' . $business . ' of them reviews of the business, with no product), '
				. 'including every copy made with its Assign / Duplicate. The other ' . ( $all - $own ) . ' are the '
				. "plugin's own copies of WooCommerce reviews made by its \"Sync WooCommerce reviews\": each is "
				. 'carried on the WooCommerce review it copied -- its approval, likes, title and photos -- '
				. 'rather than written a second time.'
		);
	}

	/**
	 * A shopper's photographs, recognised by their VALUE and never by their key.
	 *
	 * ========================================================================
	 * `reviews.images` HAS EXISTED ON THE LARAVEL SIDE SINCE THE FIRST SCHEMA
	 * MIGRATION AND NO EXPORT HAS EVER PUT ANYTHING IN IT.
	 * ========================================================================
	 *
	 * It is a `json` column, it is cast to an array on the model, the product
	 * page draws up to four of them per review with a "+n" chip, the review
	 * wall filters on `whereNotNull('images')`, and App\Support\ReviewWall
	 * scheme-checks every one before it reaches a page. All of that machinery
	 * is built, tested and reachable, and on an imported shop it rendered
	 * nothing, because the only writer was a shopper uploading a photo to the
	 * NEW site.
	 *
	 * Photographs are the most persuasive thing on a review and they are the
	 * part a shop cannot re-create: the owner can retype a review, and cannot
	 * retype a customer's picture of her own face.
	 *
	 * ── THE RULE LIVES IN KBB_Export_Review_Photos, AND SO DOES THE REASON ───
	 *
	 * It was private to this stage while reviews.csv was the only file that
	 * needed the answer. media.csv needs it too -- that is the file the new
	 * shop's downloader works from, and until it walked reviews as well, a
	 * customer's photograph was NAMED in one file and absent from the only
	 * file that says "fetch this before the old shop goes dark".
	 *
	 * @param array<int,int> $ids
	 * @return array<int,array<int,string>>
	 */
	private function photographs( array $ids ) {
		return KBB_Export_Review_Photos::for_comments( $ids );
	}

	/**
	 * What was taken, and -- the load-bearing half -- what was left behind.
	 *
	 * Both lists are recomputed from the database at the end of the run rather
	 * than tallied across batches, because a batch is a separate HTTP request
	 * and an instance property does not survive one. `report_unrated()` above
	 * solves the same problem the same way.
	 *
	 * @return void
	 */
	private function report_photographs() {
		global $wpdb;

		$reviews = 'SELECT c.comment_ID FROM ' . $wpdb->prefix . 'comments c WHERE ' . $this->where();
		$report  = KBB_Export_Review_Photos::key_report( $reviews );

		if ( ! empty( $report['used'] ) ) {
			$this->note(
				'Review photographs were read from ' . count( $report['used'] ) . ' comment meta key(s): `'
					. implode( '`, `', $report['used'] ) . '`. They are in reviews.csv\'s `images` '
					. 'column, pipe-separated, they land in `reviews.images`, and every one of them is also a row in '
					. 'media.csv so the new shop fetches the FILE before this one is switched off. Recognised by the '
					. 'VALUE being an attachment id this site resolves, an address under its own uploads directory, or '
					. 'a path the uploads directory actually holds -- not by the key being one this plugin had heard of.'
			);
		}

		if ( empty( $report['unused'] ) ) {
			return;
		}

		$this->note(
			'These comment meta keys are on reviews and are NOT in reviews.csv: `' . implode( '`, `', $report['unused'] )
				. '`. Every one of them was checked against this site rather than against a list of plugin names: an '
				. 'attachment id it resolves, an address under its own uploads directory, and a path -- bare filename, '
				. 'uploads-relative or root-relative -- that the uploads directory actually holds. None of these keys '
				. 'answered to any of those, so they are a plugin\'s bookkeeping rather than pictures. They are named '
				. 'here because the alternative is an export that quietly carried nothing.'
		);
	}

	/**
	 * The first child comment of each review, which is the shop's reply.
	 *
	 * @param array<int,int> $ids
	 * @return array<int,string>
	 */
	private function replies( array $ids ) {
		global $wpdb;

		$out = array();

		if ( empty( $ids ) ) {
			return $out;
		}

		$rows = $wpdb->get_results(
			'SELECT comment_parent, comment_content
			 FROM ' . $wpdb->prefix . 'comments
			 WHERE comment_parent IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')
			 ORDER BY comment_ID',
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$parent = (int) $row['comment_parent'];

			if ( ! isset( $out[ $parent ] ) ) {
				$out[ $parent ] = (string) $row['comment_content'];
			}
		}

		return $out;
	}

	/** Product comments with no rating: counted, named, not exported. */
	private function report_unrated() {
		global $wpdb;

		$unrated = (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . "comments c
			 WHERE c.comment_post_ID IN (SELECT ID FROM {$wpdb->prefix}posts WHERE post_type = 'product')
			   AND c.comment_parent = 0
			   AND NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}commentmeta cm WHERE cm.comment_id = c.comment_ID AND cm.meta_key = 'rating' AND cm.meta_value <> '')"
		);

		if ( $unrated > 0 ) {
			$this->note(
				$unrated . ' comment' . ( 1 === $unrated ? '' : 's' ) . ' on products carr'
					. ( 1 === $unrated ? 'ies' : 'y' ) . ' no star rating and '
					. ( 1 === $unrated ? 'is' : 'are' ) . ' NOT in reviews.csv -- these are customer questions, not '
					. 'reviews. ReviewImporter refuses a rating-less row rather than defaulting it to five stars, '
					. 'so exporting them would only produce refusals. The shop has nowhere to put a product '
					. 'question, so this is a real loss and it is named here rather than left silent.'
			);
		}
	}
}
