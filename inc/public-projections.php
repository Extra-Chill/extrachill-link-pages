<?php
/**
 * Owner-neutral public projection providers.
 *
 * @package ExtraChillLinkPages
 */

defined( 'ABSPATH' ) || exit;

/** Return the append-only public projection registry. */
function ec_link_page_public_projection_registry() {
	static $registry = null;
	if ( null === $registry ) {
		$registry = new class() {
			private $providers = array();
			public function register( $name, $callback, $priority ) {
				if ( ! is_string( $name ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $name ) || ! is_callable( $callback ) || ! is_int( $priority ) ) {
					return new WP_Error( 'invalid_link_page_public_projection_provider', 'The Link Page public projection provider registration is invalid.' );
				}
				if ( isset( $this->providers[ $name ] ) ) {
					return new WP_Error( 'duplicate_link_page_public_projection_provider', 'The Link Page public projection provider is already registered.' );
				}
				$this->providers[ $name ] = array(
					'name'     => $name,
					'callback' => $callback,
					'priority' => $priority,
				);
				return true;
			}
			public function snapshot() {
				$providers = array_values( $this->providers );
				usort(
					$providers,
					static function ( $left, $right ) {
						$order = $left['priority'] <=> $right['priority'];
						return 0 !== $order ? $order : strcmp( $left['name'], $right['name'] );
					}
				);
				return $providers;
			}
		};
	}
	return $registry;
}

/** Register one public projection provider. */
function ec_register_link_page_public_projection_provider( $name, $callback, $priority = 10 ) {
	return ec_link_page_public_projection_registry()->register( $name, $callback, $priority );
}

/** Invoke public provider code without storage-context leakage. */
function ec_invoke_link_page_public_projection_callback( $callback, $arguments ) {
	$blog_id  = get_current_blog_id();
	$stack    = isset( $GLOBALS['_wp_switched_stack'] ) && is_array( $GLOBALS['_wp_switched_stack'] ) ? $GLOBALS['_wp_switched_stack'] : array();
	$switched = ! empty( $GLOBALS['switched'] );
	$result   = null;
	$error    = null;
	try {
		$result = call_user_func_array( $callback, $arguments );
	} catch ( Throwable $throwable ) {
		$error = new WP_Error( 'link_page_public_projection_provider_exception', 'A Link Page public projection provider failed.' );
	}
	$leaked   = get_current_blog_id() !== $blog_id || ( $GLOBALS['_wp_switched_stack'] ?? array() ) !== $stack || ! empty( $GLOBALS['switched'] ) !== $switched;
	$restored = ec_restore_link_page_owner_provider_context( $blog_id, $stack, $switched );
	if ( $leaked || ! $restored ) {
		return new WP_Error( 'link_page_public_projection_provider_context_leak', 'A Link Page public projection provider leaked its storage context.' );
	}
	return $error ? $error : $result;
}

/** Validate one provider projection. */
function ec_validate_link_page_public_projection( $projection ) {
	$allowed = array( 'display_title', 'bio', 'profile_img_url', 'social_links', 'social_renderer', 'management_url', 'body_attributes', 'seo', 'tracking_url', 'components', 'assets', 'legacy_head_arguments', 'css_vars', 'body_style' );
	if ( ! is_array( $projection ) || array_diff( array_keys( $projection ), $allowed ) || ! isset( $projection['display_title'] ) || ! is_string( $projection['display_title'] ) ) {
		return new WP_Error( 'invalid_link_page_public_projection', 'A Link Page public projection provider returned invalid data.' );
	}
	foreach ( array( 'bio', 'profile_img_url', 'management_url', 'tracking_url', 'body_style' ) as $key ) {
		if ( isset( $projection[ $key ] ) && ! is_string( $projection[ $key ] ) ) {
			return new WP_Error( 'invalid_link_page_public_projection', 'A Link Page public projection provider returned invalid data.' );
		}
	}
	foreach ( array( 'social_links', 'body_attributes', 'seo', 'components', 'css_vars' ) as $key ) {
		if ( isset( $projection[ $key ] ) && ! is_array( $projection[ $key ] ) ) {
			return new WP_Error( 'invalid_link_page_public_projection', 'A Link Page public projection provider returned invalid data.' );
		}
	}
	if ( isset( $projection['legacy_head_arguments'] ) && ! is_array( $projection['legacy_head_arguments'] ) ) {
		return new WP_Error( 'invalid_link_page_public_projection', 'A Link Page public projection provider returned invalid data.' );
	}
	foreach ( array( 'social_renderer', 'assets' ) as $key ) {
		if ( isset( $projection[ $key ] ) && null !== $projection[ $key ] && ! is_callable( $projection[ $key ] ) ) {
			return new WP_Error( 'invalid_link_page_public_projection', 'A Link Page public projection provider returned invalid data.' );
		}
	}
	foreach ( $projection['components'] ?? array() as $slot => $callbacks ) {
		if ( ! in_array( $slot, array( 'head', 'body_start', 'before_header', 'header_actions', 'after_header', 'after_links', 'footer' ), true ) || ! is_array( $callbacks ) ) {
			return new WP_Error( 'invalid_link_page_public_projection', 'A Link Page public projection provider returned invalid components.' );
		}
		foreach ( $callbacks as $callback ) {
			if ( ! is_callable( $callback ) ) {
				return new WP_Error( 'invalid_link_page_public_projection', 'A Link Page public projection provider returned invalid components.' );
			}
		}
	}
	return $projection;
}

