<?php
/**
 * The stored-data migration rail.
 *
 * Migrations run once, against real installs, and a mistake is not undoable.
 * Each must be idempotent and correctly scoped — over-reaching is the failure
 * that matters, because relabelling a pet nobody asked to relabel is worse
 * than leaving it alone.
 *
 * @package Shelter_Pets
 */

declare( strict_types = 1 );

namespace Petsync\Tests\Integration;

final class MigrationsTest extends PetTestCase {

	public function test_the_rail_runs_every_declared_migration(): void {
		$migrations = petsync_get_migrations();

		$this->assertSame( range( 1, PETSYNC_DB_VERSION ), array_keys( $migrations ), 'migrations must be contiguous from 1' );

		foreach ( $migrations as $version => $callback ) {
			$this->assertTrue( is_callable( $callback ), "migration {$version} is not callable" );
		}
	}

	// ── Migration 2: provider backfill ───────────────────────────────────────

	public function test_migration_2_stamps_pets_that_were_imported(): void {
		$imported = $this->make_manual_pet();
		update_post_meta( $imported, $this->prefix . 'ps_id', '4242' );

		petsync_migrate_2_provider_meta();

		$this->assertSame(
			\Petsync_Sync::PROVIDER,
			get_post_meta( $imported, $this->prefix . 'provider', true )
		);
	}

	/**
	 * A record ID is the evidence a pet was imported. Without one it was typed
	 * by hand, and labelling it with a provenance it never had would put it
	 * inside a sync's reach.
	 */
	public function test_migration_2_leaves_hand_entered_pets_alone(): void {
		$manual = $this->make_manual_pet();

		petsync_migrate_2_provider_meta();

		$this->assertSame( '', get_post_meta( $manual, $this->prefix . 'provider', true ) );
	}

	public function test_migration_2_is_idempotent(): void {
		$id = $this->make_manual_pet();
		update_post_meta( $id, $this->prefix . 'ps_id', '4242' );

		petsync_migrate_2_provider_meta();
		$first = get_post_meta( $id, $this->prefix . 'provider', true );

		petsync_migrate_2_provider_meta();

		$this->assertSame( $first, get_post_meta( $id, $this->prefix . 'provider', true ) );
		$this->assertCount( 1, get_post_meta( $id, $this->prefix . 'provider' ), 'must not accumulate duplicate meta rows' );
	}

	// ── Migration 3: default status backfill ─────────────────────────────────

	public function test_migration_3_gives_statusless_hand_entered_pets_a_status(): void {
		$id = $this->make_manual_pet();
		wp_set_object_terms( $id, array(), 'pet_status' );

		petsync_migrate_3_default_status();

		$this->assertSame(
			array( 'available' ),
			wp_get_object_terms( $id, 'pet_status', array( 'fields' => 'slugs' ) )
		);
	}

	public function test_migration_3_never_relabels_a_pet_that_has_a_status(): void {
		$id = $this->make_manual_pet();
		wp_set_object_terms( $id, 'adopted', 'pet_status' );

		petsync_migrate_3_default_status();

		$this->assertSame(
			array( 'adopted' ),
			wp_get_object_terms( $id, 'pet_status', array( 'fields' => 'slugs' ) ),
			'an existing status is the shelter’s statement about the animal'
		);
	}

	/**
	 * An imported pet takes its status from the provider on the next sync.
	 * Guessing on its behalf could contradict the platform.
	 */
	public function test_migration_3_skips_imported_pets(): void {
		$id = $this->make_synced_pet();
		wp_set_object_terms( $id, array(), 'pet_status' );

		petsync_migrate_3_default_status();

		$this->assertSame( array(), wp_get_object_terms( $id, 'pet_status', array( 'fields' => 'slugs' ) ) );
	}

	public function test_migration_3_is_idempotent(): void {
		$id = $this->make_manual_pet();
		wp_set_object_terms( $id, array(), 'pet_status' );

		petsync_migrate_3_default_status();
		petsync_migrate_3_default_status();

		$this->assertSame(
			array( 'available' ),
			wp_get_object_terms( $id, 'pet_status', array( 'fields' => 'slugs' ) )
		);
	}

	public function test_migrations_no_op_on_a_fresh_install(): void {
		// No pets at all — every migration must run without error, because a
		// fresh install starts at version 0 and runs the whole list.
		foreach ( petsync_get_migrations() as $version => $callback ) {
			$callback();
			$this->assertTrue( true, "migration {$version} completed on an empty install" );
		}
	}

	// ── Migration 4: template namespace ──────────────────────────────────────

