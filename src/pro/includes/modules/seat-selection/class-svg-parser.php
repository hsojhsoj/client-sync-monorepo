<?php
/**
 * File: src/pro/includes/modules/seat-selection/class-svg-parser.php
 * Parses uploaded SVG documents into a normalized venue layout JSON structure.
 *
 * Supports two parsing modes:
 *  - flat_ids:       Element IDs with a configurable delimiter (e.g. sectionA-row1-seat5)
 *  - nested_groups:  Nested <g> elements (Illustrator layer hierarchy)
 *
 * Both modes produce the same normalized layout array suitable for storage
 * in _clisyc_venue_layout post meta.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Seat_Selection
 */

namespace ClientSyncPro\Modules\Seat_Selection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVG_Parser {

	/**
	 * Parse an SVG string into a venue layout array.
	 *
	 * @param string $svg_string  Raw SVG markup.
	 * @param string $mode        'flat_ids' or 'nested_groups'.
	 * @param string $delimiter   Delimiter for flat_ids mode (default '-').
	 * @return array|\WP_Error Normalized layout array or WP_Error on failure.
	 */
	public function parse( string $svg_string, string $mode = 'flat_ids', string $delimiter = '-' ) {
		$svg_string = $this->sanitize_svg( $svg_string );

		if ( empty( $svg_string ) ) {
			return new \WP_Error( 'empty_svg', __( 'SVG content is empty after sanitization.', 'client-sync-pro' ) );
		}

		libxml_use_internal_errors( true );
		$dom = new \DOMDocument();
		$loaded = $dom->loadXML( $svg_string );
		libxml_clear_errors();

		if ( ! $loaded ) {
			return new \WP_Error( 'invalid_svg', __( 'Could not parse SVG. Ensure it is valid XML.', 'client-sync-pro' ) );
		}

		switch ( $mode ) {
			case 'nested_groups':
				$sections = $this->parse_nested_groups( $dom );
				break;
			case 'flat_ids':
			default:
				$sections = $this->parse_flat_ids( $dom, $delimiter );
				break;
		}

		if ( is_wp_error( $sections ) ) {
			return $sections;
		}

		$total_seats = 0;
		foreach ( $sections as $section ) {
			foreach ( $section['rows'] as $row ) {
				$total_seats += count( $row['seats'] );
			}
		}

		if ( 0 === $total_seats ) {
			return new \WP_Error( 'no_seats', __( 'No seats found in the SVG. Check element naming and parsing mode.', 'client-sync-pro' ) );
		}

		return [
			'parsing_mode' => $mode,
			'total_seats'  => $total_seats,
			'sections'     => $sections,
		];
	}

	/**
	 * Sanitize SVG by stripping script elements, event handlers, and external references.
	 *
	 * @param string $svg Raw SVG string.
	 * @return string Sanitized SVG.
	 */
	public function sanitize_svg( string $svg ): string {
		// Strip <script> tags and their contents.
		$svg = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $svg );

		// Strip event handler attributes (on*).
		$svg = preg_replace( '/\s+on\w+\s*=\s*"[^"]*"/i', '', $svg );
		$svg = preg_replace( "/\s+on\w+\s*=\s*'[^']*'/i", '', $svg );

		// Strip href="javascript:" and xlink:href="javascript:".
		$svg = preg_replace( '/\s+(xlink:)?href\s*=\s*"javascript:[^"]*"/i', '', $svg );
		$svg = preg_replace( "/\s+(xlink:)?href\s*=\s*'javascript:[^']*'/i", '', $svg );

		// Strip <foreignObject> elements.
		$svg = preg_replace( '/<foreignObject\b[^>]*>.*?<\/foreignObject>/is', '', $svg );

		// Strip <use> elements referencing external files.
		$svg = preg_replace( '/<use\b[^>]*href\s*=\s*"https?:[^"]*"[^>]*\/?\s*>/i', '', $svg );

