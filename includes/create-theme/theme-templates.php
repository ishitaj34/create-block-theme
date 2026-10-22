<?php

require_once __DIR__ . '/theme-media.php';
require_once __DIR__ . '/theme-patterns.php';

class CBT_Theme_Templates {

	/**
	 * Build a collection of templates and template-parts that should be exported (and modified)
	 * based on the given export_type.
	 *
	 * @param string $export_type The type of export to perform. 'all', 'current', or 'user'.
	 * @param array $templates_to_export List of specific templates to export.
	 * @return object An object containing the templates and parts that should be exported.
	 */
	public static function get_theme_templates( $export_type, $templates_to_export = null ) {

		$templates          = get_block_templates( array( 'slug__in' => $templates_to_export ) );
		$template_parts     = get_block_templates( array( 'slug__in' => $templates_to_export ), 'wp_template_part' );
		$exported_templates = array();
		$exported_parts     = array();

		// build collection of templates/parts in currently activated theme
		$templates_paths = get_block_theme_folders();
		$templates_path  = get_stylesheet_directory() . '/' . $templates_paths['wp_template'] . '/';
		$parts_path      = get_stylesheet_directory() . '/' . $templates_paths['wp_template_part'] . '/';

		foreach ( $templates as $template ) {
			if ( self::should_include_template(
				$template,
				$export_type,
				$templates_path
			) ) {
				$exported_templates[] = self::cleanup_template( $template );
			}
		}

		foreach ( $template_parts as $template ) {
			if ( self::should_include_template(
				$template,
				$export_type,
				$parts_path
			) ) {
				$exported_parts[] = self::cleanup_template( $template );
			}
		}

		return (object) array(
			'templates' => $exported_templates,
			'parts'     => $exported_parts,
		);
	}

	/**
	 * Filter a template out (return false) based on the export_type expected and the templates origin.
	 *
	 * @param object $template The template to filter.
	 * @param string $export_type The type of export to perform. 'all', 'current', or 'user'.
	 * @param string $path The path to the templates folder.
	 * @return object|bool The template if it should be included, or false if it should be excluded.
	 */
	public static function should_include_template( $template, $export_type, $path ) {
		if ( 'theme' === $template->source && 'user' === $export_type ) {
			return false;
		}
		if (
			'theme' === $template->source &&
			'current' === $export_type &&
			! file_exists( $path . $template->slug . '.html' )
		) {
			return false;
		}
		return true;
	}

	/**
	 * Clean up the template content before exporting.
	 * @param object $template The template to clean up.
	 * @return object The cleaned up template.
	 */
	private static function cleanup_template( $template ) {
		// NOTE: Dashes are encoded as \u002d in the content that we get (noteably in things like css variables used in templates)
		// This replaces that with dashes again. We should consider decoding the entire string but that is proving difficult.
		$template->content = str_replace( '\u002d', '-', $template->content );

		return $template;
	}

	/**
	 * Replace the old theme slug with the new theme slug in the template content.
	 *
	 * @param object $template The template to replace the namespace in.
	 * @param string $new_slug The new theme slug.
	 * @return object The template with the namespace replaced.
	 */
	public static function replace_template_namespace( $template, $new_slug ) {
		$old_slug = wp_get_theme()->get( 'TextDomain' );
		if ( $new_slug ) {
			$template->content = str_replace( $old_slug, $new_slug, $template->content );
		}
		return $template;
	}

	/**
	 * Clear all user templates customizations.
	 * This will remove all user templates from the database.
	 */
	public static function clear_user_templates_customizations() {
		$templates = get_block_templates();
		foreach ( $templates as $template ) {
			if ( 'custom' !== $template->source ) {
				continue;
			}
			wp_delete_post( $template->wp_id, true );
		}
	}

	/**
	 * Clear all user template-parts customizations.
	 * This will remove all user template-parts from the database.
	 */
	public static function clear_user_template_parts_customizations() {
		$template_parts = get_block_templates( array(), 'wp_template_part' );
		foreach ( $template_parts as $template ) {
			if ( 'custom' !== $template->source ) {
				continue;
			}
			wp_delete_post( $template->wp_id, true );
		}
	}

