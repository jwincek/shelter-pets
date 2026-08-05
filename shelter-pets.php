<?php
/**
 * Plugin Name: Shelter Pets
 * Plugin URI: https://github.com/jwincek/shelter-pets
 * Description: Adoptable pet listings for animal shelters — blocks for cards, grids, filters, galleries, favorites and comparison, with sync from Petstablished.
 * Version: 1.0.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: Jerome Wincek
 * Author URI: https://github.com/jwincek
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: shelter-pets
 *
 * @package Shelter_Pets
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'PETSYNC_VERSION', '1.0.0' );
define( 'PETSYNC_FILE', __FILE__ );
define( 'PETSYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'PETSYNC_URL', plugin_dir_url( __FILE__ ) );

// Autoload classes.
spl_autoload_register(
	function ( string $class ): void {
		// Legacy classes: Petsync_Foo → includes/class-petsync-foo.php
		if ( str_starts_with( $class, 'Petsync_' ) ) {
				$file = PETSYNC_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
				return;
			}
		}

		// Namespaced classes: Petsync\Core\Config → includes/core/class-config.php
		if ( str_starts_with( $class, 'Petsync\\' ) ) {
			$relative = substr( $class, strlen( 'Petsync\\' ) );
			$parts    = explode( '\\', $relative );
			$name     = array_pop( $parts ); // Class name.
			$dir      = strtolower( implode( '/', $parts ) ); // Sub-directory.
			$file     = PETSYNC_DIR . 'includes/' . $dir . '/class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';

			if ( file_exists( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
);

/**
 * Plugin activation — register CPTs early and flush rewrite rules.
 *
 * register_activation_hook must be called from the main plugin file,
 * not from inside a plugins_loaded callback. CPTs must be registered
 * before flush_rewrite_rules() or the /pets/ archive will 404.
 */
register_activation_hook(
	__FILE__,
	function (): void {
		// Initialize config so CPT_Registry can read post-types.json.
		\Petsync\Core\Config::init( PETSYNC_DIR . 'config/' );

		// Register CPTs and taxonomies so WP knows about the rewrite rules.
		\Petsync\Core\CPT_Registry::register_post_types();
		\Petsync\Core\CPT_Registry::register_taxonomies();

		// Flush rewrite rules so /pets/ and taxonomy archives work immediately.
		flush_rewrite_rules();

		// Schedule cron sync. Route through the shared helper so the 6pm-anchor
		// and Sunday-skip semantics apply identically at activation and settings save.
		$settings = Petsync_Admin::get_settings();
		if ( ! wp_next_scheduled( 'petsync_scheduled_sync' ) ) {
			Petsync_Admin::reschedule_cron( $settings['auto_sync'], $settings['sync_interval'] );
		}
	}
);

/**
 * Plugin deactivation — clean up cron and flush rewrite rules.
 */
register_deactivation_hook(
	__FILE__,
	function (): void {
		wp_clear_scheduled_hook( 'petsync_scheduled_sync' );
		// Also the pre-1.0 hook name, matching uninstall.php. The rename
		// migration normally clears this, but it only runs once an admin page
		// has loaded — deactivating straight after an upgrade would otherwise
		// leave a recurring event scheduled against a hook nothing answers.
		wp_clear_scheduled_hook( 'petstablished_scheduled_sync' );

		// Flush rewrite rules to remove our custom rules cleanly.
		flush_rewrite_rules();
	}
);

/**
 * Initialize the plugin.
 */
