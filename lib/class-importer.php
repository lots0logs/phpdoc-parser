<?php

namespace WP_Parser;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

/**
 * Handles creating and updating posts from (functions|classes|files) generated by phpDoc.
 */
class Importer implements LoggerAwareInterface {

	use LoggerAwareTrait;

	/**
	 * Taxonomy name for files
	 *
	 * @var string
	 */
	public $taxonomy_file;

	/**
	 * Taxonomy name for an item's namespace tags
	 *
	 * @var string
	 */
	public $taxonomy_namespace;

	/**
	 * Taxonomy name for an item's @since tag
	 *
	 * @var string
	 */
	public $taxonomy_since_version;

	/**
	 * Taxonomy name for an item's @package/@subpackage tags
	 *
	 * @var string
	 */
	public $taxonomy_package;

	/**
	 * Post type name for functions
	 *
	 * @var string
	 */
	public $post_type_function;

	/**
	 * Post type name for classes
	 *
	 * @var string
	 */
	public $post_type_class;

	/**
	 * Post type name for methods
	 *
	 * @var string
	 */
	public $post_type_method;

	/**
	 * Post type name for hooks
	 *
	 * @var string
	 */
	public $post_type_hook;

	/**
	 * Handy store for meta about the current item being imported
	 *
	 * @var array
	 */
	public $file_meta = array();

	/**
	 * @var array Human-readable errors
	 */
	public $errors = array();

	/**
	 * @var array Cached items of inserted terms
	 */
	protected $inserted_terms = array();

	/**
	 * Constructor. Sets up post type/taxonomy names.
	 *
	 * @param array $args Optional. Associative array; class property => value.
	 */
	public function __construct( array $args = array() ) {

		$properties = wp_parse_args(
			$args,
			array(
				'post_type_class'        => 'wp-parser-class',
				'post_type_method'       => 'wp-parser-method',
				'post_type_function'     => 'wp-parser-function',
				'post_type_hook'         => 'wp-parser-hook',
				'taxonomy_file'          => 'wp-parser-source-file',
				'taxonomy_namespace'     => 'wp-parser-namespace',
				'taxonomy_package'       => 'wp-parser-package',
				'taxonomy_since_version' => 'wp-parser-since',
			)
		);

		foreach ( $properties as $property_name => $value ) {
			$this->{$property_name} = $value;
		}

		$this->logger = new NullLogger();
	}

	/**
	 * Import the PHPDoc $data into WordPress posts and taxonomies
	 *
	 * @param array $data
	 * @param bool  $skip_sleep               Optional; defaults to false. If true, the sleep() calls are skipped.
	 * @param bool  $import_ignored_functions Optional; defaults to false. If true, functions marked `@ignore` will be imported.
	 */
	public function import( array $data, $skip_sleep = false, $import_ignored_functions = false ) {
		global $wpdb;

		$time_start = microtime(true);
		$num_queries = $wpdb->num_queries;

		$this->logger->info( 'Starting import. This will take some time…' );

		$file_number  = 1;
		$num_of_files = count( $data );

		do_action( 'wp_parser_starting_import' );

		// Defer term counting for performance
		wp_suspend_cache_invalidation( true );
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );

		// Remove actions for performance
		remove_action( 'transition_post_status', '_update_blog_date_on_post_publish', 10 );
		remove_action( 'transition_post_status', '__clear_multi_author_cache', 10 );

		delete_option( 'wp_parser_imported_wp_version' );
		delete_option( 'wp_parser_root_import_dir' );

		// Sanity check -- do the required post types exist?
		if ( ! post_type_exists( $this->post_type_class ) || ! post_type_exists( $this->post_type_function ) || ! post_type_exists( $this->post_type_hook ) ) {
			$this->logger->error( sprintf( 'Missing post type; check that "%1$s", "%2$s", and "%3$s" are registered.', $this->post_type_class, $this->post_type_function, $this->post_type_hook ) );
			exit;
		}

		// Sanity check -- do the required taxonomies exist?
		if ( ! taxonomy_exists( $this->taxonomy_file ) || ! taxonomy_exists( $this->taxonomy_since_version ) || ! taxonomy_exists( $this->taxonomy_package ) ) {
			$this->logger->error( sprintf( 'Missing taxonomy; check that "%1$s" is registered.', $this->taxonomy_file ) );
			exit;
		}

