<?php
/**
 * BEAZER merch as WooCommerce products, with themed product pages.
 *
 * The T-shirts in merch/products.php become variable products (sizes S–2XL,
 * one price) in a "BEAZER Merch" category, hidden from the host store's
 * catalogue and search so the rest of the shop is unchanged. Each shirt has
 * two shots: on white (the store image, used by cart, checkout and emails)
 * and on the site's dark red stage (used on the demo pages). They are sold
 * from /beazertheband/merch/ and /beazertheband/merch/<shirt>/ through the
 * store's normal cart and checkout.
 *
 * @package beazer-demo
 */

defined( 'ABSPATH' ) || exit;

define( 'BEAZER_DEMO_MERCH_SIZES', array( 'S', 'M', 'L', 'XL', '2XL' ) );

/** Bundled merch list: price + items of [ sku, name, black, white ], each with its page slug. */
function beazer_demo_merch_items() {
	$file = BEAZER_DEMO_PATH . 'merch/products.php';
	$list = is_readable( $file ) ? include $file : array( 'price' => '250', 'items' => array() );
	foreach ( $list['items'] as &$item ) {
		$item['slug'] = sanitize_title( preg_replace( '/[^A-Za-z0-9]+/', ' ', $item['name'] ) ); // plain ASCII URLs
	}
	return $list;
}

function beazer_demo_wc_ready() {
	return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product_id_by_sku' );
}

function beazer_demo_merch_url( $item = null ) {
	return beazer_demo_url( $item ? 'merch/' . $item['slug'] : 'merch' );
}

/** Media library attachment for a bundled image, uploaded once and reused. */
function beazer_demo_merch_image( $file, $title ) {
	$source = BEAZER_DEMO_PATH . 'merch/' . $file;
	$key    = 'beazer_demo_img_' . md5( $file . filemtime( $source ) );
	$id     = (int) get_option( $key );
	if ( $id && get_post( $id ) ) {
		return $id;
	}
	if ( ! is_readable( $source ) ) {
		return 0;
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$tmp = wp_tempnam( $file );
	copy( $source, $tmp );
	$id = media_handle_sideload( array( 'name' => $file, 'tmp_name' => $tmp ), 0, $title );
	if ( is_wp_error( $id ) ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return 0;
	}
	update_option( $key, $id, false );
	return $id;
}

/** WooCommerce product for a bundled item, or null. */
function beazer_demo_merch_product( $item ) {
	if ( ! beazer_demo_wc_ready() ) {
		return null;
	}
	$product = wc_get_product( wc_get_product_id_by_sku( $item['sku'] ) );
	return $product && 'publish' === $product->get_status() ? $product : null;
}

/**
 * Create the merch products that do not exist yet (matched by SKU) and keep
 * name, price, images, sizes and visibility in line with the bundled list.
 */
function beazer_demo_sync_merch() {
	if ( ! beazer_demo_wc_ready() ) {
		return array( 'error' => 'WooCommerce is not active.' );
	}
	$list  = beazer_demo_merch_items();
	$price = (string) $list['price'];
	$term  = term_exists( 'BEAZER Merch', 'product_cat' ) ?: wp_insert_term( 'BEAZER Merch', 'product_cat', array( 'slug' => 'beazer-merch' ) );
	$cat   = is_array( $term ) ? (int) $term['term_id'] : 0;
	$done  = array( 'created' => 0, 'updated' => 0 );

	foreach ( $list['items'] as $item ) {
		$id      = wc_get_product_id_by_sku( $item['sku'] );
		$product = $id ? wc_get_product( $id ) : new WC_Product_Variable();
		if ( ! $product ) {
			continue;
		}
		$is_new = ! $id;
		$product->set_name( $item['name'] );
		if ( $is_new ) {
			$product->set_sku( $item['sku'] );
			$product->set_status( 'publish' );
			$product->set_description( 'Official BEAZER T-shirt. Caribbean rock, global ambition.' );
		}
		$product->set_catalog_visibility( 'hidden' );
		if ( $cat ) {
			$product->set_category_ids( array( $cat ) );
		}
		$white = beazer_demo_merch_image( $item['white'], $item['name'] );
		$black = beazer_demo_merch_image( $item['black'], $item['name'] . ' (on black)' );
		if ( $white ) {
			$product->set_image_id( $white );
		}
		$product->set_gallery_image_ids( $black ? array( $black ) : array() );
		$size = new WC_Product_Attribute();
		$size->set_name( 'Size' );
		$size->set_options( BEAZER_DEMO_MERCH_SIZES );
		$size->set_visible( true );
		$size->set_variation( true );
		$product->set_attributes( array( $size ) );
		$parent = $product->save();

		$existing = array();
		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( $child ) {
				$existing[ $child->get_attribute( 'Size' ) ] = $child;
			}
		}
		foreach ( BEAZER_DEMO_MERCH_SIZES as $label ) {
			$variation = $existing[ $label ] ?? new WC_Product_Variation();
			$variation->set_parent_id( $parent );
			$variation->set_attributes( array( 'size' => $label ) );
			$variation->set_sku( $item['sku'] . '-' . $label );
			$variation->set_regular_price( $price );
			$variation->set_stock_status( 'instock' );
			$variation->save();
		}
		WC_Product_Variable::sync( $parent );
		wc_delete_product_transients( $parent );
		$done[ $is_new ? 'created' : 'updated' ]++;
	}
	update_option( 'beazer_demo_merch_synced', BEAZER_DEMO_VERSION );
	return $done;
}