function petsync_init(): void {
	// Initialize config loader.
	\Petsync\Core\Config::init( PETSYNC_DIR . 'config/' );

	// Config-driven CPT, taxonomy, and meta registration.
	\Petsync\Core\CPT_Registry::init();

	// Apply compatibility filters (?compat_goodWithDogs=1 etc.) to the pet
	// archive / taxonomy main query. Compatibility data lives in the
	// pet_attribute taxonomy — it is NOT stored as post meta (the sync
	// keeps it in the _pet_api_response snapshot + attribute terms), so
	// this must be a tax_query. Mirrors Query::whereAttribute() and the
	// filter-pets ability so a no-JS request and the grid block agree.
	add_action(
		'pre_get_posts',
		function ( \WP_Query $query ): void {
			if ( is_admin() || ! $query->is_main_query() ) {
				return;
			}

			$entities   = \Petsync\Core\Config::get_item( 'entities', 'entities', [] );
			$taxonomies = array_column( $entities['vcps_pet']['taxonomies'] ?? [], 'taxonomy' );

			if ( ! $query->is_post_type_archive( 'vcps_pet' ) && ! $query->is_tax( $taxonomies ) ) {
				return;
			}

			// camelCase URL key (compat_<key>) => pet_attribute term slug.
			// Mirrors \Petsync\Abilities\Pets\COMPAT_MAP and the grid block's URL params.
			$compat_map    = [
				'goodWithDogs'   => 'good-with-dogs',
				'goodWithCats'   => 'good-with-cats',
				'goodWithKids'   => 'good-with-kids',
				'shotsCurrent'   => 'shots-current',
				'spayedNeutered' => 'spayed-neutered',
				'housebroken'    => 'housebroken',
				'specialNeeds'   => 'special-needs',
				'hypoallergenic' => 'hypoallergenic',
				'declawed'       => 'declawed',
			];
			$attribute_tax = $entities['vcps_pet']['attribute_taxonomy'] ?? 'pet_attribute';

			$compat_clauses = [];
			foreach ( $compat_map as $input_key => $term_slug ) {
				if ( ! empty( $_GET[ 'compat_' . $input_key ] ) ) {
					$compat_clauses[] = [
						'taxonomy' => $attribute_tax,
						'field'    => 'slug',
						'terms'    => $term_slug,
					];
				}
			}

			if ( empty( $compat_clauses ) ) {
				return;
			}

			if ( count( $compat_clauses ) > 1 ) {
				$compat_clauses['relation'] = 'AND';
			}

			// Combine with any pre-existing tax_query (e.g. an explicit one)
			// via AND. On a taxonomy archive the term constraint comes from
			// query vars and WordPress AND-merges it during parse_tax_query.
			$existing = $query->get( 'tax_query' ) ?: [];
			$query->set(
				'tax_query',
				$existing ? [
					'relation' => 'AND',
					$existing,
					$compat_clauses,
				] : $compat_clauses
			);
		}
	);

	// Template helpers — shared functions for block render callbacks.
	require_once PETSYNC_DIR . 'includes/template-helpers.php';

	// Core functionality.
	new Petsync_Blocks();
	new Petsync_Variations();
	new Petsync_Templates();

	// Config-driven abilities registration (replaces old Petsync_Abilities class).
	add_action(
		'wp_abilities_api_categories_init',
		function () {
			wp_register_ability_category(
				'pets',
				[
					'label'       => __( 'Pets', 'shelter-pets' ),
					'description' => __( 'Pet adoption data operations.', 'shelter-pets' ),
				]
			);
		}
	);
	add_action( 'wp_abilities_api_init', [ \Petsync\Abilities\Provider::class, 'register' ] );

	// Plugin-scoped REST routes for client-side ability execution.
	// The core Abilities REST API at /wp-abilities/v1/ requires an authenticated
	// user for ALL endpoints. Favorites and comparison must work for anonymous
	// front-end visitors, so we register thin routes that delegate to the
	// abilities directly while respecting each ability's permission_callback.
	require_once PETSYNC_DIR . 'includes/class-petsync-rest.php';
	add_action( 'rest_api_init', [ 'Petsync_REST', 'register_routes' ] );

	// Admin & Sync (admin only).
	if ( is_admin() ) {
		new Petsync_Admin();
		new Petsync_Pet_Fields();
		new Petsync_Kennel_Cards();
	}
	new Petsync_Sync();
}
add_action( 'plugins_loaded', 'petsync_init' );

/**
 * Stored-data schema version this build of the plugin expects.
 *
 * Bump this whenever stored data (options, post meta, cron hook names) changes
 * shape, and add the matching entry to petsync_get_migrations(). This is the
 * plugin's DATA version and is deliberately independent of the release version
 * in the plugin header — most releases change no stored data at all.
 */
define( 'PETSYNC_DB_VERSION', 4 );