	/**
	 * Extract content from a template that need to be patternized.
	 * Return the modified template and the pattern that was created
	 *
	 * @param object $template The template to extract content from.
	 * @return object The template with the patternized content.
	 */
	public static function paternize_template( $template, $slug = null ) {
		// If there is any PHP in the template then paternize
		if ( str_contains( $template->content, '<?php' ) ) {
			$pattern                 = CBT_Theme_Patterns::pattern_from_template( $template, $slug );
			$pattern_link_attributes = array(
				'slug' => $pattern['slug'],
			);
			$template->content       = CBT_Theme_Patterns::create_pattern_link( $pattern_link_attributes );
			$template->pattern       = $pattern['content'];
		}
		return $template;
	}

	/**
	 * Prepare a template for export.
	 * This will escape text in the template, eliminate environment specific content,
	 * make template images local, and paternize the template.
	 *
	 * @param object $template The template to prepare for export.
	 * @param string $slug The slug of the theme.
	 * @return object The prepared template.
	 */
	public static function prepare_template_for_export( $template, $slug = null, $options = null ) {

		if ( ! $options ) {
			$options = array(
				'localizeText'   => false,
				'removeNavRefs'  => true,
				'localizeImages' => true,
			);
		}

		// Strip PHP tags from the raw template body BEFORE any
		// trusted-PHP injection (escape_text_in_template below).
		// Sanitising here preserves the legitimate localization output.
		$template->content = CBT_Theme_Patterns::strip_php_tags( $template->content );

		$template = self::eliminate_environment_specific_content( $template, $options );

		if ( ! array_key_exists( 'patternReferences', $options ) || $options['patternReferences'] ) {
			$template = self::replace_unedited_theme_patterns( $template );
		}

		if ( array_key_exists( 'localizeText', $options ) && $options['localizeText'] ) {
			$template = self::escape_text_in_template( $template );
		}

		if ( array_key_exists( 'localizeImages', $options ) && $options['localizeImages'] ) {
			$validated_media = array_key_exists( 'validatedMedia', $options ) ? $options['validatedMedia'] : null;
			$template        = CBT_Theme_Media::make_template_images_local( $template, $validated_media );
		}

		if ( $slug ) {
			$template = self::replace_template_namespace( $template, $slug );
		}

		$template = self::paternize_template( $template, $slug );

		return $template;
	}

	private static function should_localize_images( $options ) {
		return ! $options || ( array_key_exists( 'localizeImages', $options ) && $options['localizeImages'] );
	}

	private static function add_validated_media_to_options( $options, $validated_media ) {
		if ( ! $options ) {
			$options = array(
				'localizeText'   => false,
				'removeNavRefs'  => true,
				'localizeImages' => true,
			);
		}

		$options['validatedMedia'] = $validated_media;
		return $options;
	}

