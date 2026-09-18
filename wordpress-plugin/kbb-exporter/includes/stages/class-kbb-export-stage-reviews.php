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
			'verified', 'user_id', 'ip', 'reply',
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
		);
	}

	public function batch( $cursor, $limit ) {
		global $wpdb;

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

			return array( 'rows' => array(), 'cursor' => (int) $cursor, 'done' => true );
		}

		$ids = array();

		foreach ( $rows as $row ) {
			$ids[] = (int) $row['comment_ID'];
		}

		$meta    = KBB_Export_Wp::comment_meta( $ids, array( 'rating', 'verified' ) );
		$replies = $this->replies( $ids );

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
				'title'           => '',
				'content'         => $row['comment_content'],
				// '1' | '0' | 'spam' | 'trash', which is the exact vocabulary
				// ReviewImporter::STATUS_MAP folds -- including 'trash', which
				// it imports as `spam` with an adjustment saying so.
				'comment_approved' => $row['comment_approved'],
				'comment_date'     => $row['comment_date'],
				'comment_date_gmt' => $row['comment_date_gmt'],
				'verified'         => isset( $m['verified'] ) ? $this->yesno( $m['verified'] ) : 'no',
				'user_id'          => (int) $row['user_id'] > 0 ? (int) $row['user_id'] : '',
				'ip'               => $row['comment_author_IP'],
				'reply'            => isset( $replies[ $id ] ) ? $replies[ $id ] : '',
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
		}

		return array( 'rows' => $out, 'cursor' => (int) end( $ids ), 'done' => $done );
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
