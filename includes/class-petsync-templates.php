<?php
/**
 * Shelter Pets Templates
 *
 * Registers block templates for pet archive and single views.
 *
 * @package Shelter_Pets
 * @since 1.0.0
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Petsync_Templates {

	/**
	 * The `wp_theme` term this plugin's templates are filed under.
	 *
	 * The Site Editor stores a customized plugin template as a wp_template or
	 * wp_template_part post assigned to a wp_theme term named after the plugin
	 * — not after the active theme. That term name is therefore a STORAGE KEY,
	 * not a label: change it and every existing customization stops being
	 * found, silently, and the front end quietly falls back to the bundled
	 * file.
	 *
	 * That has already happened twice (see LEGACY_NAMESPACES), because the name
	 * was written out in three separate places and only some of them were
	 * updated. It lives here now so a rename is one edit, and so the migration
	 * that repairs the damage can agree with the lookup by construction.
	 */
	public const THEME_NAMESPACE = 'shelter-pets';

	/**
	 * Namespaces this plugin has previously filed templates under.
	 *
	 * Taken from git history rather than memory: `vcpahumane-pet-sync` up to
	 * 2026-07-04, `shelter-pet-sync` from 2026-07-05, and the current name from
	 * 2026-08-01. Migration 4 re-files anything found under these.
	 *
	 * Add the outgoing name here on any future rename — an install can upgrade
	 * across several renames at once, so this list is cumulative and nothing
	 * should ever be removed from it.
	 */
	public const LEGACY_NAMESPACES = array(
		'vcpahumane-pet-sync',
		'shelter-pet-sync',
	);

	public function __construct() {
		add_filter( 'get_block_templates', array( $this, 'add_templates' ), 10, 3 );
		add_filter( 'pre_get_block_file_template', array( $this, 'get_template' ), 10, 3 );
	}

	public function add_templates( array $templates, array $query, string $template_type ): array {
		if ( 'wp_template' === $template_type ) {
			$plugin_items = $this->get_plugin_templates();
		} elseif ( 'wp_template_part' === $template_type ) {
			$plugin_items = $this->get_plugin_template_parts();
		} else {
			return $templates;
		}

		foreach ( $plugin_items as $slug => $data ) {
			// Skip if specific template requested and this isn't it.
			if ( ! empty( $query['slug__in'] ) && ! in_array( $slug, $query['slug__in'], true ) ) {
				continue;
			}

			// Skip if already exists in theme.
			$exists = false;
			foreach ( $templates as $template ) {
				if ( $template->slug === $slug ) {
					$exists = true;
					break;
				}
			}

			if ( ! $exists ) {
				$templates[] = $this->get_customized_template( $slug, $template_type )
					?? $this->build_template_object( $slug, $data, $template_type );
			}
		}

		return $templates;
	}

	public function get_template( $template, string $id, string $template_type ) {
		if ( $template ) {
			return $template;
		}

		$parts = explode( '//', $id );
		$slug  = $parts[1] ?? $parts[0];

		if ( 'wp_template' === $template_type ) {
			$plugin_items = $this->get_plugin_templates();
		} elseif ( 'wp_template_part' === $template_type ) {
			$plugin_items = $this->get_plugin_template_parts();
		} else {
			return $template;
		}

		if ( ! isset( $plugin_items[ $slug ] ) ) {
			return $template;
		}

		$result = $this->build_template_object( $slug, $plugin_items[ $slug ], $template_type );

		// Echo back the requested theme namespace. The Site Editor injects
		// the ACTIVE theme into template-part blocks on save (and core only
		// renders parts whose theme matches the active theme), so lookups
		// for our parts arrive as e.g. `{active-theme}//pet-floating-ui`.
		// Answering with our own namespace would make the editor treat the
		// part as missing because the returned ID differs from the request.
		$requested_theme = count( $parts ) > 1 ? $parts[0] : null;
		if ( $requested_theme && $requested_theme !== $result->theme ) {
			$result->id    = $id;
			$result->theme = $requested_theme;
		}

		return $result;
	}

	/**
	 * Find a user-customized version of a plugin template.
	 *
	 * The Site Editor saves customizations as wp_template/wp_template_part
	 * posts filed under this plugin's wp_theme term — not the active
	 * theme's — so the default front-end template query never sees them.
	 * Without this lookup the front end always renders the bundled file
	 * and silently ignores editor customizations.
	 */
	private function get_customized_template( string $slug, string $type ): ?WP_Block_Template {
		$posts = get_posts(
			array(
				'post_type'      => $type,
				'name'           => $slug,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'wp_theme',
					'field'    => 'name',
					'terms'    => self::THEME_NAMESPACE,
				),
				),
			)
		);

		if ( ! $posts ) {
			return null;
		}

		$template = _build_block_template_result_from_post( $posts[0] );

		return $template instanceof WP_Block_Template ? $template : null;
	}

	private function get_plugin_templates(): array {
		return array(
			'archive-vcps_pet' => array(
				'title'       => __( 'Pet Archive', 'shelter-pets' ),
				'description' => __( 'Displays the pet adoption listings.', 'shelter-pets' ),
				'post_types'  => array( 'vcps_pet' ),
			),
			'single-vcps_pet'  => array(
				'title'       => __( 'Single Pet', 'shelter-pets' ),
				'description' => __( 'Displays a single adoptable pet.', 'shelter-pets' ),
				'post_types'  => array( 'vcps_pet' ),
			),
		);
	}

	private function get_plugin_template_parts(): array {
		return array(
			'pet-floating-ui' => array(
				'title'       => __( 'Pet Floating UI', 'shelter-pets' ),
				'description' => __( 'Favorites modal and compare bar — shared across pet templates.', 'shelter-pets' ),
				'area'        => 'uncategorized',
			),
			'kennel-card'     => array(
				'title'       => __( 'Kennel Card', 'shelter-pets' ),
				'description' => __( 'The printed card for a kennel or cage. Edit it here and every card printed from Pets → Kennel Cards follows this layout.', 'shelter-pets' ),
				'area'        => 'uncategorized',
			),
		);
	}

	private function build_template_object( string $slug, array $data, string $type = 'wp_template' ): WP_Block_Template {
		$dir     = 'wp_template_part' === $type ? 'parts' : 'templates';
		$file    = PETSYNC_DIR . $dir . '/' . $slug . '.html';
		$content = file_exists( $file ) ? file_get_contents( $file ) : '';

		$template                 = new WP_Block_Template();
		$template->id             = self::THEME_NAMESPACE . '//' . $slug;
		$template->theme          = self::THEME_NAMESPACE;
		$template->slug           = $slug;
		$template->source         = 'plugin';
		$template->type           = $type;
		$template->title          = $data['title'];
		$template->description    = $data['description'] ?? '';
		$template->status         = 'publish';
		$template->has_theme_file = true;
		$template->is_custom      = false;
		$template->content        = $content;

		if ( 'wp_template' === $type ) {
			$template->post_types = $data['post_types'] ?? array();
		}

		if ( 'wp_template_part' === $type ) {
			$template->area = $data['area'] ?? 'uncategorized';
		}

		return $template;
	}
}