	/**
	 * Copy the templates and template-parts (including user customizations)
	 * as well as any media to the theme filesystem.
	 * If patterns need to be created for media or localizations they will also be added.
	 *
	 * @param string $export_type The type of export to perform. 'all', 'current', or 'user'.
	 * @param string $path The path to the theme folder. If null it is assumed to be the current theme.
	 * @param string $slug The slug of the theme. If null it is assumed to be the current theme.
	 * @param array $options An array of options to use when exporting the templates.
	 * @param array $templates_to_export List of specific templates to export. If null it will be fetched.
	 */
	public static function add_templates_to_local( $export_type, $path = null, $slug = null, $options = null, $templates_to_export = null ) {

		$theme_templates  = self::get_theme_templates( $export_type, $templates_to_export );
		$template_folders = get_block_theme_folders();

		$base_dir          = $path ? $path : get_stylesheet_directory();
		$template_dir      = $base_dir . DIRECTORY_SEPARATOR . $template_folders['wp_template'];
		$template_part_dir = $base_dir . DIRECTORY_SEPARATOR . $template_folders['wp_template_part'];
		$patterns_dir      = $base_dir . DIRECTORY_SEPARATOR . 'patterns';

		// If there is no templates folder, and it is needed, create it.
		if ( ! is_dir( $template_dir ) && count( $theme_templates->templates ) > 0 ) {
			wp_mkdir_p( $template_dir );
		}

		// If there is no parts folder, and it is needed, create it.
		if ( ! is_dir( $template_part_dir ) && count( $theme_templates->parts ) > 0 ) {
			wp_mkdir_p( $template_part_dir );
		}

		foreach ( $theme_templates->templates as $template ) {

			$template_options = $options;
			if ( self::should_localize_images( $template_options ) ) {
				$template->media  = CBT_Theme_Media::get_media_absolute_urls_from_template( $template );
				$validated_media  = ! empty( $template->media ) ? CBT_Theme_Media::add_media_to_local( $template->media ) : array();
				$template_options = self::add_validated_media_to_options( $template_options, $validated_media );
			}

			$template = self::prepare_template_for_export( $template, $slug, $template_options );

			// Write the template content
			file_put_contents(
				$template_dir . DIRECTORY_SEPARATOR . $template->slug . '.html',
				$template->content
			);

			// Write the pattern if it exists
			if ( isset( $template->pattern ) ) {
				// If there is no patterns folder, create it.
				if ( ! is_dir( $patterns_dir ) ) {
					wp_mkdir_p( $patterns_dir );
				}
				file_put_contents(
					$patterns_dir . DIRECTORY_SEPARATOR . $template->slug . '.php',
					$template->pattern
				);
			}
		}

		foreach ( $theme_templates->parts as $template ) {

			$template_options = $options;
			if ( self::should_localize_images( $template_options ) ) {
				$template->media  = CBT_Theme_Media::get_media_absolute_urls_from_template( $template );
				$validated_media  = ! empty( $template->media ) ? CBT_Theme_Media::add_media_to_local( $template->media ) : array();
				$template_options = self::add_validated_media_to_options( $template_options, $validated_media );
			}

			$template = self::prepare_template_for_export( $template, $slug, $template_options );

			// Write the template content
			file_put_contents(
				$template_part_dir . DIRECTORY_SEPARATOR . $template->slug . '.html',
				$template->content
			);

			// Write the pattern if it exists
			if ( isset( $template->pattern ) ) {
				// If there is no patterns folder, create it.
				if ( ! is_dir( $patterns_dir ) ) {
					wp_mkdir_p( $patterns_dir );
				}
				file_put_contents(
					$patterns_dir . DIRECTORY_SEPARATOR . $template->slug . '.php',
					$template->pattern
				);
			}
		}
	}

	/**
	 * Escape text in template content.
	 *
	 * @param object $template The template to escape text content in.
	 * @return object The template with the content escaped.
	 */
	public static function escape_text_in_template( $template ) {
		$template_blocks          = parse_blocks( $template->content );
		$localized_blocks         = CBT_Theme_Locale::escape_text_content_of_blocks( $template_blocks );
		$updated_template_content = serialize_blocks( $localized_blocks );
		// Process block attributes after serialization to avoid JSON encoding of PHP tags
		$updated_template_content = CBT_Theme_Locale::escape_block_attribute_strings( $updated_template_content );
		$template->content        = $updated_template_content;
		return $template;
	}

