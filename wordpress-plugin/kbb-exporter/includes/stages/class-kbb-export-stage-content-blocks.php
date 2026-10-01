<?php
/**
 * `content_blocks.csv` -- the page-builder sections a product description
 * pulls in by shortcode. (Lane PJ-A, 1.10.0)
 *
 * ── THE DEFECT, AS IT LOOKED ON THE SHOP ────────────────────────────────────
 *
 * The owner's old shop runs the Rey theme. Many of his product descriptions end
 * with `[rey_global_section id="18159"]`, and on kbeautybliss.com that line is
 * a whole designed block -- "Gentle Yet Effective Ingredients", then three
 * pictures with QUERCETINOL / ANTI-SEBUM P / 0.5% BHA under them and a sentence
 * each. The block is not in the description. It is its own WordPress post,
 * built in Elementor, whose layout lives in post meta `_elementor_data` (a JSON
 * tree) with a plain-HTML fallback in its `post_content`.
 *
 * products.csv carried the description, so it carried the shortcode -- and
 * nothing carried the post it points at. The new shop printed
 * `[rey_global_section id="18159"]` as text, in the middle of the product page.
 *
 * ── WHAT THIS FILE IS ───────────────────────────────────────────────────────
 *
 * One row per referenced post, SELECTED BY ID and never by post type: Rey's
 * type is believed to be `rey-global-sections`, but the shortcode takes any id
 * and a reference to an Elementor library template or a page works the same
 * way on the old site. Whatever the id names is exported, with its type as
 * stored, and the importer decides.
 *
 * Which ids: every one named by a resolved shortcode (see RESOLVED) in the
 * description or short description of a product this export carries, plus the
 * ids those sections name in turn, to MAX_DEPTH levels. A section is written
 * once however many products use it; `referenced_by` lists the products that
 * name it directly.
 *
 * ── AND WHAT IT CANNOT KNOW, IT SAYS ────────────────────────────────────────
 *
 * Three things go into manifest.json's notes on the last batch, because each
 * is a gap a reader of the files alone would never see:
 *
 *  - THE SHORTCODE CENSUS: every tag found in any carried product's
 *    description or short description, with how many products use it and
 *    whether this export resolves it. The owner's shop may use more Rey or
 *    Elementor shortcodes than this one; they are named here, on the first
 *    export, instead of being found one product page at a time after cutover.
 *  - an id a description names that does not exist on this site, by id and
 *    with the products (or sections) that name it;
 *  - a section nested deeper than MAX_DEPTH, which is left out and named.
 *
 * ── KEYSET, LIKE EVERY OTHER STAGE, AND STATELESS BETWEEN REQUESTS ──────────
 *
 * The set of ids is a property of the shop, not of a cursor, so each batch
 * re-derives it (one LIKE-filtered pass over the products, then one query per
 * nesting level) and writes the ids above the cursor in order. Nothing is kept
 * between requests: the harness gives every batch a fresh runner precisely to
 * catch a stage that does. On a shop with a few dozen sections the whole file
 * is one batch.
 */

defined( 'ABSPATH' ) || exit;

class KBB_Export_Stage_Content_Blocks extends KBB_Export_Stage {

	/**
	 * The shortcodes this export follows to a post, by tag.
	 *
	 * One today. Adding a tag whose `id` attribute names a post (Elementor's
	 * own `elementor-template` is the obvious next one) is one line here; the
	 * census below names every tag the shop uses, so the decision can be made
	 * from the owner's real data rather than guessed.
	 */
	const RESOLVED = array( 'rey_global_section' );

	/** Sections inside sections are followed this many levels down from a product. */
	const MAX_DEPTH = 3;

	/** Products read per query while looking for references; bounds memory, not correctness. */
	const SCAN_CHUNK = 200;

	/** The meta key Elementor keeps a post's layout in. */
	const ELEMENTOR_KEY = '_elementor_data';

	/**
	 * The resolved graph, for the life of THIS OBJECT only -- which is one
	 * request, because the runner builds its stages per request. total() and
	 * batch() in the same request share it; the next request derives it again.
	 *
	 * @var array<string,mixed>|null
	 */
	private $graph = null;

	public function file() {
		return 'content_blocks.csv';
	}

