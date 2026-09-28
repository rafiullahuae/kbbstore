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
			'verified', 'user_id', 'ip', 'reply', 'images',
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
			$this->report_photographs();

			return array( 'rows' => array(), 'cursor' => (int) $cursor, 'done' => true );
		}

		$ids = array();

		foreach ( $rows as $row ) {
			$ids[] = (int) $row['comment_ID'];
		}

		$meta    = KBB_Export_Wp::comment_meta( $ids, array( 'rating', 'verified' ) );
		$replies = $this->replies( $ids );
		$photos  = $this->photographs( $ids );

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
				// Pipe-separated, which is the separator ProductImporter
				// already prefers for `images` on products -- one spelling of
				// "a list of pictures" in this pipe rather than two.
				'images'           => isset( $photos[ $id ] ) ? $this->pipes( $photos[ $id ] ) : '',
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
		}

		return array( 'rows' => $out, 'cursor' => (int) end( $ids ), 'done' => $done );
	}

	/**
	 * Keys another column of this file already carries.
	 *
	 * Anything else on a review is a candidate photograph, and anything that is
	 * not one is REPORTED rather than ignored -- see photographs().
	 */
	const META_CARRIED_ELSEWHERE = array( 'rating', 'verified' );

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
	 * is built, tested and reachable, and on an imported shop it renders
	 * nothing, because the only writer is a shopper uploading a photo to the
	 * NEW site.
	 *
	 * Photographs are the most persuasive thing on a review and they are the
	 * part a shop cannot re-create: the owner can retype a review, and cannot
	 * retype a customer's picture of her own face.
	 *
	 * ── WHY IT DOES NOT LOOK FOR KNOWN PLUGIN KEYS ──────────────────────────
	 *
	 * Review photographs are not WooCommerce core. Every shop that has them has
	 * them from one of a dozen plugins, each with its own meta key, and this
	 * plugin does not write down what it remembers about other people's
	 * software -- the header of this whole plugin says every column here was
	 * derived by READING the importer, "not from WooCommerce's documentation
	 * and not from memory".
	 *
	 * A list of keys to look for would be exactly that. It would also fail
	 * SILENTLY on the one shop it is pointed at, which is the failure mode this
	 * migration has paid for repeatedly.
	 *
	 * So the rule is about the VALUE:
	 *
	 *   · an ATTACHMENT ID -- an integer this site can resolve to an uploads
	 *     URL. That is WordPress itself confirming the row names a file it
	 *     holds; an integer that resolves to nothing is not a photograph and is
	 *     dropped.
	 *   · an ADDRESS UNDER THIS SITE'S OWN UPLOADS DIRECTORY with an image
	 *     extension. Under the uploads directory specifically, so a meta value
	 *     holding somebody else's URL cannot become one of this shop's
	 *     pictures.
	 *
	 * Both are facts about this database, established from it. Neither is a
	 * guess about a plugin.
	 *
	 * ── AND EVERY KEY IT DID NOT USE IS NAMED ───────────────────────────────
	 *
	 * The rule can still miss: a plugin storing a bare filename, or a path
	 * relative to the uploads root, produces no match. That is why unused keys
	 * are counted and named in manifest.json. A key in that note is the owner
	 * -- or the next reader -- being handed the exact thing to look at, instead
	 * of an export that quietly carried no pictures.
	 *
	 * @param array<int,int> $ids
	 * @return array<int,array<int,string>>
	 */
	private function photographs( array $ids ) {
		$rows = KBB_Export_Wp::comment_meta_rows( $ids, self::META_CARRIED_ELSEWHERE );
		$out  = array();

		foreach ( $rows as $comment_id => $entries ) {
			$urls = array();

			foreach ( $entries as $entry ) {
				$found = $this->image_urls( $entry['value'] );

				if ( empty( $found ) ) {
					$this->unused_keys[ $entry['key'] ] = isset( $this->unused_keys[ $entry['key'] ] )
						? $this->unused_keys[ $entry['key'] ] + 1
						: 1;

					continue;
				}

				$this->used_keys[ $entry['key'] ] = true;

				foreach ( $found as $url ) {
					// One picture once, however many keys name it. A plugin
					// that stores both the ids and the URLs would otherwise
					// double every photograph on every review.
					$urls[ $url ] = true;
				}
			}

			if ( ! empty( $urls ) ) {
				$out[ (int) $comment_id ] = array_keys( $urls );
			}
		}

		return $out;
	}

	/** @var array<string,int> meta keys seen on a review that held no picture */
	private $unused_keys = array();

	/** @var array<string,bool> meta keys a picture was actually taken from */
	private $used_keys = array();

	/**
	 * Every image address one meta value names, or an empty list.
	 *
	 * Handles the three shapes a list of pictures is stored in: a PHP-
	 * serialised array (what update_post_meta writes for an array), a
	 * comma- or pipe-separated string, and a single value.
	 *
	 * @return array<int,string>
	 */
	private function image_urls( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return array();
		}

		$parts = array();

		// A serialised array, which is what WordPress stores for an array meta
		// value. unserialize() is given no classes: a review's meta is data a
		// plugin wrote, and an object in it must never be instantiated here.
		if ( 0 === strpos( $value, 'a:' ) ) {
			$decoded = @unserialize( $value, array( 'allowed_classes' => false ) );

			if ( is_array( $decoded ) ) {
				array_walk_recursive(
					$decoded,
					function ( $item ) use ( &$parts ) {
						if ( is_scalar( $item ) ) {
							$parts[] = (string) $item;
						}
					}
				);
			}
		}

		if ( empty( $parts ) ) {
			$parts = preg_split( '/[,|\s]+/', $value );
			$parts = is_array( $parts ) ? $parts : array();
		}

		$out = array();

		foreach ( $parts as $part ) {
			$part = trim( (string) $part );

			if ( '' === $part ) {
				continue;
			}

			// An attachment id: WordPress resolving it is the proof.
			if ( ctype_digit( $part ) ) {
				$url = KBB_Export_Wp::attachment_url( (int) $part );

				if ( '' !== (string) $url ) {
					$out[] = (string) $url;
				}

				continue;
			}

			if ( $this->is_own_upload( $part ) ) {
				$out[] = $part;
			}
		}

		return $out;
	}

	/**
	 * Is this an address of a picture in THIS site's uploads directory?
	 *
	 * The uploads check is not decoration. Without it any meta value holding
	 * any URL ending in `.jpg` becomes one of this shop's review photographs --
	 * including one pointing at somebody else's server, which the new shop
	 * would then hotlink from a product page.
	 */
	private function is_own_upload( $url ) {
		$uploads = rtrim( (string) KBB_Export_Wp::uploads_url(), '/' );

		if ( '' === $uploads ) {
			return false;
		}

		// Compared scheme-insensitively: a shop that moved to https years ago
		// has http:// addresses in meta rows written before the move, and they
		// are the same files.
		$strip = function ( $value ) {
			return preg_replace( '#^https?://#i', '', (string) $value );
		};

		if ( 0 !== strpos( $strip( $url ), $strip( $uploads ) . '/' ) ) {
			return false;
		}

		$path = parse_url( $url, PHP_URL_PATH );

		if ( ! is_string( $path ) ) {
			return false;
		}

		return (bool) preg_match( '/\.(jpe?g|png|gif|webp|avif)$/i', $path );
	}

	/**
	 * What was taken, and -- the load-bearing half -- what was left behind.
	 *
	 * @return void
	 */
	private function report_photographs() {
		if ( ! empty( $this->used_keys ) ) {
			$this->note(
				'Review photographs were read from ' . count( $this->used_keys ) . ' comment meta key(s): `'
					. implode( '`, `', array_keys( $this->used_keys ) ) . '`. They are in reviews.csv\'s `images` '
					. 'column, pipe-separated, and land in `reviews.images`. Recognised by the VALUE being an '
					. 'attachment id this site resolves or an address under its own uploads directory -- not by the '
					. 'key being one this plugin had heard of.'
			);
		}

		$unused = $this->unused_keys;

		// A key that produced a picture ANYWHERE is not an unused key, even if
		// some of its rows held something else -- reporting it would send the
		// reader to look at a key that is already working.
		foreach ( array_keys( $this->used_keys ) as $key ) {
			unset( $unused[ $key ] );
		}

		if ( empty( $unused ) ) {
			return;
		}

		arsort( $unused );

		$parts = array();

		foreach ( $unused as $key => $count ) {
			$parts[] = '`' . $key . '` (' . $count . ')';
		}

		$this->note(
			'These comment meta keys are on reviews and are NOT in reviews.csv: ' . implode( ', ', $parts )
				. '. Most will be a plugin\'s bookkeeping and no loss at all. But if this shop shows customer '
				. 'PHOTOGRAPHS on its reviews and the `images` column came out empty, the plugin storing them is '
				. 'one of these keys in a shape this export did not recognise (a bare filename, or a path relative '
				. 'to the uploads root). That is the list to look at, and it is here so the answer is a key name '
				. 'rather than a discovery after the cutover.'
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