/* Sync after activation or an update, as soon as WooCommerce is active. */
add_action( 'admin_init', function () {
	if ( beazer_demo_wc_ready() && current_user_can( 'manage_options' ) && get_option( 'beazer_demo_merch_synced' ) !== BEAZER_DEMO_VERSION ) {
		beazer_demo_sync_merch();
	}
} );

/** Image URL for a shot ('black' or 'white'): the media library copy, else the bundled file. */
function beazer_demo_merch_img( $item, $product, $shade, $size = 'woocommerce_single' ) {
	if ( $product ) {
		$id = 'white' === $shade ? $product->get_image_id() : ( $product->get_gallery_image_ids()[0] ?? 0 );
		$url = $id ? wp_get_attachment_image_url( $id, $size ) : '';
		if ( $url ) {
			return $url;
		}
	}
	return plugins_url( 'merch/' . $item[ $shade ], BEAZER_DEMO_FILE );
}

function beazer_demo_price_html( $list, $product ) {
	return $product ? wp_kses_post( $product->get_price_html() ) : esc_html( 'TT$' . $list['price'] );
}

/** Size picker: <select> (compact, for cards) or radio pills (product page). */
function beazer_demo_sizes( $item, $product, $style ) {
	$out = '';
	foreach ( $product->get_children() as $child_id ) {
		$child = wc_get_product( $child_id );
		if ( ! $child || ! $child->is_in_stock() ) {
			continue;
		}
		$label = esc_html( $child->get_attribute( 'Size' ) );
		$out  .= 'pills' === $style
			? sprintf( '<label class="bz-size"><input type="radio" name="add-to-cart" value="%d" required><span>%s</span></label>', $child_id, $label )
			: sprintf( '<option value="%d">%s</option>', $child_id, $label );
	}
	if ( 'pills' === $style ) {
		return '<fieldset class="bz-sizes"><legend>Size</legend>' . $out . '</fieldset>';
	}
	return sprintf( '<label class="screen-reader-text" for="size-%1$s">Size</label><select id="size-%1$s" name="add-to-cart" required><option value="">Size</option>%2$s</select>', esc_attr( $item['sku'] ), $out );
}

function beazer_demo_merch_card( $item, $list ) {
	$product = beazer_demo_merch_product( $item );
	$url     = esc_url( beazer_demo_merch_url( $item ) );
	$card    = '<article class="bz-tee">';
	$card   .= sprintf( '<a class="bz-tee-img" href="%s"><img src="%s" alt="%s" loading="lazy"></a>', $url, esc_url( beazer_demo_merch_img( $item, $product, 'black' ) ), esc_attr( $item['name'] ) );
	$card   .= sprintf( '<h3><a href="%s">%s</a></h3>', $url, esc_html( $item['name'] ) );
	$card   .= sprintf( '<p class="bz-tee-price">%s</p>', beazer_demo_price_html( $list, $product ) );
	if ( $product && $product->is_purchasable() ) {
		$card .= sprintf( '<form class="bz-tee-buy" method="get" action="%s">%s<button class="btn btn-red" type="submit">Add to cart</button></form>', esc_url( wc_get_cart_url() ), beazer_demo_sizes( $item, $product, 'select' ) );
	} else {
		$card .= '<p class="bz-tee-soon">Coming soon</p>';
	}
	return $card . '</article>';
}

/** The grid placed into /beazertheband/merch/. */
function beazer_demo_merch_html() {
	$list = beazer_demo_merch_items();
	if ( ! $list['items'] ) {
		return '<p class="bz-merch-empty">The store is opening soon. Join the fan club to hear first.</p>';
	}
	return '<div class="bz-tees">' . implode( '', array_map( function ( $item ) use ( $list ) {
		return beazer_demo_merch_card( $item, $list );
	}, $list['items'] ) ) . '</div>';
}

/** Find a bundled item by its page slug. */
function beazer_demo_merch_find( $slug ) {
	$list = beazer_demo_merch_items();
	foreach ( $list['items'] as $item ) {
		if ( $item['slug'] === $slug ) {
			return $item;
		}
	}
	return null;
}