	/**
	 * THE CONTRACT. Lane PJ-B's ContentBlockImporter reads exactly these
	 * names; docs/WP-EXPORT-CONTRACT.md describes each. Do not rename.
	 */
	public function columns() {
		return array(
			'id', 'post_type', 'slug', 'title', 'status', 'modified',
			'shortcode', 'referenced_by', 'elementor_data', 'plain_html',
		);
	}

	public function total() {
		$graph = $this->resolve();

		return count( $graph['blocks'] );
	}

	public function batch( $cursor, $limit ) {
		global $wpdb;

		$graph = $this->resolve();
		$ahead = array();

		foreach ( array_keys( $graph['blocks'] ) as $id ) {
			if ( $id > (int) $cursor ) {
				$ahead[] = (int) $id;
			}
		}

		sort( $ahead );

		$slice = array_slice( $ahead, 0, max( 1, (int) $limit ) );
		$done  = count( $ahead ) <= count( $slice );
		$out   = array();

		if ( ! empty( $slice ) ) {
			$rows = (array) $wpdb->get_results(
				'SELECT ID, post_type, post_name, post_title, post_status, post_modified_gmt, post_content
				 FROM ' . $wpdb->prefix . 'posts
				 WHERE ID IN (' . implode( ',', $slice ) . ')
				 ORDER BY ID',
				ARRAY_A
			);

			$meta = KBB_Export_Wp::post_meta( $slice, array( self::ELEMENTOR_KEY ) );

			foreach ( $rows as $row ) {
				$id    = (int) $row['ID'];
				$block = $graph['blocks'][ $id ];

				$out[] = array(
					'id'             => $id,
					'post_type'      => $row['post_type'],
					'slug'           => $row['post_name'],
					'title'          => $row['post_title'],
					'status'         => $row['post_status'],
					'modified'       => $this->date( $row['post_modified_gmt'] ),
					'shortcode'      => $block['shortcode'],
					'referenced_by'  => $this->pipes( array_map( 'strval', $block['products'] ) ),
					'elementor_data' => isset( $meta[ $id ][ self::ELEMENTOR_KEY ] ) ? $meta[ $id ][ self::ELEMENTOR_KEY ] : '',
					'plain_html'     => $row['post_content'],
				);
			}
		}

		if ( $done ) {
			$this->report( $graph );
		}

		return array(
			'rows'   => $out,
			'cursor' => empty( $slice ) ? (int) $cursor : (int) end( $slice ),
			'done'   => $done,
		);
	}

	/**
	 * The ids this file carries that posts.csv and permalinks.csv should
	 * therefore leave out -- and only when this file is actually being written.
	 *
	 * A Rey section used to go out as a posts.csv row of type
	 * `rey-global-sections`, which PostImporter refuses by name as "a WordPress
	 * post type this shop has no screen for". Once this file carries it, that
	 * refusal is a false line in the import report, and a false line teaches
	 * the owner to skim the list (docs/GQ-MIGRATION-COMPLETENESS.md §5.4).
	 *
	 * NOT a post, a page or a product, even when a shortcode names one: those
	 * have a file and an importer of their own, and taking a page out of
	 * posts.csv because a description happened to embed it would cost the page.
	 *
	 * @param array<string,mixed> $options the runner's pinned settings
	 * @return array<int,int>
	 */
	public static function carried_ids( array $options ) {
		$groups = isset( $options['groups'] ) ? (array) $options['groups'] : KBB_Export_Groups::keys();

		if ( ! in_array( 'catalogue', $groups, true ) ) {
			// content_blocks.csv is not in this export, so nothing is carried
			// by it and posts.csv keeps every row it always had.
			return array();
		}

		$stage = new self( $options );
		$graph = $stage->resolve();
		$out   = array();

		foreach ( $graph['blocks'] as $id => $block ) {
			if ( ! in_array( $block['post_type'], array( 'post', 'page', 'product', 'product_variation' ), true ) ) {
				$out[] = (int) $id;
			}
		}

		sort( $out );

		return $out;
	}

	/* ─────────────────────────────────────────────────── finding the sections */

