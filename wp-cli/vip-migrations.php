<?php

use \WP_CLI\Utils;

class VIP_Go_Migrations_Command extends WPCOM_VIP_CLI_Command {

	/**
	 * Deletes senstive options
	 *
	 * @subcommand scrub-site
	 */
	public function clear_sensitive_options( $args, $assoc_args ) {
		WP_CLI::log( 'Clearing sensitive options (like Jetpack connection).' );

		// TODO: if called from preprod site with production values, does it disconnect the preprod site?
//		WP_CLI::log( '- Disconnecting Jetpack' );
//		Jetpack::disconnect();

		WP_CLI::log( '- Deleting Options' );
		$options = [
			'jetpack_options',
			'jetpack_private_options',
			'vaultpress',
			'wordpress_api_key',
		];

		foreach ( $options as $option_name ) {
			WP_CLI::log( '-- ' . $option_name );
			delete_option( $option_name );
		}
	}

	/**
	 * Update URLs across a whitelist of table columns
	 *
	 * ## OPTIONS
	 *
	 * --from=<from_url>
	 * : The URL to search for.
	 *
	 * --to=<to_url>
	 * : The URL to replace with.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vip migration search-replace-url 'http://example.com' 'http://example.go-vip.co'
	 *
	 * @subcommand search-replace-url
	 */
	public function search_replace_url( $args, $assoc_args ) {
		$from_url = $assoc_args['from'] ?? false;
		$to_url = $assoc_args['to'] ?? false;

		$from_url_parsed = parse_url( $from_url );
		if ( empty( $from_url ) || ! $from_url_parsed ) {
			WP_CLI::error( sprintf( 'Please provide a valid `--from` URL (current: %s)', $from_url ) );
			return;
		}

		$to_url_parsed = parse_url( $to_url );
		if ( empty( $to_url ) || ! $to_url_parsed ) {
			WP_CLI::error( sprintf( 'Please provide a valid `--to` URL (current: %s)', $to_url ) );
			return;
		}

		// TODO: should we have a flag to exclude home/siteurl option?

		$tables_and_columns = [
			'wp_commentmeta' => [
				'meta_key',
				'meta_value',
			],

			'wp_comments' => [
				'comment_author',
				'comment_author_url',
				'comment_content',
				'comment_agent',
			],

			'wp_links' => [
				'link_url',
				'link_name',
				'link_image',
				'link_description',
				'link_notes',
				'link_rss',
			],

			'wp_options' => [
				'option_name',
				'option_value',
			],

			'wp_postmeta' => [
				'meta_key',
				'meta_value',
			],

			'wp_posts' => [
				'post_content',
				'post_title',
				'post_excerpt',
				'post_name',
				'post_content_filtered',
				'guid',
			],

			'wp_term_taxonomy' => [
				'taxonomy',
				'description',
			],

			'wp_termmeta' => [
				'meta_key',
				'meta_value',
			],

			'wp_terms' => [
				'name',
			],

			'wp_usermeta' => [
				'meta_key',
				'meta_value',
			],

			'wp_users' => [
				'user_url',
				'display_name',
			],
		];

		$runcommand_args = [
			'exit_error' => false,
		];

		foreach ( $tables_and_columns as $table => $columns ) {
			$command = sprintf(
				'vip search-replace %1$s %2$s %3$s --include-columns=%4$s --verbose',
				escapeshellarg( $from_url ),
				escapeshellarg( $to_url ),
				escapeshellarg( $table ),
				escapeshellarg( implode( ',', $columns ) )
			);

			WP_CLI::log( 'Running command: ' . $command );
			WP_CLI::runcommand( $command, $runcommand_args );
			WP_CLI::log( '---' );
		}
	}

	/**
	 * Run dbDelta() for the current site.
	 *
	 * [--network]
	 * : Update databases for all sites on a network
	 *
	 * [--dry-run]
	 * : Show changes without updating
	 *
	 * ## OPTIONS
	 *
	 * [<tables>]
	 * : Which tables to update (all, blog, global, ms_global)
	 */
	function dbdelta( $args, $assoc_args ) {
		global $wpdb;

		$network = Utils\get_flag_value( $assoc_args, 'network' );
		if ( $network && ! is_multisite() ) {
			WP_CLI::error( 'This is not a multisite install.' );
		}

		$dry_run = Utils\get_flag_value( $assoc_args, 'dry-run' );
		if ( $dry_run ) {
			WP_CLI::log( 'Performing a dry run, with no database modification.' );
		}

		if ( $network ) {
			$iterator_args = array(
				'table' => $wpdb->blogs,
				'where' => array( 'spam' => 0, 'deleted' => 0, 'archived' => 0 ),
			);
			$it = new \WP_CLI\Iterators\Table( $iterator_args );
			foreach( $it as $blog ) {
				$url = $blog->domain . $blog->path;
				$cmd = "--url={$url} vip migration dbdelta";

				// Update global tables if this is the main site
				// otherwise only update the given blog's tables
				if ( is_main_site( $blog->blog_id ) ) {
					$cmd .= ' all';
				} else {
					$cmd .= ' blog';
				}

				if ( $dry_run ) {
					$cmd .= ' --dry-run';
				}

				WP_CLI::line();
				WP_CLI::line( WP_CLI::colorize( '%mUpdating:%n ' ) . $blog->domain . $blog->path );
				WP_CLI::runcommand( $cmd );
			}
			return;
		}

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		if ( in_array( $args[1], array( '', 'all', 'blog', 'global', 'ms_global' ), true ) ) {
			$changes = dbDelta( $args[1], !$dry_run );
		} else {
			$changes = dbDelta( null, !$dry_run );
		}

		if ( empty( $changes ) ) {
			WP_CLI::success( 'No changes.' );
			return;
		}

		foreach( $changes as $change ) {
			WP_CLI::line( $change );
		}

		$count = count( $changes );
		WP_CLI::success( _n( '%s change', '%s changes', $count ), number_format_i18n( $count ) );
	}
}

WP_CLI::add_command( 'vip migration', 'VIP_Go_Migrations_Command' );