	/**
	 * File a customized template under a given wp_theme term, the way the Site
	 * Editor does.
	 *
	 * @param string $theme_name wp_theme term name.
	 * @param string $slug       Template slug.
	 * @return int Post ID.
	 */
	private function customize_template_under( string $theme_name, string $slug = 'single-vcps_pet' ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'wp_template',
				'post_name'    => $slug,
				'post_title'   => $slug,
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>customized</p><!-- /wp:paragraph -->',
			)
		);

		wp_set_object_terms( $post_id, $theme_name, 'wp_theme' );

		return (int) $post_id;
	}

	/**
	 * @param int $post_id Template post ID.
	 * @return string[] wp_theme term names on the post.
	 */
	private function theme_terms_of( int $post_id ): array {
		return wp_get_object_terms( $post_id, 'wp_theme', array( 'fields' => 'names' ) );
	}

	public function test_migration_4_carries_a_customization_across_a_rename(): void {
		$customized = $this->customize_template_under( 'shelter-pet-sync' );

		petsync_migrate_4_template_namespace();

		$this->assertSame(
			array( \Petsync_Templates::THEME_NAMESPACE ),
			$this->theme_terms_of( $customized ),
			'a customization filed under the old name must end up under the current one'
		);
		$this->assertFalse(
			get_term_by( 'name', 'shelter-pet-sync', 'wp_theme' ),
			'the legacy term should not survive'
		);
	}

	public function test_migration_4_handles_the_oldest_namespace_too(): void {
		$customized = $this->customize_template_under( 'vcpahumane-pet-sync' );

		petsync_migrate_4_template_namespace();

		$this->assertSame(
			array( \Petsync_Templates::THEME_NAMESPACE ),
			$this->theme_terms_of( $customized )
		);
	}

	/**
	 * The partially-migrated case: someone customized a template after the
	 * rename, so both terms hold real work. Neither side may be discarded.
	 */
	public function test_migration_4_merges_when_both_terms_hold_work(): void {
		$old = $this->customize_template_under( 'shelter-pet-sync', 'single-vcps_pet' );
		$new = $this->customize_template_under( \Petsync_Templates::THEME_NAMESPACE, 'archive-vcps_pet' );

		petsync_migrate_4_template_namespace();

		$this->assertSame( array( \Petsync_Templates::THEME_NAMESPACE ), $this->theme_terms_of( $old ) );
		$this->assertSame( array( \Petsync_Templates::THEME_NAMESPACE ), $this->theme_terms_of( $new ) );
		$this->assertNotNull( get_post( $old ), 'the older customization must survive the merge' );
		$this->assertNotNull( get_post( $new ), 'the newer customization must survive the merge' );
		$this->assertFalse( get_term_by( 'name', 'shelter-pet-sync', 'wp_theme' ) );
	}

	/**
	 * An install can be upgrading across BOTH renames at once — it may have sat
	 * on a version that predates them all.
	 */
	public function test_migration_4_consolidates_several_legacy_namespaces(): void {
		$oldest = $this->customize_template_under( 'vcpahumane-pet-sync', 'single-vcps_pet' );
		$older  = $this->customize_template_under( 'shelter-pet-sync', 'archive-vcps_pet' );

		petsync_migrate_4_template_namespace();

		$this->assertSame( array( \Petsync_Templates::THEME_NAMESPACE ), $this->theme_terms_of( $oldest ) );
		$this->assertSame( array( \Petsync_Templates::THEME_NAMESPACE ), $this->theme_terms_of( $older ) );

		foreach ( \Petsync_Templates::LEGACY_NAMESPACES as $legacy ) {
			$this->assertFalse( get_term_by( 'name', $legacy, 'wp_theme' ), "$legacy should be gone" );
		}
	}

	/**
	 * A customization of a template the plugin no longer ships is exactly the
	 * one a shelter would be upset to lose, so the migration moves a term's
	 * whole contents rather than filtering to slugs it recognises.
	 */
	public function test_migration_4_carries_templates_the_plugin_no_longer_ships(): void {
		$retired = $this->customize_template_under( 'shelter-pet-sync', 'some-retired-template' );

		petsync_migrate_4_template_namespace();

		$this->assertSame( array( \Petsync_Templates::THEME_NAMESPACE ), $this->theme_terms_of( $retired ) );
	}

	public function test_migration_4_is_idempotent(): void {
		$customized = $this->customize_template_under( 'shelter-pet-sync' );

		petsync_migrate_4_template_namespace();
		petsync_migrate_4_template_namespace();

		$this->assertSame( array( \Petsync_Templates::THEME_NAMESPACE ), $this->theme_terms_of( $customized ) );
	}

	public function test_migration_4_leaves_an_unrelated_theme_alone(): void {
		$theme_template = $this->customize_template_under( 'twentytwentyfive' );

		petsync_migrate_4_template_namespace();

		$this->assertSame(
			array( 'twentytwentyfive' ),
			$this->theme_terms_of( $theme_template ),
			'a real theme\'s own customizations are not ours to move'
		);
	}

	/**
	 * The migration exists because the lookup and the storage key drifted
	 * apart. Pinning them to the same constant is the fix; this asserts they
	 * cannot drift again.
	 */
	public function test_the_lookup_and_the_migration_agree_on_the_namespace(): void {
		$customized = $this->customize_template_under( 'shelter-pet-sync' );

		petsync_migrate_4_template_namespace();

		$found = get_posts(
			array(
				'post_type'      => 'wp_template',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- deliberately mirrors the front-end lookup this test is pinning.
				'tax_query'      => array(
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => \Petsync_Templates::THEME_NAMESPACE,
					),
				),
			)
		);

		$this->assertContains(
			$customized,
			$found,
			'after migrating, the template must be findable by the same query the front end uses'
		);
	}
}