	/** Product statuses this export carries -- the products stage's own rule. */
	private function product_statuses() {
		$statuses = array( 'publish', 'draft', 'private', 'pending', 'future' );

		if ( ! $this->option( 'skip_trashed', true ) ) {
			$statuses[] = 'trash';
		}

		return implode( ',', array_map( array( 'KBB_Export_Wp', 'quote' ), $statuses ) );
	}

	/**
	 * Walk products for references, then sections for nested ones.
	 *
	 * @return array{blocks: array<int,array<string,mixed>>, missing: array<int,array<string,array<int,int>>>, beyond: array<int,array<int,int>>, inside: array<string,mixed>}
	 */
	private function resolve() {
		if ( null !== $this->graph ) {
			return $this->graph;
		}

		/*
		 * $seen[id] = shortcode, products[], sections[], depth. Filled level by
		 * level; an id is checked against wp_posts once, at the level it is
		 * first named, and moved to `missing` if it is not there.
		 */
		$seen    = array();
		$missing = array();
		$beyond  = array();
		$inside  = array(
			'widgets'    => array(),
			'images'     => array(),
			'shortcodes' => array(),
			'json_bad'   => array(),
		);

		foreach ( $this->product_references() as $reference ) {
			list( $tag, $id, $product ) = $reference;

			if ( ! isset( $seen[ $id ] ) ) {
				$seen[ $id ] = array( 'shortcode' => $tag, 'products' => array(), 'sections' => array(), 'depth' => 1 );
			}

			$seen[ $id ]['products'][] = $product;
		}

		$frontier = array_keys( $seen );
		$depth    = 1;

		while ( ! empty( $frontier ) && $depth <= self::MAX_DEPTH ) {
			$posts = $this->sections( $frontier );
			$next  = array();

			foreach ( $frontier as $id ) {
				if ( ! isset( $posts[ $id ] ) ) {
					$missing[ $id ] = array(
						'products' => $seen[ $id ]['products'],
						'sections' => $seen[ $id ]['sections'],
					);
					unset( $seen[ $id ] );

					continue;
				}

				$seen[ $id ]['post_type'] = $posts[ $id ]['post_type'];

				$texts = $this->texts_of( $id, $posts[ $id ], $inside );

				foreach ( $texts as $text ) {
					foreach ( self::RESOLVED as $tag ) {
						foreach ( self::ids_in( $text, $tag ) as $child ) {
							if ( isset( $seen[ $child ] ) || isset( $missing[ $child ] ) ) {
								// Named already: a cycle, a sibling, or a
								// section a product also names directly.
								if ( isset( $seen[ $child ] ) ) {
									$seen[ $child ]['sections'][] = $id;
								} else {
									$missing[ $child ]['sections'][] = $id;
								}

								continue;
							}

							if ( $depth >= self::MAX_DEPTH ) {
								$beyond[ $child ][] = $id;

								continue;
							}

							$seen[ $child ] = array(
								'shortcode' => $tag,
								'products'  => array(),
								'sections'  => array( $id ),
								'depth'     => $depth + 1,
							);

							$next[] = $child;
						}
					}

					foreach ( self::tags_in( $text ) as $tag ) {
						if ( ! in_array( $tag, self::RESOLVED, true ) ) {
							$inside['shortcodes'][ $tag ][ $id ] = true;
						}
					}
				}
			}

			$frontier = array_values( array_unique( $next ) );
			$depth++;
		}

		foreach ( $seen as $id => $block ) {
			$seen[ $id ]['products'] = self::unique_ints( $block['products'] );
			$seen[ $id ]['sections'] = self::unique_ints( $block['sections'] );
		}

		foreach ( $missing as $id => $refs ) {
			$missing[ $id ]['products'] = self::unique_ints( $refs['products'] );
			$missing[ $id ]['sections'] = self::unique_ints( $refs['sections'] );
		}

		foreach ( $beyond as $id => $parents ) {
			if ( isset( $seen[ $id ] ) ) {
				// Reached at a shallower level by another route after all.
				unset( $beyond[ $id ] );

				continue;
			}

			$beyond[ $id ] = self::unique_ints( $parents );
		}

		ksort( $seen );
		ksort( $missing );
		ksort( $beyond );

		$this->graph = array(
			'blocks'  => $seen,
			'missing' => $missing,
			'beyond'  => $beyond,
			'inside'  => $inside,
		);

		return $this->graph;
	}

