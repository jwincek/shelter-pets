<?php
/**
 * Config-contract validator for Shelter Pets.
 *
 * Static analysis — no WordPress, no Composer dependencies. It cross-checks
 * the JSON config (config/*.json) against the PHP that consumes it, catching
 * the config-drift bug classes a config-driven plugin is most exposed to:
 *
 *   1. config-path  — every Config::get_path()/get_item() string literal
 *                     resolves to a real key (would have caught the
 *                     entities.pet -> entities.vcps_pet rename regression).
 *   2. computed     — entities.json `computed` keys <-> Pet_Hydrator::
 *                     compute_field() match arms (a declared computed field
 *                     with no arm silently hydrates to null).
 *   3. profiles     — every name in summary/grid/comparison_fields resolves
 *                     to a real base/taxonomy/field/api_field/computed field.
 *   4. abilities    — every abilities.json ability has a resolvable callback
 *                     and a known permission; no dead callback mappings.
 *   5. taxonomies   — entity taxonomies + attribute_map reference real
 *                     taxonomies / api_field keys.
 *   6. interactivity (heuristic) — actions.X / callbacks.X referenced in
 *                     block render.php are defined in a view.js / store.
 *   7. hash-coverage — every literal $data['key'] the sync reads is in
 *                     get_consumed_api_keys(), so the change-detection hash
 *                     can't silently miss a field (stale-display risk).
 *
 * Usage:
 *   php bin/validate-config.php [--format=human|json]
 *
 * Exit code: 1 if any ERROR-level issue is found (warnings do not fail CI).
 *
 * @package Shelter_Pets
 */

declare( strict_types = 1 );

$root   = dirname( __DIR__ );
$format = 'human';
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( str_starts_with( $arg, '--format=' ) ) {
		$format = substr( $arg, strlen( '--format=' ) );
	}
}

/** Collected issues: each ['level' => 'error'|'warning', 'check' => string, 'message' => string]. */
$issues = [];

/** Record an issue. */
$add = static function ( string $level, string $check, string $message ) use ( &$issues ): void {
	$issues[] = compact( 'level', 'check', 'message' );
};

/** Load + decode a config JSON file (no $ref resolution — only top-level keys are checked). */
$load_json = static function ( string $rel ) use ( $root, $add ): ?array {
	$path = $root . '/' . $rel;
	if ( ! is_file( $path ) ) {
		$add( 'error', 'config', "Missing config file: $rel" );
		return null;
	}
	$data = json_decode( (string) file_get_contents( $path ), true );
	if ( ! is_array( $data ) ) {
		$add( 'error', 'config', "Invalid JSON in $rel: " . json_last_error_msg() );
		return null;
	}
	return $data;
};

/** Read a PHP/JS source file as text. */
$read = static function ( string $rel ) use ( $root ): string {
	$path = $root . '/' . $rel;
	return is_file( $path ) ? (string) file_get_contents( $path ) : '';
};

// ── Load configs ────────────────────────────────────────────────────────────
$entities_json   = $load_json( 'config/entities.json' );
$abilities_json  = $load_json( 'config/abilities.json' );
$taxonomies_json = $load_json( 'config/taxonomies.json' );
$posttypes_json  = $load_json( 'config/post-types.json' );

$entity_key = 'vcps_pet';
$entity     = $entities_json['entities'][ $entity_key ] ?? null;
if ( null === $entity ) {
	$add( 'error', 'config', "entities.json has no '$entity_key' entity (key renamed?)." );
}

// ── Check 1: Config::get_path()/get_item() string literals resolve ───────────
$php_files = [];
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	if ( $f->getExtension() === 'php' ) {
		$php_files[] = $f->getPathname();
	}
}
// The main plugin file, identified by its "Plugin Name:" header.
//
// Not hardcoded (it once pointed at the pre-rename petstablished-sync.php and
// was silently skipped by every check) and not derived from the directory name
// either — the checkout directory is not reliably the slug, since CI clones
// into a folder named after the repository. Only the header is authoritative.
$main_file = null;
foreach ( glob( $root . '/*.php' ) ?: [] as $candidate ) {
	$head = (string) file_get_contents( $candidate, false, null, 0, 8192 );
	if ( preg_match( '/^[ \t]*\*?[ \t]*Plugin Name:[ \t]*\S/mi', $head ) ) {
		$main_file = $candidate;
		break;
	}
}

