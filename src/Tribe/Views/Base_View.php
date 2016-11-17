<?php
class Tribe__Views__Base_View {
	protected $data       = array();
	protected $generated  = false;
	protected $output     = '';
	protected $properties = array();
	protected $slug       = '';

	/**
	 * Static variable that holds array of vendor script handles, for adding to later deps.
	 *
	 * @static
	 * @var array
	 */
	protected static $vendor_scripts = array();

	/**
	 * @param string     $slug
	 * @param array      $properties
	 * @param array|null $data
	 */
	public function __construct( $slug, array $properties = array(), array $data = array() ) {
		$this->slug = $slug;
		$this->set_properties( $properties );

		if ( null !== $this->data ) {
			$this->set_data( $data );
		}

		$this->assets();
		$this->hook();
	}

	/**
	 * Sets the view properties, which define a variety of different things
	 * including the rewrite slug and which templates and asset packages to
	 * load.
	 *
	 * @param array $properties
	 */
	public function set_properties( array $properties ) {
		// Properties pre-defined in the subclass have the lowest priority and
		// can be overridden with properties defined during registration
		$this->properties = array_merge(
			$this->properties,
			(array) tribe( 'tec.views' )->get_view_definition( $this->slug ),
			$properties
		);

		// If the outer and inner template properties have not been defined then
		// assume reasonable defaults of {slug}.php for the outer wrapper and
		// {slug}/content.php for the inner template that can be served without
		// any wrapper (ie, during ajax requests)
		if ( empty( $this->properties['outer_template'] ) ) {
			$this->properties['outer_template'] = $this->slug;
		}

		if ( empty( $this->properties['inner_template'] ) ) {
			$this->properties['inner_template'] = $this->slug . DIRECTORY_SEPARATOR . 'content';
		}
	}

	/**
	 * Sets the data available to the view.
	 *
	 * If $data contains a 'query' key, then that element ought to contain a WP_Query
	 * object (and that will be used to populate the view).
	 *
	 * If $data contains a 'posts' key, then that element ought to contain an array of
	 * WP_Post objects (and those will be used to populate the view).
	 *
	 * @param array $data
	 */
	public function set_data( array $data = array() ) {
		$this->data = $data;
		$this->set_query();
	}

	/**
	 * Sets the WP_Query object to be used when rendering the view.
	 *
	 * If this is not available or hasn't been set it will fall back on the global
	 * $wp_query object (and if this is not available, returns a new instance of
	 * WP_Query).
	 *
	 * @param WP_Query $query
	 */
	public function set_query( WP_Query $query = null ) {
		global $wp_query;

		// Use $query if provided
		if ( null !== $query ) {
			$this->data['query'] = $query;
		}

		// If the query has already been set then reset the posts array and bail
		if ( ! empty( $this->data['query'] ) && is_a( $this->data['query'], 'WP_Query' ) ) {
			$this->reset_posts();
			return;
		}

		// Fallback: if $query wasn't provided and the global $wp_query isn't set, generate a blank WP_Query object
		if ( ! is_a( $wp_query, 'WP_Query' ) ) {
			$this->data['query'] = new WP_Query;
		}

		// Set the query and reset the posts array
		$this->data['query'] = $wp_query;
		$this->reset_posts();
	}

	/**
	 * Resets the posts array to whatever is contained in the query object.
	 */
	protected function reset_posts() {
		unset( $this->data['posts'] );
		$this->set_posts();
	}

	/**
	 * Sets the array of posts to be displayed.
	 *
	 * If a set hasn't explicitly been passed to the view via set_data() then it will
	 * utilize the current query object.
	 *
	 * @param WP_Post[] $posts
	 */
	public function set_posts( array $posts = null ) {
		// If for any reason the query object has not yet been set, do that now
		if ( empty( $this->data['query'] ) ) {
			$this->set_query();
		}

		// If $posts was provided, then use that array
		if ( null !== $posts ) {
			$this->data['posts'] = $posts;
			$this->update_query_post_data();
		}

		// Otherwise, if we still don't have an array of posts, extract them from the query object
		if ( ! isset( $this->data['posts'] ) || ! is_array( $this->data['posts'] ) ) {
			$this->data['posts'] = (array) $this->data['query']->posts;
		}
	}

	/**
	 * @return string
	 */
	public function get_slug() {
		return $this->slug;
	}

	/**
	 * @return array
	 */
	public function get_posts() {
		return (array) $this->data['posts'];
	}

	/**
	 * @return int
	 */
	public function get_post_count() {
		return count( $this->get_posts() );
	}

	/**
	 * Returns the specified property (or null if it does not exist).
	 *
	 * @param string $name
	 *
	 * @return mixed|null
	 */
	public function get_property( $name ) {
		return isset( $this->properties[ $name ] ) ? $this->properties[ $name ] : null;
	}

	/**
	 * When required, can be used to update the query object's post array and post count.
	 */
	protected function update_query_post_data() {
		$this->data['query']->posts = $this->data['posts'];
		$this->data['query']->post_count = count( $this->data['posts'] );
		$this->data['query']->rewind_posts();
	}

	/**
	 * Subclasses should override as required to perform setup work.
	 */
	public function hook() {}

	/**
	 * Registers and enqueues any assets required for proper rendering of the view.
	 */
	public function assets() {
		if ( empty( $this->properties[ 'asset_packages'] ) ) {
			return;
		}

		foreach ( $this->properties[ 'asset_packages'] as $asset_package ) {
			$plugin = Tribe__Events__Main::instance();
			$prefix = 'tribe-events'; // Tribe__Events__Main::POSTTYPE;
			$vendor_url = trailingslashit( $plugin->plugin_url ) . 'vendor/';

			/**
			 * @var Tribe__Events__Asset__Abstract_Asset $asset
			 */
			$asset = Tribe__Events__Asset__Factory::instance()->make_for_name( $asset_package );

			if ( ! $asset ) {
				do_action( $prefix . '-' . $asset );
				return;
			}

			$asset->set_name( $asset_package );
			$asset->set_vendor_url( $vendor_url );
			$asset->set_prefix( $prefix );
			$asset->set_tec( $plugin );
			$asset->handle();
		}
	}

