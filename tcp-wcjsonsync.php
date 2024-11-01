<?php
/*
  Plugin Name: TCP Product Sync
  Plugin URI: 
  Description: Sync WooCommerce products from another WooCommerce site to your own store.
  Version: 1.0.0
  WC tested up to: 3.5.8
  Author: TCP Team
  Author URI: https://www.thecartpress.com/
 */

defined('ABSPATH') or exit;

class TCP_wcjsonsync {

  const ENABLE_DEBUG = false;
  const PER_PAGE = 10;
  const MAX_RETRY = 5;
  const MAX_RESELLERS = 1;
  const PLUGIN_ID = 'tcp-wcjsonsync';
  const ASSET_VERSION = '1.0.0';
  
  var $_premium_installed;
  var $_license_active;

  function __construct() {
    
    // check woocommerce is active
    if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
      return;
    }

    add_filter('plugin_action_links_'. plugin_basename(__FILE__), [$this, 'plugin_links']);
    add_action('admin_enqueue_scripts', [$this, 'load_js_and_css']);

    require_once __DIR__ .'/tcp-menu.php';
    require_once __DIR__ .'/admin.php';
    require_once __DIR__ .'/sync.php';
    require_once __DIR__ .'/json.php';
  }

  //---------------------------------------------------------------------------
  // hooks
  //---------------------------------------------------------------------------

  function plugin_links($links) {
    $plugin_links = [
      '<a href="'. admin_url('admin.php?page=wcjsonsync_admin') .'">'. __('Settings', 'wcjsonsync') .'</a>'
    ];
    return array_merge($plugin_links, $links);
  }

  function load_js_and_css($handle) {
    if (isset($_GET['page']) && $_GET['page'] == 'wcjsonsync_admin') {
      wp_enqueue_style('wcjsonsync_admin_css', plugins_url('/css/admin.css', __FILE__), [], self::ASSET_VERSION);
      wp_enqueue_script('wcjsonsync_admin_js', plugins_url('/js/admin.js', __FILE__), ['jquery'], self::ASSET_VERSION, true);
      wp_localize_script('wcjsonsync_admin_js', 'wcjsonsync_lang', [
        'update' => __('Update', 'wcjsonsync'),
        'add_reseller' => __('Add Reseller', 'wcjsonsync'),
        'url_is_required' => __('URL is required', 'wcjsonsync'),
        'confirm_delete_reseller' => __('Confirm delete this reseller?', 'wcjsonsync'),
      ]);
    }
  }

  //---------------------------------------------------------------------------
  // functions
  //---------------------------------------------------------------------------

  function premium_installed() {
    if (is_null($this->_premium_installed)) {
      $this->_premium_installed = in_array('tcp-wcjsonsync-premium/tcp-wcjsonsync-premium.php', apply_filters('active_plugins', get_option('active_plugins')));
    }
    return $this->_premium_installed;
  }

  function premium_active() {
    $license_active = $this->license_active();
    if (!$license_active) {
      $premium_info = (array) get_option('wcjsonsync_premium_info');
      if (isset($premium_info['premium']) && $premium_info['premium']) { // can use premium w/o premium plugin
        return true;
      }
    }
    return $license_active;
  }

  function license_active() {
    if (is_null($this->_license_active)) {
      $this->_license_active = false;
      $premium_info = get_option('wcjsonsync_premium_info', []);
      if (is_array($premium_info)) {
        if (isset($premium_info['expiry'])) {
          $now = $this->create_datetime();
          $expiry = $this->create_datetime($premium_info['expiry']);
          if (!empty($expiry) && $now <= $expiry) {
            $this->_license_active = true;
          }
        } else if (!empty($premium_info)) {
          $this->_license_active = true; // premium without expiry
        }
      }
    }
    return $this->_license_active;
  }

  /// https://wordpress.stackexchange.com/a/283094
  function get_timezone() {
    $tz = get_option('timezone_string');
    if (!empty($tz)) {
      return $tz;
    }
    $offset = get_option('gmt_offset');
    $hours = (int) $offset;
    $minutes = abs(($offset - (int) $offset) * 60);
    return sprintf('%+03d:%02d', $hours, $minutes);
  }

  function create_datetime($timestamp = 0) {
    $d = new DateTime();
    $tz = $this->get_timezone();
    if (!empty($tz)) {
      $dtz = new DateTimeZone($tz);
      $d->setTimezone($dtz);
    }
    if (!empty($timestamp)) {
      $d->setTimestamp($timestamp);
    }
    return $d;
  }

}

$GLOBALS['tcp_wcjsonsync'] = new TCP_wcjsonsync();
