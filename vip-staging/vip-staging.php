<?php
/*
Plugin Name: VIP Staging
Description: Enables quick staging on your VIP website. Do not enable it.
Version: 0.0.1
Author: Team 1
*/

class VIP_Staging {

	const SUFIX = '-live';

    /**
     * VIP_Staging constructor
     *
     * Loading the required hooks and filters for the staging functionality
     * to be added to a blog.
     *
     * The action hooks and filters are only loaded for a specific user a stagin
     * enabled site.
     */
	public function __construct() {

		// TODO: remove this, just for testing
		if( isset($_GET['toggle_staging']) ) {

			$this->toggle_staging();

		}

		// Only load the staging theme if user has the option
		if( $this->is_current_user_staging() ) {

			add_filter( 'template_directory', array( $this, 'change_template_directory' ), 10, 3 );
			add_filter( 'template', array( $this, 'change_template' ) );

			add_filter( 'stylesheet_directory', array( $this, 'change_template_directory' ), 10, 3 );
			add_filter( 'stylesheet', array( $this, 'change_template' ) );

			add_action( 'wp_footer' , array( $this, 'load_staging_button' ) );

		}

		// Load the AJAX endpoints
		add_action( "wp_ajax_vip_staging_deploy", array( $this, 'ajax_deploy_endpoint' ) );
		add_action( "wp_ajax_vip_staging_deploy_status", array( $this, 'ajax_deploy_status_endpoint' ) );   
		add_action( "wp_ajax_vip_staging_deploy_info", array( $this, 'ajax_deploy_info_endpoint' ) );
		add_action( "wp_ajax_vip_staging_toggle", array( $this, 'ajax_toggle_staging_endpoint' ) );

	}

    /**
     * Override the template directory to load the staging repository instead
     * of the live site repository.
     *
     * @param $template_dir
     * @param $template
     * @param $theme_root
     *
     * @return string $template_dir
     */
	public function change_template_directory( $template_dir, $template, $theme_root ) {

		$staging_name = str_replace( self::SUFIX, '', $template );

		$staging_theme = wp_get_theme( $staging_name );
		// Check if there is a staging parent theme.
		if ( $staging_theme->exists() ) {

			return $theme_root . '/' . $staging_name;

		}

		return $template_dir;

	}

    /**
     * Override the template name when in a staging environment.
     *
     * @param $template
     * @return mixed
     */
	public function change_template( $template ) {

		$staging_name = str_replace( self::SUFIX, '', $template );
		$theme = wp_get_theme( $staging_name );

		if( $theme->exists() ) {

			return $staging_name;

		}

		return $template;

	} // end change_template

    /**
     * Check if the current user has enabled the staging environment
     * on the current blog.
     *
     * @return boolean
     */
	public function is_current_user_staging() {

		return get_user_option( 'show_staging_env', get_current_user_id() );

	}// end is_current_user_staging

	private function get_repositories_info() {
		$stylesheet = str_replace( 'vip/' , '', get_stylesheet() );
		$template = str_replace( 'vip/' , '', get_template() );

		// There is a child theme activated when the stylesheet name is different than the template name.
		$has_child = ( $stylesheet != $template );

		// Get the parent revisions
		$deployed_revision = wpcom_get_vip_deployed_revision( $template );
		$committed_revision = wpcom_get_vip_committed_revision( $template );

		$data = array(
			'template'      => $template,
			'stylesheet'    => $stylesheet,
			'has_child'     => $has_child,
			'parent_theme'  => false, // default to false. This can happen when it's a public theme.
		);

		// It should validate if the parent theme is a valid VIP theme. If so, overwrite with the correct revisions info
		if( false != $committed_revision ) {

			$data['parent_theme'] = array(
				'deployed_rev'  => $deployed_revision,
				'committed_rev' => $committed_revision,
			);

		}

		if( $has_child ) {

			// Fetch the correct data from the child theme
			$deployed_revision = wpcom_get_vip_deployed_revision( $stylesheet );
			$committed_revision = wpcom_get_vip_committed_revision( $stylesheet );

			$data['child_theme'] = array(
				'deployed_rev'  => $deployed_revision,
				'committed_rev' => $committed_revision,
			);
		}

		return $data;
	}

