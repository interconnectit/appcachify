<?php
/*
Plugin Name: Appcachify
Plugin URI: http://interconnectit.com
Description: Creates an appcache manifest for your static theme files. If your theme has a 307.php temaplte it is used when visitors are offline.
Version: 0.1
Author: Robert O'Rourke
Author URI: http://interconnectit.com
*/

if ( ! class_exists( 'appcachify' ) ) {

	add_action( 'plugins_loaded', array( 'appcachify', 'instance' ) );

	class appcachify {

		/**
		 * @var WP_Theme
		 */
		public $theme;

		/**
		 * @var string Default offline template file name
		 */
		public $offline_file = '307.php';

		/**
		 * @var bool Whether to use offline mode
		 */
		public $offline_mode = false;

		/**
		 * @var array List of file extensions to scan theme for
		 */
		public $extensions;

		/**
		 * @var string Used to buffer output sent before wp_enqueue_scripts hook runs
		 */
		public $output_buffer;

		/**
		 * @var object self
		 */
		protected static $instance = null;

		/**
		* Method to either return or create and return an instance of this
		* class.
		*
		* @return appcachify
		*/
		public static function instance( ) {
			null === self::$instance && self::$instance = new self;
			return self::$instance;
		}

		public function __construct() {

			if ( ! is_admin() )
				add_action( 'wp_footer', array( $this, 'manifest_page_frame' ) );

			add_action( 'template_redirect', array( $this, 'template_redirect' ) );

			$this->theme = wp_get_theme();

			$this->extensions = apply_filters( 'appcachify_extensions', array( 'jpg', 'jpeg', 'png', 'gif', 'svg', 'xml', 'swf' ) );

			if ( file_exists( $this->theme->get_stylesheet_directory() . '/' . $this->offline_file ) )
				$this->offline_mode = true;

		}

		/**
		 * Handles the appcache request on wp_enqueue_scripts
		 * Stops the output buffer, modifies headers and
		 * delivers the manifest page or manifest itself
		 *
		 * @return void
		 */
		public function request() {
			global $wp, $post;

			// prevent output to browser
			$this->output_buffer = ob_get_clean();

			if ( $wp->request == "manifest" ) {


				header( 'HTTP/1.0 200 Ok' );
				header( 'Content-type: text/html' );
				header( 'Cache-Control: no-cache, must-revalidate' );

				$this->manifest_page();
				die;
			}

			if ( $wp->request == "manifest.appcache" ) {

				header( 'HTTP/1.0 200 Ok' );
				header( 'Content-type: text/cache-manifest' );
				header( 'Cache-Control: max-age=3600, must-revalidate' );

				$this->manifest();
				die;
			}

		}

		/**
		 * Captures requests to our manifest URLs
		 *
		 * @param string $template
		 *
		 * @return string
		 */
		public function template_redirect( $template ) {
			global $wp, $post;

			if ( is_404() && $wp->request == "offline" && $this->offline_mode ) {

				header( 'HTTP/1.0 307 Temporary Redirect' );
				header( 'Cache-Control: max-age=3600, must-revalidate' );

				return get_query_template( '307' );
			}

			if ( is_404() && in_array( $wp->request, array( 'manifest', 'manifest.appcache' ) ) ) {

				// start output buffer so we capture queued scripts
				$this->output_buffer = ob_start();

				// add late scripts hook to add registered scripts to appcache
				add_action( 'wp_enqueue_scripts', array( $this, 'request' ), 783921321 );

			}

			return $template;
		}

		/**
		 * Returns the URL to the manifest page or the manifest itself
		 *
		 * @param bool $appcache If true fetches manifest URL
		 * @param bool $echo     Whether to return or echo the URL
		 *
		 * @return string    Manifest page or manifest URL
		 */
		public function manifest_url( $appcache = false, $echo = true ) {
			$url = get_home_url() . '/manifest' . ( $appcache ? '.appcache' : '' );
			if ( $echo )
				echo $url;
			return $url;
		}

		/**
		 * Placeholder page to reference the manifest file
		 *
		 * @return void
		 */
		public function manifest_page() {
			echo '<!DOCTYPE html><html manifest="' . $this->manifest_url( true, false ) . '"><head><title></title></head><body></body></html>';
		}

		/**
		 * Iframe referencing the manifest page
		 *
		 * @return void
		 */
		public function manifest_page_frame() {
			echo '<iframe style="display:none;" src="' . $this->manifest_url( false, false ) . '"></iframe>';
		}

		/**
		 * Get the absolute filesystem path to the root of the WordPress installation
		 *
		 * @since 1.5.0
		 *
		 * @uses get_option
		 * @return string Full filesystem path to the root of the WordPress installation
		 */
		function get_home_path() {
			$home = get_option( 'home' );
			$siteurl = get_option( 'siteurl' );
			if ( ! empty( $home ) && 0 !== strcasecmp( $home, $siteurl ) ) {
				$wp_path_rel_to_home = str_ireplace( $home, '', $siteurl ); /* $siteurl - $home */
				$pos = strripos( str_replace( '\\', '/', $_SERVER['SCRIPT_FILENAME'] ), trailingslashit( $wp_path_rel_to_home ) );
				$home_path = substr( $_SERVER['SCRIPT_FILENAME'], 0, $pos );
				$home_path = trailingslashit( $home_path );
			} else {
				$home_path = ABSPATH;
			}

			return str_replace( '\\', '/', $home_path );
		}

		/**
		 * Attempts to resolve the path to a file from it's URL
		 *
		 * @param string $url
		 *
		 * @return string|bool    File path if successful, false if not
		 */
		public function get_path_from_url( $url ) {

			// is it a local file
			if ( strstr( $url, get_home_url() ) ) {

				// content url/dir replacement
				$file = str_replace( WP_CONTENT_URL, WP_CONTENT_DIR, $url );

				if ( file_exists( $file ) )
					return $file;

			}

			// is it from includes
			if ( strstr( $url, '/wp-includes/' ) ) {

				$file = str_replace( site_url(), $this->get_home_path(), $url );

				if ( file_exists( $file ) )
					return $file;

			}

			return false;
		}

		/**
		 * Returns an array of URLs from a WP_Dependcies instance
		 *
		 * @param WP_Dependencies $assets
		 *
		 * @return array            Array of assets URLs
		 */
		public function get_assets( WP_Dependencies $assets ) {
			$output = array();
			foreach( $assets->queue as $handle ) {
				$output = array_merge( $this->recurse_deps( $assets, $handle ), $output );
			}
			return array_filter( array_unique( $output ) );
		}

		/**
		 * Used to recurse through asset dependencies
		 *
		 * @param WP_Dependencies $assets
		 * @param string         $handle The asset handle
		 *
		 * @return array
		 */
		public function recurse_deps( WP_Dependencies $assets, $handle ) {
			$output = array();
			$output[ $handle ] = preg_replace( '|^/wp-includes/|', includes_url(), $assets->registered[ $handle ]->src );
			foreach( $assets->registered[ $handle ]->deps as $dep ) {
				$output = array_merge( $this->recurse_deps( $assets, $dep ), $output );
			}
			return array_unique( $output );
		}

		/**
		 * Generates the actual manifest file content
		 *
		 * @return void
		 */
		public function manifest() {
			global $wp_scripts, $wp_styles;

			$cache = array();
			$network = array();
			$fallback = array();

			// flag for when to refresh appcached scripts
			$assets_updated = 0;
			$assets_size = 0;

			// get queued js & css
			$cache += $this->get_assets( $wp_scripts );
			$cache += $this->get_assets( $wp_styles );

			if ( $this->offline_mode ) {
				$network = array( '*' );
				$fallback = array( '/ /offline/' );
			}

			$src_dir = $this->theme->get_stylesheet_directory();
			$src_url = $this->theme->get_stylesheet_directory_uri();

			// $assets = $this->process_dir( $src_dir, true );
			$assets = $this->theme->get_files( $this->extensions, 10, false );

			array_walk( $assets, function( &$item, $relative_path ) use ( $src_url, $src_dir ) {
				if ( preg_match( '/screenshot\.(gif|png|jpg|jpeg|bmp)/', $relative_path ) )
					$item = false;
				else
					$item = rtrim( $src_url, '/' ) . '/' . ltrim( $relative_path, '/' );
				} );

			$cache += $assets;

			foreach( $cache as $url ) {
				$filename = $this->get_path_from_url( $url );
				if ( $filename ) {
					$filemtime = filemtime( $filename );
					$assets_updated = $assets_updated < $filemtime ? $filemtime : $assets_updated;
					$assets_size += filesize( $filename );
				}
			}

			foreach( array( 'cache', 'network', 'fallback' ) as $section ) {
				$$section = array_filter( array_unique( apply_filters( "appcache_{$section}", $$section ) ) );
				$$section = implode( "\n", $$section );
			}

			// flag to alter when manifest should be refetched
			$update = implode( "\n# ", array_filter( array(
				'theme' => 'Theme: ' . $this->theme->get_stylesheet() . ' ' . $this->theme->display( 'version', false ),
				'modified' => 'Modified: ' . date( "Y-m-d H:i:s", $assets_updated ),
				'size' => 'Size: ' . number_format( $assets_size/1000, 0 ) . 'kb'
			) ) );

			$update = apply_filters( 'appcache_update_header', $update, $cache, $network, $fallback, $assets_size, $assets_updated );

			echo "CACHE MANIFEST
# $update

";
if ( ! empty( $cache ) ) :
echo "
# Explicitly cached 'master entries'.
CACHE:
$cache

";
endif;
if ( ! empty( $network ) ) :
echo "
# Resources that require the user to be online.
NETWORK:
$network

";
endif;
if ( ! empty( $fallback ) ) :
echo "
# Fallback resources if user is offline
FALLBACK:
$fallback

";
endif;

		}

		/**
		 * Scans a directory recursively and provides hooks to modify files, folders
		 * or both
		 *
		 * @param string $dir       Directory path
		 * @param bool $recursive Whether to traverse directories recursively
		 *
		 * @return array|bool    Array of files and directories or false if $dir not found
		 */
		public function process_dir( $dir, $recursive = false ) {
			if ( is_dir( $dir ) ) {
				for ( $list = array(), $handle = opendir( $dir ); ( false !== ( $file = readdir( $handle ) ) ); ) {
					if ( ( $file != '.' && $file != '..' ) && ( file_exists( $path = $dir . '/' . $file ) ) ) {
						if ( is_dir( $path ) && ( $recursive ) ) {
							$list = array_merge( $list, $this->process_dir( $path, true ) );
						} else {
							$entry = array( 'filename' => $file, 'dirpath' => $dir );

							$entry = apply_filters( 'process_dir_entry', $entry );

							do if ( ! is_dir( $path ) ) {

								$entry = apply_filters( 'process_dir_file', $entry );

								break;
							} else {

								$entry = apply_filters( 'process_dir_directory', $entry );

								break;
							} while ( false );

							$list[] = $entry;
						}
					}
				}
				closedir( $handle );
				return $list;
			} else
				return false;
		}

	}

}
