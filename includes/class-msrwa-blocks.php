<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The article as blocks, because that is what an editor opens.
 *
 * The engine returns one string of semantic HTML — h2, h3, p, ul, ol, li, and
 * the `<!--nextpage-->` marker. Stored as-is, the block editor can make nothing
 * of it: the whole article arrives as a single Classic block, which cannot be
 * reordered, cannot take a block-level change, and offers none of the tools an
 * editor reaches for. The article is the one thing they are here to work on.
 *
 * So it is converted on the way into the post. Only the tags the article
 * contract allows are given real blocks; anything else is wrapped in an HTML
 * block rather than dropped, because losing a paragraph is far worse than
 * showing one in a plainer block.
 */
final class MSRWA_Blocks {

	/** Headings the converter gives a heading block; everything else is HTML. */
	private static function headings() { return array( 'h1' => 1, 'h2' => 2, 'h3' => 3, 'h4' => 4, 'h5' => 5, 'h6' => 6 ); }

	/**
	 * Block markup for one article.
	 *
	 * Returns the input unchanged when there is nothing to do — no DOM
	 * extension, nothing in the string, or markup that already carries blocks —
	 * so running it twice cannot double-wrap an article.
	 */
	public static function from_html( $html ) {
		$html = (string) $html;
		if ( '' === trim( $html ) ) { return $html; }
		if ( false !== strpos( $html, '<!-- wp:' ) ) { return $html; }
		if ( ! class_exists( 'DOMDocument' ) ) { return $html; }

		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		// Two things this has to get right, and both bite silently.
		//
		// The article is UTF-8 and libxml will guess Latin-1 without being told,
		// which turns every accent into mojibake — hence the meta charset.
		//
		// And it is a fragment with many top-level elements, which a document
		// cannot hold: given several, libxml keeps the first as the document
		// element and drops the rest. Wrapping them in one div gives it the
		// single root it needs, and the article's own nodes stay inside.
		$loaded = $document->loadHTML(
			'<meta charset="utf-8"><div data-msrwa-root="1">' . $html . '</div>',
			LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) { return $html; }

		$root = null;
		foreach ( $document->getElementsByTagName( 'div' ) as $candidate ) {
			if ( $candidate->hasAttribute( 'data-msrwa-root' ) ) { $root = $candidate; break; }
		}
		if ( ! $root ) { return $html; }

		$blocks = array();
		foreach ( iterator_to_array( $root->childNodes ) as $node ) {
			$block = self::node( $document, $node );
			if ( '' !== $block ) { $blocks[] = $block; }
		}
		$out = implode( "\n\n", $blocks );
		return '' === trim( $out ) ? $html : $out;
	}

	/** One top-level node, as the block that carries it. */
	private static function node( DOMDocument $document, DOMNode $node ) {
		if ( XML_COMMENT_NODE === $node->nodeType ) {
			// The page break the article was asked to place is a block of its
			// own; every other comment travels through untouched.
			return 'nextpage' === trim( $node->textContent )
				? "<!-- wp:nextpage -->\n<!--nextpage-->\n<!-- /wp:nextpage -->"
				: '';
		}

		if ( XML_TEXT_NODE === $node->nodeType ) {
			$text = trim( $node->textContent );
			return '' === $text ? '' : self::paragraph( esc_html( $text ) );
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType ) { return ''; }

		$tag = strtolower( $node->nodeName );
		if ( 'meta' === $tag ) { return ''; }

		$inner = self::inner_html( $document, $node );

		$headings = self::headings();
		if ( isset( $headings[ $tag ] ) ) {
			$level = $headings[ $tag ];
			$attributes = 2 === $level ? '' : ' {"level":' . $level . '}';
			return '<!-- wp:heading' . $attributes . " -->\n"
				. '<' . $tag . ' class="wp-block-heading">' . $inner . '</' . $tag . ">\n"
				. '<!-- /wp:heading -->';
		}

		if ( 'p' === $tag ) { return '' === trim( $inner ) ? '' : self::paragraph( $inner ); }
		if ( 'ul' === $tag || 'ol' === $tag ) { return self::list_block( $document, $node, 'ol' === $tag ); }

		return self::html_block( $document->saveHTML( $node ) );
	}

	private static function paragraph( $inner ) {
		return "<!-- wp:paragraph -->\n<p>" . $inner . "</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * A list, with each item a block of its own.
	 *
	 * The editor has treated list items as blocks since WordPress 6.0, and a
	 * list whose items are not blocks is flagged as invalid content the moment
	 * it is opened.
	 */
	private static function list_block( DOMDocument $document, DOMNode $node, $ordered ) {
		$items = array();
		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType || 'li' !== strtolower( $child->nodeName ) ) { continue; }
			$items[] = "<!-- wp:list-item -->\n<li>" . self::item_html( $document, $child ) . "</li>\n<!-- /wp:list-item -->";
		}
		if ( ! $items ) { return ''; }

		$tag = $ordered ? 'ol' : 'ul';
		$attributes = $ordered ? ' {"ordered":true}' : '';
		return '<!-- wp:list' . $attributes . " -->\n"
			. '<' . $tag . ' class="wp-block-list">' . implode( "\n\n", $items ) . '</' . $tag . ">\n"
			. '<!-- /wp:list -->';
	}

	/** Anything the contract did not promise, kept rather than lost. */
	private static function html_block( $html ) {
		$html = trim( (string) $html );
		return '' === $html ? '' : "<!-- wp:html -->\n" . $html . "\n<!-- /wp:html -->";
	}

	/**
	 * One list item, with any list inside it made a block of its own.
	 *
	 * A nested list written as plain `<ul>` inside the item survives and
	 * renders, but the editor cannot see it: it is not a block, so it cannot
	 * be indented, reordered or added to. Core nests a whole list block inside
	 * the item, and so does this.
	 */
	private static function item_html( DOMDocument $document, DOMNode $node ) {
		$inner = '';
		foreach ( $node->childNodes as $child ) {
			$tag = XML_ELEMENT_NODE === $child->nodeType ? strtolower( $child->nodeName ) : '';
			if ( 'ul' === $tag || 'ol' === $tag ) {
				$inner .= self::list_block( $document, $child, 'ol' === $tag );
				continue;
			}
			$inner .= $document->saveHTML( $child );
		}
		return trim( $inner );
	}

	private static function inner_html( DOMDocument $document, DOMNode $node ) {
		$inner = '';
		foreach ( $node->childNodes as $child ) { $inner .= $document->saveHTML( $child ); }
		return trim( $inner );
	}
}
