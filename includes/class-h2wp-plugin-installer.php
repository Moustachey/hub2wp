<?php
/**
 * Handles plugin installation and activation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This class could be extended if we need custom install logic.
 * For now, plugin installation is handled directly in the admin page class.
 * This class is provided as a placeholder for organizational purposes.
 */
class H2WP_Plugin_Installer {

	public $plugin_data = array();
    public $theme_data  = array();
    private $install_target_folder = '';
    private $install_subdirectory  = ''; // subdirectory within the zip for monorepo plugins

	/**
	 * Install a plugin from a GitHub ZIP URL.
	 *
	 * @param string $download_url  ZIP file URL.
	 * @param string $access_token  Optional GitHub access token for private repos.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function install_plugin( $download_url, $access_token = '', $subdirectory = '' ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$upgrader = new Plugin_Upgrader( new H2WP_Silent_Installer_Skin() );

		// For private repos the upgrader's built-in download_url() has no auth headers,
		// so we download the zip ourselves first and hand the local file to the upgrader.
		$local_file = null;
		if ( ! empty( $access_token ) ) {
			$local_file = $this->download_authenticated( $download_url, $access_token );
			if ( is_wp_error( $local_file ) ) {
				return $local_file;
			}
			$package = $local_file;
		} else {
			$package = $download_url;
		}

		// For monorepo plugins, target the plugin slug; for single repos, use the repo slug
        $this->install_subdirectory  = trim( (string) $subdirectory, '/' );
        $this->install_target_folder = ! empty( $subdirectory )
            ? basename( $subdirectory )
            : $this->get_repo_slug_from_download_url( $download_url );
        add_filter( 'upgrader_source_selection', array( $this, 'normalize_install_source_folder' ), 10, 4 );

		ob_start();
		$result = $upgrader->install( $package );
		ob_end_clean();
		remove_filter( 'upgrader_source_selection', array( $this, 'normalize_install_source_folder' ), 10 );
        $this->install_target_folder = '';
        $this->install_subdirectory  = '';

        // Always clean up the temp file.
        if ( null !== $local_file && file_exists( $local_file ) ) {
			// phpcs:ignore -- WordPress.PHP.NoSilencedErrors.Discouraged & WordPress.WP.AlternativeFunctions.file_system_read_file -- We want to suppress errors here since the file might not exist or be deletable, and there's no real alternative function for this.
			@unlink( $local_file );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $result ) {
			return new WP_Error( 'h2wp_install_error', __( 'Failed to install the plugin.', 'hub2wp' ) );
		}

		$this->plugin_data = array(
			'directory' => $upgrader->result['destination_name'],
			'name'      => $upgrader->new_plugin_data['Name'],
			'author'    => $upgrader->new_plugin_data['Author'],
			'version'   => $upgrader->new_plugin_data['Version'],
		);

		return true;
	}

	/**
	 * Install a theme from a GitHub ZIP URL.
	 *
	 * @param string $download_url ZIP file URL.
	 * @param string $access_token Optional GitHub access token for private repos.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function install_theme( $download_url, $access_token = '', $subdirectory = '' ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$upgrader = new Theme_Upgrader( new H2WP_Silent_Installer_Skin() );

		$local_file = null;
		if ( ! empty( $access_token ) ) {
			$local_file = $this->download_authenticated( $download_url, $access_token );
			if ( is_wp_error( $local_file ) ) {
				return $local_file;
			}
			$package = $local_file;
		} else {
			$package = $download_url;
		}

		$this->install_subdirectory  = trim( (string) $subdirectory, '/' );
        $this->install_target_folder = ! empty( $subdirectory )
            ? basename( $subdirectory )
            : $this->get_repo_slug_from_download_url( $download_url );
        add_filter( 'upgrader_source_selection', array( $this, 'normalize_install_source_folder' ), 10, 4 );

		ob_start();
		$result = $upgrader->install( $package );
		ob_end_clean();
		remove_filter( 'upgrader_source_selection', array( $this, 'normalize_install_source_folder' ), 10 );
        $this->install_target_folder = '';
        $this->install_subdirectory  = '';

        // Always clean up the temp file.
        if ( null !== $local_file && file_exists( $local_file ) ) {
			// phpcs:ignore -- WordPress.PHP.NoSilencedErrors.Discouraged & WordPress.WP.AlternativeFunctions.file_system_read_file -- We want to suppress errors here since the file might not exist or be deletable, and there's no real alternative function for this.
			@unlink( $local_file );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $result ) {
			return new WP_Error( 'h2wp_install_error', __( 'Failed to install the theme.', 'hub2wp' ) );
		}

		$stylesheet = isset( $upgrader->result['destination_name'] ) ? $upgrader->result['destination_name'] : '';
		$theme      = $stylesheet ? wp_get_theme( $stylesheet ) : false;

		$this->theme_data = array(
			'directory'  => $stylesheet,
			'name'       => $theme ? $theme->get( 'Name' ) : '',
			'author'     => $theme ? $theme->get( 'Author' ) : '',
			'version'    => $theme ? $theme->get( 'Version' ) : '',
			'stylesheet' => $stylesheet,
			'template'   => $theme ? $theme->get_template() : $stylesheet,
		);

		return true;
	}

	/**
	 * Download a file from a URL using an Authorization header and save it to a temp file.
	 *
	 * WordPress's built-in download_url() never sends auth headers, so for private
	 * GitHub repos we must handle the download ourselves.
	 *
	 * @param string $url          The URL to download.
	 * @param string $access_token GitHub personal access token.
	 * @return string|WP_Error Path to the temp file on success, WP_Error on failure.
	 */
	private function download_authenticated( $url, $access_token ) {
		$tmpfname = wp_tempnam( $url );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 300,
				'stream'      => true,
				'filename'    => $tmpfname,
				'redirection' => 5,
				'headers'     => array(
					'Authorization' => 'token ' . $access_token,
					'Accept'        => 'application/vnd.github+json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore -- WordPress.PHP.NoSilencedErrors.Discouraged & WordPress.WP.AlternativeFunctions.file_system_read_file -- We want to suppress errors here since the file might not exist or be deletable, and there's no real alternative function for this.
			@unlink( $tmpfname );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			// phpcs:ignore -- WordPress.PHP.NoSilencedErrors.Discouraged & WordPress.WP.AlternativeFunctions.file_system_read_file -- We want to suppress errors here since the file might not exist or be deletable, and there's no real alternative function for this.
			@unlink( $tmpfname );
			/* translators: %d: HTTP status code */
			return new WP_Error(
				'h2wp_download_error',
				sprintf(
					// Translators: %d: HTTP status code.
					__( 'Could not download the repository zip (HTTP %d). Please verify your access token has the "repo" scope and that you can access this repository.', 'hub2wp' ),
					$code
				)
			);
		}

		return $tmpfname;
	}

