<?php
/*
Plugin Name: Kili. Automatic updater
Plugin URI:
Description: Automatically checks GitHub releases tags and update to Kiliframework.
Author: Fabian Altahona
Version: 0.0.2
*/

/**
 * Automatically checks GitHub releases tags and update to Kiliframework
 *
 * @package kiliframework
 */

/**
 * Class Automatic updater for GitHub hosted Wordpress theme
 */
class Kili_Automatic_Updater {
	/**
	 * Class constructor
	 */
	public function __construct() {
		// register the custom stylesheet header
		add_action( 'extra_theme_headers', array( $this, 'kili_github_extra_theme_headers' ) );
		//disable updater during core wordpress updates
		if( empty( $_GET['action'] ) ) {
			add_filter( 'site_transient_update_themes', array( $this, 'kili_transient_update_themes_filter' ) );
		}
		add_filter('upgrader_source_selection', array( $this, 'kili_upgrader_source_selection_filter' ), 10, 3);
		add_action('http_request_args', array( $this, 'kili_no_ssl_http_request_args' ), 10, 2);
	}

	/**
	 * Get github theme url
	 *
	 * @param array $headers Request headers
	 * @return array Headers array with github theme url
	 */
	public function kili_github_extra_theme_headers( $headers ) {
		$headers['Github Theme URI'] = 'Github Theme URI';
		return $headers;
	}

	public function kili_transient_update_themes_filter( $data ) {
		if( function_exists( 'wp_get_themes' ) ) {
			$installed_themes = wp_get_themes();
		} else {
			$installed_themes = get_themes();
		}
		foreach ( (array) $installed_themes as $theme_title => $_theme ) {
			if( !$_theme->get( 'Github Theme URI' ) ) {
				continue;
			} else {
				$theme = array(
					'Github Theme URI' => $_theme->get( 'Github Theme URI' ),
					'Stylesheet'       => $_theme->stylesheet,
					'Version'          => 'v' . $_theme->version,
				);
			}

			$theme_key = $theme['Stylesheet'];
			// Grab Github Tags.
			preg_match( '/http(s)?:\/\/github.com\/(?<username>[\w-]+)\/(?<repo>[\w-]+)$/', $theme['Github Theme URI'], $matches);

			if( !isset( $matches['username'] ) or !isset( $matches['repo'] ) ) {
				$data->response[$theme_key]['error'] = 'Incorrect github project url.  Format should be (no trailing slash): <code style="background:#FFFBE4;">https://github.com/&lt;username&gt;/&lt;repo&gt;</code>';
				continue;
			}

			$url = sprintf( 'https://api.github.com/repos/%s/%s/tags', urlencode( $matches['username'] ), urlencode( $matches['repo'] ) );
			$response = get_transient( md5( $url ) );

			if( empty( $response ) ) {
				$raw_response = wp_remote_get( $url, array( 'sslverify' => false, 'timeout' => 10 ) );

				if ( is_wp_error( $raw_response ) ){
					$data->response[ $theme_key ]['error'] = "Error response from " . $url;
					continue;
				}

				$response = json_decode( $raw_response['body'] );

				if( isset( $response->message ) ) {
					if( is_array( $response->message ) ) {
						$errors = '';
						foreach ( $response->message as $error) {
							$errors .= ' ' . $error;
						}
					} else {
						$errors = print_r( $response->message, true );
					}
					$data->response[ $theme_key ]['error'] = sprintf('While <a href="%s">fetching tags</a> api error</a>: <span class="error">%s</span>', $url, $errors);
					continue;
				}

				if( count( $response ) == 0 ) {
					$data->response[ $theme_key ]['error'] = "Github theme does not have any tags";
					continue;
				}

				//set cache, just 60 seconds.
				set_transient( md5( $url ), $response, 30 );
			}

			// Sort and get latest tag.
			$tags = array_map(function ($t) { return $t->name; }, $response );
			usort( $tags, "version_compare" );

			// check and generate download link.$GLOBALS.
			$newest_tag = array_pop( $tags );
			if( version_compare( $theme['Version'],  $newest_tag, '>=' ) ) {
				// up-to-date!
				$data->up_to_date[ $theme_key ]['rollback'] = $tags;
				continue;
			}

			// new update available, add to $data.
			$download_link = $theme['Github Theme URI'] . '/zipball/' . $newest_tag;
			$newest_tag_numbers = explode( 'v', $newest_tag );
			$update = array();
			$update['new_version'] = $newest_tag_numbers[1];
			$update['url']         = $theme['Github Theme URI'];
			$update['package']     = $download_link;
			$data->response[ $theme_key ] = $update;

		}
		return $data;
	}

	public function kili_upgrader_source_selection_filter( $source, $remote_source=null, $upgrader=null ) {
		/*
		Github delivers zip files as <Username>-<TagName>-<Hash>.zip
		must rename this zip file to the accurate theme folder
		*/
		$upgrader->skin->feedback( 'Executing kili_upgrader_source_selection_filter function...' );
		if ( isset( $upgrader->skin->theme ) ) {
			$correct_theme_name = $upgrader->skin->theme;
		} elseif ( isset( $upgrader->skin->theme_info->stylesheet ) ) {
			$correct_theme_name = $upgrader->skin->theme_info->stylesheet;
		} elseif ( isset( $upgrader->skin->theme_info->template ) ) {
			$correct_theme_name = $upgrader->skin->theme_info->template;
		} else {
			$upgrader->skin->feedback( 'Theme name not found. Unable to rename downloaded Kiliframework.' );
		}

		if ( isset( $source, $remote_source, $correct_theme_name ) ) {
			$corrected_source = $remote_source . '/' . $correct_theme_name . '/';
			if ( @rename( $source, $corrected_source ) ) {
				$upgrader->skin->feedback( 'Renamed Kiliframework folder successfully.' );
				return $corrected_source;
			} else {
				$upgrader->skin->feedback( '**Unable to rename downloaded Kiliframework.' );
				return new WP_Error();
			}
		} else {
			$upgrader->skin->feedback( '**Source or Remote Source is unavailable.' );
		}
		return $source;
	}

	public function kili_no_ssl_http_request_args( $args, $url ) {
		$args['sslverify'] = false;
		return $args;
	}
}

$kili_automatic_updater = new Kili_Automatic_Updater();