/**
 * The ordered migration list.
 *
 * Keyed by the schema version each migration brings the install UP TO. They run
 * in key order, and only those above the installed version run. Every migration
 * must be idempotent anyway: a fresh install starts at 0 and runs the whole list,
 * so each one has to no-op cleanly when there is nothing to convert.
 *
 * @return array<int, callable> Migration callables keyed by target version.
 */
function petsync_get_migrations(): array {
	return array(
		1 => 'petsync_migrate_1_option_names',
		2 => 'petsync_migrate_2_provider_meta',
		3 => 'petsync_migrate_3_default_status',
		4 => 'petsync_migrate_4_template_namespace',
	);
}

/**
 * Run any migrations the installed schema version hasn't seen yet.
 *
 * Hooked late on `init` so the CPT (priority 10) and its registered meta
 * (priority 11) both exist before a migration touches pet records.
 */
function petsync_maybe_upgrade(): void {
	$installed = (int) get_option( 'petsync_db_version', 0 );

	if ( $installed >= PETSYNC_DB_VERSION ) {
		return;
	}

	foreach ( petsync_get_migrations() as $version => $callback ) {
		if ( $version > $installed && is_callable( $callback ) ) {
			call_user_func( $callback );
		}
	}

	update_option( 'petsync_db_version', PETSYNC_DB_VERSION, true );
}
add_action( 'init', 'petsync_maybe_upgrade', 20 );

/**
 * Migration 1 — legacy petstablished_* option and cron names to the neutral
 * petsync_* names (pre-multi-provider cleanup).
 *
 * Idempotent: returns early unless the old settings exist and the new ones
 * don't, then removes the old rows.
 */
function petsync_migrate_1_option_names(): void {
	if ( get_option( 'petsync_settings' ) !== false || get_option( 'petstablished_sync_settings' ) === false ) {
		return;
	}

	$map = array(
		'petstablished_sync_settings'   => 'petsync_settings',
		'petstablished_last_sync'       => 'petsync_last_sync',
		'petstablished_last_sync_stats' => 'petsync_last_sync_stats',
		'petstablished_sync_log'        => 'petsync_sync_log',
	);

	foreach ( $map as $old => $new ) {
		$value = get_option( $old, null );
		if ( null !== $value ) {
			add_option( $new, $value, '', 'petsync_sync_log' === $new ? false : true );
			delete_option( $old );
		}
	}

	// Move the scheduled sync to the renamed hook.
	wp_clear_scheduled_hook( 'petstablished_scheduled_sync' );
	$settings = Petsync_Admin::get_settings();
	Petsync_Admin::reschedule_cron( (bool) $settings['auto_sync'], $settings['sync_interval'] );
}

/**
 * Migration 2 — stamp the provider slug onto pets imported before the sync
 * became provider-aware.
 *
 * Those pets carry `_pet_ps_id` but no `_pet_provider`. The sync now matches on
 * the pair, so without this backfill it would fail to recognise them and
 * re-import every pet as a duplicate. Everything imported before this change
 * came from Petstablished, so that is the correct slug for all of them.
 *
 * Scoped to pets that actually carry a `_pet_ps_id`: that meta is the evidence a
 * pet was imported at all. Pets authored by hand in the editor have no provider
 * and must not be labelled with one, or a later sync would treat them as records
 * the API had forgotten.
 *
 * Idempotent: only selects pets missing the key, and no-ops on a fresh install.
 */
function petsync_migrate_2_provider_meta(): void {
	$pet_ids = get_posts(
		array(
			'post_type'        => 'vcps_pet',
			'post_status'      => 'any',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'suppress_filters' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-time migration, not a request-path query.
			'meta_query'       => array(
				'relation' => 'AND',
				array(
					'key'     => '_pet_provider',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_pet_ps_id',
					'compare' => 'EXISTS',
				),
			),
		)
	);

	foreach ( $pet_ids as $pet_id ) {
		update_post_meta( $pet_id, '_pet_provider', Petsync_Sync::PROVIDER );
	}
}

