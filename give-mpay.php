<?php
/**
 * Plugin Name: Mpay for GiveWP
 * Plugin URI:  studiorimau.com
 * Description: Mpay for GiveWp
 * Version:     1.0.0
 * Author:      studiorimau.com
 * Author URI:  https://studiorimau.com
 * Text Domain: give-mpay
 * Domain Path: /languages
 */
// Exit if accessed directly.
if (!defined('ABSPATH')) {
  exit;
}

/**
 * Define constants.
 *
 * Required minimum versions, paths, urls, etc.
 */
if (!defined('GIVE_MPAY_MIN_GIVE_VER')) {
  define('GIVE_MPAY_MIN_GIVE_VER', '1.8.3');
}
if (!defined('GIVE_MPAY_MIN_PHP_VER')) {
  define('GIVE_MPAY_MIN_PHP_VER', '5.6.0');
}
if (!defined('GIVE_MPAY_PLUGIN_FILE')) {
  define('GIVE_MPAY_PLUGIN_FILE', __FILE__);
}
if (!defined('GIVE_MPAY_PLUGIN_DIR')) {
  define('GIVE_MPAY_PLUGIN_DIR', dirname(GIVE_MPAY_PLUGIN_FILE));
}
if (!defined('GIVE_MPAY_PLUGIN_URL')) {
  define('GIVE_MPAY_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if (!defined('GIVE_MPAY_BASENAME')) {
  define('GIVE_MPAY_BASENAME', plugin_basename(__FILE__));
}

if (!class_exists('Give_Mpay')):

  /**
   * Class Give_Mpay.
   */
  class Give_Mpay {

    /**
     * @var Give_Mpay The reference the *Singleton* instance of this class.
     */
    private static $instance;

    public $notices = array();


    /**
     * Returns the *Singleton* instance of this class.
     *
     * @return Give_Mpay The *Singleton* instance.
     */
    public static function get_instance() {
      if (null === self::$instance) {
        self::$instance = new self();
      }

      return self::$instance;
    }

    /**
     * Private clone method to prevent cloning of the instance of the
     * *Singleton* instance.
     *
     * @return void
     */
    private function __clone() {

    }

    /**
     * Give_Mpay constructor.
     *
     * Protected constructor to prevent creating a new instance of the
     * *Singleton* via the `new` operator from outside of this class.
     */
    protected function __construct() {
      add_action('admin_init', array($this, 'check_environment'));
      add_action('admin_notices', array($this, 'admin_notices'), 15);
      add_action('plugins_loaded', array($this, 'init'));
	  add_action('init', array($this, 'rewrite_rules'));
      add_filter('query_vars', array($this, 'query_vars'), 10, 1);
      add_action('parse_request', array($this, 'parse_request'), 10, 1);
      register_activation_hook(__FILE__, array($this, 'flush_rules' ));

    }

    /**
     * Init the plugin after plugins_loaded so environment variables are set.
     */
    public function init() {

      // Don't hook anything else in the plugin if we're in an incompatible environment.
      if (self::get_environment_warning()) {
        return;
      }

      add_filter('give_payment_gateways', array($this, 'register_gateway'));

      $this->includes();
    }

    public function rewrite_rules(){
      add_rewrite_rule('mpay-payment-pg/?$', 'index.php?mpay-payment-pg=true', 'top');
  }

  public function flush_rules(){
      $this->rewrite_rules();
      flush_rewrite_rules();
  }

  public function query_vars($vars){
      $vars[] = 'mpay-payment-pg';
      return $vars;
  }

  public function parse_request($wp){
      if ( array_key_exists( 'mpay-payment-pg', $wp->query_vars ) ){
          include plugin_dir_path(__FILE__) . 'mpay-payment-pg.php';
          exit();
      }
  }

    /**
     * The primary sanity check, automatically disable the plugin on activation if it doesn't
     * meet minimum requirements.
     *
     * Based on http://wptavern.com/how-to-prevent-wordpress-plugins-from-activating-on-sites-with-incompatible-hosting-environments
     */
    public static function activation_check() {
      $environment_warning = self::get_environment_warning(true);
      if ($environment_warning) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die($environment_warning);
      }
    }

    /**
     * Check the server environment.
     *
     * The backup sanity check, in case the plugin is activated in a weird way,
     * or the environment changes after activation.
     */
    public function check_environment() {

      $environment_warning = self::get_environment_warning();
      if ($environment_warning && is_plugin_active(plugin_basename(__FILE__))) {
        deactivate_plugins(plugin_basename(__FILE__));
        $this->add_admin_notice('bad_environment', 'error', $environment_warning);
        if (isset($_GET['activate'])) {
          unset($_GET['activate']);
        }
      }

      // Check for if give plugin activate or not.
      $is_give_active = defined('GIVE_PLUGIN_BASENAME') ? is_plugin_active(GIVE_PLUGIN_BASENAME) : false;
      // Check to see if Give is activated, if it isn't deactivate and show a banner.
      if (is_admin() && current_user_can('activate_plugins') && !$is_give_active) {

        $this->add_admin_notice('prompt_give_activate', 'error', sprintf(__('<strong>Activation Error:</strong> You must have the <a href="%s" target="_blank">Give</a> plugin installed and activated for Mpay to activate.', 'give-mpay'), 'https://givewp.com'));

        // Don't let this plugin activate
        deactivate_plugins(plugin_basename(__FILE__));

        if (isset($_GET['activate'])) {
          unset($_GET['activate']);
        }

        return false;
      }

      // Check min Give version.
      if (defined('GIVE_MPAY_MIN_GIVE_VER') && version_compare(GIVE_VERSION, GIVE_MPAY_MIN_GIVE_VER, '<')) {

        $this->add_admin_notice('prompt_give_version_update', 'error', sprintf(__('<strong>Activation Error:</strong> You must have the <a href="%s" target="_blank">Give</a> core version %s+ for the Give Mpay add-on to activate.', 'give-mpay'), 'https://givewp.com', GIVE_MPAY_MIN_GIVE_VER));

        // Don't let this plugin activate.
        deactivate_plugins(plugin_basename(__FILE__));

        if (isset($_GET['activate'])) {
          unset($_GET['activate']);
        }

        return false;
      }
    }

    /**
     * Environment warnings.
     *
     * Checks the environment for compatibility problems.
     * Returns a string with the first incompatibility found or false if the environment has no problems.
     *
     * @param bool $during_activation
     *
     * @return bool|mixed|string
     */
    public static function get_environment_warning($during_activation = false) {

      if (version_compare(phpversion(), GIVE_MPAY_MIN_PHP_VER, '<')) {
        if ($during_activation) {
          $message = __('The plugin could not be activated. The minimum PHP version required for this plugin is %1$s. You are running %2$s. Please contact your web host to upgrade your server\'s PHP version.', 'give-mpay');
        } else {
          $message = __('The plugin has been deactivated. The minimum PHP version required for this plugin is %1$s. You are running %2$s.', 'give-mpay');
        }

        return sprintf($message, GIVE_MPAY_MIN_PHP_VER, phpversion());
      }

      if (!function_exists('curl_init')) {

        if ($during_activation) {
          return __('The plugin could not be activated. cURL is not installed. Please contact your web host to install cURL.', 'give-mpay');
        }

        return __('The plugin has been deactivated. cURL is not installed. Please contact your web host to install cURL.', 'give-mpay');
      }

      return false;
    }

    public function add_admin_notice($slug, $class, $message)
    {
        $this->notices[$slug] = array(
            'class' => $class,
            'message' => $message,
        );
    }

    /**
     * Display admin notices.
     */
    public function admin_notices()
    {
        $allowed_tags = array(
            'a' => array(
                'href' => array(),
                'title' => array(),
                'class' => array(),
                'id' => array()
            ),
            'br' => array(),
            'em' => array(),
            'span' => array(
                'class' => array(),
            ),
            'strong' => array(),
        );
        foreach ((array) $this->notices as $notice_key => $notice) {
            echo "<div class='" . esc_attr($notice['class']) . "'><p>";
            echo wp_kses($notice['message'], $allowed_tags);
            echo '</p></div>';
        }
    }

    /**
     * Give Mpay Includes.
     */
    private function includes() {

      // Checks if Give is installed.
      if (!class_exists('Give')) {
        return false;
      }

      if (is_admin()) {
        include GIVE_MPAY_PLUGIN_DIR . '/includes/admin/give-mpay-activation.php';
        include GIVE_MPAY_PLUGIN_DIR . '/includes/admin/give-mpay-settings.php';
        include GIVE_MPAY_PLUGIN_DIR . '/includes/admin/give-mpay-settings-metabox.php';
      }
      include GIVE_MPAY_PLUGIN_DIR . '/includes/give-mpay-gateway.php';
    }

    /**
     * Register the Mpay.
     *
     * @access      public
     * @since       1.0
     *
     * @param $gateways array
     *
     * @return array
     */
    public function register_gateway($gateways) {

      // Format: ID => Name
      $label = array(
        'admin_label'    => __('Mpay', 'give-mpay'),
        'checkout_label' => __('Mpay', 'give-mpay'),
      );

      $gateways['mpay'] = apply_filters('give_mpay_label', $label);

      return $gateways;
    }
  }

  $GLOBALS['give_mpay'] = Give_Mpay::get_instance();
  register_activation_hook(__FILE__, array('Give_Mpay', 'activation_check'));

endif; // End if class_exists check.