		$root = '';
		foreach ( $data as $file ) {
			$this->logger->info( sprintf( 'Processing file %1$s of %2$s "%3$s".', number_format_i18n( $file_number ), number_format_i18n( $num_of_files ), $file['path'] ) );
			$file_number ++;

			$this->import_file( $file, $skip_sleep, $import_ignored_functions );

			if ( empty( $root ) && ( isset( $file['root'] ) && $file['root'] ) ) {
				$root = $file['root'];
			}
		}

		if ( ! empty( $root ) ) {
			update_option( 'wp_parser_root_import_dir', $root );
			$this->logger->info( 'Updated option wp_parser_root_import_dir: ' . $root );
		}

		$last_import = time();
		$import_date = date_i18n( get_option('date_format'), $last_import );
		$import_time = date_i18n( get_option('time_format'), $last_import );
		update_option( 'wp_parser_last_import', $last_import );
		$this->logger->info( sprintf( 'Updated option wp_parser_last_import: %1$s at %2$s.', $import_date, $import_time ) );

		$wp_version = get_option( 'wp_parser_imported_wp_version' );
		if ( $wp_version ) {
			$this->logger->info( 'Updated option wp_parser_imported_wp_version: ' . $wp_version );
		}

		/**
		 * Workaround for a WP core bug where hierarchical taxonomy caches are not being cleared
		 *
		 * https://core.trac.wordpress.org/ticket/14485
		 * http://wordpress.stackexchange.com/questions/8357/inserting-terms-in-an-hierarchical-taxonomy
		 */
		delete_option( "{$this->taxonomy_package}_children" );
		delete_option( "{$this->taxonomy_since_version}_children" );

		/**
		 * Action at the end of a complete import
		 */
		do_action( 'wp_parser_ending_import' );

		// Start counting again
		wp_defer_term_counting( false );
		wp_suspend_cache_invalidation( false );
		wp_cache_flush();
		wp_defer_comment_counting( false );

		$time_end = microtime(true);
		$time = $time_end - $time_start;