if ( null === $main_file ) {
	$add( 'error', 'config', 'No PHP file at the repo root carries a "Plugin Name:" header — checks below will not cover the main plugin file.' );
} else {
	$php_files[] = $main_file;

	// WordPress.org requires the text domain to equal the slug, and the slug is
	// taken from the main file's name. A mismatch here is what makes
	// translations silently fail to load after a rename.
	$main_head   = (string) file_get_contents( $main_file, false, null, 0, 8192 );
	$slug        = basename( $main_file, '.php' );
	$text_domain = preg_match( '/^[ \t]*\*?[ \t]*Text Domain:[ \t]*(\S+)/mi', $main_head, $td ) ? $td[1] : null;

	if ( null === $text_domain ) {
		$add( 'error', 'config', 'No "Text Domain:" header in ' . basename( $main_file ) . '.' );
	} elseif ( $text_domain !== $slug ) {
		$add( 'error', 'config', "Text Domain '$text_domain' does not match the plugin slug '$slug' — WordPress.org requires them to be identical, and translations will not load." );
	}
}

foreach ( $php_files as $path ) {
	$src = (string) file_get_contents( $path );
	$rel = str_replace( $root . '/', '', $path );

	// Config::get_item( 'name', 'key' )
	if ( preg_match_all( "/Config::get_item\(\s*'([^']+)'\s*,\s*'([^']+)'/", $src, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $hit ) {
			$cfg = $load_json( "config/{$hit[1]}.json" );
			if ( is_array( $cfg ) && ! array_key_exists( $hit[2], $cfg ) ) {
				$add( 'error', 'config-path', "$rel: Config::get_item('{$hit[1]}', '{$hit[2]}') — key '{$hit[2]}' not in {$hit[1]}.json" );
			}
		}
	}

	// Config::get_path( 'name', 'dot.path' )
	if ( preg_match_all( "/Config::get_path\(\s*'([^']+)'\s*,\s*'([^']+)'/", $src, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $hit ) {
			$cfg = $load_json( "config/{$hit[1]}.json" );
			if ( ! is_array( $cfg ) ) {
				continue;
			}
			$node    = $cfg;
			$ok      = true;
			foreach ( explode( '.', $hit[2] ) as $seg ) {
				if ( ! is_array( $node ) || ! array_key_exists( $seg, $node ) ) {
					$ok = false;
					break;
				}
				$node = $node[ $seg ];
			}
			if ( ! $ok ) {
				$add( 'error', 'config-path', "$rel: Config::get_path('{$hit[1]}', '{$hit[2]}') — path does not resolve in {$hit[1]}.json" );
			}
		}
	}
}

// ── Build the valid field-name universe for this entity ──────────────────────
$base_fields    = [ 'id', 'name' ]; // Always emitted by Pet_Hydrator::hydrate().
$tax_keys       = array_keys( $entity['taxonomies'] ?? [] );
$field_keys     = array_keys( $entity['fields'] ?? [] );
$api_field_keys = array_keys( $entity['api_fields'] ?? [] );
$computed_keys  = array_keys( $entity['computed'] ?? [] );
$valid_fields   = array_merge( $base_fields, $tax_keys, $field_keys, $api_field_keys, $computed_keys );

// ── Check 2: computed dispatch coverage (entities.computed <-> compute_field) ─
$hydrator = $read( 'includes/core/class-pet-hydrator.php' );
$match_arms = [];
if ( preg_match( '/function compute_field\(.*?match\s*\(\s*\$name\s*\)\s*\{(.*?)\n\t\t\};/s', $hydrator, $mm ) ) {
	preg_match_all( "/'([a-z0-9_]+)'\s*=>/i", $mm[1], $am );
	$match_arms = $am[1];
} else {
	$add( 'warning', 'computed', 'Could not locate Pet_Hydrator::compute_field() match block to verify dispatch.' );
}
foreach ( $computed_keys as $ck ) {
	if ( $match_arms && ! in_array( $ck, $match_arms, true ) ) {
		$add( 'error', 'computed', "computed field '$ck' is declared in entities.json but has no arm in compute_field() — it will hydrate to null." );
	}
}
foreach ( $match_arms as $arm ) {
	if ( ! in_array( $arm, $computed_keys, true ) ) {
		$add( 'warning', 'computed', "compute_field() has a match arm '$arm' with no entities.json `computed` declaration — dead/unreachable via hydration." );
	}
}

// ── Check 3: profile field-list integrity ────────────────────────────────────
foreach ( [ 'summary_fields', 'grid_fields', 'comparison_fields' ] as $list ) {
	foreach ( (array) ( $entity[ $list ] ?? [] ) as $name ) {
		if ( ! in_array( $name, $valid_fields, true ) ) {
			$add( 'error', 'profiles', "$list references '$name', which is not a declared base/taxonomy/field/api_field/computed field." );
		}
	}
}

