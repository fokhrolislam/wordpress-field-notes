<?php
/**
 * Plugin Name: WPSC WooCommerce Basket Guard
 * Description: Stops WP Super Cache from storing a guest's basket (header count, subtotal, session nonce) as the shared copy of a page. Drop into wp-content/mu-plugins/.
 * Version:     1.0.0
 * Author:      Fokhrol Islam
 * License:     MIT
 *
 * Why: WP Super Cache only knows the wordpress_logged_in, comment_author and
 * wp-postpass cookies. A guest with a basket who hits an uncached page gets that
 * page cached for everyone. WooCommerce sets DONOTCACHEPAGE only on cart,
 * checkout and account.
 *
 * Add your theme's own state cookies to the list below (wishlist, compare,
 * per-page choices) if its header renders them.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cookie names, or prefixes, that mean "this visitor has state the page may render".
 */
function wpsc_wbg_cookie_patterns() {
	return apply_filters( 'wpsc_wbg_cookie_patterns', array(
		'woocommerce_items_in_cart',
		'woocommerce_cart_hash',
		'wp_woocommerce_session_',   // prefix: wp_woocommerce_session_<hash>
		'woocommerce_recently_viewed',
		// WoodMart and similar themes
		'woodmart_wishlist_products',
		'woodmart_compare_list',
		'shop_per_page',
		'shop_per_row',
	) );
}

/**
 * Read the cookie state once, at load time. WooCommerce unsets a stale basket
 * cookie during the request, so reading $_COOKIE inside the cache callback can
 * miss the very visitor who triggered the problem.
 */
function wpsc_wbg_request_has_state() {
	static $has = null;
	if ( null !== $has ) {
		return $has;
	}
	$has = false;
	foreach ( array_keys( $_COOKIE ) as $name ) {
		foreach ( wpsc_wbg_cookie_patterns() as $pattern ) {
			if ( $name === $pattern || 0 === strpos( $name, $pattern ) ) {
				$has = true;
				return true;
			}
		}
	}
	return false;
}
wpsc_wbg_request_has_state();

// 1. Tell WP Super Cache these cookies make the visitor unique (not served the shared copy).
if ( function_exists( 'wpsc_add_cookie' ) ) {
	foreach ( wpsc_wbg_cookie_patterns() as $pattern ) {
		wpsc_add_cookie( rtrim( $pattern, '_' ) );
	}
}

// 2. Never store a page generated for a visitor who carried state.
add_filter( 'wp_cache_ob_callback_filter', function ( $buffer ) {
	if ( wpsc_wbg_request_has_state() && ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	return $buffer;
}, 1 );

// 3. Pages with query strings carry filters and tracking; do not cache them either.
$GLOBALS['wp_cache_no_cache_for_get'] = 1;
