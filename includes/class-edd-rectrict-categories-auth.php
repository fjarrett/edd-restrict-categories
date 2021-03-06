<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EDD_Restrict_Categories_Auth {

	/**
	 * Class constructor
	 *
	 * @access public
	 */
	public function __construct() {
		// Verify authentication on restricted taxonomies/products
		add_action( 'template_redirect', array( $this, 'authenticate' ) );

		// Check for possible restriction on taxonomy term archives
		add_action( 'template_redirect', array( $this, 'maybe_restrict_tax_term_archive' ) );

		// Check for possible restriction on single products
		add_action( 'template_redirect', array( $this, 'maybe_restrict_post' ) );

		// Omit posts in restricted taxonomies from frontend queries when not authenticated
		add_action( 'pre_get_posts', array( $this, 'maybe_filter_posts' ) );

		// Prevent products in restricted taxonomies from being purchasable when not authenticated
		add_filter( 'edd_cart_contents', array( $this, 'maybe_restrict_cart_contents' ) );
	}

	/**
	 * Verify authentication on restricted taxonomies/products
	 *
	 * @action template_redirect
	 *
	 * @access public
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function authenticate() {
		if (
			empty( $_POST['eddrc_auth_nonce'] )
			||
			empty( $_POST['eddrc_pass'] )
			||
			empty( $_POST['eddrc_taxonomy'] )
			||
			empty( $_POST['eddrc_term_id'] )
			||
			empty( $_POST['_wp_http_referer'] )
		) {
			return;
		}

		$pass     = trim( $_POST['eddrc_pass'] );
		$taxonomy = sanitize_key( $_POST['eddrc_taxonomy'] );
		$term_id  = absint( $_POST['eddrc_term_id'] );
		$redirect = home_url( wp_unslash( remove_query_arg( 'eddrc-auth', $_POST['_wp_http_referer'] ) ) );

		if ( false === wp_verify_nonce( $_POST['eddrc_auth_nonce'], sprintf( 'eddrc_auth_nonce-%s-%d', $taxonomy, $term_id ) ) ) {
			return;
		}

		$_pass = (string) EDD_Restrict_Categories_Term_Meta::get_tax_term_option( $term_id, $taxonomy, 'pass' );

		if ( $pass === $_pass ) {
			/**
			 * Fires after a user has successfully entered the correct password
			 *
			 * @since 1.0.0
			 *
			 * @param string $taxonomy
			 * @param int    $term_id
			 */
			do_action( 'eddrc_password_correct', $taxonomy, $term_id );

			self::set_cookie( $term_id, $taxonomy, $pass );

			wp_safe_redirect( $redirect, 302 );

			exit;
		}

		/**
		 * Fires after a user has entered an incorrect password
		 *
		 * @since 1.0.0
		 *
		 * @param string $taxonomy
		 * @param int    $term_id
		 * @param string $pass
		 */
		do_action( 'eddrc_password_incorrect', $taxonomy, $term_id, $pass );

		$redirect = add_query_arg(
			array(
				'eddrc-auth' => 'incorrect',
			),
			$redirect
		);

		wp_safe_redirect( $redirect, 302 );

		exit;
	}

	/**
	 * Check for possible restriction on taxonomy term archives
	 *
	 * @action template_redirect
	 *
	 * @access public
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybe_restrict_tax_term_archive() {
		if ( ! is_tax() && ! is_category() && ! is_tag() ) {
			return;
		}

		$taxonomy = is_category() ? 'category' : ( is_tag() ? 'post_tag' : get_query_var( 'taxonomy' ) );

		if ( ! in_array( $taxonomy, EDD_Restrict_Categories::$taxonomies ) ) {
			return;
		}

		$term_slug = is_category() ? get_query_var( 'category_name' ) : ( is_tag() ? get_query_var( 'tag' ) : get_query_var( $taxonomy ) );

		if ( empty( $term_slug ) ) {
			return;
		}

		$term = get_term_by( 'slug', $term_slug, $taxonomy );

		if ( ! $term ) {
			return;
		}

		$restricted = (array) get_option( EDD_Restrict_Categories::PREFIX . $taxonomy );

		if ( in_array( $term->term_id, $restricted ) ) {
			self::password_notice( $term->term_id, $taxonomy );
		}
	}

	/**
	 * Check for possible restriction on single products
	 *
	 * @action template_redirect
	 *
	 * @access public
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybe_restrict_post() {
		if ( ! is_singular() ) {
			return;
		}

		$post_id          = get_queried_object_id();
		$restricted_terms = self::get_post_restricted_terms( $post_id );

		if ( empty( $restricted_terms ) ) {
			return;
		}

		foreach ( $restricted_terms as $taxonomy => $terms ) {
			foreach ( $terms as $key => $term_id ) {
				/**
				 * Filter if/when posts in restricted taxonomies should be restricted
				 *
				 * By default, posts that belong to restricted categories are
				 * also restricted when their URL is accessed directly by
				 * unauthenticated users. This behavior can be overridden here
				 * globally or on a more granular post/tax/term basis using the
				 * available params.
				 *
				 * @since 1.0.0
				 *
				 * @param bool   $restrict_post
				 * @param int    $post_id
				 * @param string $taxonomy
				 * @param int    $term_id
				 *
				 * @return bool
				 */
				$restrict_post = (bool) apply_filters( 'eddrc_restrict_post', true, $post_id, $taxonomy, $term_id );

				if ( false === $restrict_post ) {
					unset( $restricted_terms[ $taxonomy ][ $key ] );
				}
			}

			if ( empty( $restricted_terms[ $taxonomy ] ) ) {
				unset( $restricted_terms[ $taxonomy ] );
			}
		}

		if ( empty( $restricted_terms ) ) {
			return;
		}

		$terms   = array_shift( $restricted_terms );
		$term_id = array_shift( $terms );

		self::password_notice( $term_id, $taxonomy );
	}

	/**
	 * Omit posts from restricted taxonomies from main queries when not authenticated
	 *
	 * @action pre_get_posts
	 *
	 * @access public
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybe_filter_posts( $query ) {
		if (
			is_admin()
			||
			! in_array( $query->get( 'post_type' ), EDD_Restrict_Categories::$post_types )
		) {
			return;
		}

		$tax_queries = array();

		foreach ( EDD_Restrict_Categories::$taxonomies as $taxonomy ) {
			$terms  = (array) get_option( EDD_Restrict_Categories::PREFIX . sanitize_key( $taxonomy ) );
			$terms  = array_filter( $terms );
			$_terms = array();

			foreach ( $terms as $term_id ) {
				/**
				 * Filter if/when posts should be hidden from frontend queries
				 *
				 * By default, posts that belong to restricted categories are
				 * filtered out of the frontend query results for unauthenticated
				 * users. This behavior can be overridden here globally or on a
				 * more granular tax/term basis using the available params.
				 *
				 * @since 1.0.0
				 *
				 * @param bool     $hide_posts
				 * @param WP_Query $query
				 * @param string   $taxonomy
				 * @param int      $term_id
				 *
				 * @return bool
				 */
				$hide_posts = (bool) apply_filters( 'eddrc_hide_posts_from_results', true, $query, $taxonomy, $term_id );

				if ( false === $hide_posts ) {
					continue;
				}

				if ( ! self::is_access_granted( $term_id, $taxonomy ) ) {
					$_terms[] = $term_id;
				}
			}

			if ( ! empty( $_terms ) ) {
				$tax_queries[] = array(
					'taxonomy'  => $taxonomy,
					'terms'     => $_terms,
					'operator'  => 'NOT IN'
				);
			}
		}

		// Don't overwrite any existing tax queries
		if ( ! empty( $query->tax_query->queries ) ) {
			$tax_queries = array_merge( (array) $query->tax_query->queries, $tax_queries );
		}

		if ( ! empty( $tax_queries ) ) {
			$query->set( 'tax_query', $tax_queries );
		}
	}

	/**
	 * Prevent products in restricted categories from being purchasable when unauthenticated
	 *
	 * @filter edd_cart_contents
	 *
	 * @access public
	 * @since 1.0.0
	 *
	 * @param array $cart
	 *
	 * @return array|bool
	 */
	public function maybe_restrict_cart_contents( $cart ) {
		if ( empty( $cart ) ) {
			return $cart;
		}

		foreach ( (array) $cart as $key => $item ) {
			if ( $restricted = self::get_post_restricted_terms( $item['id'] ) ) {
				foreach ( $restricted as $taxonomy => $terms ) {
					foreach ( $terms as $term_id ) {
						if ( ! self::is_access_granted( $term_id, $taxonomy ) ) {
							unset( $cart[ $key ] );
							break;
						}
					}
				}
			}
		}

		return $cart;
	}

	/**
	 * Display restriction notice requiring a password to proceed
	 *
	 * @access public
	 * @since 1.0.0
	 * @static
	 *
	 * @return void
	 */
	public static function password_notice( $term_id, $taxonomy ) {
		if ( self::is_access_granted( $term_id, $taxonomy ) ) {
			return;
		}

		$tax_label = EDD_Restrict_Categories::get_tax_label( $taxonomy, 'singular_name' );
		$self_url  = home_url( wp_unslash( remove_query_arg( 'eddrc-auth', $_SERVER['REQUEST_URI'] ) ) );
		$incorrect = ( isset( $_GET['eddrc-auth'] ) && 'incorrect' === $_GET['eddrc-auth'] );

		ob_start();
		?>
		<div style="text-align:center;">
			<?php if ( $incorrect ) : ?>
				<p style="background:#ffe6e5;border:1px solid #ffc5c2;padding:10px;"><strong><?php _e( 'The password you entered is incorrect. Please try again.', 'edd-restrict-categories' ) ?></strong></p>
			<?php endif; ?>
			<h1 style="border:none;"><?php printf( __( 'This is a Restricted %s', 'edd-restrict-categories' ), esc_html( $tax_label ) ) ?></h1>
			<p><?php _e( 'Please enter the password to unlock:', 'edd-restrict-categories' ) ?></p>
			<form method="post" action="<?php echo esc_url( $self_url ) ?>">
				<p><input type="password" name="eddrc_pass" size="30" style="padding:3px 5px;font-size:16px;text-align:center;" autocomplete="off"></p>
				<p>
					<input type="hidden" name="eddrc_taxonomy" value="<?php echo sanitize_key( $taxonomy ) ?>">
					<input type="hidden" name="eddrc_term_id" value="<?php echo absint( $term_id ) ?>">
					<?php wp_nonce_field( sprintf( 'eddrc_auth_nonce-%s-%d', sanitize_key( $taxonomy ), absint( $term_id ) ), 'eddrc_auth_nonce' ) ?>
					<input type="submit" class="button" value="<?php esc_attr_e( 'Continue', 'edd-restrict-categories' ) ?>">
				</p>
			</form>
		</div>
		<?php
		$html = ob_get_clean();

		/**
		 * Fires before the password notice is thrown
		 *
		 * Here you can customize the password notice, perhaps by
		 * redirecting to some custom template or page you have built
		 * rather than using the default wp_die() screen. Or to redirect
		 * somewhere else when the user enters an incorrect password
		 * rather than staying on the password notice screen.
		 *
		 * However, keep in mind that any customizations you do still
		 * need to include the appropriate form nonce and hidden inputs
		 * as used in the default notice HTML.
		 *
		 * @since 1.0.0
		 *
		 * @param string $taxonomy
		 * @param int    $term_id
		 * @param string $self_url
		 * @param bool   $incorrect
		 */
		do_action( 'eddrc_password_notice', $taxonomy, $term_id, $self_url, $incorrect );

		wp_die( $html, sprintf( __( 'This is a Restricted %s', 'edd-restrict-categories' ), esc_html( $tax_label ) ) );
	}

	/**
	 * Set authentication cookie after access has been granted
	 *
	 * @access public
	 * @since 1.0.0
	 * @static
	 *
	 * @return void
	 */
	public static function set_cookie( $term_id, $taxonomy, $pass ) {
		$name = EDD_Restrict_Categories_Term_Meta::get_tax_term_option_name( $term_id, $taxonomy, 'hash' );

		/**
		 * Filter authentication cookie expiration length (in seconds)
		 *
		 * @since 1.0.0
		 *
		 * @param int    $ttl
		 * @param string $taxonomy
		 * @param int    $term_id
		 *
		 * @return int
		 */
		$ttl = absint( apply_filters( 'eddrc_auth_cookie_ttl', HOUR_IN_SECONDS, $taxonomy, $term_id ) );

		setcookie( $name, wp_hash_password( $pass ), time() + $ttl, '/' );
	}

	/**
	 * Check authentication cookie hash for validity before granting access
	 *
	 * @access public
	 * @since 1.0.0
	 * @static
	 *
	 * @param int    $term_id
	 * @param string $taxonomy
	 * @param string $hash
	 *
	 * @return bool
	 */
	public static function is_valid_cookie( $term_id, $taxonomy ) {
		$cookie = EDD_Restrict_Categories_Term_Meta::get_tax_term_option_name( $term_id, $taxonomy, 'hash' );
		$hash   = ! empty( $_COOKIE[ $cookie ] ) ? $_COOKIE[ $cookie ] : null;

		if ( empty( $hash ) ) {
			return false;
		}

		if ( ! class_exists( 'PasswordHash' ) ) {
			require_once ABSPATH . 'wp-includes/class-phpass.php';
		}

		$hasher = new PasswordHash( 8, true );
		$pass   = (string) EDD_Restrict_Categories_Term_Meta::get_tax_term_option( $term_id, $taxonomy, 'pass' );

		return $hasher->CheckPassword( $pass, $hash );
	}

	/**
	 * Returns true when a user's role has been whitelisted for a given taxonomy term
	 *
	 * @access public
	 * @since 1.0.0
	 * @static
	 *
	 * @param int    $term_id
	 * @param string $taxonomy
	 *
	 * @return bool
	 */
	public static function has_whitelisted_role( $term_id, $taxonomy ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$user      = wp_get_current_user();
		$whitelist = (array) EDD_Restrict_Categories_Term_Meta::get_tax_term_option( $term_id, $taxonomy, 'role_whitelist' );
		$roles     = isset( $user->roles ) ? (array) $user->roles : array();
		$intersect = array_intersect( $roles, $whitelist );

		return ! empty( $intersect );
	}

	/**
	 * Returns true when a user has been whitelisted for a given taxonomy term
	 *
	 * @access public
	 * @since 1.0.0
	 * @static
	 *
	 * @param int    $term_id
	 * @param string $taxonomy
	 *
	 * @return bool
	 */
	public static function is_whitelisted_user( $term_id, $taxonomy ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$whitelist = (array) EDD_Restrict_Categories_Term_Meta::get_tax_term_option( $term_id, $taxonomy, 'user_whitelist' );

		return in_array( get_current_user_id(), $whitelist );
	}

	/**
	 * Returns true when a visitor is granted access
	 *
	 * @access public
	 * @since 1.0.0
	 * @static
	 *
	 * @param int    $term_id
	 * @param string $taxonomy
	 *
	 * @return bool
	 */
	public static function is_access_granted( $term_id, $taxonomy ) {
		// Exit early when applicable in rare cases
		// No need to fire the access denied action this early
		// You can't be denied access to something that doesn't exist
		if ( ! term_exists( $term_id, $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}

		if (
			self::is_valid_cookie( $term_id, $taxonomy )
			||
			self::has_whitelisted_role( $term_id, $taxonomy )
			||
			self::is_whitelisted_user( $term_id, $taxonomy )
		) {
			/**
			 * Fires after a visitor has been granted automatic access
			 *
			 * @since 1.0.0
			 *
			 * @param string $taxonomy
			 * @param int    $term_id
			 */
			do_action( 'eddrc_access_granted', $taxonomy, $term_id );

			return true;
		}

		/**
		 * Fires after a visitor has been denied access
		 *
		 * @since 1.0.0
		 *
		 * @param string $taxonomy
		 * @param int    $term_id
		 */
		do_action( 'eddrc_access_denied', $taxonomy, $term_id );

		return false;
	}

	/**
	 * Returns an array of restricted taxonomy terms for a given post
	 *
	 * @access public
	 * @since 1.0.0
	 * @static
	 *
	 * @param mixed $post  Post ID or WP_Post object
	 *
	 * @return array|bool
	 */
	public static function get_post_restricted_terms( $post ) {
		$post_id = isset( $post->ID ) ? $post->ID : absint( $post );

		if (
			empty( $post_id )
			||
			! in_array( get_post_type( $post_id ), EDD_Restrict_Categories::$post_types )
		) {
			return false;
		}

		$output = array();

		foreach ( EDD_Restrict_Categories::$taxonomies as $taxonomy ) {
			$terms = wp_get_post_terms( $post_id, $taxonomy );

			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				continue;
			}

			$terms            = wp_list_pluck( $terms, 'term_id' );
			$restricted_terms = (array) get_option( EDD_Restrict_Categories::PREFIX . $taxonomy );
			$matches          = array_intersect( $terms, $restricted_terms );

			sort( $matches );

			if ( ! empty( $matches[0] ) ) {
				$output[ $taxonomy ] = $matches;
			}
		}

		ksort( $output );

		if ( ! empty( $output ) ) {
			return (array) $output;
		}

		return false;
	}

}

new EDD_Restrict_Categories_Auth();