// ── Check 4: ability callbacks + permissions ─────────────────────────────────
$ability_names = array_keys( $abilities_json['abilities'] ?? [] );
$provider      = $read( 'includes/abilities/class-provider.php' );

// Parse the resolve_callback() $explicit_map (name => FQN).
$explicit_map = [];
if ( preg_match( '/\$explicit_map\s*=\s*\[(.*?)\];/s', $provider, $em ) ) {
	if ( preg_match_all( "/'([^']+)'\s*=>\s*'([^']+)'/", $em[1], $pairs, PREG_SET_ORDER ) ) {
		foreach ( $pairs as $p ) {
			$explicit_map[ $p[1] ] = str_replace( '\\\\', '\\', $p[2] );
		}
	}
}

// Collect every function FQN defined in the ability files.
$defined_fns = [];
foreach ( glob( $root . '/includes/abilities/*.php' ) as $af ) {
	$src = (string) file_get_contents( $af );
	if ( ! preg_match( '/namespace\s+([^;]+);/', $src, $nm ) ) {
		continue;
	}
	$ns = trim( $nm[1] );
	if ( preg_match_all( '/^\s*function\s+(\w+)\s*\(/m', $src, $fm ) ) {
		foreach ( $fm[1] as $fn ) {
			$defined_fns[ $ns . '\\' . $fn ] = true;
		}
	}
}

$known_perms = [ 'public', 'logged_in', 'public_with_session', 'admin', 'manage_options', 'edit_posts' ];
foreach ( $ability_names as $name ) {
	if ( ! isset( $explicit_map[ $name ] ) ) {
		$add( 'error', 'abilities', "ability '$name' has no callback mapping in Provider::resolve_callback() \$explicit_map — it will not register." );
	} elseif ( ! isset( $defined_fns[ $explicit_map[ $name ] ] ) ) {
		$add( 'error', 'abilities', "ability '$name' maps to {$explicit_map[$name]}() which is not defined in includes/abilities/." );
	}
	$perm = $abilities_json['abilities'][ $name ]['permission'] ?? 'public';
	if ( is_string( $perm ) && ! in_array( $perm, $known_perms, true ) ) {
		$add( 'warning', 'abilities', "ability '$name' permission '$perm' is not a known keyword — falls through to current_user_can('$perm')." );
	}
}
// Dead mappings: explicit_map entries with no matching ability.
foreach ( array_keys( $explicit_map ) as $mapped ) {
	if ( ! in_array( $mapped, $ability_names, true ) ) {
		$add( 'warning', 'abilities', "Provider \$explicit_map maps '$mapped' but no such ability exists in abilities.json — dead mapping." );
	}
}

// ── Check 5: taxonomy + attribute_map consistency ────────────────────────────
$registered_tax = array_keys( $taxonomies_json['taxonomies'] ?? [] );
foreach ( (array) ( $entity['taxonomies'] ?? [] ) as $key => $cfg ) {
	$tax = $cfg['taxonomy'] ?? null;
	if ( $tax && ! in_array( $tax, $registered_tax, true ) ) {
		$add( 'error', 'taxonomies', "entity taxonomy '$key' => '$tax' is not registered in taxonomies.json." );
	}
}
$attr_tax = $entity['attribute_taxonomy'] ?? null;
if ( $attr_tax && ! in_array( $attr_tax, $registered_tax, true ) ) {
	$add( 'error', 'taxonomies', "attribute_taxonomy '$attr_tax' is not registered in taxonomies.json." );
}

// taxonomy_source_map drives every single-value term the sync writes. An
// unregistered target means wp_set_object_terms() silently writes nothing.
$tax_source_map = (array) ( $entity['taxonomy_source_map'] ?? [] );
if ( empty( $tax_source_map ) ) {
	$add( 'error', 'taxonomies', 'entities.json has no taxonomy_source_map — the sync would assign no single-value taxonomy terms at all.' );
}
foreach ( $tax_source_map as $src_key => $tax ) {
	if ( ! in_array( $tax, $registered_tax, true ) ) {
		$add( 'error', 'taxonomies', "taxonomy_source_map '$src_key' => '$tax' is not registered in taxonomies.json." );
	}
}
$api_keys = [];
foreach ( (array) ( $entity['api_fields'] ?? [] ) as $cfg ) {
	if ( isset( $cfg['api_key'] ) ) {
		$api_keys[] = $cfg['api_key'];
	}
}
foreach ( array_keys( (array) ( $entity['attribute_map'] ?? [] ) ) as $src_key ) {
	if ( ! in_array( $src_key, $api_keys, true ) ) {
		$add( 'warning', 'taxonomies', "attribute_map source key '$src_key' is not an api_field api_key — it can only match raw API data." );
	}
}

