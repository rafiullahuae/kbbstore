<?php
/**
 * A shopper's photographs on a review, recognised by their VALUE.
 *
 * ============================================================================
 * WHY THIS IS A CLASS OF ITS OWN AND NOT A PRIVATE METHOD ON THE REVIEWS STAGE
 * ============================================================================
 *
 * It was a private method on the reviews stage, and that was right while
 * reviews.csv was the only file that needed the answer. It is not any more.
 *
 * `reviews.csv`'s `images` column says WHICH pictures a review carries.
 * `media.csv` is the list of FILES the new shop has to fetch off the old one
 * before it is switched off -- and until this class existed, media.csv walked
 * products, categories, brands and articles and NOT reviews. So a customer's
 * own photograph of her own face, which is the one picture on the shop that
 * exists nowhere else and can never be re-created, was named in one file and
 * absent from the only file that says "fetch this".
 *
 * Two readers of one rule, so the two files cannot disagree about what a
 * photograph is. The alternative is the two hand-maintained lists this
 * repository keeps paying for.
 *
 * ============================================================================
 * THE RULE: A FACT ABOUT THIS DATABASE, NEVER A GUESS ABOUT A PLUGIN
 * ============================================================================
 *
 * Review photographs are not WooCommerce core. Every shop that has them has
 * them from one of a dozen plugins, each with its own meta key, and this
 * plugin does not write down what it remembers about other people's software.
 * A list of keys to look for would be a list of guesses, and a guess that
 * misses is SILENT -- which is the failure mode this migration has paid for
 * repeatedly.
 *
 * So a value is a photograph when one of three things about THIS site is true
 * of it:
 *
 *   1. an ATTACHMENT ID -- an integer this site resolves to an uploads URL.
 *      WordPress itself confirming the row names a file it holds.
 *   2. an ADDRESS UNDER THIS SITE'S OWN UPLOADS DIRECTORY with an image
 *      extension. Under the uploads directory specifically, so a meta value
 *      holding somebody else's URL cannot become one of this shop's pictures.
 *   3. a PATH THE UPLOADS DIRECTORY ACTUALLY HOLDS -- `2021/07/her-face.jpg`,
 *      `/wp-content/uploads/2021/07/her-face.jpg`, or a bare filename sitting
 *      at the uploads root. Established by stat()ing the file.
 *
 * ── THE THIRD ONE IS NEW, AND IT IS THE NOTE THIS PLUGIN USED TO WRITE ──────
 *
 * Until now the unused-key note in manifest.json ended: *"the plugin storing
 * them is one of these keys in a shape this export did not recognise (a bare
 * filename, or a path relative to the uploads root). That is the list to look
 * at."* That sentence named, exactly, two shapes the export knew it was
 * dropping -- and handed the owner the job of noticing. It is homework, not a
 * migration, and after the cutover there is nothing left to do the homework
 * against: `wp_commentmeta` does not survive the move.
 *
 * Rule 3 reads those two shapes. It is not a widening of the standard the
 * other two are held to: `is_file()` on this site's own uploads directory is
 * the same class of evidence as `wp_get_attachment_url()` resolving -- the
 * server answering for itself. A string that looks like a path and names no
 * file is still dropped, so a plugin's bookkeeping value of `image.jpg` that
 * points at nothing does not become a broken picture on the new shop.
 *
 * `..` is refused outright before the stat, so a meta value cannot walk out of
 * the uploads directory and make an arbitrary readable file on the server into
 * one of this shop's review photographs.
 */

defined( 'ABSPATH' ) || exit;

class KBB_Export_Review_Photos {

	/**
	 * Meta keys a column of reviews.csv already carries, so not candidates.
	 *
	 * The reviews stage reads `rating` and `verified` into columns of their
	 * own. Everything else on a review is a candidate photograph, and anything
	 * that is not one is REPORTED rather than ignored.
	 */
	const CARRIED_ELSEWHERE = array( 'rating', 'verified' );