	/**
	 * Handles an asset package request.
	 *
	 * @param string              $name          The asset name in the `hyphen-separated-format`
	 * @param array               $deps          An array of dependency handles
	 * @param string              $vendor_url    URL to vendor scripts and styles dir
	 * @param string              $prefix        MT script and style prefix
	 * @param Tribe__Main         $tec           An instance of the main plugin class
	 */
	protected static function handle_asset_package_request( $name, $deps, $vendor_url, $prefix, $tec ) {
		$asset = self::get_asset_factory_instance( $name );
		self::prepare_asset_package_request( $asset, $name, $deps, $vendor_url, $prefix, $tec );
	}

	/**
	 * initializes asset package request
	 *
	 * @param object              $asset         The Tribe__*Asset object
	 * @param string              $name          The asset name in the `hyphen-separated-format`
	 * @param array               $deps          An array of dependency handles
	 * @param string              $vendor_url    URL to vendor scripts and styles dir
	 * @param string              $prefix        MT script and style prefix
	 * @param Tribe__Main         $common        An instance of the main plugin class
	 */
	protected static function prepare_asset_package_request( $asset, $name, $deps, $vendor_url, $prefix, $common ) {
		if ( ! $asset ) {
			do_action( $prefix . '-' . $name );

			return;
		}

		$asset->set_name( $name );
		$asset->set_deps( $deps );
		$asset->set_vendor_url( $vendor_url );
		$asset->set_prefix( $prefix );
		$asset->set_tec( $common );

		$asset->handle();
	}

	/**
	 * Retrieves the appropriate asset factory instance
	 */
	protected static function get_asset_factory_instance( $name ) {
		$asset = Tribe__Asset__Factory::instance()->make_for_name( $name );
		return $asset;
	}

	/**
	 * @param string $script_handle A registered script handle.
	 */
	public static function add_vendor_script( $script_handle ) {
		if ( in_array( $script_handle, self::$vendor_scripts ) ) {
			return;
		}
		self::$vendor_scripts[] = $script_handle;
	}

	/**
	 * @return string[] An array of registered vendor script handles.
	 */
	public static function get_vendor_scripts() {
		return self::$vendor_scripts;
	}

	/**
	 * Asset calls for vendor packages
	 *
	 * @param string $name
	 * @param array  $deps Dependents
	 */
	public static function asset_package( $name, $deps = array() ) {

		$common = Tribe__Main::instance();
		$prefix = 'tribe-events';

		// setup plugin resources & 3rd party vendor urls
		$vendor_url = trailingslashit( $common->plugin_url ) . 'vendor/';

		self::handle_asset_package_request( $name, $deps, $vendor_url, $prefix, $common );
	}

	/**
	 * Returns the path to a minified version of a js or css file, if it exists.
	 * If the file does not exist, returns false.
	 *
	 * @param string $url                 The path or URL to the un-minified file.
	 * @param bool   $default_to_original Whether to just return original path if min version not found.
	 *
	 * @return string|false The path/url to minified version or false, if file not found.
	 */
	public static function getMinFile( $url, $default_to_original = false ) {
		if ( ! defined( 'SCRIPT_DEBUG' ) || SCRIPT_DEBUG === false ) {
			if ( substr( $url, - 3, 3 ) == '.js' ) {
				$url_new = substr_replace( $url, '.min', - 3, 0 );
			}
			if ( substr( $url, - 4, 4 ) == '.css' ) {
				$url_new = substr_replace( $url, '.min', - 4, 0 );
			}
		}

		if ( isset( $url_new ) && file_exists( str_replace( WP_CONTENT_URL, WP_CONTENT_DIR, $url_new ) ) ) {
			return $url_new;
		} elseif ( $default_to_original ) {
			return $url;
		} else {
			return false;
		}
	}

	/**
	 * Playing ping-pong with WooCommerce. They keep changing their script.
	 *
	 * @see https://github.com/woothemes/woocommerce/issues/3623
	 */
	public static function get_placeholder_handle() {
		$placeholder_handle = 'jquery-placeholder';
		global $woocommerce;

		if (
			class_exists( 'Woocommerce' )
			&& version_compare( $woocommerce->version, '2.0.11', '>=' )
			&& version_compare( $woocommerce->version, '2.0.13', '<=' )
		) {
			$placeholder_handle = 'tribe-placeholder';
		}

		return $placeholder_handle;
	}

	public function generate() {
		$outer_or_inner = Tribe__Main::instance()->doing_ajax() ? 'inner_template' : 'outer_template';
		$template = Tribe__Events__Views__Template::locate( $this->get_property( $outer_or_inner ) );

		if ( ! $template ) {
			return;
		}

		/**
		 * Fires immediately prior to the inclusion of the specified template.
		 *
		 * @param string $template
		 */
		do_action( 'tribe_view_pre_render', $template );

		ob_start();
		include $template;
		$this->output = ob_get_clean();

		/**
		 * Fires immediately after the inclusion of the specified template.
		 *
		 * @param string $template
		 */
		do_action( 'tribe_view_post_render', $template );

		$this->generated = true;
	}

	/**
	 * Returns the view output.
	 *
	 * This will generally be, but is not limited to, HTML.
	 *
	 * @return mixed
	 */
	public function get_output() {
		if ( ! $this->generated ) {
			$this->generate();
		}
		return $this->output;
	}
}