// ── Check 5b: editable_fields are real, grouped, and readable back ───────────
// Manual entry writes post meta that Pet_Hydrator reads in preference to the
// API snapshot. A field declared editable but missing from api_fields would be
// written by the editor and never read back — a silent data black hole.
$editable_fields = (array) ( $entity['editable_fields'] ?? [] );
$editable_groups = (array) ( $entity['editable_field_groups'] ?? [] );
$api_field_names = array_keys( (array) ( $entity['api_fields'] ?? [] ) );
// An editable field may be backed by a provider mapping (api_fields) or by
// storage in its own right (fields, e.g. gallery_ids). Either is readable back;
// neither means the editor writes into a void.
$entity_field_names = array_keys( (array) ( $entity['fields'] ?? [] ) );
$backed_names       = array_merge( $api_field_names, $entity_field_names );

foreach ( $editable_fields as $field => $cfg ) {
	if ( ! in_array( $field, $backed_names, true ) ) {
		$add( 'error', 'editable-fields', "editable_fields '$field' is declared in neither api_fields nor fields — the editor would write meta the hydrator never reads back." );
	}

	$group = $cfg['group'] ?? null;
	if ( null === $group || ! array_key_exists( $group, $editable_groups ) ) {
		$add( 'error', 'editable-fields', "editable_fields '$field' is in group '" . ( $group ?? '(none)' ) . "', which is not declared in editable_field_groups — its panel would never render." );
	}

	$control = $cfg['control'] ?? 'text';
	if ( ! in_array( $control, [ 'text', 'url', 'textarea', 'tristate', 'media' ], true ) ) {
		$add( 'error', 'editable-fields', "editable_fields '$field' uses control '$control', which assets/js/pet-fields.js does not implement (it would silently fall back to a text input)." );
	}

	// A tristate control on a non-tristate field (or the reverse) round-trips
	// badly: the hydrator casts by the api_fields type, not the control.
	$declared_type = $entity['api_fields'][ $field ]['type'] ?? ( $entity['fields'][ $field ]['type'] ?? null );
	if ( null !== $declared_type ) {
		$is_tristate_control = 'tristate' === $control;
		$is_tristate_type    = 'tristate' === $declared_type;
		if ( $is_tristate_control !== $is_tristate_type ) {
			$add( 'warning', 'editable-fields', "editable_fields '$field' uses control '$control' but api_fields declares type '$declared_type' — the hydrator casts by type, so the two should agree." );
		}
	}
}

// A group with no fields renders an empty panel.
foreach ( array_keys( $editable_groups ) as $group_slug ) {
	$used = array_filter( $editable_fields, static fn( $c ) => ( $c['group'] ?? null ) === $group_slug );
	if ( ! $used ) {
		$add( 'warning', 'editable-fields', "editable_field_groups '$group_slug' has no fields — it would render an empty panel." );
	}
}

// ── Check 6: interactivity state/action/callback references resolve ──────────
//
// Failures in this layer are silent: a directive naming something no store
// defines throws nothing, logs nothing, and fails no test. It simply does not
// work in the browser. Nothing else in CI can see that, which is why this check
// earns its complexity.
//
// The previous version of this check was namespace-blind — it pooled every
// store's method names into one flat set, so a reference in petsync/slider was
// satisfied by a definition in petsync/grid. It also only looked at `actions.`
// and `callbacks.`, never `state.`, which is where derived getters live. Both
// gaps hid a real bug (a hero-image crossfade that never fired), so the check is
// now resolved per namespace and covers all three kinds.

/** Blank out comments, preserving byte offsets so line numbers stay correct. */
$ia_strip = static function ( string $s ): string {
	$blank = static fn( array $m ): string => preg_replace( '/[^\n]/', ' ', $m[0] );
	$s     = preg_replace_callback( '#/\*.*?\*/#s', $blank, $s );
	$s     = preg_replace_callback( '#^\s*//[^\n]*#m', $blank, $s );
	$s     = preg_replace_callback( '#<!--.*?-->#s', $blank, $s );
	return $s;
};

/** $i points at '{'; return the offset just past its match, string-aware. */
$ia_brace = static function ( string $s, int $i ): int {
	$d = 0; $n = strlen( $s ); $in = false; $q = '';
	while ( $i < $n ) {
		$c = $s[ $i ];
		if ( $in ) {
			if ( '\\' === $c ) { $i += 2; continue; }
			if ( $c === $q ) { $in = false; }
		} elseif ( '"' === $c || "'" === $c || '`' === $c ) {
			$in = true; $q = $c;
		} elseif ( '{' === $c ) { ++$d;
		} elseif ( '}' === $c ) { if ( 0 === --$d ) { return $i + 1; } }
		++$i;
	}
	return $n;
};