	private static function eliminate_environment_specific_content_from_block( $block, $options = null ) {

		// remove theme attribute from template parts
		if ( 'core/template-part' === $block['blockName'] && isset( $block['attrs']['theme'] ) ) {
			unset( $block['attrs']['theme'] );
		}

		// (optionally) remove ref attribute from nav blocks
		if ( 'core/navigation' === $block['blockName'] && isset( $block['attrs']['ref'] ) ) {
			if ( ! $options || ( array_key_exists( 'removeNavRefs', $options ) && $options['removeNavRefs'] ) ) {
				unset( $block['attrs']['ref'] );
			}
		}

		// remove id attributes and classes from image and cover blocks
		if ( in_array( $block['blockName'], array( 'core/image', 'core/cover' ), true ) ) {
			// remove id attribute from image and cover blocks
			if ( isset( $block['attrs']['id'] ) ) {
				$image_class = 'wp-image-' . $block['attrs']['id'];
				unset( $block['attrs']['id'] );
				// Remove the matching class token without changing similar text elsewhere.
				foreach ( $block['innerContent'] as $inner_key => $inner_content ) {
					if ( is_null( $inner_content ) || false === strpos( $inner_content, $image_class ) ) {
						continue;
					}

					// Covers with fixed or repeated backgrounds put the class on a div, not an img.
					$processor = new WP_HTML_Tag_Processor( $inner_content );
					while ( $processor->next_tag() ) {
						if ( $processor->has_class( $image_class ) ) {
							$processor->remove_class( $image_class );
						}
					}
					$block['innerContent'][ $inner_key ] = $processor->__toString();
				}
			}
		}

		// (optionally) remove taxQuery attribute from query blocks
		if ( 'core/query' === $block['blockName'] && isset( $block['attrs']['query']['taxQuery'] ) ) {
			if ( ! $options || ( array_key_exists( 'removeTaxQuery', $options ) && $options['removeTaxQuery'] ) ) {
				unset( $block['attrs']['query']['taxQuery'] );
			}
		}

		// process any inner blocks
		if ( ! empty( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $inner_block_key => $inner_block ) {
				$block['innerBlocks'][ $inner_block_key ] = static::eliminate_environment_specific_content_from_block( $inner_block, $options );
			}
		}

		return $block;
	}

	public static function eliminate_environment_specific_content( $template, $options = null ) {

		$template_blocks = parse_blocks( $template->content );
		$parsed_content  = '';

		foreach ( $template_blocks as $block ) {
			$parsed_block    = static::eliminate_environment_specific_content_from_block( $block, $options );
			$parsed_content .= serialize_block( $parsed_block );
		}

		$template->content = $parsed_content;
		return $template;
	}

	/**
	 * Replace unedited theme patterns in template content with pattern references.
	 *
	 * When a theme pattern is inserted in the editor, Gutenberg inlines its blocks
	 * and sets metadata.patternName on the root block. If the content still matches
	 * the registered pattern from the active theme, replace it with a wp:pattern reference.
	 *
	 * @param object $template The template to process.
	 * @return object The template with unedited patterns converted to references.
	 */
	public static function replace_unedited_theme_patterns( $template ) {
		if ( empty( $template->content ) ) {
			return $template;
		}

		$blocks            = parse_blocks( $template->content );
		$blocks            = self::process_blocks_for_pattern_references( $blocks );
		$template->content = serialize_blocks( $blocks );

		return $template;
	}

	/**
	 * Recursively process blocks and replace unedited theme pattern blocks with pattern references.
	 *
	 * @param array $blocks Array of parsed blocks.
	 * @return array Processed array of blocks.
	 */
	private static function process_blocks_for_pattern_references( array $blocks ) {
		$processed = array();

		foreach ( $blocks as $block ) {
			$pattern_name = isset( $block['attrs']['metadata']['patternName'] ) ? $block['attrs']['metadata']['patternName'] : null;

			if (
				is_string( $pattern_name ) &&
				self::is_active_theme_pattern( $pattern_name ) &&
				self::is_block_matching_pattern( $block, $pattern_name )
			) {
				$processed[] = array(
					'blockName'    => 'core/pattern',
					'attrs'        => array( 'slug' => $pattern_name ),
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				);
			} else {
				if ( ! empty( $block['innerBlocks'] ) ) {
					$block['innerBlocks'] = self::process_blocks_for_pattern_references( $block['innerBlocks'] );
				}
				$processed[] = $block;
			}
		}

		return $processed;
	}