		return trim( $svg );
	}

	/**
	 * Parse SVG using flat element IDs.
	 *
	 * Scans all elements for IDs matching compound naming:
	 *   3-part: section{D}row{D}seat  → section + row + seat
	 *   2-part: row{D}seat            → _default section
	 *
	 * @param \DOMDocument $dom
	 * @param string       $delimiter
	 * @return array Sections array.
	 */
	private function parse_flat_ids( \DOMDocument $dom, string $delimiter ): array {
		$xpath    = new \DOMXPath( $dom );
		$sections = [];

		// Find all elements with an id attribute.
		$elements = $xpath->query( '//*[@id]' );

		foreach ( $elements as $element ) {
			$id = $element->getAttribute( 'id' );
			$parts = explode( $delimiter, $id );
			$part_count = count( $parts );

			if ( $part_count < 2 ) {
				continue; // Not a seat element.
			}

			if ( $part_count >= 3 ) {
				// 3+ parts: first = section, second = row, last = seat.
				$section_id = $parts[0];
				$row_id     = $parts[1];
				$seat_label = $parts[ $part_count - 1 ];
			} else {
				// 2 parts: row + seat (no sections).
				$section_id = '_default';
				$row_id     = $parts[0];
				$seat_label = $parts[1];
			}

			$category = $this->detect_category( $element );

			if ( ! isset( $sections[ $section_id ] ) ) {
				$sections[ $section_id ] = [
					'id'             => $section_id,
					'label'          => $this->humanize_label( $section_id ),
					'category'       => '',
					'svg_element_id' => $section_id !== '_default' ? $section_id : '',
					'rows'           => [],
				];
			}

			// Apply category from seat to section if section has none.
			if ( ! empty( $category ) && empty( $sections[ $section_id ]['category'] ) ) {
				$sections[ $section_id ]['category'] = $category;
			}

			$row_key = $section_id . '::' . $row_id;
			if ( ! isset( $sections[ $section_id ]['rows'][ $row_id ] ) ) {
				$sections[ $section_id ]['rows'][ $row_id ] = [
					'id'             => $row_id,
					'label'          => $this->humanize_label( $row_id ),
					'svg_element_id' => $row_id,
					'seats'          => [],
				];
			}

			$sections[ $section_id ]['rows'][ $row_id ]['seats'][] = [
				'id'             => $id,
				'label'          => $this->humanize_label( $seat_label ),
				'svg_element_id' => $id,
				'category'       => $category,
				'status'         => 'active',
			];
		}

		// Re-index rows from associative to sequential.
		$result = [];
		foreach ( $sections as $section ) {
			$section['rows'] = array_values( $section['rows'] );
			$result[] = $section;
		}

		return $result;
	}

	/**
	 * Parse SVG using nested group hierarchy (Illustrator layers).
	 *
	 * Hierarchy from nested <g> elements:
	 *   3 levels: section > row > seat elements
	 *   2 levels: row > seat elements (wrapped in _default section)
	 *
	 * @param \DOMDocument $dom
	 * @return array Sections array.
	 */
	private function parse_nested_groups( \DOMDocument $dom ): array {
		$xpath = new \DOMXPath( $dom );

		// Find the root <svg> element.
		$svg_elements = $dom->getElementsByTagName( 'svg' );
		if ( 0 === $svg_elements->length ) {
			return [];
		}

		$svg_root = $svg_elements->item( 0 );

		// Find top-level <g> elements with IDs (direct children of svg or nested one level in a wrapper).
		$top_groups = $this->get_child_groups_with_ids( $svg_root );

		if ( empty( $top_groups ) ) {
			// Try one level deeper (some SVGs wrap everything in a single <g>).
			foreach ( $svg_root->childNodes as $child ) {
				if ( $child instanceof \DOMElement && 'g' === $child->tagName ) {
					$top_groups = $this->get_child_groups_with_ids( $child );
					if ( ! empty( $top_groups ) ) {
						break;
					}
				}
			}
		}

		if ( empty( $top_groups ) ) {
			return [];
		}

		// Determine depth: do top-level groups contain <g> children that themselves contain <g> children?
		$max_depth = $this->detect_group_depth( $top_groups );

		if ( $max_depth >= 3 ) {
			// 3 levels: section > row > seat
			return $this->parse_three_level_groups( $top_groups );
		}

		// 2 levels: row > seat (no sections).
		return $this->parse_two_level_groups( $top_groups );
	}

	/**
	 * Parse 3-level nested groups: section > row > seat.
	 *
	 * @param \DOMElement[] $section_groups
	 * @return array
	 */
	private function parse_three_level_groups( array $section_groups ): array {
		$sections = [];

		foreach ( $section_groups as $section_el ) {
			$section_id = $section_el->getAttribute( 'id' );
			$category   = $this->detect_category( $section_el );

			$section = [
				'id'             => $section_id,
				'label'          => $this->humanize_label( $section_id ),
				'category'       => $category,
				'svg_element_id' => $section_id,
				'rows'           => [],
			];

			$row_groups = $this->get_child_groups_with_ids( $section_el );

			foreach ( $row_groups as $row_el ) {
				$row_id = $row_el->getAttribute( 'id' );
				$row = [
					'id'             => $row_id,
					'label'          => $this->humanize_label( $row_id ),
					'svg_element_id' => $row_id,
					'seats'          => [],
				];

				$seat_elements = $this->get_seat_elements( $row_el );

				foreach ( $seat_elements as $seat_el ) {
					$seat_id      = $seat_el->getAttribute( 'id' );
					$seat_category = $this->detect_category( $seat_el );

					$row['seats'][] = [
						'id'             => $seat_id,
						'label'          => $this->humanize_label( $seat_id ),
						'svg_element_id' => $seat_id,
						'category'       => $seat_category ?: $category,
						'status'         => 'active',
					];
				}

				if ( ! empty( $row['seats'] ) ) {
					$section['rows'][] = $row;
				}
			}

			if ( ! empty( $section['rows'] ) ) {
				$sections[] = $section;
			}
		}

		return $sections;
	}

	/**
	 * Parse 2-level nested groups: row > seat (no sections).
	 *
	 * @param \DOMElement[] $row_groups
	 * @return array
	 */
	private function parse_two_level_groups( array $row_groups ): array {
		$section = [
			'id'             => '_default',
			'label'          => '',
			'category'       => '',
			'svg_element_id' => '',
			'rows'           => [],
		];

		foreach ( $row_groups as $row_el ) {
			$row_id = $row_el->getAttribute( 'id' );
			$row = [
				'id'             => $row_id,
				'label'          => $this->humanize_label( $row_id ),
				'svg_element_id' => $row_id,
				'seats'          => [],
			];

			$seat_elements = $this->get_seat_elements( $row_el );

			foreach ( $seat_elements as $seat_el ) {
				$seat_id  = $seat_el->getAttribute( 'id' );
				$category = $this->detect_category( $seat_el );

				$row['seats'][] = [
					'id'             => $seat_id,
					'label'          => $this->humanize_label( $seat_id ),
					'svg_element_id' => $seat_id,
					'category'       => $category,
					'status'         => 'active',
				];
			}

			if ( ! empty( $row['seats'] ) ) {
				$section['rows'][] = $row;
			}
		}

		return ! empty( $section['rows'] ) ? [ $section ] : [];
	}

	/**
	 * Get child <g> elements that have an id attribute.
	 *
	 * @param \DOMElement $parent
	 * @return \DOMElement[]
	 */
	private function get_child_groups_with_ids( \DOMNode $parent ): array {
		$groups = [];
		foreach ( $parent->childNodes as $child ) {
			if ( $child instanceof \DOMElement && 'g' === $child->tagName && $child->hasAttribute( 'id' ) ) {
				$groups[] = $child;
			}
		}
		return $groups;
	}

	/**
	 * Get seat-level elements (elements with id, excluding <g>).
	 *
	 * Seat elements are leaf-level shapes: rect, circle, ellipse, path, polygon, polyline, or <g> with IDs
	 * that don't themselves contain <g> children.
	 *
	 * @param \DOMElement $parent
	 * @return \DOMElement[]
	 */
	private function get_seat_elements( \DOMElement $parent ): array {
		$seats = [];
		$shape_tags = [ 'rect', 'circle', 'ellipse', 'path', 'polygon', 'polyline', 'line' ];

		foreach ( $parent->childNodes as $child ) {
			if ( ! ( $child instanceof \DOMElement ) || ! $child->hasAttribute( 'id' ) ) {
				continue;
			}

			if ( in_array( $child->tagName, $shape_tags, true ) ) {
				$seats[] = $child;
			} elseif ( 'g' === $child->tagName ) {
				// A <g> with an id that has no <g> children is treated as a seat group.
				$sub_groups = $this->get_child_groups_with_ids( $child );
				if ( empty( $sub_groups ) ) {
					$seats[] = $child;
				}
			}
		}

		return $seats;
	}

	/**
	 * Detect the maximum nesting depth of groups.
	 *
	 * @param \DOMElement[] $groups Top-level groups.
	 * @return int Maximum depth (1 = no children, 2 = one level of children, 3+ = deeper).
	 */
	private function detect_group_depth( array $groups ): int {
		$max_depth = 1;

		foreach ( $groups as $group ) {
			$child_groups = $this->get_child_groups_with_ids( $group );
			if ( ! empty( $child_groups ) ) {
				$child_depth = $this->detect_group_depth( $child_groups );
				$max_depth = max( $max_depth, 1 + $child_depth );
			}
		}

		return $max_depth;
	}

	/**
	 * Detect category from element's data-category attribute or CSS class.
	 *
	 * @param \DOMElement $element
	 * @return string Category string or empty.
	 */
	private function detect_category( \DOMElement $element ): string {
		// Priority 1: data-category attribute.
		if ( $element->hasAttribute( 'data-category' ) ) {
			return sanitize_key( $element->getAttribute( 'data-category' ) );
		}

		// Priority 2: CSS class (first non-standard class).
		if ( $element->hasAttribute( 'class' ) ) {
			$classes = preg_split( '/\s+/', trim( $element->getAttribute( 'class' ) ) );
			// Filter out common SVG/Illustrator utility classes.
			$ignore = [ 'st0', 'st1', 'st2', 'st3', 'st4', 'cls-1', 'cls-2', 'cls-3', 'cls-4', 'fil0', 'fil1' ];
			foreach ( $classes as $class ) {
				$class = trim( $class );
				if ( ! empty( $class ) && ! in_array( $class, $ignore, true ) && ! preg_match( '/^(st|cls-|fil)\d+$/', $class ) ) {
					return sanitize_key( $class );
				}
			}
		}

		return '';
	}

	/**
	 * Convert a machine ID into a human-readable label.
	 *
	 * e.g. "section-a" → "Section A", "row_3" → "Row 3", "seat-12" → "Seat 12"
	 * Strips common prefixes like "seat-", "row-", "section-" to keep labels concise.
	 *
	 * @param string $id
	 * @return string
	 */
	private function humanize_label( string $id ): string {
		if ( '_default' === $id || empty( $id ) ) {
			return '';
		}

		// Replace dashes and underscores with spaces.
		$label = str_replace( [ '-', '_' ], ' ', $id );

		// Title-case each word.
		$label = ucwords( $label );

		return trim( $label );
	}
}
