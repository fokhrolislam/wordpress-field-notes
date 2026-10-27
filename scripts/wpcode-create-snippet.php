<?php
/**
 * Create (or replace) a WPCode snippet from the command line, the way the
 * plugin itself does it, and leave the front-end cache in a working state.
 *
 *   wp eval-file scripts/wpcode-create-snippet.php "<title>" <css|js|html|php> <location> <path-to-code> --user=<admin login>
 *
 * Locations: site_wide_header, site_wide_body, site_wide_footer, everywhere, frontend_only, admin_only, ...
 *
 * Three traps this script handles:
 *  1. --user is required: WPCode blocks wp_insert_post for users without wpcode_edit_snippets.
 *  2. Type and location are taxonomies, not meta.
 *  3. The front end reads only the wpcode_snippets option; after any change it
 *     must be rebuilt with cache_all_loaded_snippets(), or every snippet stops.
 */

if ( ! defined( 'WP_CLI' ) ) {
	exit( "Run through wp eval-file\n" );
}
if ( ! function_exists( 'wpcode' ) ) {
	WP_CLI::error( 'WPCode (insert-headers-and-footers) is not active' );
}
if ( ! current_user_can( 'wpcode_edit_snippets' ) ) {
	WP_CLI::error( 'Run with --user=<an administrator>; WPCode refuses writes from user 0' );
}

list( $title, $type, $location, $file ) = array_slice( $args, 0, 4 ) + array( null, null, null, null );
if ( ! $title || ! in_array( $type, array( 'css', 'js', 'html', 'php' ), true ) || ! $location || ! is_file( $file ) ) {
	WP_CLI::error( 'Usage: "<title>" <css|js|html|php> <location> <code-file>' );
}
$code = file_get_contents( $file );

// Replace an existing snippet of the same title instead of creating a duplicate.
$existing = get_posts( array( 'post_type' => 'wpcode', 'title' => $title, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ) );

$post = array(
	'post_type'    => 'wpcode',
	'post_title'   => $title,
	'post_content' => $code,
	'post_status'  => 'publish', // draft = disabled snippet
);
if ( $existing ) {
	$post['ID'] = $existing[0];
	$id         = wp_update_post( $post, true );
} else {
	$id = wp_insert_post( $post, true );
}
if ( is_wp_error( $id ) ) {
	WP_CLI::error( $id->get_error_message() );
}

wp_set_object_terms( $id, $type, 'wpcode_type' );
wp_set_object_terms( $id, $location, 'wpcode_location' );

foreach ( array(
	'_wpcode_auto_insert'               => 1,
	'_wpcode_auto_insert_number'        => 1,
	'_wpcode_priority'                  => 10,
	'_wpcode_conditional_logic_enabled' => '',
	'_wpcode_conditional_logic'         => array(),
	'_wpcode_shortcode_attributes'      => array(),
	'_wpcode_location_extra'            => '',
	'_wpcode_note'                      => '',
) as $key => $value ) {
	update_post_meta( $id, $key, $value );
}

// Rebuild the option the front end actually reads.
wpcode()->cache->cache_all_loaded_snippets();
$cached = get_option( 'wpcode_snippets' );
$listed = false;
foreach ( (array) $cached as $group ) {
	foreach ( (array) $group as $s ) {
		if ( isset( $s['id'] ) && (int) $s['id'] === (int) $id ) {
			$listed = true;
		}
	}
}
if ( ! $listed ) {
	WP_CLI::warning( "Snippet $id saved but not present in the wpcode_snippets option; check the location/type terms." );
}

WP_CLI::success( ( $existing ? 'Updated' : 'Created' ) . " snippet $id ($type, $location). Verify by curling a page and grepping for your code." );
