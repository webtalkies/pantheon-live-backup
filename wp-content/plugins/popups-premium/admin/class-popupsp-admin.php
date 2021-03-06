<?php
/**
 * Popups.
 *
 * @package   PopupsP_Admin
 * @author    Damian Logghe <info@timersys.com>
 * @license   GPL-2.0+
 * @link      http://wp.timersys.com
 * @copyright 2014 Timersys
 */


define( 'SPUP_ADMIN_DIR' , plugin_dir_path(__FILE__) );



/**
 * Admin Class of the plugin
 *
 * @package PopupsP_Admin
 * @author  Damian Logghe <info@timersys.com>
 */
class PopupsP_Admin {

	/**
	 * Instance of this class.
	 *
	 * @since    1.0.0
	 *
	 * @var      object
	 */
	protected static $instance = null;

	/**
	 * Array with all integrations apis
	 * @var
	 */
	public $spu_integrations;

	/**
	 * Slug of the plugin screen.
	 *
	 * @since    1.0.0
	 *
	 * @var      string
	 */
	protected $plugin_screen_hook_suffix = null;

	/**
	 * Plugin options in db
	 * @var array
	 */
	protected $spu_settings = array();

	/**
	 * Email providers
	 * @var array
	 */
	protected $providers = array( 'mailchimp', 'aweber');

	/**
	 * Initialize the plugin by loading admin scripts & styles and adding a
	 * settings page and menu.
	 *
	 * @since     1.0.0
	 */
	private function __construct() {


		$plugin = PopupsP::get_instance();
		$this->plugin_slug = $plugin->get_plugin_slug();

		//settings name
		$this->options_name		= $this->plugin_slug .'_settings';

		$this->loadDependencies();

		// helper funcs
		if( class_exists('Spu_Helper'))
			$this->helper = new Spu_Helper;

		add_action('spu/settings_page/before', array( $this, 'load_spu_settings'), 1 );

		//Integrations
		add_action('admin_menu' , array( $this, 'add_integrations_menu' ) );

		//Add new effect
		add_action( 'spu/metaboxes/animations', array( $this, 'add_animations' ), 10, 1 );

		//Add new positions
		add_action( 'spu/metaboxes/positions', array( $this, 'add_positions' ), 10, 1 );

		//Add trigger action and value
		add_action( 'spu/metaboxes/trigger_options', array( $this, 'add_trigger_actions' ), 10, 1 );
		add_action( 'spu/metaboxes/trigger_values', array( $this, 'add_trigger_values' ), 10, 1 );

		// Add advanced closing methods
		add_action( 'spu/metaboxes/after_display_options', array( $this, 'add_closing_methods' ), 10, 1 );
		
		// Add auto close timer
		add_action( 'spu/metaboxes/after_display_options', array( $this, 'add_autoclose_timer' ), 10, 1 );
		// Add GA fields
		add_action( 'spu/metaboxes/after_display_options', array( $this, 'add_ga_events_fields' ), 15, 1 );

		// Thanks message
		#add_action( 'spu/metaboxes/after_display_options', array( $this, 'add_thanks_msg' ), 10, 1 );
		
		// Tracking events
		add_action( 'wp_ajax_track_spup', array( $this, 'track_events' ) );
		add_action( 'wp_ajax_nopriv_track_spup', array( $this, 'track_events' ) );

		// tracking events columns in cpt
		add_filter( 'manage_edit-spucpt_columns' ,  array( $this, 'set_custom_cpt_columns'), 10, 2 );
		add_filter( 'post_row_actions' ,  array( $this, 'modify_title_row'), 10, 2 );
		add_action( 'manage_spucpt_posts_custom_column' ,  array( $this, 'custom_columns'), 10, 2 );

		// Custom actions - Reseting stats, Ab Test
		add_action( 'admin_init',  array( $this, 'custom_actions'));

		// Sanitize new options
		add_filter( 'spu/metaboxes/sanitized_options', array( $this, 'sanitize_options' ), 10, 1 );
		
		//Add default values
		add_filter( 'spu/metaboxes/default_options', array( $this, 'add_default_values' ), 10, 1 );

		// Add custom appeareance options for optins
		add_filter( 'spu/metaboxes/after_appearance_options', array( $this, 'add_custom_appearance' ), 10, 1 );

		// Support metabox
		add_filter( 'spu/metaboxes/support_metabox', array( $this, 'support_metabox' ),10, 1 );
	
		// Load admin style sheet and JavaScript.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		// Google UA settings
		add_action( 'spu/settings_page/before', array( $this, 'google_ua_field' ), 15 );

		// Data Sampling
		if( !defined('SPUP_DISABLE_STATS') )
		add_action( 'spu/settings_page/before', array( $this, 'data_sampling_field' ), 16 );

		// License & Updates
		add_action( 'spu/settings_page/before', array( $this, 'license_field' ) );
		// We run early to active all the proccess of updates
		add_action( 'admin_init', array( $this, 'handle_license' ),1 );

		//Add our metaboxes
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );

		// Optin ajax
		add_action( 'wp_ajax_spu_get_optin_lists', array( $this, 'ajax_get_optin_lists' ) );
		add_action( 'wp_ajax_spu_get_optin_list_segments', array( $this, 'ajax_get_optin_list_segments' ) );

		// Optin styles
		add_action( 'admin_init', array( $this, 'optin_editor_styles' ) );

		// Add integrations that don't require api keys
		add_action( 'spu/settings_page/integrations', array( $this, 'add_custom_integrations'));

		// keep same date on update for A/B popups and to keep same order
		add_filter( 'wp_insert_post_data' , array( $this, 'change_post_date' ), '99', 2 ) ;
		