/** Top-level keys of an object-literal body (outer braces already removed). */
$ia_keys = static function ( string $body ): array {
	$keys = []; $i = 0; $n = strlen( $body ); $depth = 0;
	$in = false; $q = ''; $item_start = true;
	while ( $i < $n ) {
		$c = $body[ $i ];
		if ( $in ) {
			if ( '\\' === $c ) { $i += 2; continue; }
			if ( $c === $q ) { $in = false; }
			++$i; continue;
		}
		if ( '"' === $c || "'" === $c || '`' === $c ) { $in = true; $q = $c; ++$i; continue; }
		if ( '{' === $c || '[' === $c || '(' === $c ) { ++$depth; ++$i; continue; }
		if ( '}' === $c || ']' === $c || ')' === $c ) { --$depth; ++$i; continue; }
		if ( 0 === $depth ) {
			if ( $item_start && preg_match( '/^\s*(?:(?:async|get|set)\s+)*\*?\s*([A-Za-z_$][\w$]*)\s*(?=[:(])/', substr( $body, $i ), $m ) ) {
				$keys[]     = $m[1];
				$i         += strlen( $m[0] );
				$item_start = false;
				continue;
			}
			if ( ',' === $c ) { $item_start = true; }
		}
		++$i;
	}
	return $keys;
};