		$this->logger->info( 'Time: '.$time );
		$this->logger->info( 'Queries: ' . ( $wpdb->num_queries - $num_queries ) );
		if ( empty( $this->errors ) ) {
			$this->logger->notice( 'Import complete!' );

		} else {
			$this->logger->info( 'Import complete, but some errors were found:' );

			foreach ( $this->errors as $error ) {
				$this->logger->error( $error );
			}
		}
	}

	/**
	 * @param int|string $term
	 * @param string     $taxonomy
	 * @param array      $args
	 *
	 * @return array|mixed|\WP_Error
	 */
	protected function insert_term( $term, $taxonomy, $args = array() ) {
		$parent = isset( $args['parent'] ) ? $args['parent'] : 0;

		if ( isset( $this->inserted_terms[ $taxonomy ][ $term . $parent ] ) ) {
			return $this->inserted_terms[ $taxonomy ][ $term . $parent ];
		}


		if ( ! $inserted_term = term_exists( $term, $taxonomy, $parent ) ) {
			$inserted_term = wp_insert_term( $term, $taxonomy, $args );
		}

		if ( ! is_wp_error( $inserted_term ) ) {
			$this->inserted_terms[ $taxonomy ][ $term . $parent ] = $inserted_term;
		}

		return $inserted_term;
	}

	/**
	 * For a specific file, go through and import the file, functions, and classes.
	 *
	 * @param array $file
	 * @param bool  $skip_sleep     Optional; defaults to false. If true, the sleep() calls are skipped.
	 * @param bool  $import_ignored Optional; defaults to false. If true, functions and classes marked `@ignore` will be imported.
	 */
	public function import_file( array $file, $skip_sleep = false, $import_ignored = false ) {

		/**
		 * Filter whether to proceed with importing a prospective file.
		 *
		 * Returning a falsey value to the filter will short-circuit processing of the import file.
		 *
		 * @param bool  $display         Whether to proceed with importing the file. Default true.
		 * @param array $file            File data
		 */
		if ( ! apply_filters( 'wp_parser_pre_import_file', true, $file ) )
			return;

		// Maybe add this file to the file taxonomy
		$slug = sanitize_title( str_replace( '/', '_', $file['path'] ) );

		$term = $this->insert_term( $file['path'], $this->taxonomy_file, array( 'slug' => $slug ) );

		if ( is_wp_error( $term ) ) {
			$this->errors[] = sprintf( 'Problem creating file tax item "%1$s" for %2$s: %3$s', $slug, $file['path'], $term->get_error_message() );
			return;
		}

		// Detect deprecated file
		$deprecated_file = false;
		if ( isset( $file['uses']['functions'] ) ) {
			$first_function = $file['uses']['functions'][0];

			// If the first function in this file is _deprecated_function
			if ( '_deprecated_file' === $first_function['name'] ) {

				// Set the deprecated flag to the version number
				$deprecated_file = $first_function['deprecation_version'];
			}
		}

		// Store file meta for later use
		$this->file_meta = array(
			'docblock'   => apply_filters( 'wp_parser_file_dockblock', $file['file'] ), // File docblock
			'term_id'    => $file['path'], // Term name in the file taxonomy is the file name
			'deprecated' => $deprecated_file, // Deprecation status
		);

		// TODO ensures values are set, but better handled upstream later
		$file = array_merge( array(
			'functions' => array(),
			'classes'   => array(),
			'hooks'     => array(),
		), $file );

		$count = 0;

		foreach ( $file['functions'] as $function ) {
			$this->import_function( $function, 0, $import_ignored );
			$count ++;

			if ( ! $skip_sleep && 0 == $count % 10 ) { // TODO figure our why are we still doing this
				sleep( 3 );
			}
		}

		foreach ( $file['classes'] as $class ) {
			$this->import_class( $class, $import_ignored );
			$count ++;

			if ( ! $skip_sleep && 0 == $count % 10 ) {
				sleep( 3 );
			}
		}

		foreach ( $file['hooks'] as $hook ) {
			$this->import_hook( $hook, 0, $import_ignored );
			$count ++;

			if ( ! $skip_sleep && 0 == $count % 10 ) {
				sleep( 3 );
			}
		}

		if ( 'wp-includes/version.php' === $file['path'] ) {
			$this->import_version( $file );
		}
	}

	/**
	 * Create a post for a function
	 *
	 * @param array $data           Function.
	 * @param int   $parent_post_id Optional; post ID of the parent (class or function) this item belongs to. Defaults to zero (no parent).
	 * @param bool  $import_ignored Optional; defaults to false. If true, functions marked `@ignore` will be imported.
	 *
	 * @return bool|int Post ID of this function, false if any failure.
	 */
	public function import_function( array $data, $parent_post_id = 0, $import_ignored = false ) {
		$function_id = $this->import_item( $data, $parent_post_id, $import_ignored );

		foreach ( $data['hooks'] as $hook ) {
			$this->import_hook( $hook, $function_id, $import_ignored );
		}
	}

	/**
	 * Create a post for a hook
	 *
	 * @param array $data           Hook.
	 * @param int   $parent_post_id Optional; post ID of the parent (function) this item belongs to. Defaults to zero (no parent).
	 * @param bool  $import_ignored Optional; defaults to false. If true, hooks marked `@ignore` will be imported.
	 * @return bool|int Post ID of this hook, false if any failure.
	 */
	public function import_hook( array $data, $parent_post_id = 0, $import_ignored = false ) {
		/**
		 * Filter whether to skip parsing duplicate hooks.
		 *
		 * "Duplicate hooks" are characterized in WordPress core by a preceding DocBlock comment
		 * including the phrases "This action is documented in" or "This filter is documented in".
		 *
		 * Passing a truthy value will skip the parsing of duplicate hooks.
		 *
		 * @param bool $skip Whether to skip parsing duplicate hooks. Default false.
		 */
		$skip_duplicates = apply_filters( 'wp_parser_skip_duplicate_hooks', false );

		if ( false !== $skip_duplicates ) {
			if ( 0 === strpos( $data['doc']['description'], 'This action is documented in' ) ) {
				return false;
			}

			if ( 0 === strpos( $data['doc']['description'], 'This filter is documented in' ) ) {
				return false;
			}

			if ( '' === $data['doc']['description'] && '' === $data['doc']['long_description'] ) {
				return false;
			}
		}

		$hook_id = $this->import_item( $data, $parent_post_id, $import_ignored, array( 'post_type' => $this->post_type_hook ) );

		if ( ! $hook_id ) {
			return false;
		}

		update_post_meta( $hook_id, '_wp-parser_hook_type', $data['type'] );

		return $hook_id;
	}

	/**
	 * Create a post for a class
	 *
	 * @param array $data           Class.
	 * @param bool  $import_ignored Optional; defaults to false. If true, functions marked `@ignore` will be imported.
	 * @return bool|int Post ID of this function, false if any failure.
	 */
	protected function import_class( array $data, $import_ignored = false ) {

		// Insert this class
		$class_id = $this->import_item( $data, 0, $import_ignored, array( 'post_type' => $this->post_type_class ) );

		if ( ! $class_id ) {
			return false;
		}

		// Set class-specific meta
		update_post_meta( $class_id, '_wp-parser_final', (string) $data['final'] );
		update_post_meta( $class_id, '_wp-parser_abstract', (string) $data['abstract'] );
		update_post_meta( $class_id, '_wp-parser_extends', $data['extends'] );
		update_post_meta( $class_id, '_wp-parser_implements', $data['implements'] );
		update_post_meta( $class_id, '_wp-parser_properties', $data['properties'] );

		// Now add the methods
		foreach ( $data['methods'] as $method ) {
			// Namespace method names with the class name
			$method['name'] = $data['name'] . '::' . $method['name'];
			$this->import_method( $method, $class_id, $import_ignored );
		}

		return $class_id;
	}

	/**
	 * Create a post for a class method.
	 *
	 * @param array $data           Method.
	 * @param int   $parent_post_id Optional; post ID of the parent (class) this
	 *                              method belongs to. Defaults to zero (no parent).
	 * @param bool  $import_ignored Optional; defaults to false. If true, functions
	 *                              marked `@ignore` will be imported.
	 * @return bool|int Post ID of this function, false if any failure.
	 */
	protected function import_method( array $data, $parent_post_id = 0, $import_ignored = false ) {

		// Insert this method.
		$method_id = $this->import_item( $data, $parent_post_id, $import_ignored, array( 'post_type' => $this->post_type_method ) );

		if ( ! $method_id ) {
			return false;
		}

		// Set method-specific meta.
		update_post_meta( $method_id, '_wp-parser_final', (string) $data['final'] );
		update_post_meta( $method_id, '_wp-parser_abstract', (string) $data['abstract'] );
		update_post_meta( $method_id, '_wp-parser_static', (string) $data['static'] );
		update_post_meta( $method_id, '_wp-parser_visibility', $data['visibility'] );

		// Now add the hooks.
		if ( ! empty( $data['hooks'] ) ) {
			foreach ( $data['hooks'] as $hook ) {
				$this->import_hook( $hook, $method_id, $import_ignored );
			}
		}

		return $method_id;
	}

	/**
	 * Updates the 'wp_parser_imported_wp_version' option with the version from wp-includes/version.php.
	 *
	 * @param array   $data Data
	 */
	protected function import_version( $data ) {

		$version_path = $data['root'] . '/' . $data['path'];

		if ( ! is_readable( $version_path ) ) {
			return;
		}

		include $version_path;

		if ( isset( $wp_version ) && $wp_version ) {
			update_option( 'wp_parser_imported_wp_version', $wp_version );
			$this->logger->info( "\t" . sprintf( 'Updated option wp_parser_imported_wp_version to "%1$s"', $wp_version ) );
		}
	}

	/**
	 * Create a post for an item (a class or a function).
	 *
	 * Anything that needs to be dealt identically for functions or methods should go in this function.
	 * Anything more specific should go in either import_function() or import_class() as appropriate.
	 *
	 * @param array $data           Data.
	 * @param int   $parent_post_id Optional; post ID of the parent (class or function) this item belongs to. Defaults to zero (no parent).
	 * @param bool  $import_ignored Optional; defaults to false. If true, functions or classes marked `@ignore` will be imported.
	 * @param array $arg_overrides  Optional; array of parameters that override the defaults passed to wp_update_post().
	 *
	 * @return bool|int Post ID of this item, false if any failure.
	 */
	public function import_item( array $data, $parent_post_id = 0, $import_ignored = false, array $arg_overrides = array() ) {

		/** @var \wpdb $wpdb */
		global $wpdb;

		$is_new_post = true;
		$ns_name     = ( empty( $data['namespace'] ) || 'global' === $data['namespace'] ) ? $data['name'] :  $data['namespace'] . '\\' . $data['name'];
		$slug        = sanitize_title( str_replace( '\\', '-', str_replace( '::', '-', $ns_name ) ) );

		$post_data   = wp_parse_args(
			$arg_overrides,
			array(
				'post_content' => $data['doc']['long_description'],
				'post_excerpt' => $data['doc']['description'],
				'post_name'    => $slug,
				'post_parent'  => (int) $parent_post_id,
				'post_status'  => 'publish',
				'post_title'   => $data['name'],
				'post_type'    => $this->post_type_function,
			)
		);

		// Don't import items marked `@ignore` unless explicitly requested. See https://github.com/WordPress/phpdoc-parser/issues/16
		if ( ! $import_ignored && wp_list_filter( $data['doc']['tags'], array( 'name' => 'ignore' ) ) ) {

			switch ( $post_data['post_type'] ) {
				case $this->post_type_class:
					$this->logger->info( "\t" . sprintf( 'Skipped importing @ignore-d class "%1$s"', $ns_name ) );
					break;

				case $this->post_type_method:
					$this->logger->info( "\t\t" . sprintf( 'Skipped importing @ignore-d method "%1$s"', $ns_name ) );
					break;

				case $this->post_type_hook:
					$indent = ( $parent_post_id ) ? "\t\t" : "\t";
					$this->logger->info( $indent . sprintf( 'Skipped importing @ignore-d hook "%1$s"', $ns_name ) );
					break;

				default:
					$this->logger->info( "\t" . sprintf( 'Skipped importing @ignore-d function "%1$s"', $ns_name ) );
			}

			return false;
		}

		if ( wp_list_filter( $data['doc']['tags'], array( 'name' => 'ignore' ) ) ) {
			return false;
		}

		/**
		 * Filter whether to proceed with adding/updating a prospective import item.
		 *
		 * Returning a falsey value to the filter will short-circuit addition of the import item.
		 *
		 * @param bool  $display         Whether to proceed with adding/updating the import item. Default true.
		 * @param array $data            Data
		 * @param int   $parent_post_id  Optional; post ID of the parent (class or function) this item belongs to. Defaults to zero (no parent).
		 * @param bool  $import_ignored Optional; defaults to false. If true, functions or classes marked `@ignore` will be imported.
		 * @param array $arg_overrides   Optional; array of parameters that override the defaults passed to wp_update_post().
		 */
		if ( ! $data = apply_filters( 'wp_parser_pre_import_item', true, $data, $parent_post_id, $import_ignored, $arg_overrides ) ) {
			return false;
		}

		// Look for an existing post for this item
		$existing_post_id = $wpdb->get_var(
			$q = $wpdb->prepare(
				"SELECT ID FROM $wpdb->posts WHERE post_name = %s AND post_type = %s AND post_parent = %d LIMIT 1",
				$slug,
				$post_data['post_type'],
				(int) $parent_post_id
			)
		);

		/**
		 * Filter an import item's post data before it is updated or inserted.
		 *
		 * @param array       $post_data        Array of post data.
		 * @param string|null $existing_post_id ID if the post already exists, null otherwise.
		 */
		$post_data = apply_filters( 'wp_parser_import_item_post_data', $post_data, $existing_post_id );

		// Insert/update the item post
		if ( ! empty( $existing_post_id ) ) {
			$is_new_post     = false;
			$post_id = $post_data['ID'] = (int) $existing_post_id;
			$post_needed_update = array_diff_assoc( sanitize_post( $post_data, 'db' ), get_post( $existing_post_id, ARRAY_A, 'db' ) );
			if ( $post_needed_update ) {
				$post_id = wp_update_post( wp_slash( $post_data ), true );
			}
		} else {
			$post_id = wp_insert_post( wp_slash( $post_data ), true );
		}
		$anything_updated = array();

		if ( ! $post_id || is_wp_error( $post_id ) ) {

			switch ( $post_data['post_type'] ) {
				case $this->post_type_class:
					$this->errors[] = "\t" . sprintf( 'Problem inserting/updating post for class "%1$s"', $ns_name, $post_id->get_error_message() );
					break;

				case $this->post_type_method:
					$this->errors[] = "\t\t" . sprintf( 'Problem inserting/updating post for method "%1$s"', $ns_name, $post_id->get_error_message() );
					break;

				case $this->post_type_hook:
					$indent = ( $parent_post_id ) ? "\t\t" : "\t";
					$this->errors[] = $indent . sprintf( 'Problem inserting/updating post for hook "%1$s"', $ns_name, $post_id->get_error_message() );
					break;

				default:
					$this->errors[] = "\t" . sprintf( 'Problem inserting/updating post for function "%1$s"', $ns_name, $post_id->get_error_message() );
			}

			return false;
		}

		$namespaces = ( ! empty( $data['namespace'] ) ) ? explode( '\\', $data['namespace'] ) : array();
		$this->_set_namespaces( $post_id, $namespaces );

		// If the item has @since markup, assign the taxonomy
		$since_versions = wp_list_filter( $data['doc']['tags'], array( 'name' => 'since' ) );
		if ( ! empty( $since_versions ) ) {

			// Loop through all @since versions.
			foreach ( $since_versions as $since_version ) {

				if ( ! empty( $since_version['content'] ) ) {
					$since_term = $this->insert_term( $since_version['content'], $this->taxonomy_since_version );

					// Assign the tax item to the post
					if ( ! is_wp_error( $since_term ) ) {
						$added_term_relationship = did_action( 'added_term_relationship' );
						wp_set_object_terms( $post_id, (int) $since_term['term_id'], $this->taxonomy_since_version, true );
						if ( did_action( 'added_term_relationship' ) > $added_term_relationship ) {
							$anything_updated[] = true;
						}
					} else {
						$this->logger->warning( "\tCannot set @since term: " . $since_term->get_error_message() );
					}
				}
			}
		}

		$packages = array(
			'main' => wp_list_filter( $data['doc']['tags'], array( 'name' => 'package' ) ),
			'sub'  => wp_list_filter( $data['doc']['tags'], array( 'name' => 'subpackage' ) ),
		);

		// If the @package/@subpackage is not set by the individual function or class, get it from the file scope
		if ( empty( $packages['main'] ) ) {
			$packages['main'] = wp_list_filter( $this->file_meta['docblock']['tags'], array( 'name' => 'package' ) );
		}

		if ( empty( $packages['sub'] ) ) {
			$packages['sub'] = wp_list_filter( $this->file_meta['docblock']['tags'], array( 'name' => 'subpackage' ) );
		}

		$main_package_id   = false;
		$package_term_ids = array();

		// If the item has any @package/@subpackage markup (or has inherited it from file scope), assign the taxonomy.
		foreach ( $packages as $pack_name => $pack_value ) {

			if ( empty( $pack_value ) ) {
				continue;
			}

			$pack_value = array_shift( $pack_value );
			$pack_value = $pack_value['content'];

			$package_term_args = array( 'parent' => 0 );
			// Set the parent term_id to look for, as the package taxonomy is hierarchical.
			if ( 'sub' === $pack_name && is_int( $main_package_id ) ) {
				$package_term_args = array( 'parent' => $main_package_id );
			}

			// If the package doesn't already exist in the taxonomy, add it
			$package_term = $this->insert_term( $pack_value, $this->taxonomy_package, $package_term_args );
			$package_term_ids[] = (int) $package_term['term_id'];

			if ( 'main' === $pack_name && false === $main_package_id && ! is_wp_error( $package_term ) ) {
				$main_package_id = (int) $package_term['term_id'];
			}

			if ( is_wp_error( $package_term ) ) {
				if ( is_int( $main_package_id ) ) {
					$this->logger->warning( "\tCannot create @subpackage term: " . $package_term->get_error_message() );
				} else {
					$this->logger->warning( "\tCannot create @package term: " . $package_term->get_error_message() );
				}
			}
		}
		$added_term_relationship = did_action( 'added_term_relationship' );
		wp_set_object_terms( $post_id, $package_term_ids, $this->taxonomy_package );
		if ( did_action( 'added_term_relationship' ) > $added_term_relationship ) {
			$anything_updated[] = true;
		}

		// Set other taxonomy and post meta to use in the theme templates
		$added_item = did_action( 'added_term_relationship' );
		wp_set_object_terms( $post_id, $this->file_meta['term_id'], $this->taxonomy_file );
		if ( did_action( 'added_term_relationship' ) > $added_item ) {
			$anything_updated[] = true;
		}

		// If the file is deprecated do something
		if ( ! empty( $this->file_meta['deprecated'] ) ) {
			$data['doc']['tags']['deprecated'] = $this->file_meta['deprecated'];
		}

		if ( $post_data['post_type'] !== $this->post_type_class ) {
			$anything_updated[] = update_post_meta( $post_id, '_wp-parser_args', $data['arguments'] );
		}

		// If the post type is using namespace aliases, record them.
		if ( ! empty( $data['aliases'] ) ) {
			$anything_updated[] = update_post_meta( $post_id, '_wp_parser_aliases', (array) $data['aliases'] );
		}

		// Recored the namespace if there is one.
		if ( ! empty( $data['namespace'] ) ) {
			$anything_updated[] = update_post_meta( $post_id, '_wp_parser_namespace', (string) addslashes( $data['namespace'] ) );
		}

		$anything_updated[] = update_post_meta( $post_id, '_wp-parser_line_num', (string) $data['line'] );
		$anything_updated[] = update_post_meta( $post_id, '_wp-parser_end_line_num', (string) $data['end_line'] );
		$anything_updated[] = update_post_meta( $post_id, '_wp-parser_tags', $data['doc']['tags'] );

		// If the post didn't need to be updated, but meta or tax changed, update it to bump last modified.
		if ( ! $is_new_post && ! $post_needed_update && array_filter( $anything_updated ) ) {
			wp_update_post( wp_slash( $post_data ), true );
		}

		$action = $is_new_post ? 'Imported' : 'Updated';

		switch ( $post_data['post_type'] ) {
			case $this->post_type_class:
				$this->logger->info( "\t" . sprintf( '%1$s class "%2$s"', $action, $ns_name ) );
				break;

			case $this->post_type_hook:
				$indent = ( $parent_post_id ) ? "\t\t" : "\t";
				$this->logger->info( $indent . sprintf( '%1$s hook "%2$s"', $action, $ns_name ) );
				break;

			case $this->post_type_method:
				$this->logger->info( "\t\t" . sprintf( '%1$s method "%2$s"', $action, $ns_name ) );
				break;

			default:
				$this->logger->info( "\t" . sprintf( '%1$s function "%2$s"', $action, $ns_name ) );
		}

		/**
		 * Action at the end of importing an item.
		 *
		 * @param int   $post_id   Optional; post ID of the inserted or updated item.
		 * @param array $data PHPDoc data for the item we just imported
		 * @param array $post_data WordPress data of the post we just inserted or updated
		 */
		do_action( 'wp_parser_import_item', $post_id, $data, $post_data );

		return $post_id;
	}

	/**
	 * Process the Namespace of items and add them to the correct taxonomy terms.
	 *
	 * This creates terms for each of the namespace terms in a hierachical tree
	 * and then adds the item being processed to each of the terms in that tree.
	 *
	 * @param int   $post_id    The ID of the post item being processed.
	 * @param array $namespaces An array of namespaces strings
	 */
	protected function _set_namespaces( $post_id, $namespaces ) {
		$ns_term = false;
		$ns_terms = array();
		foreach ( $namespaces as $namespace ) {
			$ns_term = $this->insert_term(
				$namespace,
				$this->taxonomy_namespace,
				array(
					'slug'   => strtolower( str_replace( '_', '-', $namespace ) ),
					'parent' => ( $ns_term ) ? $ns_term['term_id'] : 0,
				)
			);
			if ( ! is_wp_error( $ns_term ) ) {
				$ns_terms[] = (int) $ns_term['term_id'];
			} else {
				$this->logger->warning( "\tCannot set namespace term: " . $ns_term->get_error_message() );
				$ns_term = false;
			}
		}

		if ( ! empty( $ns_terms ) ) {
			$added_term_relationship = did_action( 'added_term_relationship' );
			wp_set_object_terms( $post_id, $ns_terms, $this->taxonomy_namespace );
			if( did_action( 'added_term_relationship' ) > $added_term_relationship ) {
				$this->anything_updated[] = true;
			}
		}
	}
}