	/**
	 * Every (tag, id, product) a carried product's description names.
	 *
	 * LIKE narrows the read to products that contain the tag at all; the
	 * regular expression decides. `_` is a LIKE wildcard, which only widens
	 * the net -- the regex is what has to be exact.
	 *
	 * @return array<int,array{0:string,1:int,2:int}>
	 */
	private function product_references() {
		global $wpdb;

		$like = array();

		foreach ( self::RESOLVED as $tag ) {
			$pattern = KBB_Export_Wp::quote( '%[' . $tag . '%' );
			$like[]  = 'post_content LIKE ' . $pattern;
			$like[]  = 'post_excerpt LIKE ' . $pattern;
		}

		$out    = array();
		$cursor = 0;

		do {
			$rows = (array) $wpdb->get_results(
				'SELECT ID, post_content, post_excerpt FROM ' . $wpdb->prefix . "posts
				 WHERE post_type = 'product' AND post_status IN (" . $this->product_statuses() . ')
				   AND (' . implode( ' OR ', $like ) . ')
				   AND ID > ' . (int) $cursor . '
				 ORDER BY ID
				 LIMIT ' . self::SCAN_CHUNK,
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$cursor = (int) $row['ID'];

				foreach ( self::RESOLVED as $tag ) {
					$ids = array_merge(
						self::ids_in( (string) $row['post_content'], $tag ),
						self::ids_in( (string) $row['post_excerpt'], $tag )
					);

					foreach ( array_unique( $ids ) as $id ) {
						$out[] = array( $tag, (int) $id, $cursor );
					}
				}
			}
		} while ( count( $rows ) === self::SCAN_CHUNK );

		return $out;
	}

	/**
	 * The posts a set of ids names, with what is needed to look inside them.
	 *
	 * @param array<int,int> $ids
	 * @return array<int,array<string,string>>
	 */
	private function sections( array $ids ) {
		global $wpdb;

		$ids = self::unique_ints( $ids );

		if ( empty( $ids ) ) {
			return array();
		}

		$rows = (array) $wpdb->get_results(
			'SELECT ID, post_type, post_content FROM ' . $wpdb->prefix . 'posts
			 WHERE ID IN (' . implode( ',', $ids ) . ')',
			ARRAY_A
		);

		$meta = KBB_Export_Wp::post_meta( $ids, array( self::ELEMENTOR_KEY ) );
		$out  = array();

		foreach ( $rows as $row ) {
			$id = (int) $row['ID'];

			$out[ $id ] = array(
				'post_type'      => (string) $row['post_type'],
				'post_content'   => (string) $row['post_content'],
				'elementor_data' => isset( $meta[ $id ][ self::ELEMENTOR_KEY ] ) ? (string) $meta[ $id ][ self::ELEMENTOR_KEY ] : '',
			);
		}

		return $out;
	}

	/**
	 * Every piece of text inside a section a shortcode could hide in: the
	 * plain-HTML fallback, and every string value of the Elementor tree --
	 * a text-editor's `editor`, a shortcode widget's `shortcode`, a heading.
	 *
	 * Read DECODED rather than as raw JSON, because in the raw meta value the
	 * quotes of `id="18160"` are `\"` and a URL's slashes are `\/`; decoding is
	 * the one way to see the text the old site rendered. A value that does not
	 * decode is scanned raw with its backslashes taken off, and named.
	 *
	 * Also records, for the notes, which widget types the tree uses and which
	 * pictures it names: the importer has to draw the first and the new shop
	 * has to fetch the second.
	 *
	 * @param array<string,mixed> $inside
	 * @return array<int,string>
	 */
	private function texts_of( $id, array $post, array &$inside ) {
		$texts = array( $post['post_content'] );
		$raw   = $post['elementor_data'];

		if ( '' === trim( $raw ) ) {
			return $texts;
		}

		$tree = json_decode( $raw, true );

		if ( ! is_array( $tree ) ) {
			$inside['json_bad'][] = (int) $id;
			$texts[]              = stripslashes( $raw );

			return $texts;
		}

		self::walk( $tree, $texts, $inside );

		return $texts;
	}

	/**
	 * @param mixed               $node
	 * @param array<int,string>   $texts
	 * @param array<string,mixed> $inside
	 */
	private static function walk( $node, array &$texts, array &$inside ) {
		if ( is_string( $node ) ) {
			if ( false !== strpos( $node, '[' ) ) {
				$texts[] = $node;
			}

			return;
		}

		if ( ! is_array( $node ) ) {
			return;
		}

		if ( isset( $node['elType'] ) && 'widget' === $node['elType'] && isset( $node['widgetType'] ) && is_string( $node['widgetType'] ) ) {
			$type = $node['widgetType'];

			$inside['widgets'][ $type ] = isset( $inside['widgets'][ $type ] ) ? $inside['widgets'][ $type ] + 1 : 1;
		}

		// Elementor's media control: {url, id, ...}. The id is the old site's
		// attachment; the url is what the importer will have to fetch.
		if ( isset( $node['url'] ) && is_string( $node['url'] ) && '' !== $node['url'] && array_key_exists( 'id', $node ) ) {
			$inside['images'][ $node['url'] ] = true;
		}

		foreach ( $node as $child ) {
			self::walk( $child, $texts, $inside );
		}
	}

	/* ─────────────────────────────────────────────────────── reading a shortcode */

	/**
	 * The post ids one shortcode tag names in a piece of text.
	 *
	 * `id="N"`, `id='N'` and `id=N`, with any other attributes in any order,
	 * and with the quote written as `\"` (a JSON or slashed string), as an HTML
	 * entity, or as the curly quote a copy from a rendered page brings with it.
	 * `data-id=` and `gs_id=` are NOT `id=`, and `[[tag]]` is not a use.
	 *
	 * @return array<int,int>
	 */
	public static function ids_in( $text, $tag ) {
		$text = (string) $text;

		if ( false === strpos( $text, '[' . $tag ) ) {
			return array();
		}

		// `(?<!\[)`: `[[tag id="1"]]` is WordPress's escape for "print this
		// literally", and the old site printed it rather than rendering it.
		if ( ! preg_match_all( '/(?<!\[)\[' . preg_quote( $tag, '/' ) . '(?=[\s\]\/])([^\]]*)\]/', $text, $matches ) ) {
			return array();
		}

		$quote = '(?:\\\\?["\']|&quot;|&#0?3[49];|&#82(?:16|17|20|21|42|43);|\xE2\x80[\x98\x99\x9C\x9D\xB2\xB3])?';
		$out   = array();

		foreach ( $matches[1] as $attributes ) {
			if ( preg_match( '/(?<![\w-])id\s*=\s*' . $quote . '\s*(\d+)/i', $attributes, $id ) ) {
				$out[] = (int) $id[1];
			}
		}

		return array_values( array_unique( array_filter( $out ) ) );
	}

	/**
	 * Every shortcode tag in a piece of text, each once.
	 *
	 * A tag is a bracket, then a name beginning with a letter, then a space, a
	 * `]` or a `/`. `[[tag]]` is WordPress's escape for "print this literally"
	 * and is not counted; `[/tag]` is a closer and is not a second use.
	 *
	 * @return array<int,string>
	 */
	public static function tags_in( $text ) {
		$text = (string) $text;

		if ( false === strpos( $text, '[' ) ) {
			return array();
		}

		if ( ! preg_match_all( '/(?<!\[)\[([A-Za-z][A-Za-z0-9_-]*)(?=[\s\]\/])/', $text, $matches ) ) {
			return array();
		}

		return array_values( array_unique( $matches[1] ) );
	}

	/* ─────────────────────────────────────────────────────────────── the notes */

	/**
	 * The census of shortcodes across every carried product.
	 *
	 * A separate pass from product_references(), and only on the last batch:
	 * it has to read every product with a bracket in it, not only the ones
	 * that name a resolved tag.
	 *
	 * @return array{products: int, tags: array<string,int>}
	 */
	private function census() {
		global $wpdb;

		$products = (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . "posts
			 WHERE post_type = 'product' AND post_status IN (" . $this->product_statuses() . ')'
		);

		$tags   = array();
		$cursor = 0;

		do {
			$rows = (array) $wpdb->get_results(
				'SELECT ID, post_content, post_excerpt FROM ' . $wpdb->prefix . "posts
				 WHERE post_type = 'product' AND post_status IN (" . $this->product_statuses() . ")
				   AND (post_content LIKE '%[%' OR post_excerpt LIKE '%[%')
				   AND ID > " . (int) $cursor . '
				 ORDER BY ID
				 LIMIT ' . self::SCAN_CHUNK,
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$cursor = (int) $row['ID'];

				$found = array_unique( array_merge(
					self::tags_in( (string) $row['post_content'] ),
					self::tags_in( (string) $row['post_excerpt'] )
				) );

				foreach ( $found as $tag ) {
					$tags[ $tag ] = isset( $tags[ $tag ] ) ? $tags[ $tag ] + 1 : 1;
				}
			}
		} while ( count( $rows ) === self::SCAN_CHUNK );

		// Most used first, then by name, so two exports of one shop agree.
		uksort(
			$tags,
			function ( $a, $b ) use ( $tags ) {
				return $tags[ $a ] === $tags[ $b ] ? strcmp( $a, $b ) : $tags[ $b ] - $tags[ $a ];
			}
		);

		return array( 'products' => $products, 'tags' => $tags );
	}

	/**
	 * Whether WordPress has a tag registered right now, if it can say.
	 *
	 * @return bool|null null when this is not running inside WordPress
	 */
	private static function registered( $tag ) {
		if ( ! isset( $GLOBALS['shortcode_tags'] ) || ! is_array( $GLOBALS['shortcode_tags'] ) ) {
			return null;
		}

		return array_key_exists( $tag, $GLOBALS['shortcode_tags'] );
	}

	/** @param array<string,mixed> $graph */
	private function report( array $graph ) {
		$census = $this->census();
		$blocks = $graph['blocks'];

		/* 1. The census, always -- "none" is a finding too. */
		if ( empty( $census['tags'] ) ) {
			$this->note(
				'SHORTCODES IN PRODUCT DESCRIPTIONS: none. No product description or short description in '
					. 'this export contains a shortcode (' . $census['products'] . ' product'
					. ( 1 === $census['products'] ? '' : 's' ) . ' read).'
			);
		} else {
			$parts = array();

			foreach ( $census['tags'] as $tag => $count ) {
				$part = '[' . $tag . '] in ' . $count . ' product' . ( 1 === $count ? '' : 's' ) . ' -- ';

				if ( in_array( $tag, self::RESOLVED, true ) ) {
					$part .= 'RESOLVED: the posts it names are in content_blocks.csv';
				} else {
					$part .= 'NOT RESOLVED: the new shop receives it as text';
				}

				$registered = self::registered( $tag );

				if ( false === $registered ) {
					$part .= ' (no shortcode of that name is registered on this site, so it may be ordinary '
						. 'text in square brackets, or belong to a plugin that is switched off)';
				}

				$parts[] = $part;
			}

			$this->note(
				'SHORTCODES IN PRODUCT DESCRIPTIONS: ' . count( $census['tags'] ) . ' distinct tag'
					. ( 1 === count( $census['tags'] ) ? '' : 's' ) . ' across the description and short '
					. 'description of ' . $census['products'] . ' product' . ( 1 === $census['products'] ? '' : 's' )
					. '. ' . implode( '; ', $parts ) . '. A tag marked NOT RESOLVED prints on the new shop '
					. 'exactly as written unless something there understands it.'
			);
		}

		/* 2. What content_blocks.csv carries. */
		if ( ! empty( $blocks ) ) {
			$direct = 0;
			$types  = array();
			$built  = 0;

			foreach ( $blocks as $block ) {
				if ( ! empty( $block['products'] ) ) {
					$direct++;
				}

				$types[ $block['post_type'] ] = isset( $types[ $block['post_type'] ] ) ? $types[ $block['post_type'] ] + 1 : 1;
			}

			ksort( $types );

			$type_parts = array();

			foreach ( $types as $type => $count ) {
				$type_parts[] = $count . ' ' . $type;
			}

			$widgets = $graph['inside']['widgets'];
			ksort( $widgets );

			$widget_parts = array();

			foreach ( $widgets as $type => $count ) {
				$widget_parts[] = $type . ' x' . $count;
			}

			$text = 'content_blocks.csv carries ' . count( $blocks ) . ' section' . ( 1 === count( $blocks ) ? '' : 's' )
				. ' that product descriptions pull in by shortcode: ' . $direct . ' named by a product directly, '
				. ( count( $blocks ) - $direct ) . ' only from inside another section (followed '
				. self::MAX_DEPTH . ' levels down). By post type: ' . implode( ', ', $type_parts ) . '.';

			if ( ! empty( $widget_parts ) ) {
				$text .= ' Elementor widgets they use: ' . implode( ', ', $widget_parts ) . '.';
			}

			if ( ! empty( $graph['inside']['images'] ) ) {
				$images = count( $graph['inside']['images'] );

				$text .= ' They name ' . $images . ' picture' . ( 1 === $images ? '' : 's' ) . ' by address inside '
					. '`elementor_data`; media.csv does not list them, so they have to be fetched from the old site '
					. 'before it is switched off.';
			}

			$this->note( $text );
		}

		/* 3. Ids named and not there. */
		if ( ! empty( $graph['missing'] ) ) {
			$parts = array();

			foreach ( $graph['missing'] as $id => $refs ) {
				$by = array();

				if ( ! empty( $refs['products'] ) ) {
					$by[] = 'product' . ( 1 === count( $refs['products'] ) ? ' ' : 's ' ) . implode( ', ', $refs['products'] );
				}

				if ( ! empty( $refs['sections'] ) ) {
					$by[] = 'section' . ( 1 === count( $refs['sections'] ) ? ' ' : 's ' ) . implode( ', ', $refs['sections'] );
				}

				$parts[] = 'id ' . $id . ' (named by ' . implode( ' and ', $by ) . ')';
			}

			$this->note(
				'A SHORTCODE NAMES A SECTION THAT DOES NOT EXIST on this site, so nothing could be exported '
					. 'for it: ' . implode( '; ', $parts ) . '. There is nothing for the new shop to draw in its place: '
					. 'the shortcode stays as text in the description until it is removed or pointed at a section '
					. 'that exists.'
			);
		}

		/* 4. Nested too deep to follow. */
		if ( ! empty( $graph['beyond'] ) ) {
			$parts = array();

			foreach ( $graph['beyond'] as $id => $parents ) {
				$parts[] = 'id ' . $id . ' (inside section' . ( 1 === count( $parents ) ? ' ' : 's ' ) . implode( ', ', $parents ) . ')';
			}

			$this->note(
				'Sections nested more than ' . self::MAX_DEPTH . ' levels below a product are NOT in '
					. 'content_blocks.csv: ' . implode( '; ', $parts ) . '.'
			);
		}

		/* 5. A layout that is not JSON. */
		if ( ! empty( $graph['inside']['json_bad'] ) ) {
			$this->note(
				'The `_elementor_data` of section' . ( 1 === count( $graph['inside']['json_bad'] ) ? ' ' : 's ' )
					. implode( ', ', self::unique_ints( $graph['inside']['json_bad'] ) ) . ' is not valid JSON. It is '
					. 'exported exactly as stored; the plain-HTML fallback in `plain_html` is the usable copy.'
			);
		}

		/* 6. Shortcodes inside the sections that nothing resolves. */
		if ( ! empty( $graph['inside']['shortcodes'] ) ) {
			$parts = array();
			$inner = $graph['inside']['shortcodes'];
			ksort( $inner );

			foreach ( $inner as $tag => $sections ) {
				$ids = array_keys( $sections );
				sort( $ids );

				$parts[] = '[' . $tag . '] in section' . ( 1 === count( $ids ) ? ' ' : 's ' ) . implode( ', ', $ids );
			}

			$this->note(
				'Shortcodes INSIDE the exported sections that this export does not resolve: '
					. implode( '; ', $parts ) . '.'
			);
		}
	}

	/** @return array<int,int> */
	private static function unique_ints( array $values ) {
		$out = array_values( array_unique( array_map( 'intval', $values ) ) );
		sort( $out );

		return $out;
	}
}
