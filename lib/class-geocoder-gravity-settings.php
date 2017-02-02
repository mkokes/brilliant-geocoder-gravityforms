<?php

if(!class_exists('GFForms')){
	die();
}

/**
 * This class handles geocoder related settings.
 */
class Geocoder_for_Gravity extends GFAddOn {
	/**
	 * Plugin version.
	 *
	 * @var $_version
	 */
	protected $_version = GFG_VERSION;
	/**
	 * Min gravityforms version.
	 *
	 * @var $_min_gravityforms_version
	 */
	protected $_min_gravityforms_version = '2.1.2';
	/**
	 * What's the slug.
	 *
	 * @var $_slug
	 */
	protected $_slug = 'geocoderforgf';
	/**
	 * A path to this plugins file. Relative to plugins folder.
	 *
	 * @var $_path
	 */
	protected $_path = 'geocoder-gravityforms/geocoder-gravityforms.php';
	/**
	 * Full path to this file.
	 *
	 * @var $_full_path
	 */
	protected $_full_path = __FILE__;
	/**
	 * What's the title of this addon.
	 *
	 * @var $_title
	 */
	protected $_title = 'Geocoder for GravityForms';
	/**
	 * What's the short title of this addon.
	 *
	 * @var $_short_title
	 */
	protected $_short_title = 'Geocoder';
	/**
	 * An instance variable.
	 *
	 * @var $_instance
	 */
	private static $_instance = null;

	/**
	 * Returns an instance of this class, and stores it in the $_instance property.
	 *
	 * @return object $_instance An instance of this class.
	 **/
	public static function get_instance() {
		if ( self::$_instance == null ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Called by GF when initializing plugins.
	 *
	 * Set up actions and filters.
	 */
	public function init(){
		parent::init();
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'enqueue_scripts', array( $this, 'enqueue_scripts' ), 10, 2 );
		add_filter( 'gform_form_settings', array( $this, 'gform_form_settings' ), 10, 2 );
		add_filter( 'gform_pre_form_settings_save', array( $this, 'gform_pre_form_settings_save' ) );
	}

	/**
	 * Get a list of all supported geocoders along with any settings.
	 */
	public function get_geocoders() {
		$geocoders = array(
			array(
				'geocoder' => 'OSM Nomination simple query',
				'geocoder_engine' => 'nomination',
			),
			array(
				'geocoder' => 'OSM Nomination full address',
				'geocoder_engine' => 'nomination',
			)
		);

		$geocoders = apply_filters( 'gfg_geocoders', $geocoders );

		return $geocoders;
	}

	/**
	 * Get the engine for a geocoder, given its 'geocoder' label
	 *
	 * @param string $in_use_geocoder The Geocoder in use.
	 */
	public function get_engine_for_geocoder( $in_use_geocoder ) {
		$geocoders = $this->get_geocoders();
		foreach( $geocoders as $geocoder ) {

			if ( !array_key_exists( 'geocoder', $geocoder ) ) {
				continue;
			}

			if ( $geocoder[ 'geocoder' ] === $in_use_geocoder ) {
				return $geocoder[ 'geocoder_engine' ];
			}
		}

		return false;
	}

	/**
	 * Make the settings fields.
	 */
	public function plugin_settings_fields() {
		$settings = $this->get_plugin_settings();

		$geocoders = $this->get_geocoders();

		$geocoders = array_filter( $geocoders, function( $g ){
			return array_key_exists( 'label', $g );
		});

		$geocoders[] = array(
			'id'    => 'save_button',
			'type'  => 'save',
			'value' => 'Update',
		);

		$form_def = array(
			array(
				'description' => $this->plugin_settings_description(),
				'fields' => $geocoders
			)
		);

		return $form_def;
	}

	/**
	 * Prepare custom app settings settings description.
	 *
	 * @return string $description
	 */
	public function plugin_settings_description() {
		/* Introduction. */
		$description = '';
		$description .= '<h2>Geocoding settings</h2>';
		$description .= '<p>http://wiki.openstreetmap.org/wiki/Nominatim, https://geocod.io/docs/#single-address</p>';

		return $description;
	}

	/**
	 * Enqueue scripts on the editor pages.
	 */
	public function admin_enqueue_scripts() {
		if ( GFForms::get_page() === 'form_editor' ) {
			$base_url = plugins_url( '', dirname( __FILE__ ) );
			wp_enqueue_script( 'form_admin_geocode', $base_url . '/assets/form_admin_geocode.js', array( 'jquery' ), $this->_version );
			wp_enqueue_style( 'form_admin_geocode', $base_url . '/assets/form_admin_geocode.css', array( ), $this->_version );
		}
	}

	/**
	 * Enqueue scripts on the regular non-editor pages.
	 *
	 * @param object $form The form.
	 * @param bool $is_ajax Bool if the form is being submitted by ajax.
	 */
	public function enqueue_scripts( $form = '', $is_ajax = false ) {
		parent::enqueue_scripts( $form, $is_ajax );
		$base_url = plugins_url( '', dirname( __FILE__ ) );
		wp_enqueue_script( 'gfg_geocode', $base_url . '/assets/form_geocode.js', array( 'jquery' ), $this->_version );
	}

	/**
	 * Get the settings to display on a form's individual settings page.
	 *
	 * @param array $settings The existing settings.
	 * @param object $form The current form.
	 */
	public function gform_form_settings( $settings, $form ) {

		// Get plugin settings so we can see if we have needed API keys 
		$plugin_settings = $this->get_plugin_settings();

		// See which geocoder we're using. Default to the OSM Nomination geocoder
		$selected_geocoder = rgar( $form, 'which_geocoder' );
		$selected_geocoder = ( empty( $selected_geocoder ) ? 'OSM Nomination simple query' : $selected_geocoder );

		// Build up the options
		$options = '';
		$geocoders = $this->get_geocoders();
		foreach( $geocoders as $geocoder ) {

			// Check if we have the required keys for the service
			if ( array_key_exists( 'label', $geocoder ) && empty( $plugin_settings[ $geocoder['name'] ] ) ) {
				continue;
			}

			if ( !array_key_exists( 'geocoder', $geocoder ) ) {
				continue;
			}

			$selected = '';
			if ( $selected_geocoder === $geocoder['geocoder'] ) {
				$selected = ' selected="selected"';
			} 

			$options[$geocoder['geocoder']] = '<option' . $selected . ' value="' . esc_attr( $geocoder['geocoder'] ) . '">' . esc_html( $geocoder['geocoder'] ) . '</option>';
		}

		ksort( $options );

		// Make the settings string
		$setting = '<tr><th><label for="which_geocoder">Geocoder engine</label></th><td><select name="which_geocoder">';
		$setting .= implode('',$options);
		$setting .= '</select></td></tr>';

		$settings['Geocoder']['which_geocoder'] = $setting;

		return $settings;
	}

	/**
	 * Save off the settings for the form.
	 *
	 * @param object $form The current form.
	 */
	public function gform_pre_form_settings_save( $form ) {
		$form['which_geocoder'] = trim( rgpost( 'which_geocoder' ) );
		return $form;
	}
}