/** Resolve exactly one owner projection. */
function ec_get_link_page_public_projection( $link_page_id, $request = array() ) {
	$storage_blog_id = ec_get_link_page_storage_blog_id();
	if ( ! $storage_blog_id ) {
		return new WP_Error( 'link_page_storage_unavailable', 'The canonical Link Page storage blog is unavailable.' );
	}
	if ( get_current_blog_id() !== $storage_blog_id ) {
		return ec_with_link_page_storage_blog(
			static function () use ( $link_page_id, $request ) {
				return ec_get_link_page_public_projection( $link_page_id, $request );
			}
		);
	}
	$owner = ec_get_link_page_owner( absint( $link_page_id ) );
	if ( is_wp_error( $owner ) ) {
		return $owner;
	}
	$context = array(
		'link_page_id'    => absint( $link_page_id ),
		'owner'           => $owner,
		'owner_reference' => $owner['reference'],
		'public_url'      => ec_get_link_page_public_url( $link_page_id ),
		'request'         => is_array( $request ) ? $request : array(),
	);
	$matches = array();
	foreach ( ec_link_page_public_projection_registry()->snapshot() as $provider ) {
		$result = ec_invoke_link_page_public_projection_callback( $provider['callback'], array( $context ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( null !== $result ) {
			$result = ec_validate_link_page_public_projection( $result );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$matches[] = $result;
		}
	}
	if ( 1 !== count( $matches ) ) {
		return new WP_Error( empty( $matches ) ? 'link_page_public_projection_missing' : 'multiple_link_page_public_projections', empty( $matches ) ? 'No public projection provider is available for the Link Page owner.' : 'Multiple public projection providers claimed the Link Page owner.' );
	}
	$projection = array_merge(
		array(
			'bio'                   => '',
			'profile_img_url'       => '',
			'social_links'          => array(),
			'management_url'        => '',
			'body_attributes'       => array(),
			'seo'                   => array(),
			'tracking_url'          => '',
			'components'            => array(),
			'social_renderer'       => null,
			'assets'                => null,
			'legacy_head_arguments' => array( $context['owner'] ),
			'css_vars'              => array(),
			'body_style'            => '',
		),
		$matches[0]
	);
	if ( $projection['assets'] ) {
		$loaded = ec_invoke_link_page_public_projection_callback( $projection['assets'], array( $context, $projection ) );
		if ( is_wp_error( $loaded ) || false === $loaded ) {
			return is_wp_error( $loaded ) ? $loaded : new WP_Error( 'link_page_public_projection_assets_failed', 'The Link Page owner assets could not be loaded.' );
		}
	}
	$projection['_context'] = $context;
	return $projection;
}

/** Render one validated owner component slot. */
function ec_render_link_page_public_components( $projection, $slot ) {
	$output = '';
	foreach ( $projection['components'][ $slot ] ?? array() as $callback ) {
		$result = ec_invoke_link_page_public_projection_callback( $callback, array( $projection['_context'], $projection ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_string( $result ) ) {
			return new WP_Error( 'invalid_link_page_public_component_result', 'A Link Page public component returned invalid output.' );
		}
		$output .= $result;
	}
	return $output;
}

/** Execute every dynamic public callback before response output begins. */
function ec_prepare_link_page_public_render( $projection, $data ) {
	foreach ( array( 'head', 'body_start', 'before_header', 'header_actions', 'after_header', 'after_links', 'footer' ) as $slot ) {
		$output = ec_render_link_page_public_components( $projection, $slot );
		if ( is_wp_error( $output ) ) {
			return $output;
		}
		$projection['_rendered_components'][ $slot ] = $output;
	}
	foreach ( array( 'above', 'below' ) as $position ) {
		$output = '';
		if ( $projection['social_renderer'] && $projection['social_links'] && $position === $data['settings']['social_icons_position'] ) {
			$output = ec_invoke_link_page_public_projection_callback( $projection['social_renderer'], array( $projection['social_links'], $position, $projection['_context'] ) );
			if ( is_wp_error( $output ) ) {
				return $output;
			}
			if ( ! is_string( $output ) ) {
				return new WP_Error( 'invalid_link_page_public_social_result', 'A Link Page public social renderer returned invalid output.' );
			}
		}
		$projection[ '_rendered_social_' . $position ] = $output;
	}
	return $projection;
}