		// remove rules on child A/B popups
		add_action( 'spu/metaboxes/before_rules', array( $this, 'remove_rules' ) );
	}
	
	/**
	 * Load plugin options before adding our fields
	 * @since  1.2
	 * @return array
	 */
	function load_spu_settings(){
		
		$defaults = array(
			'spu_license_key' => '',
			'ua_code' => '',
			'mc_api' => '',
		);
		
		// opts
		$this->spu_settings 		= apply_filters('spu/settings_page/opts', get_option( 'spu_settings', $defaults ) );
	    
	}

	/**
	 * Add custom columns to spu cpt
	 * @param [type] $columns [description]
	 * @since  1.2
	 */
	public function set_custom_cpt_columns( $columns ){
		$columns['impressions'] = __( 'Impressions', $this->plugin_slug );
		$columns['conversions'] = __( 'Conversions', $this->plugin_slug );
		$columns['rate']        = __( '% Conversions', $this->plugin_slug );
		return $columns;
	}
	/**
	 * Add callbacks for custom colums
	 * @param  array $column  [description]
	 * @param  int $post_id [description]
	 * @return echo html     
	 * @since  1.2
	 */
	function custom_columns( $column, $post_id ) {
		global $wpdb;
		$hits        = get_post_meta( $post_id , 'spup_hit_counter' , true );
		$wpdb->query( "SELECT hit_id FROM {$wpdb->prefix}spu_hits_logs WHERE box_id = $post_id AND hit_type = '1'");
		$conversions = $wpdb->num_rows;


		switch ( $column ) {
			case 'title' :
				echo '<a class="row-title" href="'.get_permalink($post_id).'">'.get_the_title($post_id).'</a> | <span class="spu-id">spu- '.$post_id.'</span>';
				break;

			case 'impressions' :
				echo $hits ? $hits : '0';
				break;

			case 'conversions' :
				echo $conversions ? $conversions : '0';
				break;
			case 'rate' :
				echo $hits > 0 ? number_format( ( ( $conversions * 100 ) / $hits ) , 2) : '0';
				echo '%';
				break;
		}
	}

	/**
	 * Add ID to title actions
	 * @return array
	 * @since  1.2
	 */
	function modify_title_row( $actions, $post ){
		if( 'spucpt' != $post->post_type )
			return $actions;
		$ab_child = get_post_meta( $post->ID, 'spu_ab_parent', true );
		$actions['del_stats']   = '<a class="spu_reset_stats" title="'.__('Reset Popup Stats',$this->plugin_slug).'" href="'. wp_nonce_url( admin_url('edit.php?post_type=spucpt&post='. $post->ID . '&spu_action=spu_reset_stats'), 'spu_reset_stats', 'spu_nonce') .'">'.__('Reset Stats',$this->plugin_slug).'</a>';
		if( $ab_child ) {
			$actions['ab_test_live']   = '<a class="spu_ab_test" title="'.__('Make this version live',$this->plugin_slug).'" href="'. wp_nonce_url( admin_url('edit.php?post_type=spucpt&post='. $post->ID . '&spu_action=spu_ab_live'), 'spu_ab_live', 'spu_nonce') .'">'.__('Make this version live',$this->plugin_slug).'</a>';
		} else {
			$actions['ab_test']   = '<a class="spu_ab_test" title="'.__('Create A/B test',$this->plugin_slug).'" href="'. wp_nonce_url( admin_url('edit.php?post_type=spucpt&post='. $post->ID . '&spu_action=spu_ab_test'), 'spu_ab_test', 'spu_nonce') .'">'.__('Create A/B test',$this->plugin_slug).'</a>';
		}

		$actions['show_id']     = '<span class="spu-id">#spu- '.$post->ID.'</span>';

		return $actions;
	}

	/**
	 * Return an instance of this class.
	 *
	 * @since     1.0.0
	 *
	 * @return    object    A single instance of this class.
	 */
	public static function get_instance() {


		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}
	

	/**
	 * Add  animations metabox
	 * @param array $opts plugin selected options
	 * @since     1.0.0
	 */
	function add_animations( $opts ) {

		echo '<option value="wiggle" '. selected($opts['animation'], 'wiggle', false).' /> '. __( 'Wiggle', $this->plugin_slug ) . '</option>';
		echo '<option value="tada" '. selected($opts['animation'], 'tada', false).' /> '. __( 'Tada', $this->plugin_slug ) . '</option>';
		echo '<option value="shake" '. selected($opts['animation'], 'shake', false).' /> '. __( 'Shake', $this->plugin_slug ) . '</option>';
		echo '<option value="wobble" '. selected($opts['animation'], 'wobble', false).' /> '. __( 'Wobble', $this->plugin_slug ) . '</option>';
		echo '<option value="rotate-in" '. selected($opts['animation'], 'rotate-in', false).' /> '. __( 'Rotate In', $this->plugin_slug ) . '</option>';
		echo '<option value="hinge" '. selected($opts['animation'], 'hinge', false).' /> '. __( 'Hingle', $this->plugin_slug ) . '</option>';
		echo '<option value="speedy-left" '. selected($opts['animation'], 'speedy-left', false).' /> '. __( 'Speedy left', $this->plugin_slug ) . '</option>';
		echo '<option value="speedy-right" '. selected($opts['animation'], 'speedy-right', false).' /> '. __( 'Speedy right', $this->plugin_slug ) . '</option>';

	}

	/**
	 * Add new positions to metabox
	 * @param array $opts plugin selected options
	 * @since     1.0.0
	 */
	function add_positions( $opts ) {

		echo '<option value="float-left" '. selected($opts['css']['position'], 'float-left', false).'>'. __( 'Float Left', $this->plugin_slug ). '</option>';
		echo '<option value="float-right" '. selected($opts['css']['position'], 'float-right', false).'>'. __( 'Float Right', $this->plugin_slug ). '</option>';
		echo '<option value="top-bar" '. selected($opts['css']['position'], 'top-bar', false).'>'. __( 'Top Bar', $this->plugin_slug ). '</option>';
		echo '<option value="bottom-bar" '. selected($opts['css']['position'], 'bottom-bar', false).'>'. __( 'Bottom Bar', $this->plugin_slug ). '</option>';
		echo '<option value="after-content" '. selected($opts['css']['position'], 'after-content', false).'>'. __( 'After post content', $this->plugin_slug ). '</option>';
		echo '<option value="full-screen" '. selected($opts['css']['position'], 'full-screen', false).'>'. __( 'Full Screen', $this->plugin_slug ). '</option>';

	}

	/**
	 * Add new trigger_actions to metabox
	 * @param array $opts plugin selected options
	 * @since     1.0.0
	 */
	function add_trigger_actions( $opts ) {

		echo '<option value="trigger-click" '. selected($opts['trigger'], 'trigger-click', false).'>'. __( 'Trigger on click of element with classname', $this->plugin_slug ). '</option>';
		echo '<option value="exit-intent" '. selected($opts['trigger'], 'exit-intent', false).'>'. __( 'Trigger when user try to leave the page (Exit Intent)', $this->plugin_slug ). '</option>';
		echo '<option value="visible" '. selected($opts['trigger'], 'visible', false).'>'. __( 'Trigger when element with classname is visible in user viewport', $this->plugin_slug ). '</option>';


	}

	/**
	 * Add new trigger_values to metabox
	 * @param array $opts plugin selected options
	 * @since     1.0.0
	 */
	function add_trigger_values( $opts ) {
		$trigger = isset( $opts['trigger_value'] ) ? esc_attr($opts['trigger_value']) : '';
		echo '<input type="text" class="spu-trigger-value small" name="spu[trigger_value]" value="'. $trigger .'"  />';
		
	}

	/**
	 * Add new trigger_values to metabox
	 * @param array $opts plugin selected options
	 * @since     1.5
	 */
	function add_custom_appearance( $opts ) {
		$defaults = array();
		$defaults = $this->add_default_values( $defaults );
		if( ! isset( $opts['css']['button_bg'] ) )
			$opts['css']['button_bg'] = $defaults['css']['button_bg'];

		if( ! isset( $opts['css']['button_color'] ) )
			$opts['css']['button_color'] = $defaults['css']['button_color'];

		if( ! isset( $opts['css']['cta_bg2'] ) )
			$opts['css']['cta_bg2'] = $defaults['css']['cta_bg2'];

		?>
		<tr valign="top" class="spu-appearance">
			<td class="spu-button-bg">
				<label class="spu-label" for="spu-button-bg"><?php _e( 'Button Background', 'popups' ); ?></label>
				<input name="spu[css][button_bg]" id="spu-button-bg" type="text" class="spu-color-field" value="<?php echo esc_attr($opts['css']['button_bg']); ?>" />
			</td>
			<td class="spu-button-color">
				<label class="spu-label" for="spu-button-color"><?php _e( 'Button text', 'popups' ); ?></label>
				<input name="spu[css][button_color]" id="spu-button-color" type="text" class="spu-color-field" value="<?php echo esc_attr($opts['css']['button_color']); ?>" />
			</td>
			<td class="spu-cta_bg2" style="<?php echo $opts['optin_theme'] == 'cta' ? '': 'display: none;';?>">
				<label class="spu-label" for="spu-bg2"><?php _e( 'Secondary Background', 'popups' ); ?></label>
				<input name="spu[css][cta_bg2]" id="spu-cta-bg2" type="text" class="spu-color-field" value="<?php echo esc_attr($opts['css']['cta_bg2']); ?>" />
			</td>
		</tr>
		<?php
	}

	/**
	 * Add advanced closing methods
	 * @param array $opts plugin selected options
	 * @since     1.0.0
	 */
	function add_closing_methods( $opts ) {
		?>
			<tr valign="top">
				<th><label for="spu_disable_close"><?php _e( 'Disable close button?', $this->plugin_slug ); ?></label></th>
				<td colspan="3">
					<label><input type="radio" id="spu_disable_close_1" name="spu[disable_close]" value="1" <?php checked($opts['disable_close'], 1); ?> /> <?php _e( 'Yes' ); ?></label> &nbsp;
					<label><input type="radio" id="spu_disable_close_0" name="spu[disable_close]" value="0" <?php checked($opts['disable_close'], 0); ?> /> <?php _e( 'No' ); ?></label> &nbsp;
					<p class="help"><?php _e( 'Removes the close button in the popup', $this->plugin_slug ); ?></p>
				</td>	
			</tr>
			<tr valign="top">
				<th><label for="spu_disable_advanced_close"><?php _e( 'Disable advanced close methods?', $this->plugin_slug ); ?></label></th>
				<td colspan="3">
					<label><input type="radio" id="spu_disable_advanced_close_1" name="spu[disable_advanced_close]" value="1" <?php checked($opts['disable_advanced_close'], 1); ?> /> <?php _e( 'Yes' ); ?></label> &nbsp;
					<label><input type="radio" id="spu_disable_advanced_close_0" name="spu[disable_advanced_close]" value="0" <?php checked($opts['disable_advanced_close'], 0); ?> /> <?php _e( 'No' ); ?></label> &nbsp;
					<p class="help"><?php _e( 'Removes the ability to close the popup by pressing ESC key or clicking outside the popup', $this->plugin_slug ); ?></p>
				</td>	
			</tr>
		<?php	
	}

	/**
	 * Add auto close timer
	 * @since     1.0.0
	 * @param array $opts plugin selected options
	 */
	function add_autoclose_timer( $opts ) {

		?>
			<tr valign="top">
				<th><label for="spu_autoclose"><?php _e( 'Close popup after X seconds', $this->plugin_slug ); ?></label></th>
				<td colspan="3">
					<input type="number" id="spu_autoclose" name="spu[autoclose]" min="0" step="1" value="<?php echo esc_attr($opts['autoclose']); ?>" />
					<p class="help"><?php _e( 'Popup will auto close after X seconds and a timer will show. Leave 0 to disable', $this->plugin_slug ); ?></p>
				</td>	
			</tr>	
		<?php	
	}

	/**
	 * Add Google Analytics fields
	 * @since     1.0.0
	 * @param array $opts plugin selected options
	 */
	function add_ga_events_fields( $opts ) {

		if( empty( $this->spu_settings['ua_code'] ) )
			return; 
		?>
			<tr valign="top">
				<th><label for="spu_event_cat"><?php _e( 'Event Category', $this->plugin_slug ); ?></label></th>
				<td colspan="3">
					<input type="text" id="spu_event_cat" name="spu[event_cat]" min="0" step="1" value="<?php echo isset($opts['event_cat'])? esc_attr($opts['event_cat']) : ''; ?>" />
					<p class="help"><?php _e( 'Enter your Google Analitycs event category name. Default to: Popup Event ', $this->plugin_slug ); ?></p>
				</td>	
			</tr>
			<tr valign="top">
				<th><label for="spu_event_c_action"><?php _e( 'Event conversion Action', $this->plugin_slug ); ?></label></th>
				<td colspan="3">
					<input type="text" id="spu_event_c_action" name="spu[event_c_action]" min="0" step="1" value="<?php echo isset($opts['event_c_action'])? esc_attr($opts['event_c_action']) : ''; ?>" />
					<p class="help"><?php _e( 'Enter your Google Analitycs event action name for conversions. Default to: conversion ', $this->plugin_slug ); ?></p>
				</td>	
			</tr>
			<tr valign="top">
				<th><label for="spu_event_i_action"><?php _e( 'Event Impression Action', $this->plugin_slug ); ?></label></th>
				<td colspan="3">
					<input type="text" id="spu_event_i_action" name="spu[event_i_action]" min="0" step="1" value="<?php echo isset($opts['event_i_action'])? esc_attr($opts['event_i_action']) : ''; ?>" />
					<p class="help"><?php _e( 'Enter your Google Analitycs event action name for impressions. Default to: impression ', $this->plugin_slug ); ?></p>
				</td>	
			</tr>
			<tr valign="top">
				<th><label for="spu_event_label"><?php _e( 'Event Label', $this->plugin_slug ); ?></label></th>
				<td colspan="3">
					<input type="text" id="spu_event_label" name="spu[event_label]" min="0" step="1" value="<?php echo isset($opts['event_label'])? esc_attr($opts['event_label']) : ''; ?>" />
					<p class="help"><?php _e( 'Enter your Google Analitycs event label name. Default to: spu-POPUP_ID ', $this->plugin_slug ); ?></p>
				</td>	
			</tr>	
		<?php	
	}

	/**
	 * Add thanks Message
	 * @param array $opts plugin selected options
	 * @since     1.0.0 
	 */
	function add_thanks_msg( $opts ) {
		?>
			<tr valign="top">
				<th><label for="spu_thanks_msg"><?php _e( 'Show a thanks message', $this->plugin_slug ); ?></label></th>
				<td colspan="3">
					<?php 
					$settings = array( 'textarea_name' => 'spu[thanks_msg]', 'media_buttons' => false, 'quicktags' => true, 'tinymce' => true, 'textarea_rows' => 10 );
					wp_editor( $opts['thanks_msg'], 'spueditor', $settings );?>
					<p class="help"><?php _e( 'Popup will auto close after X seconds and a timer will show. Leave 0 to disable', $this->plugin_slug ); ?></p>
				</td>	
			</tr>
		<?php			
	}	

	/**
	 * Sanitize premium new options
	 * @param array $opts plugin selected options
	 * @since     1.0.0
	 */
	function sanitize_options( $opts ) {
		
		$opts['trigger_value']	 = sanitize_text_field( $opts['trigger_value'] );
		$opts['autoclose'] 		 = absint( sanitize_text_field( $opts['autoclose'] ) );

		return $opts;
	}


	/**
	 * Add default values for new premium options
	 *
	 * @param array $default default plugin options
	 *
	 * @since     1.0.0
	 * @return array
	 */
	function add_default_values( $default ) {

		$default['disable_close']			= 0;
		$default['disable_advanced_close'] 	= 0;
		$default['autoclose'] 				= 0;
		$default['dsampling'] 				= 0;
		$default['dsamplingrate'] 			= 100;

		// optin defaults
		$default['optin'] 				    = 0;
		$default['optin_list'] 				= 0;
		$default['optin_list_segments']		= 0;
		$default['optin_display_name']		= 0;
		$default['optin_theme']		        = 'simple';
		$default['optin_placeholder']		= __('Your email', $this->plugin_slug);
		$default['optin_name_placeholder']	= __('Your name', $this->plugin_slug);
		$default['optin_submit']	        = __('Submit', $this->plugin_slug);
		$default['optin_success']	        = 'Thanks for subscribing! Please check your email for further instructions.';
		$default['optin_redirect']	        = '';
		$default['optin_pass_redirect']	    = 0;
		$default['css']['button_color']	    = '#fff';
		$default['css']['button_bg']	    = '#50bbe8';
		$default['css']['cta_bg2']	        = '#cdd1d4';


		return $default;
	}	

	/**
	 * Register and enqueue admin-specific style sheet.
	 * 
	 * @since     1.0.0
	 *
	 * @return    null    Return early if no settings page is registered.
	 */
	public function enqueue_admin_styles() {

		global $pagenow;

		if ( get_post_type() == 'spucpt' || ( isset($_GET['post_type']) && 'spucpt' == $_GET['post_type'] ) ) {
		
			wp_enqueue_style( 'spup-admin-css', plugins_url( 'assets/css/admin.css', __FILE__ ) , '', PopupsP::VERSION );

		}
	}

	/**
	 * Register and enqueue admin-specific JavaScript.
	 *
	 * @TODO:
	 *
	 * - Rename "Plugin_Name" to the name your plugin
	 *
	 * @since     1.0.0
	 *
	 * @return    null    Return early if no settings page is registered.
	 */
	public function enqueue_admin_scripts() {
		global $pagenow;
		$post_type = isset($_GET['post_type']) ? $_GET['post_type'] : get_post_type();

		if (  $post_type !== 'spucpt' || !in_array( $pagenow, array( 'post-new.php', 'post.php', 'edit.php' ) ) ) {
			return;
		}
		wp_enqueue_script( 'spup-admin-js', plugins_url( 'assets/js/admin.js', __FILE__ ) , '', PopupsP::VERSION );
		wp_localize_script( 'spup-admin-js', 'spuvar', array(
			'l18n' => array(
				'reset_stats'   => __('Do you want to reset stats? This can\'t be undone', $this->plugin_slug)
			)
		));
		if( isset($_GET['page']) && 'spu_integrations' == $_GET['page'] )  {
			wp_enqueue_script( 'spup-postmessage', plugins_url( 'assets/js/min/jquery.postmessage.min.js', __FILE__ ) , '', PopupsP::VERSION );
		}
	}

	/**
	 * Handle Licences and updates
	 * Handle Licences and updates
	 * @since 1.0.0
	 */
	public function handle_license(){
		// Load our custom updater
		if( ! class_exists( 'Spu_License' ) ) {
			require_once(dirname (__FILE__).'/includes/class-license-handler.php' );
		}

		$opts = get_option( 'spu_settings');

		$license = isset( $opts['spu_license_key'] ) ? $opts['spu_license_key'] : '';

		if( !empty( $license ) ) {

			$eddc_license = new Spu_License( SPUP_PLUGIN_FILE, PopupsP::PLUGIN_NAME, PopupsP::VERSION	, 'Damian Logghe', $license );
		
		}
	

	}

	/**
	 * Prints license field on admin settings page
	 * @return [type] [description]
	 * @since  1.1
	 */
	function license_field(){
		$opts = $this->spu_settings;
		?>
		<tr valign="top" class="">
			<th><label for="license"><?php _e( 'Enter your license key', $this->plugin_slug ); ?></label></th>
			<td colspan="3">
				<label><input type="text" id="license" name="spu_settings[spu_license_key]" value="<?php  echo @$opts['spu_license_key'];?>" class="regular-text <?php echo 'spu_license_' . get_option( 'spu_license_active' );?>" /> 
				<p class="help"><?php _e( 'Enter your license key to get automatic updates', $this->plugin_slug ); ?></p>
			</td>
			
		</tr>
		<?php			
	}


	/**
	 * Prints google ua field on admin settings page
	 * @since  1.2
	 */
	function google_ua_field(){
		$opts = $this->spu_settings;
		?>
		<tr valign="top" class="">
			<th><label for="uacode"><?php _e( 'Enter your uacode key', $this->plugin_slug ); ?></label></th>
			<td colspan="3">
				<label><input type="text" id="uacode" name="spu_settings[ua_code]" value="<?php  echo @$opts['ua_code'];?>" class="regular-text" /> 
				<p class="help"><?php _e( 'Enter your Google UA-XXXXXX code to track popups in analytics', $this->plugin_slug ); ?></p>
			</td>
			
		</tr>
		<?php			
	}

	/**
	 * Prints google ua field on admin settings page
	 * @since  1.3.2
	 */
	function data_sampling_field(){
		$opts = $this->spu_settings;
		?>
		<tr valign="top" class="">
			<th><label for="dsampling"><?php _e( 'Data Sampling', $this->plugin_slug ); ?></label></th>
			<td colspan="3">
				<label><input type="checkbox" id="dsampling" name="spu_settings[dsampling]" value="1" <?php checked(@$opts['dsampling'], 1); ?> />
					Rate: <input type="text" id="dsamplingrate" name="spu_settings[dsamplingrate]" value="<?php  echo empty( $opts['dsamplingrate'] ) ? '100' : $opts['dsamplingrate'];?>"/>
				<p class="help"><?php echo sprintf(__( 'If your site have lot of traffic, enable <a href="%s">data sampling</a>', $this->plugin_slug ), ''); ?></p>
			</td>

		</tr>
		<?php
	}


	/**
	 * Track impresions and conversion
	 * @uses EventsTracker
	 * @since  1.2
	 */
	function track_events(){
		require_once( plugin_dir_path( __FILE__ ) . 'includes/class-events-tracker.php');

		$data 		= $_POST;
		$tracker 	= new EventsTracker( $data );

		die();
	}

	/**
	 * Modify free support for premium one
	 * @return string file path
	 * @since 1.2.1
	 */
	public function support_metabox() {

		return dirname(__FILE__) . '/views/metabox-support.php';
	}


	/**
	 * Add menu for integrations like Mailchimp, Aweber, etc
	 * @since  1.3
	 * @return  void
	 */
	public function add_integrations_menu() {

		add_submenu_page('edit.php?post_type=spucpt', 'Integrations', 'Integrations', apply_filters( 'spu/settings_page/roles', 'manage_options'), 'spu_integrations', array( $this, 'integrations_page' ) );

	}

	/**
	 * Integrations page of the plugin
	 * @since  1.3
	 * @return  void
	 */
	public function integrations_page(){

		$defaults = apply_filters( 'spu/settings_page/defaults_integrations', array(
			'mailchimp'     => array('mc_api'),
			'aweber'        => array('aweber_auth','access_token','access_token_secret')
		));
		$opts = apply_filters( 'spu/settings_page/integrations', get_option( 'spu_integrations', $defaults ) );

		if (  isset( $_POST['spu_nonce'] ) && wp_verify_nonce( $_POST['spu_nonce'], 'spu_save_settings' ) ) {
			$opts = wp_parse_args(esc_sql( @$_POST['spu_integrations'] ), $defaults);
			update_option( 'spu_integrations' , $opts );

		}
		$mc_force_renew = $aweber_force_renew = $ccontact_force_renew = $gr_force_renew = $infusion_force_renew =  $ac_force_renew = false;
		$mc_connected = $gr_connected = $aweber = $ccontact = $infusion = $nl_connected = $ac_connected = false;

		// mailchimp
		if( !empty($opts['mailchimp']['mc_api']) ) {
			$this->providers['mailchimp'] = new SPU_mailchimp();
			$mc_connected = $this->providers['mailchimp']->is_connected();

			if( $mc_connected ) {
				if ( isset($_GET['spu_nonce']) && wp_verify_nonce($_GET['spu_nonce'], 'spu_mc_renew') )
					$mc_force_renew = true;

				$mc_lists = $this->providers['mailchimp']->get_lists($mc_force_renew);
			}
		}
		// activecampaign
		if( !empty($opts['activecampaign']['ac_api']) ) {
			$this->providers['activecampaign'] = new SPU_activecampaign();
			$ac_connected = $this->providers['activecampaign']->is_connected();

			if( $ac_connected ) {
				if ( isset($_GET['spu_nonce']) && wp_verify_nonce($_GET['spu_nonce'], 'spu_ac_renew') )
					$ac_force_renew = true;

				$ac_lists = $this->providers['activecampaign']->get_lists($ac_force_renew);
			}
		}
		// getresponse
		if( !empty($opts['getresponse']['gr_api']) ) {
			$this->providers['getresponse'] = new SPU_getresponse();
			$gr_connected = $this->providers['getresponse']->is_connected();

			if( $gr_connected ) {
				if ( isset($_GET['spu_nonce']) && wp_verify_nonce($_GET['spu_nonce'], 'spu_gr_renew') )
					$gr_force_renew = true;

				$gr_lists = $this->providers['getresponse']->get_lists($gr_force_renew);
			}
		}
		// Aweber
		if ( isset($_GET['spu_nonce']) && wp_verify_nonce($_GET['spu_nonce'], 'spu_aweber_disconnect') ) {
			$opts['aweber']['aweber_auth']  = '';
			$opts['aweber']['access_token'] = '';
			$opts['aweber']['access_token_secret'] = '';
			update_option( 'spu_integrations' , $opts );
		}
		if( !empty($opts['aweber']['aweber_auth']) ) {
			$this->providers['aweber'] = new SPU_aweber();
			if( empty($opts['aweber']['access_token'])) {
				list( $opts['aweber']['access_token'] , $opts['aweber']['access_token_secret'] ) = $this->providers['aweber']->authentificate();
			}

			$aweber = $this->providers['aweber']->is_connected();

			if( $aweber ) {
				if ( isset($_GET['spu_nonce']) && wp_verify_nonce($_GET['spu_nonce'], 'spu_aweber_renew') )
					$aweber_force_renew = true;
				$aweber_lists = $this->providers['aweber']->get_lists($aweber_force_renew);
			}
		}
		// constant contact
		if ( isset($_GET['spu_nonce']) && wp_verify_nonce($_GET['spu_nonce'], 'spu_ccontact_disconnect') ) {
			$opts['ccontact']['ccontact_auth']  = '';
			update_option( 'spu_integrations' , $opts );
		}

		if( !empty($opts['ccontact']['ccontact_auth']) ) {
			$this->providers['ccontact'] = new SPU_ccontact();
			$ccontact = $this->providers['ccontact']->is_connected();

			if( $ccontact ) {
				if ( isset($_GET['spu_nonce']) && wp_verify_nonce($_GET['spu_nonce'], 'spu_ccontact_renew') )
					$ccontact_force_renew = true;
				$ccontact_lists = $this->providers['ccontact']->get_lists($ccontact_force_renew);
			}
		}

		// Infusion soft
		if ( isset($_GET['spu_nonce']) && wp_verify_nonce($_GET['spu_nonce'], 'spu_infusion_disconnect') ) {
			$opts['infusion']['access_token']  = '';
			update_option( 'spu_integrations' , $opts );
		}

		if( !empty($opts['infusion']['infusion_auth']) ) {
			$this->providers['infusion'] = new SPU_infusion();
			$infusion = $this->providers['infusion']->is_connected();

			if( $infusion ) {
				if ( isset($_GET['spu_nonce']) && wp_verify_nonce($_GET['spu_nonce'], 'spu_infusion_renew') )
					$infusion_force_renew = true;
				$infusion_lists = $this->providers['infusion']->get_lists($infusion_force_renew);
			}
		}
		// newsletter plugin
		if( !empty($opts['newsletter']['nl_api']) ) {
			$this->providers['newsletter'] = new SPU_newsletter();
			$nl_connected = $this->providers['newsletter']->is_connected();
			
			if( $nl_connected ) {
				$nl_lists = $this->providers['newsletter']->get_lists();
			}
		}
		include 'views/integrations-page.php';

	}

	/**
	 * Add premium metaboxes
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'spu-integrations',
			'<i class="spu-icon-envelope spu-icon"></i>' . __( 'Optin Integrations', $this->plugin_slug ) . '<img src="'.admin_url('/images/loading.gif').'" alt="" class="spu-spinner optin-spinner"/>',
			array( $this, 'metabox_integrations' ),
			'spucpt',
			'normal',
			'core'
		);
	}

	/**
	 * Include integrations metabox
	 */
	function metabox_integrations($post, $metabox) {
		$opts = apply_filters('spu/metaboxes/get_box_options', $this->helper->get_box_options( $post->ID ), $post->ID );
		$this->spu_settings = apply_filters('spu/settings_page/opts', get_option( 'spu_settings' ) );
		$this->spu_integrations = apply_filters('spu/settings_page/integrations', get_option( 'spu_integrations' ) );
		$optin_lists = array();
		if( !empty( $this->spu_integrations[$opts['optin']] ) ) {
			$provider_class = 'SPU_' . esc_attr( $opts['optin'] );
			$provider = new $provider_class;
			$optin_lists = $provider->get_lists();
		}
		include_once('views/metaboxes/integrations.php');
	}

	/**
	 * Load all files and classes needed to make this work
	 * @since 1.3
	 */
	private function loadDependencies() {
		require_once( dirname( __FILE__ ) . '/includes/class-spu-errors.php' );
		require_once( dirname( __FILE__ ) . '/includes/providers/interface-spu-providers.php' );
		require_once( dirname( __FILE__ ) . '/includes/providers/class-mailchimp.php' );
		require_once( dirname( __FILE__ ) . '/includes/providers/class-newsletter.php' );
		require_once( dirname( __FILE__ ) . '/includes/providers/class-getresponse.php' );
		require_once( dirname( __FILE__ ) . '/includes/providers/class-aweber.php' );
		require_once( dirname( __FILE__ ) . '/includes/providers/class-wysija.php' );
		require_once( dirname( __FILE__ ) . '/includes/providers/class-postmatic.php' );
		require_once( dirname( __FILE__ ) . '/includes/providers/class-ccontact.php' );
		require_once( dirname( __FILE__ ) . '/includes/providers/class-activecampaign.php' );
		require_once( dirname( __FILE__ ) . '/includes/providers/class-infusionsoft.php' );
		require_once( dirname( __FILE__ ) . '/includes/apis/class-spu-mailchimp-api.php' );
		require_once( dirname( __FILE__ ) . '/includes/apis/class-spu-getresponse-api.php' );
		require_once( dirname( __FILE__ ) . '/includes/apis/class-spu-wysija-api.php' );
		require_once( dirname( __FILE__ ) . '/includes/apis/class-spu-ccontact-api.php' );
		require_once( dirname( __FILE__ ) . '/includes/vendors/getresponse/jsonRPCClient.php' );
		require_once( dirname( __FILE__ ) . '/includes/vendors/activecampaign/ActiveCampaign.class.php' );
	}

	/**
	 * Retrieve lists if exist for the given email provider
	 * @since 1.3
	 */
	public function ajax_get_optin_lists() {

		$optin = $_POST['optin'];
		$this->spu_settings = apply_filters('spu/settings_page/opts', get_option( 'spu_settings' ) );
		$this->spu_integrations = apply_filters('spu/settings_page/integrations', get_option( 'spu_integrations' ) );

		$provider_class = 'SPU_' . esc_attr( $optin );
		$provider = new $provider_class;

		$lists = $provider->get_lists();
		if ( $lists ) {
			echo '<option value="">'.__('Choose one', $this->plugin_slug ).'</option>';
			foreach ( $lists as $list ) { ?>
				<option value="<?php echo esc_html( $list->id ); ?>"><?php echo esc_html( $list->name ); ?></option>
			<?php }
		}

		die();
	}

	/**
	 * Retrieve segments if exists for selected lists
	 * @since 1.3
	 */
	public function ajax_get_optin_list_segments() {

		$list_id = $_POST['list'];
		$optin = $_POST['optin'];
		$this->spu_settings = apply_filters('spu/settings_page/opts', get_option( 'spu_settings' ) );
		$this->spu_integrations = apply_filters('spu/settings_page/integrations', get_option( 'spu_integrations' ) );

		$provider_class = 'SPU_' . esc_attr( $optin );
		$provider = new $provider_class;
		$lists = $provider->get_lists();
		if ( $lists ) {
			foreach ( $lists as $list ) {
				if( $list_id == $list->id ) {
					if( !empty( $list->interest_groupings) ) {
						foreach ( $list->interest_groupings as $group ) {
							if ( ! empty( $group->name ) ) {
								echo '<h4 class="grouping-name">' . $group->name . '</h4>';
								foreach ( $group->groups as $g ) {
									echo '<input type="checkbox" value="' . $g->name . '" name="spu[optin_list_segments][' . $group->id . '][]"/>' . $g->name;
								}
							}
						}
					}
					break;
				}
			}
		}

		die();
	}

	/**
	 * Add the stylesheet for optin in editor
	 * @since 1.3
	 */
	function optin_editor_styles() {
		$post_type = isset($_GET['post']) ? get_post_type($_GET['post']) : '';

		if( 'spucpt' == $post_type || get_post_type() == 'spucpt' || (isset( $_GET['post_type']) && $_GET['post_type'] == 'spucpt') ) {
			add_editor_style( SPUP_PLUGIN_URL .'admin/assets/css/editor-style.css' );
		}
	}

	/**
	 * Function that add integrations that don't require to be listed in integrations page
	 * like for example mailpoet
	 *
	 * @param $integrations array
	 *
	 * @return array
	 */
	function add_custom_integrations( $integrations ) {
		// MAilpoet
		if ( defined('WYSIJA') )
			$integrations['wysija'] = 1;

		// Postmatic
		if ( class_exists('Prompt_Core') && Prompt_Core::$options->get( 'prompt_key' ))
			$integrations['postmatic'] = 1;

		return $integrations;
	}

	/**
	 * Catch the row action to reset stats and reset the counters
	 * Then in redirect to popups page to clear url
	 */
	function custom_actions() {
		//checks
		if ( ! isset($_GET['spu_action']) || !isset($_GET['spu_nonce']) || empty( $_GET['post'] ) )
			return;
		global $wpdb;

		$pid = absint( $_GET['post'] );

		// Delete stats
		if (  $_GET['spu_action'] === 'spu_reset_stats' && wp_verify_nonce( $_GET['spu_nonce'], 'spu_reset_stats') ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->base_prefix}spu_hits_logs WHERE box_id = %d", array( $pid ) ) );
			delete_post_meta(esc_sql($pid), 'spup_hit_counter');
			wp_safe_redirect( admin_url('edit.php?post_type=spucpt') );
			exit;
		}

		if (  $_GET['spu_action'] === 'spu_ab_test' && wp_verify_nonce( $_GET['spu_nonce'], 'spu_ab_test') ) {
			$this->create_ab_post($pid);
			wp_safe_redirect( admin_url('edit.php?post_type=spucpt') );
			exit;
		}

		if (  $_GET['spu_action'] === 'spu_ab_live' && wp_verify_nonce( $_GET['spu_nonce'], 'spu_ab_live') ) {
			$this->make_ab_live($pid);
			wp_safe_redirect( admin_url('edit.php?post_type=spucpt') );
			exit;
		}
	}

	/**
	 * Make ab version live
	 * @param  Int $post_id
	 */
	private function make_ab_live( $post_id ) {
		global $wpdb;
		// delete ab_parent
		delete_post_meta( $post_id, 'spu_ab_parent' );

		$post_title = $wpdb->get_var( $wpdb->prepare( "SELECT post_title FROM $wpdb->posts WHERE ID = %d", array( $post_id ) ) );
		
		// remove -- from title
		$my_post = array(
		  'ID'           => $post_id,
		  'post_title'   => str_replace('-- ', '', $post_title)
		);

		// Update the post into the database
		wp_update_post( $my_post );
	}

	/**
	 * Duplicate spu post by giving ID
	 * @param $post_id
	 */
	private function create_ab_post( $post_id ) {
		global $wpdb;

		/*
		 * and all the original post data then
		 */
		$post = get_post( $post_id );

		$new_post_author = $post->post_author;

		/*
		 * if post data exists, create the post duplicate
		 */
		if (isset( $post ) && $post != null) {

			/*
			 * new post data array
			 */
			$args = array(
				'comment_status' => $post->comment_status,
				'ping_status'    => $post->ping_status,
				'post_author'    => $new_post_author,
				'post_content'   => $post->post_content,
				'post_excerpt'   => $post->post_excerpt,
				'post_name'      => $post->post_name,
				'post_parent'    => $post->post_parent,
				'post_date'      => date('Y-m-d H:j', strtotime($post->post_date) - 360 ),
				'post_password'  => $post->post_password,
				'post_status'    => 'draft',
				'post_title'     => '-- '. $post->post_title . ' A/B',
				'post_type'      => $post->post_type,
				'to_ping'        => $post->to_ping,
				'menu_order'     => $post->menu_order
			);

			/*
			 * insert the post by wp_insert_post() function
			 */
			$new_post_id = wp_insert_post( $args );

			/*
			 * get all current post terms ad set them to the new post draft
			 */
			$taxonomies = get_object_taxonomies( $post->post_type ); // returns array of taxonomy names for post type, ex array("category", "post_tag");
			foreach ( $taxonomies as $taxonomy ) {
				$post_terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'slugs' ) );
				wp_set_object_terms( $new_post_id, $post_terms, $taxonomy, false );
			}

			/*
			 * duplicate all post meta just in two SQL queries
			 */
			$post_meta_infos = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM $wpdb->postmeta WHERE post_id= %d " ,array( $post_id ) ) );
			if ( count( $post_meta_infos ) != 0 ) {
				$sql_query = "INSERT INTO $wpdb->postmeta (post_id, meta_key, meta_value) ";
				foreach ( $post_meta_infos as $meta_info ) {
					$meta_key        = $meta_info->meta_key;
					$meta_value      = addslashes( $meta_info->meta_value );
					$sql_query_sel[] = "SELECT $new_post_id, '$meta_key', '$meta_value'";
				}
				$sql_query .= implode( " UNION ALL ", $sql_query_sel );
				$wpdb->query( $sql_query );
			}
			// Update child ab
			update_post_meta( $new_post_id, 'spu_ab_parent', $post_id );
			update_post_meta( $post_id, 'spu_ab_group', $post_id );
		}
	}

	/**
	 * Hack the post_date on update so they all keep the same order. Create for A/B testing
	 * @param  [type] $data    [description]
	 * @param  [type] $postarr [description]
	 * @return [type]          [description]
	 */
	public function change_post_date( $data , $postarr ) {
		if( ! isset( $data['post_type'] ) || 'spucpt' !== $data['post_type'] ) 
			return $data;

		if( ! empty( $postarr['post_modified'] ) && ! empty( $postarr['post_modified_gmt'] ) && '0000-00-00 00:00:00' == $postarr['post_modified_gmt'] ) {
			$data['post_date'] = $postarr['post_modified'];
			$data['post_date_gmt'] = $postarr['post_modified'];
		} elseif( isset( $postarr['post_date'] ) ) {
			$data['post_date'] = $postarr['post_date'];
			$data['post_date_gmt'] = $postarr['post_date'];			
		}
		return $data;
	}
	
	/**
	 * Remove rules on A/B child popups and show message
	 * @param  [type] $post [description]
	 * @return [type]       [description]
	 */
	public function remove_rules( $post ) {

		if( ! isset( $post->ID ) )
			return;

		$ab_parent = get_post_meta( $post->ID, 'spu_ab_parent', true );

		if( empty( $ab_parent ) )
			return;
		?>
		<div class="spu_ab_child">
			<?php echo __( sprintf('To change the display rules edit the <a href="%s">main Popup rules</a> or <a href="%s">make this version live</a>', admin_url( 'post.php?post='. $ab_parent .'&action=edit' ), wp_nonce_url( admin_url('edit.php?post_type=spucpt&post='. $post->ID . '&spu_action=spu_ab_live'), 'spu_ab_live', 'spu_nonce') ), $this->plugin_slug );?>
		</div>
		<style type="text/css">
			#spu_rules{display:none;}
		</style>
		<?php
	}
}