/** Body of /beazertheband/merch/<shirt>/, in the site's design. */
function beazer_demo_product_html( $item ) {
	$list    = beazer_demo_merch_items();
	$product = beazer_demo_merch_product( $item );
	$black   = esc_url( beazer_demo_merch_img( $item, $product, 'black', 'full' ) );
	$white   = esc_url( beazer_demo_merch_img( $item, $product, 'white', 'full' ) );
	$name    = esc_html( $item['name'] );

	$html  = '<div class="bz-section"><nav class="bz-crumbs" aria-label="Breadcrumb"><a href="' . esc_url( beazer_demo_merch_url() ) . '">Merch</a><span aria-hidden="true">/</span><span>' . $name . '</span></nav>';
	$html .= '<div class="bz-product">';
	$html .= '<div class="bz-product-media">';
	$html .= '<figure class="bz-product-stage is-black" data-stage><img src="' . $black . '" alt="' . esc_attr( $item['name'] ) . '" data-black="' . $black . '" data-white="' . $white . '"></figure>';
	$html .= '<div class="bz-shades" role="group" aria-label="Background">';
	$html .= '<button type="button" class="bz-shade is-active" data-shade="black" aria-pressed="true"><img src="' . $black . '" alt="">On black</button>';
	$html .= '<button type="button" class="bz-shade" data-shade="white" aria-pressed="false"><img src="' . $white . '" alt="">On white</button>';
	$html .= '</div></div>';

	$html .= '<div class="bz-product-info">';
	$html .= '<p class="bz-kicker"><span>01</span> Official merch</p>';
	$html .= '<h2 class="bz-display">' . $name . '</h2>';
	$html .= '<p class="bz-product-price">' . beazer_demo_price_html( $list, $product ) . '</p>';
	$html .= '<p class="bz-lead">Official BEAZER T-shirt. Caribbean rock, global ambition, worn loud.</p>';
	if ( $product && $product->is_purchasable() ) {
		$html .= '<form class="bz-product-buy" method="get" action="' . esc_url( wc_get_cart_url() ) . '">';
		$html .= beazer_demo_sizes( $item, $product, 'pills' );
		$html .= '<div class="bz-buy-row"><label class="bz-qty"><span>Qty</span><input type="number" name="quantity" value="1" min="1" max="20" inputmode="numeric"></label>';
		$html .= '<button class="btn btn-red bz-btn" type="submit">Add to cart</button></div></form>';
	} else {
		$html .= '<p class="bz-tee-soon">Coming soon. Join the fan club to hear when it drops.</p>';
	}
	$html .= '<ul class="bz-product-notes"><li>Sizes S to 2XL</li><li>Secure checkout</li><li>Official band merchandise</li></ul>';
	$html .= '</div></div></div>';

	$others = array_values( array_filter( $list['items'], function ( $other ) use ( $item ) {
		return $other['sku'] !== $item['sku'];
	} ) );
	if ( $others ) {
		$html .= '<div class="bz-section"><p class="bz-kicker"><span>02</span> More tees</p><div class="bz-tees">';
		foreach ( array_slice( $others, 0, 4 ) as $other ) {
			$html .= beazer_demo_merch_card( $other, $list );
		}
		$html .= '</div><p class="bz-more-link"><a class="btn btn-dark bz-btn" href="' . esc_url( beazer_demo_merch_url() ) . '">All merch</a></p></div>';
	}
	$html .= '<script>(function(){var s=document.querySelector("[data-stage]");if(!s)return;var img=s.querySelector("img");document.querySelectorAll(".bz-shade").forEach(function(b){b.addEventListener("click",function(){var shade=b.dataset.shade;img.src=img.dataset[shade];s.className="bz-product-stage is-"+shade;document.querySelectorAll(".bz-shade").forEach(function(o){var on=o===b;o.classList.toggle("is-active",on);o.setAttribute("aria-pressed",on);});});});})();</script>';
	return $html;
}

/** Turn the exported Merch page into a product page (title, banner, body). */
function beazer_demo_product_page( $shell, $item ) {
	$body  = beazer_demo_product_html( $item );
	$title = esc_html( $item['name'] );
	$shell = preg_replace( '/<title>.*?<\/title>/s', '<title>' . $title . ' – BEAZER</title>', $shell, 1 );
	$shell = preg_replace_callback( '/(<h1 class="page-title">).*?(<\/h1>)/s', function ( $m ) {
		return $m[1] . 'Merch' . $m[2];
	}, $shell, 1 );
	return preg_replace_callback( '/(<article[^>]*inner-content[^>]*>).*?(<\/article>)/s', function ( $m ) use ( $body ) {
		return $m[1] . $body . $m[2];
	}, $shell, 1 );
}