// What each namespace defines, from JS stores…
$ia_defined = [];
foreach ( array_merge(
	glob( $root . '/assets/js/*.js' ),
	glob( $root . '/assets/js/interactivity/*.js' ),
	glob( $root . '/blocks/*/view.js' )
) as $jf ) {
	$src = $ia_strip( (string) file_get_contents( $jf ) );
	if ( ! preg_match_all( '/store\(\s*[\'"]([\w\/-]+)[\'"]\s*,\s*\{/', $src, $sm, PREG_OFFSET_CAPTURE ) ) {
		continue;
	}
	foreach ( $sm[0] as $k => $whole ) {
		$ns   = $sm[1][ $k ][0];
		$ob   = strpos( $src, '{', $whole[1] + strlen( $whole[0] ) - 1 );
		$body = substr( $src, $ob + 1, $ia_brace( $src, $ob ) - $ob - 2 );
		foreach ( [ 'state', 'actions', 'callbacks' ] as $kind ) {
			if ( ! preg_match( '/(^|[,{\s])' . $kind . '\s*:\s*\{/', $body, $km, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			$sb  = strpos( $body, '{', $km[0][1] );
			$sub = substr( $body, $sb + 1, $ia_brace( $body, $sb ) - $sb - 2 );
			foreach ( $ia_keys( $sub ) as $key ) {
				$ia_defined[ $ns ][ $kind ][ $key ] = true;
			}
		}
	}
}
// …and from state seeded server-side.
foreach ( array_merge(
	glob( $root . '/includes/*.php' ),
	glob( $root . '/includes/**/*.php' ),
	glob( $root . '/blocks/*/render.php' )
) as $pf ) {
	$src = (string) file_get_contents( $pf );
	if ( ! preg_match_all( '/wp_interactivity_state\(\s*[\'"]([\w\/-]+)[\'"]\s*,\s*(?:\[|array\()/', $src, $wm, PREG_OFFSET_CAPTURE ) ) {
		continue;
	}
	foreach ( $wm[0] as $k => $whole ) {
		$ns = $wm[1][ $k ][0];
		$st = $whole[1] + strlen( $whole[0] ) - 1;
		$d  = 0; $i = $st; $n = strlen( $src );
		while ( $i < $n ) {
			if ( '[' === $src[ $i ] || '(' === $src[ $i ] ) { ++$d; } elseif ( ']' === $src[ $i ] || ')' === $src[ $i ] ) { if ( 0 === --$d ) { break; } }
			++$i;
		}
		if ( preg_match_all( '/[\'"](\w+)[\'"]\s*=>/', substr( $src, $st, $i - $st ), $km ) ) {
			foreach ( $km[1] as $key ) {
				$ia_defined[ $ns ]['state'][ $key ] = true;
			}
		}
	}
}

// Which namespace is in scope where. Covers the three declaration forms used
// here: literal attribute, attrs-array entry, and array assignment.
$ns_re    = '/data-wp-interactive[\'"]?\]?\s*(?:=>|=)\s*[\'"]([\w\/-]+)[\'"]/';
$ia_files = array_merge(
	glob( $root . '/blocks/*/render.php' ),
	glob( $root . '/blocks/*/partials/*.php' ),
	glob( $root . '/blocks/*/template-default.php' ),
	glob( $root . '/parts/*.html' )
);
// A partial declares no namespace of its own; it inherits the one in scope at
// its include site.
$ia_inherited = [];
foreach ( $ia_files as $pf ) {
	$src = $ia_strip( (string) file_get_contents( $pf ) );
	if ( ! preg_match_all( '/(?:include|require)(?:_once)?\s*\(?\s*__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]/', $src, $im, PREG_OFFSET_CAPTURE ) ) {
		continue;
	}
	preg_match_all( $ns_re, $src, $dm, PREG_OFFSET_CAPTURE );
	foreach ( $im[1] as $k => $target ) {
		$prev = null;
		foreach ( $dm[1] as $d ) {
			if ( $d[1] < $im[0][ $k ][1] ) { $prev = $d[0]; }
		}
		if ( null !== $prev ) {
			$ia_inherited[ realpath( dirname( $pf ) . '/' . ltrim( $target[0], '/' ) ) ?: '' ] = $prev;
		}
	}
}

foreach ( $ia_files as $pf ) {
	$src  = $ia_strip( (string) file_get_contents( $pf ) );
	$name = str_replace( $root . '/', '', $pf );
	preg_match_all( $ns_re, $src, $dm, PREG_OFFSET_CAPTURE );
	if ( ! preg_match_all( '/data-wp-[a-z-]+(?:--[\w.:-]+)?\s*(?:=>|=)\s*[\'"]([^\'"]+)[\'"]/', $src, $rm, PREG_OFFSET_CAPTURE ) ) {
		continue;
	}
	foreach ( $rm[1] as $k => $expr ) {
		$pos = $rm[0][ $k ][1];
		if ( ! preg_match_all( '/(?:([\w\/-]+)::)?\b(actions|state|callbacks)\.([\w$]+)/', $expr[0], $refs, PREG_SET_ORDER ) ) {
			continue;
		}
		$scope = $ia_inherited[ realpath( $pf ) ?: '' ] ?? null;
		foreach ( $dm[1] as $d ) {
			if ( $d[1] <= $pos ) { $scope = $d[0]; }
		}
		foreach ( $refs as $ref ) {
			[ , $explicit, $kind, $member ] = $ref;
			$ns = '' !== $explicit ? $explicit : $scope;
			if ( null === $ns ) {
				$add( 'warning', 'interactivity', "$name: {$kind}.{$member} has no data-wp-interactive namespace in scope." );
				continue;
			}
			if ( isset( $ia_defined[ $ns ][ $kind ][ $member ] ) ) {
				continue;
			}
			// Several stores deliberately re-expose the global petsync store.
			if ( 'petsync' !== $ns && isset( $ia_defined['petsync'][ $kind ][ $member ] ) ) {
				continue;
			}
			$line = substr_count( substr( $src, 0, $pos ), "\n" ) + 1;
			$add( 'error', 'interactivity', "$name:$line references {$kind}.{$member} in namespace '{$ns}', which defines no such member." );
		}
	}
}

// ── Check 7: change-detection hash covers every consumed API field ───────────
$sync_src = $read( 'includes/class-petsync-sync.php' );
$consumed = [];
foreach ( (array) ( $entity['api_fields'] ?? [] ) as $cfg ) {
	if ( ! empty( $cfg['api_key'] ) ) {
		$consumed[ $cfg['api_key'] ] = true;
	}
}
foreach ( array_keys( (array) ( $entity['attribute_map'] ?? [] ) ) as $k ) {
	$consumed[ $k ] = true;
}
// computed_sources[] literal in get_retained_api_keys().
if ( preg_match( '/\$computed_sources\s*=\s*array\((.*?)\);/s', $sync_src, $m ) ) {
	preg_match_all( "/'([a-z_]+)'/", $m[1], $cm );
	foreach ( $cm[1] as $k ) {
		$consumed[ $k ] = true;
	}
}
// taxonomy_source_map keys (config-driven since the map moved out of PHP).
foreach ( array_keys( (array) ( $entity['taxonomy_source_map'] ?? [] ) ) as $k ) {
	$consumed[ $k ] = true;
}
// Extra keys listed inside get_consumed_api_keys() (post/taxonomy drivers).
if ( preg_match( '/function get_consumed_api_keys\(.*?\n\t\}/s', $sync_src, $m ) ) {
	preg_match_all( "/'([a-z_]+)'/", $m[0], $em );
	foreach ( $em[1] as $k ) {
		$consumed[ $k ] = true;
	}
}
$envelope = [ 'collection', 'pagination' ]; // Response wrapper, not per-pet.
if ( preg_match_all( "/\\\$data\[\s*'([a-z_]+)'\s*\]/", $sync_src, $dm ) ) {
	foreach ( array_unique( $dm[1] ) as $key ) {
		if ( ! in_array( $key, $envelope, true ) && ! isset( $consumed[ $key ] ) ) {
			$add( 'error', 'hash-coverage', "sync reads \$data['$key'] but it is not in get_consumed_api_keys() — changes to it won't trigger a re-sync (stale-display risk)." );
		}
	}
}

// ── Check 8: version consistency across every file that declares one ─────────
// The plugin header is the single source of truth. Everything else must agree.
// readme.txt's Stable tag is the one that actually breaks users: if it does not
// match the tagged release, WordPress.org serves the wrong code or nothing.
$version_sources = [];

if ( isset( $main_file ) && is_file( $main_file ) ) {
	$main_src = (string) file_get_contents( $main_file );

	if ( preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $main_src, $m ) ) {
		$version_sources[ basename( $main_file ) . ' (header)' ] = $m[1];
	} else {
		$add( 'error', 'version', 'No "Version:" header found in ' . basename( $main_file ) . '.' );
	}

	if ( preg_match( "/define\(\s*'PETSYNC_VERSION'\s*,\s*'([^']+)'/", $main_src, $m ) ) {
		$version_sources[ basename( $main_file ) . ' (PETSYNC_VERSION)' ] = $m[1];
	}
}

$readme_path = $root . '/readme.txt';
if ( is_file( $readme_path ) ) {
	$readme_src = (string) file_get_contents( $readme_path );
	if ( preg_match( '/^Stable tag:\s*(\S+)/mi', $readme_src, $m ) ) {
		$version_sources['readme.txt (Stable tag)'] = $m[1];
	} else {
		$add( 'error', 'version', 'readme.txt has no "Stable tag:" header — WordPress.org needs it to know which tag to serve.' );
	}
} else {
	$add( 'error', 'version', 'Missing readme.txt — required for WordPress.org.' );
}

$pkg_path = $root . '/package.json';
if ( is_file( $pkg_path ) ) {
	$pkg = json_decode( (string) file_get_contents( $pkg_path ), true );
	if ( isset( $pkg['version'] ) ) {
		$version_sources['package.json'] = $pkg['version'];
	}
}

// Every block.json carries a version used by core as the asset cache-buster
// (see wp-includes/blocks.php — absent means WP falls back to its own version,
// which would not change when the plugin ships a CSS/JS fix).
foreach ( glob( $root . '/blocks/*/block.json' ) ?: [] as $block_json ) {
	$block = json_decode( (string) file_get_contents( $block_json ), true );
	if ( isset( $block['version'] ) ) {
		$version_sources[ 'blocks/' . basename( dirname( $block_json ) ) . '/block.json' ] = $block['version'];
	}
}

$distinct = array_unique( array_values( $version_sources ) );
if ( count( $distinct ) > 1 ) {
	$canonical = $version_sources[ basename( $main_file ?? '' ) . ' (header)' ] ?? reset( $distinct );
	foreach ( $version_sources as $where => $found ) {
		if ( $found !== $canonical ) {
			$add( 'error', 'version', "$where declares $found but the plugin header says $canonical — run bin/bump-version.php to resync." );
		}
	}
}

// ── Check 9: screenshot captions match the screenshot files ─────────────────
// WordPress.org pairs `screenshot-N.png` in the SVN assets directory with the
// Nth line of the readme's Screenshots section. A mismatch does not error
// anywhere — the captions simply attach to the wrong images, or vanish.
if ( isset( $readme_src ) ) {
	$captions = [];
	if ( preg_match( '/^== Screenshots ==\s*(.*?)(?=^== )/ms', $readme_src, $m ) ) {
		if ( preg_match_all( '/^\s*(\d+)\.\s+\S/m', $m[1], $cm ) ) {
			$captions = array_map( 'intval', $cm[1] );
		}
	}

	$files = [];
	foreach ( glob( $root . '/.wordpress-org/screenshot-*.{png,jpg,gif}', GLOB_BRACE ) ?: [] as $shot ) {
		if ( preg_match( '/screenshot-(\d+)\./', basename( $shot ), $fm ) ) {
			$files[] = (int) $fm[1];
		}
	}
	sort( $files );

	if ( $captions ) {
		// Numbering must start at 1 and not skip — WordPress.org stops at the
		// first gap, so a missing 3 hides 4 onwards.
		$expected = range( 1, count( $captions ) );
		if ( $captions !== $expected ) {
			$add( 'error', 'screenshots', 'Screenshot captions are numbered ' . implode( ',', $captions ) . ' — they must run 1..' . count( $captions ) . ' with no gaps.' );
		}

		if ( ! $files ) {
			$add( 'warning', 'screenshots', count( $captions ) . ' screenshot caption(s) written, but no screenshot-N files in .wordpress-org/ yet.' );
		} elseif ( $files !== $expected ) {
			$add( 'error', 'screenshots', 'Screenshot files are numbered ' . implode( ',', $files ) . ' but there are ' . count( $captions ) . ' caption(s) — every caption needs a matching file and vice versa.' );
		}
	} elseif ( $files ) {
		$add( 'error', 'screenshots', count( $files ) . ' screenshot file(s) present with no == Screenshots == captions in readme.txt — they would appear unlabelled.' );
	}
}

// ── Check 10: uninstall knows every wp_theme namespace ──────────────────────
//
// Site Editor customizations are filed under a wp_theme term named after the
// plugin, so that name is a storage key. Petsync_Templates owns the current
// name and the historical ones; uninstall.php has to repeat the list, because
// the plugin is not loaded during uninstall and the constants are unreachable
// there.
//
// A duplicated list is exactly the drift that made migration 4 necessary in the
// first place, so it is checked rather than trusted. Getting this wrong leaves
// a deleted plugin's template posts and terms behind in the database.
$templates_src = $read( 'includes/class-petsync-templates.php' );
$uninstall_src = $read( 'uninstall.php' );

if ( '' !== $templates_src && '' !== $uninstall_src ) {
	$declared = [];

	if ( preg_match( "/const\s+THEME_NAMESPACE\s*=\s*'([^']+)'/", $templates_src, $cm ) ) {
		$declared[] = $cm[1];
	} else {
		$add( 'error', 'uninstall', 'Petsync_Templates::THEME_NAMESPACE not found — check 10 cannot verify uninstall coverage.' );
	}

	if ( preg_match( '/const\s+LEGACY_NAMESPACES\s*=\s*(?:array\(|\[)(.*?)(?:\)|\]);/s', $templates_src, $lm ) ) {
		preg_match_all( "/'([^']+)'/", $lm[1], $names );
		$declared = array_merge( $declared, $names[1] );
	}

	// The list uninstall.php actually deletes.
	$covered = [];
	if ( preg_match( '/\$theme_names\s*=\s*(?:array\(|\[)(.*?)(?:\)|\]);/s', $uninstall_src, $um ) ) {
		preg_match_all( "/'([^']+)'/", $um[1], $un );
		$covered = $un[1];
	} else {
		$add( 'error', 'uninstall', 'uninstall.php has no $theme_names list — Site Editor customizations would be left behind on delete.' );
	}

	foreach ( array_diff( $declared, $covered ) as $missing ) {
		$add( 'error', 'uninstall', "wp_theme namespace '$missing' is declared in Petsync_Templates but not deleted by uninstall.php — add it to \$theme_names." );
	}

	foreach ( array_diff( $covered, $declared ) as $extra ) {
		$add( 'warning', 'uninstall', "uninstall.php deletes wp_theme namespace '$extra', which Petsync_Templates does not declare — stale entry, or a rename that never reached the constants." );
	}
}

// ── Report ───────────────────────────────────────────────────────────────────
$errors   = array_filter( $issues, static fn( $i ) => $i['level'] === 'error' );
$warnings = array_filter( $issues, static fn( $i ) => $i['level'] === 'warning' );

if ( $format === 'json' ) {
	echo json_encode( [
		'ok'       => count( $errors ) === 0,
		'errors'   => count( $errors ),
		'warnings' => count( $warnings ),
		'issues'   => array_values( $issues ),
	], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
} else {
	$colors = [ 'error' => "\033[31m", 'warning' => "\033[33m" ];
	$reset  = "\033[0m";
	$tty    = function_exists( 'posix_isatty' ) ? @posix_isatty( STDOUT ) : false;
	if ( empty( $issues ) ) {
		echo ( $tty ? "\033[32m" : '' ) . "✓ config validation passed — no issues." . ( $tty ? $reset : '' ) . "\n";
	} else {
		foreach ( $issues as $i ) {
			$tag = strtoupper( $i['level'] );
			$c   = $tty ? ( $colors[ $i['level'] ] ?? '' ) : '';
			$r   = $tty ? $reset : '';
			echo "{$c}[{$tag}]{$r} ({$i['check']}) {$i['message']}\n";
		}
		echo "\n" . count( $errors ) . " error(s), " . count( $warnings ) . " warning(s).\n";
	}
}

exit( count( $errors ) > 0 ? 1 : 0 );