	/**
	 * Parse the repository slug from a GitHub download URL.
	 *
	 * @param string $download_url GitHub zipball URL.
	 * @return string Folder slug.
	 */
	private function get_repo_slug_from_download_url( $download_url ) {
		$path = wp_parse_url( $download_url, PHP_URL_PATH );
		if ( empty( $path ) ) {
			return '';
		}

		if ( preg_match( '#/repos/[^/]+/([^/]+)/zipball#', $path, $matches ) ) {
			return sanitize_title( $matches[1] );
		}

		return '';
	}

	/**
	 * Rename extracted GitHub archive folder to the repository slug during install.
	 *
	 * @param string      $source        Source path.
	 * @param string      $remote_source Remote source path.
	 * @param WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $hook_extra    Upgrader context.
	 * @return string|WP_Error
	 */
	public function normalize_install_source_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
        global $wp_filesystem;

        if ( empty( $this->install_target_folder ) ) {
            return $source;
        }

        if ( ! $wp_filesystem || ! method_exists( $wp_filesystem, 'move' ) ) {
            return $source;
        }

        $final_dest = trailingslashit( $remote_source ) . $this->install_target_folder;

        // Monorepo: the zip contains the full repo; navigate into the plugin subdirectory
        if ( ! empty( $this->install_subdirectory ) ) {
            $plugin_source = trailingslashit( $source ) . $this->install_subdirectory;

            if ( ! $wp_filesystem->is_dir( $plugin_source ) ) {
                return new WP_Error(
                    'h2wp_subdir_not_found',
                    sprintf(
                        /* translators: %s: subdirectory path */
                        __( 'Plugin subdirectory "%s" not found in the downloaded zip.', 'hub2wp' ),
                        $this->install_subdirectory
                    )
                );
            }

            // Move just the plugin folder out of the repo zip
            if ( ! $wp_filesystem->move( untrailingslashit( $plugin_source ), $final_dest ) ) {
                return new WP_Error(
                    'h2wp_rename_error',
                    __( 'Could not extract plugin subdirectory from the repository zip.', 'hub2wp' )
                );
            }

            // Clean up the rest of the extracted repo
            $wp_filesystem->delete( untrailingslashit( $source ), true );

            return trailingslashit( $final_dest );
        }

        // Single repo: just rename the GitHub hash folder to the repo slug
        if ( trailingslashit( $final_dest ) === trailingslashit( $source ) ) {
            return $source;
        }

        if ( ! $wp_filesystem->move( untrailingslashit( $source ), $final_dest ) ) {
            return new WP_Error(
                'h2wp_rename_error',
                sprintf(
                    /* translators: 1: extracted folder, 2: expected folder */
                    __( 'Could not rename extracted folder from "%1$s" to "%2$s".', 'hub2wp' ),
                    basename( $source ),
                    $this->install_target_folder
                )
            );
        }

        return trailingslashit( $final_dest );
    }
}