	/**
	 * Determine if a pattern name is registered by the active theme or its parent theme.
	 *
	 * @param string $pattern_name The pattern slug / name.
	 * @return bool True if registered by the active theme or parent theme.
	 */
	public static function is_active_theme_pattern( $pattern_name ) {
		if ( ! class_exists( 'WP_Block_Patterns_Registry' ) ) {
			return false;
		}

		$registry = WP_Block_Patterns_Registry::get_instance();
		if ( ! $registry->is_registered( $pattern_name ) ) {
			return false;
		}

		$pattern = $registry->get_registered( $pattern_name );
		$theme   = wp_get_theme();

		// Check if pattern filePath belongs to active theme or parent theme.
		if ( ! empty( $pattern['filePath'] ) ) {
			$file_path      = wp_normalize_path( $pattern['filePath'] );
			$stylesheet_dir = wp_normalize_path( get_stylesheet_directory() );
			$template_dir   = wp_normalize_path( get_template_directory() );

			if ( str_starts_with( $file_path, $stylesheet_dir ) || str_starts_with( $file_path, $template_dir ) ) {
				return true;
			}
		}

		// Check if the pattern slug matches the theme's text domain or stylesheet slug prefix.
		$allowed_slug_prefixes = array();
		$stylesheet            = $theme->get_stylesheet();
		$text_domain           = $theme->get( 'TextDomain' );

		if ( ! empty( $stylesheet ) ) {
			$allowed_slug_prefixes[] = $stylesheet . '/';
		}
		if ( ! empty( $text_domain ) ) {
			$allowed_slug_prefixes[] = $text_domain . '/';
		}

		$parent = $theme->parent();
		if ( $parent ) {
			$parent_stylesheet  = $parent->get_stylesheet();
			$parent_text_domain = $parent->get( 'TextDomain' );
			if ( ! empty( $parent_stylesheet ) ) {
				$allowed_slug_prefixes[] = $parent_stylesheet . '/';
			}
			if ( ! empty( $parent_text_domain ) ) {
				$allowed_slug_prefixes[] = $parent_text_domain . '/';
			}
		}

		foreach ( array_unique( $allowed_slug_prefixes ) as $prefix ) {
			if ( str_starts_with( $pattern_name, $prefix ) ) {
				return true;
			}
		}

		// Check theme block patterns directly.
		if ( method_exists( $theme, 'get_block_patterns' ) ) {
			$theme_patterns = $theme->get_block_patterns();
			if ( is_array( $theme_patterns ) ) {
				foreach ( $theme_patterns as $theme_pattern ) {
					if ( isset( $theme_pattern['slug'] ) && $theme_pattern['slug'] === $pattern_name ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Check if a block's content and structure matches the registered pattern definition.
	 *
	 * @param array  $block        The parsed block from the template.
	 * @param string $pattern_name The pattern slug / name.
	 * @return bool True if the block matches the pattern definition.
	 */
	public static function is_block_matching_pattern( array $block, $pattern_name ) {
		$registry = WP_Block_Patterns_Registry::get_instance();
		$pattern  = $registry->get_registered( $pattern_name );

		if ( empty( $pattern['content'] ) ) {
			return false;
		}

		$pattern_blocks = parse_blocks( $pattern['content'] );
		// Filter out empty whitespace blocks.
		$pattern_blocks = array_values(
			array_filter(
				$pattern_blocks,
				function ( $b ) {
					return ! empty( $b['blockName'] ) || '' !== trim( isset( $b['innerHTML'] ) ? $b['innerHTML'] : '' );
				}
			)
		);

		// Gutenberg assigns patternName only to single root blocks.
		if ( 1 !== count( $pattern_blocks ) ) {
			return false;
		}

		$pattern_root = $pattern_blocks[0];

		// Pass pattern_root through eliminate_environment_specific_content_from_block to normalize.
		$pattern_root = self::eliminate_environment_specific_content_from_block( $pattern_root );

		// Normalize candidate block attributes by removing injected pattern metadata.
		$candidate_block = $block;
		if ( isset( $candidate_block['attrs']['metadata']['patternName'] ) ) {
			unset( $candidate_block['attrs']['metadata']['patternName'] );
		}

		// If Gutenberg injected metadata.name matching pattern title/name, and pattern_root didn't have it, remove it.
		if (
			isset( $candidate_block['attrs']['metadata']['name'] ) &&
			! isset( $pattern_root['attrs']['metadata']['name'] ) &&
			(
				( isset( $pattern['title'] ) && $candidate_block['attrs']['metadata']['name'] === $pattern['title'] ) ||
				( isset( $pattern['name'] ) && $candidate_block['attrs']['metadata']['name'] === $pattern['name'] )
			)
		) {
			unset( $candidate_block['attrs']['metadata']['name'] );
		}

		if ( isset( $candidate_block['attrs']['metadata'] ) && empty( $candidate_block['attrs']['metadata'] ) ) {
			unset( $candidate_block['attrs']['metadata'] );
		}

		return self::are_blocks_equivalent( $candidate_block, $pattern_root );
	}

	/**
	 * Check if two blocks are equivalent in name, attributes, inner HTML, and inner blocks.
	 *
	 * @param array $block_a First block.
	 * @param array $block_b Second block.
	 * @return bool True if blocks are structurally and visually equivalent.
	 */
	public static function are_blocks_equivalent( array $block_a, array $block_b ) {
		$name_a = isset( $block_a['blockName'] ) ? $block_a['blockName'] : '';
		$name_b = isset( $block_b['blockName'] ) ? $block_b['blockName'] : '';
		if ( $name_a !== $name_b ) {
			return false;
		}

		$attrs_a = self::normalize_block_attributes( isset( $block_a['attrs'] ) ? $block_a['attrs'] : array() );
		$attrs_b = self::normalize_block_attributes( isset( $block_b['attrs'] ) ? $block_b['attrs'] : array() );
		if ( $attrs_a !== $attrs_b ) {
			return false;
		}

		$html_a = self::normalize_html_content( isset( $block_a['innerHTML'] ) ? $block_a['innerHTML'] : '' );
		$html_b = self::normalize_html_content( isset( $block_b['innerHTML'] ) ? $block_b['innerHTML'] : '' );
		if ( $html_a !== $html_b ) {
			return false;
		}

		$inner_a = array_values(
			array_filter(
				isset( $block_a['innerBlocks'] ) ? $block_a['innerBlocks'] : array(),
				function ( $b ) {
					return ! empty( $b['blockName'] ) || '' !== trim( isset( $b['innerHTML'] ) ? $b['innerHTML'] : '' );
				}
			)
		);
		$inner_b = array_values(
			array_filter(
				isset( $block_b['innerBlocks'] ) ? $block_b['innerBlocks'] : array(),
				function ( $b ) {
					return ! empty( $b['blockName'] ) || '' !== trim( isset( $b['innerHTML'] ) ? $b['innerHTML'] : '' );
				}
			)
		);

		if ( count( $inner_a ) !== count( $inner_b ) ) {
			return false;
		}

		$count = count( $inner_a );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! self::are_blocks_equivalent( $inner_a[ $i ], $inner_b[ $i ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Recursively normalize block attributes for comparison (e.g. key sorting).
	 *
	 * @param mixed $attrs Attributes array or value.
	 * @return mixed Normalized attributes.
	 */
	private static function normalize_block_attributes( $attrs ) {
		if ( ! is_array( $attrs ) ) {
			return $attrs;
		}

		ksort( $attrs );
		foreach ( $attrs as $k => $v ) {
			if ( is_array( $v ) ) {
				$attrs[ $k ] = self::normalize_block_attributes( $v );
			}
		}

		return $attrs;
	}

	/**
	 * Normalize HTML content by collapsing whitespace sequences.
	 *
	 * @param string $html HTML string.
	 * @return string Normalized HTML string.
	 */
	private static function normalize_html_content( $html ) {
		if ( ! is_string( $html ) ) {
			return '';
		}

		return trim( preg_replace( '/\s+/', ' ', $html ) );
	}
}
