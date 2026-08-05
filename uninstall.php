<?php
/**
 * Uninstall handler.
 *
 * Runs when the plugin is deleted from the Plugins screen. Cleanup is
 * two-tier:
 *
 * Always removed (ephemeral state, not data):
 *  - the scheduled sync cron event
 *  - plugin transients (including suffixed sync snapshots)
 *
 * Removed only when "Delete all data when this plugin is deleted" is
 * enabled in Pets → Sync Settings (default off, so delete + reinstall
 * loses nothing):
 *  - all `vcps_pet` posts (and their post meta / term relationships)
 *  - all terms in the plugin's `pet_*` taxonomies
 *  - Site Editor customizations of plugin templates and template parts
 *    (wp_template / wp_template_part posts filed under this plugin's
 *    wp_theme term), plus that term itself
 *  - settings and sync-state options
 *  - per-user favorites/comparison meta
 *
 * Pets are synced from the Petstablished API, so the destructive tier
 * is recoverable by reinstalling and running Sync Now — except Site
 * Editor template customizations and users' saved lists, which is why
 * it is opt-in.
 *
 * @package Shelter_Pets
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Clean up the current site. Returns whether the destructive tier ran,
 * so the caller can decide about the (network-wide) user meta.
 */
function vcps_uninstall_site(): bool {
	global $wpdb;

	// --- Ephemeral tier: always removed --------------------------------
	wp_clear_scheduled_hook( 'petsync_scheduled_sync' );
	wp_clear_scheduled_hook( 'petstablished_scheduled_sync' ); // legacy pre-1.0 hook name

	delete_transient( 'petsync_sync_in_progress' );
	delete_transient( 'petsync_sync_incomplete' );
	delete_transient( 'petsync_sync_pets' );

	// Suffixed transients (e.g. paged sync snapshots) — no wildcard API.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_petsync_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_petsync_' ) . '%'
		)
	);
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_petstablished_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_petstablished_' ) . '%'
		)
	);

	// The vcps_pet rewrite rules are stale once the plugin is gone,
	// whether or not data is kept.
	flush_rewrite_rules( false );

	// --- Destructive tier: opt-in via Sync Settings ---------------------
	$settings = get_option( 'petsync_settings', array() );
	if ( empty( $settings['delete_data_on_uninstall'] ) ) {
		return false;
	}

	// Pets. wp_delete_post() also removes post meta and term relationships.
	$pet_ids = get_posts(
		array(
			'post_type'      => 'vcps_pet',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	foreach ( $pet_ids as $pet_id ) {
		wp_delete_post( $pet_id, true );
	}

	// Taxonomy terms. The plugin isn't loaded during uninstall, so its
	// taxonomies are unregistered and get_terms()/wp_delete_term() would
	// refuse them. Stub-register each one just for this request.
	$taxonomies = array(
		'pet_status',
		'pet_animal',
		'pet_breed',
		'pet_age',
		'pet_sex',
		'pet_size',
		'pet_color',
		'pet_coat',
		'pet_attribute',
	);

	foreach ( $taxonomies as $taxonomy ) {
		register_taxonomy( $taxonomy, 'vcps_pet' );

		$term_ids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);

		if ( ! is_wp_error( $term_ids ) ) {
			foreach ( $term_ids as $term_id ) {
				wp_delete_term( $term_id, $taxonomy );
			}
		}

		unregister_taxonomy( $taxonomy );
	}

	// Site Editor customizations of plugin templates, saved under this
	// plugin's wp_theme term (see
	// Petsync_Templates::get_customized_template()); inert without
	// the plugin.
	//
	// Every name this plugin has filed templates under, not just the current
	// one — migration 4 normally consolidates them, but it only runs while the
	// plugin is active, so an install deleted before it ever loaded an admin
	// page still has customizations under a legacy term. Same reasoning as the
	// legacy option names below. The list is duplicated from
	// Petsync_Templates::LEGACY_NAMESPACES because the plugin is not loaded
	// during uninstall and the constant is unreachable here.
	$theme_names = array( 'shelter-pets', 'shelter-pet-sync', 'vcpahumane-pet-sync' );

	$template_ids = get_posts(
		array(
			'post_type'      => array( 'wp_template', 'wp_template_part' ),
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			array(
				'taxonomy' => 'wp_theme',
				'field'    => 'name',
				'terms'    => $theme_names,
			),
			),
		)
	);

	foreach ( $template_ids as $template_id ) {
		wp_delete_post( $template_id, true );
	}

	foreach ( $theme_names as $theme_name ) {
		$theme_term = get_term_by( 'name', $theme_name, 'wp_theme' );
		if ( $theme_term ) {
			wp_delete_term( $theme_term->term_id, 'wp_theme' );
		}
	}

	// Options (including the settings that held the opt-in itself).
	delete_option( 'petsync_settings' );
	delete_option( 'petsync_last_sync' );
	delete_option( 'petsync_last_sync_stats' );
	delete_option( 'petsync_sync_log' );
	// Schema version — dropped only on the full-delete path, so that the
	// preserve-by-default path keeps its stored data correctly versioned for
	// a later reinstall.
	delete_option( 'petsync_db_version' );
	// Legacy pre-1.0 option names, in case the rename migration never ran.
	delete_option( 'petstablished_sync_settings' );
	delete_option( 'petstablished_last_sync' );
	delete_option( 'petstablished_last_sync_stats' );
	delete_option( 'petstablished_sync_log' );

	return true;
}

$vcps_delete_user_meta = false;

if ( is_multisite() ) {
	$vcps_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $vcps_site_ids as $vcps_site_id ) {
		switch_to_blog( (int) $vcps_site_id );
		$vcps_delete_user_meta = vcps_uninstall_site() || $vcps_delete_user_meta;
		restore_current_blog();
	}
} else {
	$vcps_delete_user_meta = vcps_uninstall_site();
}

// User meta is network-wide (shared usermeta table) — delete once, and
// only if at least one site opted into data removal.
if ( $vcps_delete_user_meta ) {
	delete_metadata( 'user', 0, '_pet_favorites', '', true );
	delete_metadata( 'user', 0, '_pet_comparison', '', true );
}
