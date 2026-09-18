<?php
/**
 * Turning a URL back into an attachment, and into the SIZE that was referenced.
 *
 * ── WHY THIS IS HARDER THAN IT LOOKS, AND WHY IT MATTERS ────────────────────
 *
 * The brief for media.csv is "every attachment URL the catalogue actually
 * references ... the sizes genuinely referenced, not every thumbnail WordPress
 * generated". Those are two different sets and the difference is large: a shop
 * with six registered image sizes has produced six files per photograph, of
 * which a product page references one or two. Exporting all six is asking the
 * new shop to fetch five files nobody will ever request, over a connection to a
 * server that is about to be switched off.
 *
 * The references come from two places and only one of them is an id:
 *
 *  - `_thumbnail_id` and `_product_image_gallery` are attachment IDS, and the
 *    URL products.csv carries for them is the FULL size, because that is what
 *    wp_get_attachment_url() returns.
 *
 *  - a description, a short description or a blog post's content contains
 *    `<img src="...-300x300.jpg">` written by the editor, and there is no id in
 *    the HTML at all -- only a URL with a size suffix baked into the filename.
 *
 * So this class resolves both directions: a path to its attachment (exact
 * match on `_wp_attached_file`), and a `-300x300` variant to the attachment
 * whose base path it is a variant OF. A URL that resolves to nothing is still
 * exported, with `attachment_id` empty and the size named `unknown` -- an image
 * the catalogue references and the media library does not know about is the
 * most interesting row in the file, not one to drop.
 */

defined( 'ABSPATH' ) || exit;

class KBB_Export_Media_Index {

	/**
	 * Every `<img src>`, `srcset` candidate and bare uploads URL in a blob of
	 * HTML, restricted to this site's uploads directory.
	 *
	 * Restricted deliberately: a product description can link to a supplier's
	 * photograph or to a CDN that is not this shop's, and the shop's downloader
	 * fetching those would be fetching somebody else's files on the strength of
	 * a string in a description. docs/GB-MEDIA-AND-REDIRECTS.md makes the same
	 * point about MediaRewrite -- "Guessing 'the most common host' is how a CDN,
	 * a supplier's photograph or a partner's banner gets rewritten into a local
	 * path that does not exist."
	 *
	 * @return array<int,string>
	 */
	public static function urls_in_html( $html ) {
		$base = KBB_Export_Wp::uploads_url();

		if ( '' === $base || '' === trim( (string) $html ) ) {
			return array();
		}

		$quoted = preg_quote( $base, '#' );
		$found  = array();

		if ( preg_match_all( '#' . $quoted . '/[^\s"\'<>\\\\)]+#i', (string) $html, $matches ) ) {
			foreach ( $matches[0] as $url ) {
				// A srcset entry is "url 300w"; the split above already stops
				// at the space. What is left is a trailing comma from the
				// srcset list or a full stop from prose.
				$found[] = rtrim( $url, ',.;' );
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Attachment id and size name for a batch of URLs.
	 *
	 * TWO QUERIES FOR THE WHOLE BATCH, not two per URL. The first matches the
	 * paths exactly, the second matches the base path of anything that looked
	 * like a `-WIDTHxHEIGHT` variant.
	 *
	 * @param array<int,string> $urls
	 * @return array<string,array{attachment_id:int,size:string,path:string}>
	 */
	public static function resolve( array $urls ) {
		global $wpdb;

		$out  = array();
		$base = KBB_Export_Wp::uploads_url();

		if ( empty( $urls ) ) {
			return $out;
		}

		$paths     = array();
		$variants  = array();

		foreach ( $urls as $url ) {
			$path = self::relative_path( $url, $base );

			$out[ $url ] = array( 'attachment_id' => 0, 'size' => 'unknown', 'path' => $path );

			if ( '' === $path ) {
				continue;
			}

			$paths[ $path ][] = $url;

			$stripped = preg_replace( '/-\d+x\d+(\.[A-Za-z0-9]+)$/', '$1', $path );

			if ( null !== $stripped && $stripped !== $path ) {
				$variants[ $stripped ][] = $url;
			}
		}

		$wanted = array_merge( array_keys( $paths ), array_keys( $variants ) );

		if ( empty( $wanted ) ) {
			return $out;
		}

		$list = implode( ',', array_map( array( 'KBB_Export_Wp', 'quote' ), array_values( array_unique( $wanted ) ) ) );

		$rows = $wpdb->get_results(
			'SELECT post_id, meta_value FROM ' . $wpdb->prefix . "postmeta
			 WHERE meta_key = '_wp_attached_file' AND meta_value IN ({$list})",
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$file = (string) $row['meta_value'];
			$id   = (int) $row['post_id'];

			if ( isset( $paths[ $file ] ) ) {
				foreach ( $paths[ $file ] as $url ) {
					$out[ $url ]['attachment_id'] = $id;
					$out[ $url ]['size']          = 'full';
				}
			}

			if ( isset( $variants[ $file ] ) ) {
				foreach ( $variants[ $file ] as $url ) {
					// Already matched exactly? Then it IS the full size and the
					// filename merely happens to end in -800x600, which is a
					// real thing photographers do. Exact wins.
					if ( 'full' === $out[ $url ]['size'] ) {
						continue;
					}

					$out[ $url ]['attachment_id'] = $id;
					$out[ $url ]['size']          = self::size_name( $id, $out[ $url ]['path'] );
				}
			}
		}

		return $out;
	}

	/**
	 * Which registered size a variant path is, by asking the attachment's own
	 * metadata rather than by parsing the numbers out of the filename.
	 *
	 * The numbers are the WRONG answer often enough to matter: `medium` is
	 * 300x300 in the settings and 300x169 on a landscape photograph, because
	 * WordPress preserves the aspect ratio. Matching on the stored `file` name
	 * of each size is exact.
	 */
	private static function size_name( $attachment_id, $path ) {
		$meta = wp_get_attachment_metadata( $attachment_id );

		if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
			return 'unknown';
		}

		$basename = basename( $path );

		foreach ( $meta['sizes'] as $name => $size ) {
			if ( isset( $size['file'] ) && (string) $size['file'] === $basename ) {
				return (string) $name;
			}
		}

		return 'unknown';
	}

	/** The path relative to the uploads root, or '' when the URL is elsewhere. */
	public static function relative_path( $url, $base = null ) {
		$base = null === $base ? KBB_Export_Wp::uploads_url() : $base;

		if ( '' === $base ) {
			return '';
		}

		$url  = self::strip_query( (string) $url );
		$host = self::host( $base );

		// Matched on HOST rather than on the whole prefix, for the reason
		// docs/GB-MEDIA-AND-REDIRECTS.md §4.2 gives: "scheme moves (http ->
		// https behind the host's TLS proxy ...) and a port does not make a
		// file a different file". An https URL in a description written before
		// the certificate was installed is the same picture.
		if ( '' !== $host && self::host( $url ) !== $host ) {
			return '';
		}

		$base_path = (string) parse_url( $base, PHP_URL_PATH );
		$url_path  = (string) parse_url( $url, PHP_URL_PATH );

		if ( '' === $url_path || 0 !== strpos( $url_path, rtrim( $base_path, '/' ) . '/' ) ) {
			return '';
		}

		return ltrim( substr( $url_path, strlen( rtrim( $base_path, '/' ) ) ), '/' );
	}

	private static function strip_query( $url ) {
		$cut = strcspn( $url, '?#' );

		return substr( $url, 0, $cut );
	}

	private static function host( $url ) {
		$host = parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) ? strtolower( $host ) : '';
	}
}