    /**
     * Switch the current users between the staging and the live
     * environment.
     */
	public function toggle_staging( $is_stage = null ) {

		$user_id = get_current_user_id();

		// Simply toggle if there isn't any value set
		if ( null === $is_stage ) {
			$is_stage = get_user_option( 'show_staging_env', $user_id );
			$is_stage = ! $is_stage;
		}

		// Update the option
		update_user_option( $user_id, 'show_staging_env', $is_stage );

	} // end is_current_user_staging

    /**
     * Display the staging user interface.
     *
     * Outputs the HTML , CSS and Javascript
     * needed for the staging button to function.
     */
	public function load_staging_button(){

		?>

		<div id="wpcom-staging">
			<div id="popup" class="hidden" ></div>
			<a class="button" href="#" >Staging</a>
		</div>

		<style type="text/css">

			#wpcom-staging .button {
				text-transform: uppercase;
				background: #DCA816;
				color: #fff;
				letter-spacing: 0.2em;
				text-shadow: none;
				font-size: 9px;
				font-weight: bold;
				padding: 8px 10px;
				float: left;
				cursor: pointer;
				margin-left: 10em;
				position: fixed;
				bottom: 14px;
			}



		</style>

		<?php
	} // end load_staging_button

	public function user_can_stage() {

		// Let's say that all the administrators can stage the site, by default.
		return apply_filters( 'vip_staging_can_stage', current_user_can( 'manage_options' ) || is_automattician() );

	}

	/**
	 * Checks if the current user has permission to deploy.
	 *
	 * Right now, only who has commit access to the staging repository will have this right.
	 * This method WON'T work properly when the production theme is loading, so it should only be used on staging.
	 *
	 * @return bool
	 */
	public function user_can_deploy() {

		if ( $permission = wp_cache_get( get_current_user_id() . '_can_deploy', 'vip-staging' ) ) {
			return apply_filters( 'vip_staging_can_deploy', $permission );
		}

		// Load the SVN utils functions
		if ( ! function_exists( 'svn_get_user_themes' ) ) {
			require_once( ABSPATH . 'bin/includes/svn-utils.php' );
		}

		$current_user = wp_get_current_user();
		$svn_themes = svn_get_user_themes( $current_user->user_login, 'vip' );
		$stylesheet = str_replace( 'vip/', '', get_stylesheet());

		// Check if user has write access to the child theme.
		$has_access = ( isset( $svn_themes[ $stylesheet ] ) && 'rw' == $svn_themes[ $stylesheet ] )
		              || is_automattician();

		wp_cache_set( get_current_user_id() . '_can_deploy', $has_access, 'vip-staging', 5 * MINUTE_IN_SECONDS );

		return apply_filters( 'vip_staging_can_deploy', $has_access );

	}

	public function ajax_deploy_endpoint() {

		if( ! $this->user_can_deploy() ) {

			$this->ajax_die_no_permissions();

		}

		// todo: creating the job to start the deploy
		wp_send_json_success();
	}

	public function ajax_deploy_status_endpoint() {

		if( ! $this->user_can_deploy() ) {

			$this->ajax_die_no_permissions();

		}

		// Grab all the repositories data
		$data = $this->get_repositories_info();

		// todo: check the jobs system for the job status
		$data['status'] = ( $data['deployed_revision'] <= $data['committed_revision'] ? 'deployed' : 'undeployed' );

		wp_send_json_success( $data );

	}

	public function ajax_deploy_info_endpoint() {

		if( ! $this->user_can_deploy() ) {

			$this->ajax_die_no_permissions();

		}

		wp_send_json_success( $this->get_repositories_info() );

	}

	public function ajax_toggle_staging_endpoint() {

		$is_staging = (bool) $_POST['is_staging'];

		if( ! $this->user_can_stage() ) {

			$this->ajax_die_no_permissions();

		}

		$this->toggle_staging( $is_staging );

		wp_send_json_success();

	}

	private function ajax_die_no_permissions() {

		wp_send_json_error( 'No permissions' );

	}
}

new VIP_Staging();