/**
 * Migration 3 — give hand-created pets a status so they reach the archive.
 *
 * The listing grid filters on the `available` status term. New pets now get one
 * automatically, but any created before that have none, so they render on their
 * own page and never appear on the archive — silently missing rather than
 * visibly broken.
 *
 * Scoped to pets with no provider. A pet imported from a platform gets its
 * status from the API on the next sync, and guessing on its behalf could
 * contradict the platform. Pets that already carry any status are untouched, so
 * nothing is relabelled.
 */
function petsync_migrate_3_default_status(): void {
	$taxonomies = \Petsync\Core\Config::get_item( 'taxonomies', 'taxonomies', array() );
	$default    = $taxonomies['pet_status']['default_term'] ?? null;

	if ( ! $default ) {
		return;
	}

	$pet_ids = get_posts(
		array(
			'post_type'        => 'vcps_pet',
			'post_status'      => 'any',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'suppress_filters' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-time migration, not a request-path query.
			'meta_query'       => array(
				array(
					'key'     => '_pet_provider',
					'compare' => 'NOT EXISTS',
				),
			),
		)
	);

	foreach ( $pet_ids as $pet_id ) {
		$existing = wp_get_object_terms( $pet_id, 'pet_status', array( 'fields' => 'ids' ) );

		if ( is_wp_error( $existing ) || ! empty( $existing ) ) {
			continue;
		}

		wp_set_object_terms( $pet_id, $default, 'pet_status' );
	}
}

/**
 * Migration 4 — carry Site Editor customizations across the plugin's renames.
 *
 * The Site Editor stores a customized plugin template as a wp_template or
 * wp_template_part post filed under a `wp_theme` term named after the PLUGIN,
 * not the active theme. That term name is a storage key, so each time this
 * plugin was renamed the lookup started asking for a name nothing was filed
 * under. The customizations were never deleted — they became unreachable, and
 * the front end quietly fell back to the bundled template file. Silent, and it
 * reads as "the design reverted" rather than as an error.
 *
 * This happened across two renames (vcpahumane-pet-sync -> shelter-pet-sync ->
 * shelter-pets) with no migration to carry the term along. An install can be
 * upgrading across both at once, so every legacy name is checked, oldest first.
 *
 * Two paths, because a term name is unique within the taxonomy:
 *
 *   - No current term yet: rename the legacy one in place. Cheapest, and it
 *     preserves every object relationship untouched.
 *   - A current term already exists: move the posts onto it and drop the empty
 *     legacy term. This is the partially-migrated case — someone customized a
 *     template after the rename, so both terms hold real work and neither can
 *     simply be discarded.
 *
 * Idempotent: the legacy term is gone afterwards, so a second run finds nothing.
 */
function petsync_migrate_4_template_namespace(): void {
	if ( ! taxonomy_exists( 'wp_theme' ) ) {
		return;
	}

	foreach ( Petsync_Templates::LEGACY_NAMESPACES as $legacy ) {
		$legacy_term = get_term_by( 'name', $legacy, 'wp_theme' );

		if ( ! $legacy_term instanceof WP_Term ) {
			continue;
		}

		$current = get_term_by( 'name', Petsync_Templates::THEME_NAMESPACE, 'wp_theme' );

		if ( ! $current instanceof WP_Term ) {
			wp_update_term(
				$legacy_term->term_id,
				'wp_theme',
				array(
					'name' => Petsync_Templates::THEME_NAMESPACE,
					'slug' => Petsync_Templates::THEME_NAMESPACE,
				)
			);
			continue;
		}

		// Everything filed under a term named after this plugin belongs to this
		// plugin, including customizations of templates no longer shipped —
		// which are exactly the ones a shelter would be upset to lose. Move the
		// term's whole contents rather than filtering to current slugs.
		$orphaned = get_posts(
			array(
				'post_type'        => array( 'wp_template', 'wp_template_part' ),
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => false,
				'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one-time migration, not a request-path query.
					array(
						'taxonomy'         => 'wp_theme',
						'field'            => 'term_id',
						'terms'            => $legacy_term->term_id,
						'include_children' => false,
					),
				),
			)
		);

		foreach ( $orphaned as $post_id ) {
			wp_set_object_terms( $post_id, array( $current->term_id ), 'wp_theme', false );
		}

		wp_delete_term( $legacy_term->term_id, 'wp_theme' );
	}
}
