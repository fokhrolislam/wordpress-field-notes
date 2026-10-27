<?php
/**
 * Update a page's content without letting WordPress rename its slug.
 *
 *   wp eval-file scripts/slug-guard.php <post_id> <path-to-new-content.html>
 *
 * wp_update_post() re-runs wp_unique_post_slug(); when an attachment already
 * holds the page's slug the page silently becomes "slug-2" and the old URL
 * 404s. This script writes post_content directly, clears the cache, and then
 * proves that post_name and the live URL are unchanged.
 */

if ( ! defined( 'WP_CLI' ) ) {
	exit( "Run through wp eval-file\n" );
}

global $wpdb;

list( $id, $file ) = array_slice( $args, 0, 2 ) + array( null, null );
$id = (int) $id;
if ( ! $id || ! $file || ! is_file( $file ) ) {
	WP_CLI::error( 'Usage: wp eval-file slug-guard.php <post_id> <content-file>' );
}

$before = get_post( $id );
if ( ! $before ) {
	WP_CLI::error( "Post $id not found" );
}
$slug_before = $before->post_name;
$url         = get_permalink( $id );

// Content-only write: no slug recalculation.
$wpdb->update(
	$wpdb->posts,
	array( 'post_content' => file_get_contents( $file ), 'post_modified' => current_time( 'mysql' ), 'post_modified_gmt' => current_time( 'mysql', true ) ),
	array( 'ID' => $id ),
	array( '%s', '%s', '%s' ),
	array( '%d' )
);
clean_post_cache( $id );

$after = get_post( $id );
if ( $after->post_name !== $slug_before ) {
	WP_CLI::error( "Slug changed: $slug_before -> {$after->post_name}. Look for an attachment or sibling page with that slug." );
}

// Prove the URL itself still answers 200 without a redirect.
$resp = wp_remote_head( $url, array( 'redirection' => 0, 'timeout' => 15 ) );
$code = is_wp_error( $resp ) ? $resp->get_error_message() : wp_remote_retrieve_response_code( $resp );
if ( 200 !== (int) $code ) {
	WP_CLI::error( "$url answered $code (expected 200 with no redirect)" );
}

WP_CLI::success( "Post $id updated, slug '$slug_before' intact, $url -> 200" );