	/**
	 * How many DISTINCT values of one key are inspected when deciding whether
	 * that key carries pictures at all.
	 *
	 * Whether a key holds photographs is a question about its VALUES and the
	 * test is PHP rather than SQL, so reading every commentmeta row of every
	 * review to answer it would undo the batching the whole plugin is built
	 * for. One value that resolves is enough to classify a key, so 200 is
	 * generous by two orders of magnitude -- and the direction of the error is
	 * the safe one: a key whose only photographs are beyond 200 distinct values
	 * is reported as NOT exported, which sends the reader TO a key rather than
	 * away from one.
	 */
	const SAMPLE = 200;

	/**
	 * Every photograph each of these comments carries, keyed by comment id.
	 *
	 * @param array<int,int> $ids
	 * @return array<int,array<int,string>>
	 */
	public static function for_comments( array $ids ) {
		$rows = KBB_Export_Wp::comment_meta_rows( $ids, self::CARRIED_ELSEWHERE );
		$out  = array();

		foreach ( $rows as $comment_id => $entries ) {
			$urls = array();

			foreach ( $entries as $entry ) {
				foreach ( self::image_urls( $entry['value'] ) as $url ) {
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

	/**
	 * Which meta keys on these reviews carry pictures, and which do not.
	 *
	 * ── RECOMPUTED FROM THE DATABASE, NEVER ACCUMULATED ACROSS BATCHES ──────
	 *
	 * The obvious way is to tally the keys as the batches go by and report the
	 * tally at the end. It is also wrong here, and wrong in a way a two-review
	 * fixture could never show: a batch is a SEPARATE HTTP REQUEST on the live
	 * site -- that is the reason the whole plugin is batched and resumable --
	 * and an instance property does not survive one. An accumulating tally
	 * would name only the keys seen in the LAST request, which on a shop with
	 * 2,514 reviews at 500 rows a batch is the last 14 of them.
	 *
	 * @param string $review_ids_sql a SELECT returning the review comment ids
	 * @return array{used: array<int,string>, unused: array<int,string>}
	 */
	public static function key_report( $review_ids_sql ) {
		global $wpdb;

		$keys = $wpdb->get_results(
			'SELECT DISTINCT meta_key FROM ' . $wpdb->prefix . 'commentmeta
			 WHERE comment_id IN (' . $review_ids_sql . ')
			 ORDER BY meta_key',
			ARRAY_A
		);

		$used   = array();
		$unused = array();

		foreach ( (array) $keys as $row ) {
			$key = (string) $row['meta_key'];

			if ( in_array( $key, self::CARRIED_ELSEWHERE, true ) ) {
				continue;
			}

			$values = $wpdb->get_results(
				'SELECT DISTINCT meta_value FROM ' . $wpdb->prefix . 'commentmeta
				 WHERE comment_id IN (' . $review_ids_sql . ') AND meta_key = ' . KBB_Export_Wp::quote( $key ) . '
				 LIMIT ' . (int) self::SAMPLE,
				ARRAY_A
			);

			$carries = false;

			foreach ( (array) $values as $value ) {
				if ( ! empty( self::image_urls( $value['meta_value'] ) ) ) {
					$carries = true;

					break;
				}
			}

			if ( $carries ) {
				$used[] = $key;
			} else {
				$unused[] = $key;
			}
		}

		return array( 'used' => $used, 'unused' => $unused );
	}

	/**
	 * Every image address one meta value names, or an empty list.
	 *
	 * Handles the three shapes a list of pictures is stored in: a PHP-
	 * serialised array (what update_comment_meta writes for an array), a
	 * comma- or pipe-separated string, and a single value.
	 *
	 * @return array<int,string>
	 */
	public static function image_urls( $value ) {
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

		// A JSON array, which is what a plugin that stores its own JSON writes
		// and what the serialised branch above cannot read. Decoded before the
		// split, because splitting `["a.jpg","b.jpg"]` on punctuation leaves
		// quotes and brackets glued to both filenames and neither one stats.
		if ( empty( $parts ) && ( 0 === strpos( $value, '[' ) || 0 === strpos( $value, '{' ) ) ) {
			$decoded = json_decode( $value, true );

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

			// 1. An attachment id: WordPress resolving it is the proof.
			if ( ctype_digit( $part ) ) {
				$url = KBB_Export_Wp::attachment_url( (int) $part );

				if ( '' !== (string) $url ) {
					$out[] = (string) $url;
				}

				continue;
			}

			// 2. An address under this site's own uploads directory.
			if ( self::is_own_upload( $part ) ) {
				$out[] = $part;

				continue;
			}

			// 3. A path the uploads directory actually holds.
			$url = self::url_for_stored_path( $part );

			if ( '' !== $url ) {
				$out[] = $url;
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
	public static function is_own_upload( $url ) {
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

		return (bool) self::looks_like_an_image( $path );
	}

	/**
	 * The full URL for a stored PATH, when the uploads directory holds it.
	 *
	 * This is rule 3 -- the two shapes the unused-key note used to name and
	 * leave behind. What arrives here has already failed the attachment-id and
	 * the own-uploads-URL tests, so it is one of:
	 *
	 *     her-face.jpg                              a bare filename
	 *     2021/07/her-face.jpg                      relative to the uploads root
	 *     /wp-content/uploads/2021/07/her-face.jpg  root-relative
	 *     wp-content/uploads/2021/07/her-face.jpg   site-relative
	 *
	 * ...and it becomes a photograph only if `is_file()` says the uploads
	 * directory holds it. A value that merely LOOKS like a path is dropped, so
	 * a plugin's bookkeeping string does not become a broken <img> on the new
	 * shop.
	 *
	 * @return string the full URL, or '' when this is not one of ours
	 */
	public static function url_for_stored_path( $path ) {
		$path = trim( (string) $path );

		// A URL with a host that is not ours was already refused above, and one
		// with a scheme we do not serve is not a path either. Neither may reach
		// the filesystem test.
		if ( '' === $path || false !== strpos( $path, '://' ) || 0 === strpos( $path, '//' ) ) {
			return '';
		}

		if ( ! self::looks_like_an_image( $path ) ) {
			return '';
		}

		$base = rtrim( (string) KBB_Export_Wp::uploads_dir(), '/' );
		$url  = rtrim( (string) KBB_Export_Wp::uploads_url(), '/' );

		if ( '' === $base || '' === $url ) {
			return '';
		}

		// The uploads URL's own path, so `/wp-content/uploads/x.jpg` and
		// `wp-content/uploads/x.jpg` both reduce to `x.jpg` on a site where
		// that is where uploads live -- and do NOT on a site where the uploads
		// directory has been moved, which is the case a hard-coded
		// `wp-content/uploads/` prefix would get wrong.
		$prefix   = trim( (string) parse_url( $url, PHP_URL_PATH ), '/' );
		$relative = ltrim( $path, '/' );

		if ( '' !== $prefix && 0 === strpos( $relative, $prefix . '/' ) ) {
			$relative = substr( $relative, strlen( $prefix ) + 1 );
		}

		/*
		 * NO TRAVERSAL, CHECKED BEFORE THE STAT AND NOT AFTER IT.
		 *
		 * `../../../wp-config.php` is not an image so it never reaches here,
		 * but `../../2021/07/her-face.jpg` is -- and without this it would
		 * stat a file outside the uploads directory and publish a URL that
		 * resolves to something else entirely. A segment-wise test rather than
		 * a strpos, so a legitimate directory named `..something` is kept.
		 */
		foreach ( explode( '/', $relative ) as $segment ) {
			if ( '..' === $segment || '' === $segment ) {
				return '';
			}
		}

		if ( ! is_file( $base . '/' . $relative ) ) {
			return '';
		}

		return $url . '/' . $relative;
	}

	/** Does this path end in an extension a browser draws as a picture? */
	private static function looks_like_an_image( $path ) {
		return (bool) preg_match( '/\.(jpe?g|png|gif|webp|avif)$/i', (string) $path );
	}
}
