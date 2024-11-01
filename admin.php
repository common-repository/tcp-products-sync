<?php
defined('ABSPATH') or exit;

class TCP_wcjsonsync_admin {

  function __construct() {
    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_init', [$this, 'admin_init']);
    add_action('admin_notices', [$this, 'admin_notices']);
    add_action('admin_post_wcjsonsync_save_setting', [$this, 'save_setting']);
    add_action('admin_post_wcjsonsync_save_activation', [$this, 'save_activation']);
    add_action('admin_enqueue_scripts', [$this, 'load_js']);
  }

  //---------------------------------------------------------------------------
  // hooks
  //---------------------------------------------------------------------------

  function admin_menu() {
    add_submenu_page( 
      'thecartpress', // string $parent_slug, 
      __('TCP Products Sync', 'wcjsonsync'), // string $page_title, 
      __('Products Sync', 'wcjsonsync'), // string $menu_title, 
      'manage_options', // string $capability, 
      'wcjsonsync_admin', // string $menu_slug, 
      [$this, 'create_admin_page'] // callable $function = '', 
    );
  }

  function admin_init() {
    add_settings_section( // Add a new section to a settings page.
      'wcjsonsync_section', // id
      '', // title
      [$this, 'section_info'], // callback
      'wcjsonsync_admin' // page slug
    );
    register_setting(
      'wcjsonsync',
      'wcjsonsync_stats',
      [
        'type' => 'array',
        'default' => [],
      ]
    );
  }

  function admin_notices() {
    $notice = get_transient('wcjsonsync_notice');
    if (is_array($notice) && isset($notice['status'], $notice['message'])) { ?>
      <div class="notice notice-<?php echo $notice['status']; ?> is-dismissible">
        <p><?php echo $notice['message']; ?></p>
        <button type="button" class="notice-dismiss">
          <span class="screen-reader-text"><?php _e('Dismiss this notice', 'wcjsonsync'); ?></span>
        </button>
      </div>
      <?php
      delete_transient('wcjsonsync_notice');
    }
  }

  function save_setting() {
    global $tcp_wcjsonsync;
    $notice = [
      'status' => 'success',
      'message' => __('Changes saved', 'wcjsonsync'),
    ];
    if (TCP_wcjsonsync::ENABLE_DEBUG) {
      $use_hub = isset($_POST['wcjsonsync_source_url_hub']) ? (bool) $_POST['wcjsonsync_source_url_hub'] : false;
      update_option('wcjsonsync_source_url_hub', $use_hub, false);
    }
    $enable_cron = isset($_POST['wcjsonsync_enable_cron']) ? (bool) $_POST['wcjsonsync_enable_cron'] : false;
    update_option('wcjsonsync_enable_cron', $enable_cron, false);
    if ($enable_cron) {
      if (!wp_next_scheduled('wcjsonsync_cron_hook')) {
        $d = new DateTime();
        $tz = timezone_open($tcp_wcjsonsync->get_timezone());
        if (!empty($tz)) {
          $d->setTimezone($tz);
        }
        $d->modify('tomorrow');
        wp_schedule_event($d->getTimestamp(), 'daily', 'wcjsonsync_cron_hook');
      }
    } else {
      wp_clear_scheduled_hook('wcjsonsync_cron_hook');
    }
    set_transient('wcjsonsync_notice', $notice, 15);
    $url = admin_url('admin.php?page=wcjsonsync_admin');
    wp_redirect($url);
  }

  function save_activation() {
    global $tcp_wcjsonsync;
    $activation_key = isset($_POST['activation_key']) ? trim($_POST['activation_key']) : '';
    if (empty($activation_key)) {
      $notice = [
        'status' => 'error',
        'message' => __('Activation key is empty', 'wcjsonsync'),
      ];
    } else if (strlen($activation_key) >= 80) {
      $notice = [
        'status' => 'error',
        'message' => __('Activation key is too long', 'wcjsonsync'),
      ];
    } else {
      $params = [
        'op' => 'verify_activation',
        'key' => $activation_key,
        'pid' => TCP_wcjsonsync::PLUGIN_ID,
        'view' => 'json'
      ];
      $use_hub = (bool) get_option('wcjsonsync_source_url_hub');
      if (!TCP_wcjsonsync::ENABLE_DEBUG) {
        $use_hub = false;
      }
      $url = $use_hub ? 'http://www.uploadhub.com/tcp/api' : 'https://app.thecartpress.com/api';
      $url = add_query_arg($params, $url);
      $remote = wp_remote_get($url, [
        'sslverify' => false,
        'headers' => [
          'Referer' => get_bloginfo('url'),
        ]
      ]);
      $err_message = null;
      $err_code = null;
      $body = null;
      if (is_wp_error($remote)) {
        $err_message = $remote->get_error_message();
        $err_code = $remote->get_error_code();
      } else {
        $body = wp_remote_retrieve_body($remote);
        $err_code = wp_remote_retrieve_response_code($remote);
      }
      $notice = [
        'status' => 'success',
        'message' => __('Activation success', 'wcjsonsync'),
      ];
      $premium_info = [];
      if (empty($body)) {
        $notice = [
          'status' => 'error',
          'message' => sprintf('HTTP %s - %s', $err_code, $err_message)
        ];
      } else {
        /*
        json = array(15) { 
          ["name"]=> NULL 
          ["slug"]=> NULL 
          ["download_url"]=> NULL 
          ["version"]=> NULL 
          ["tested"]=> NULL 
          ["last_updated"]=> NULL 
          ["upgrade_notice"]=> string(27) "Plugin update is available." 
          ["author"]=> string(14) "The Cart Press" 
          ["author_homepage"]=> string(24) "https://thecartpress.com" 
          ["verf_url"]=> string(73) "http://www.uploadhub.com/tcp/api/?op=verify_activation&pid=tcp-wcjsonsync" 
          ["sections"]=> NULL 
          ["premium"]=> bool(false) 
          ["error"]=> string(11) "Invalid key" 
          ["server"]=> NULL 
          ["origin"]=> NULL 
        }
        */
        $json = json_decode($body, true);
        if (TCP_wcjsonsync::ENABLE_DEBUG) {
          $dbg_activation_key = strtolower($activation_key);
          $dbg_key = 'abc123';
          if (substr($dbg_activation_key, 0, strlen($dbg_key)) === $dbg_key) {
            unset($json['error']);
            $dbg_activation_key_suffix = str_replace($dbg_key, '', $dbg_activation_key);
            $json['valid_key'] = true;
            $json['premium'] = strpos($dbg_activation_key_suffix, 'pm1') !== false;
            $json['is_reseller'] = strpos($dbg_activation_key_suffix, 'rs1') !== false;
            $json['expiry'] = $tcp_wcjsonsync->create_datetime()->modify('+1 day')->getTimestamp();
          }
        }
        if (empty($json)) {
          $notice = [
            'status' => 'error',
            'message' => __('Invalid response from activation server', 'wcjsonsync')
          ];
        } else {
          if (isset($json['error']) && !empty($json['error'])) {
            $notice = [
              'status' => 'error',
              'message' => sprintf(__('Activation server error: %s', 'wcjsonsync'), $json['error'])
            ];
          } else {
            if (isset($json['valid_key']) && $json['valid_key']) { // ok!
              $json['key'] = $activation_key;
              $premium_info = $json;
            } else {
              $notice = [
                'status' => 'error',
                'message' => __('Invalid key', 'wcjsonsync')
              ];
            }
          }
        }
      }
    }
    update_option('wcjsonsync_premium_info', $premium_info, false);
    set_transient('wcjsonsync_notice', $notice, 15);
    $url = admin_url('admin.php?page=wcjsonsync_admin');
    wp_redirect($url);
  }

  function load_js() {
    if (isset($_GET['page']) && $_GET['page'] == 'wcjsonsync_admin') {
      wp_enqueue_script('wcjsonsync_admin_js', plugins_url('/js/admin.js', __FILE__), ['jquery'], TCP_wcjsonsync::ASSET_VERSION, true);
      wp_localize_script('wcjsonsync_admin_js', 'wcjsonsync_lang', [
        'activate' => __('Activate', 'wcjsonsync'),
        'cancel' => __('Cancel', 'wcjsonsync'),
        'change_activation_key' => __('Change activation key', 'wcjsonsync'),
        'syncing' => __('Syncing', 'wcjsonsync'),
      ]);
    }
  }

  //---------------------------------------------------------------------------
  // functions
  //---------------------------------------------------------------------------

  function create_admin_page() {
    global $tcp_wcjsonsync;
    $page_url = admin_url('admin.php?page=wcjsonsync_admin');
    $current_url = $page_url;
    $tab = 'settings';
    if (isset($_GET['tab']) && in_array($_GET['tab'], ['premium', 'plugin'])) {
      $tab = $_GET['tab'];
      $current_url = add_query_arg('tab', $tab, $current_url);
    }
    ?>
    <div class="wrap">
      <h1 class="wp-heading-inline"><?php _e('TCP Products Sync', 'wcjsonsync'); ?></h1>
      <hr class="wp-header-end">
      <ul class="subsubsub">
        <?php if ($tcp_wcjsonsync->premium_installed()) { ?>
          <li>
            <a href="<?php echo esc_url($page_url); ?>"<?php echo $tab == 'settings' ? ' class="current"' : ''; ?>><?php _e('Settings', 'wcjsonsync'); ?></a> | 
          </li>
          <li>
            <a href="<?php echo esc_url(add_query_arg('tab', 'plugin', $page_url)); ?>"<?php echo $tab == 'plugin' ? ' class="current"' : ''; ?>><?php _e('Plugins', 'wcjsonsync'); ?></a>
          </li>
        <?php } ?>
      </ul>
      <div class="clear"></div>

      <?php if ($tab == 'settings'): ?>

        <?php
        $premium_info = (array) get_option('wcjsonsync_premium_info');
        $activation_key = isset($premium_info['key']) ? $premium_info['key'] : '';
        $expiry = __('Expired', 'wcjsonsync');
        if ($tcp_wcjsonsync->license_active()) {
          if (isset($premium_info['expiry'])) {
            $now = $tcp_wcjsonsync->create_datetime();
            $t = $tcp_wcjsonsync->create_datetime($premium_info['expiry']);
            if (!empty($t) && $now <= $t) {
              $expiry = sprintf(__('Active until %s', 'wcjsonsync'), $t->format('j F Y, H:i'));
            }
          } else {
            $expiry = __('Active for lifetime', 'wcjsonsync');
          }
        }
        $valid_key = isset($premium_info['valid_key']) && $premium_info['valid_key'];
        $account_type = __('Free', 'wcjsonsync');
        if ($valid_key && isset($premium_info['premium']) && $premium_info['premium']) {
          $account_type = __('Premium', 'wcjsonsync');
        }
        $is_reseller = isset($premium_info['is_reseller']) ? (bool) $premium_info['is_reseller'] : false;
        $ts = wp_next_scheduled('wcjsonsync_cron_hook');
        $tz = timezone_open($tcp_wcjsonsync->get_timezone());
        $enable_cron = (bool) get_option('wcjsonsync_enable_cron');
        $use_hub = (bool) get_option('wcjsonsync_source_url_hub');
        ?>

        <!-- general settings -->
        <?php if (TCP_wcjsonsync::ENABLE_DEBUG || $is_reseller) { ?>
          <h2><?php _e('Settings', 'wcjsonsync'); ?></h2>
          <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
            <input type="hidden" name="action" value="wcjsonsync_save_setting">
            <table class="form-table">
              <?php if (TCP_wcjsonsync::ENABLE_DEBUG) { ?>
                <tr>
                  <th><?php _e('Source URL', 'wcjsonsync'); ?></th>
                  <td>
                    <select name="wcjsonsync_source_url_hub">
                      <option value="1"<?php echo $use_hub ? ' selected' : ''; ?>><?php _e('Uploadhub (test)'); ?></option>
                      <option value="0"<?php echo $use_hub ? '' : ' selected'; ?>><?php _e('TheCartPress (live)'); ?></option>
                    </select>
                  </td>
                </tr>
              <?php } ?>
              <?php if ($is_reseller) { ?>
                <tr>
                  <th><?php _e('Daily cron', 'wcjsonsync'); ?></th>
                  <td>
                    <label>
                      <input type="checkbox" name="wcjsonsync_enable_cron" value="1"<?php echo $enable_cron ? ' checked' : ''; ?>> <?php _e('Enabled', 'wcjsonsync'); ?>
                    </label>
                    <?php
                    if ($enable_cron && !empty($ts)) {
                      $d = new DateTime();
                      $d->setTimestamp($ts);
                      if (!empty($tz)) {
                        $d->setTimezone($tz);
                      } 
                      ?><p class="description"><?php echo sprintf(__('Next cronjob running: %s', 'wcjsonsync'), $d->format('r')); ?></p><?php
                    }
                    ?>
                  </td>
                </tr>
              <?php } ?>
            </table>
            <p class="submit">
              <input type="submit" class="button button-primary" value="<?php _e('Save Settings', 'wcjsonsync'); ?>">
            </p>
          </form>
        <?php } ?>

        <!-- activation key -->
        <h2><?php _e('Activation', 'wcjsonsync'); ?></h2>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
          <input type="hidden" name="action" value="wcjsonsync_save_activation">
          <table class="form-table">
            <tr>
              <th><?php _e('Activation key', 'wcjsonsync'); ?></th> 
              <td>
                <div id="activation_key_label">
                <?php if ($tcp_wcjsonsync->license_active()) { ?>
                  <p id="activation_key"><?php echo esc_html($activation_key); ?></p>
                  <p><a href="#" id="change_activation_key"><?php _e('Change activation key', 'wcjsonsync'); ?></a></p>
                <?php } else { ?>
                  <input class="regular-text" type="text" name="activation_key" value="<?php echo esc_attr($activation_key); ?>"> 
                  <input type="submit" class="button button-primary" value="<?php _e('Activate', 'wcjsonsync'); ?>">
                <?php } ?>
                </div>  
              </td>
            </tr>
            <?php if (!empty($activation_key) && !empty($expiry)) { ?>
              <tr>
                <th><?php _e('Expiry status', 'wcjsonsync'); ?></th>
                <td><?php echo esc_html($expiry); ?></td>
              </tr>
            <?php } ?>
            <?php if ($valid_key) { ?>
              <tr>
                <th><?php _e('Account type', 'wcjsonsync'); ?></th>
                <td><?php echo esc_html($account_type); ?></td>
              </tr>
            <?php } ?>
          </table>
        </form>

        <!-- sync status -->
        <?php
        $installed_recommended_plugins = true;
        if ($tcp_wcjsonsync->premium_active() && isset($GLOBALS['tcp_wcjsonsync_premium_admin'])) {
          foreach ($GLOBALS['tcp_wcjsonsync_premium_admin']->recommended_plugins as $id => $v) {
            if (!is_dir(WP_PLUGIN_DIR .'/'. $id)) {
              $installed_recommended_plugins = false;
              break;
            }
          }
        }
        if (defined('TCP_wcjsonsync::DEBUG_SRC_URL') || ($is_reseller && $installed_recommended_plugins)) {
          ?>
          <h2><?php _e('Sync status', 'wcjsonsync'); ?></h2><?php
          $stats = get_option('wcjsonsync_stats');
          if (!empty($stats)) { ?>
            <p>
              <?php
              if (isset($stats['last_sync'])) {
                $d2 = new DateTime();
                $d2->setTimestamp($stats['last_sync']);
                if (!empty($tz)) {
                  $d2->setTimezone($tz);
                }
                echo sprintf(__('Last update: %s', 'wcjsonsync'), $d2->format('r')) . '<br>';
              } 
              if (isset($stats['new'])) {
                echo sprintf(__('New product(s):  %s', 'wcjsonsync'), $stats['new']) . '<br>';
              }
              if (isset($stats['update'])) {
                echo sprintf(__('Updated product(s): %s', 'wcjsonsync'), $stats['update']) . '<br>';
              }
              if (!empty($stats['empty_data'])) {
                echo sprintf(__('Empty product data: %d (%s)', 'wcjsonsync'), count($stats['empty_data']), implode(', ', $stats['empty_data'])) . '<br>';
              }
              if (isset($stats['total_page']) && isset($stats['progress_page']) && isset($stats['total_product']) && isset($stats['progress_product'])) {
                echo sprintf(__('Progress: %d of %d products synced (Page %d of %d)', 'wcjsonsync'), $stats['progress_product'], $stats['total_product'], $stats['progress_page'], $stats['total_page']);
              }
              ?>
            </p><?php
          } ?>
          <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" id="sync_form">
            <input type="hidden" name="action" value="wcjsonsync"/>
            <input type="submit" value="<?php _e('Sync manually', 'wcjsonsync'); ?>" class="button" id="sync_button"/>
          </form>
        <?php } ?>

        <?php if (TCP_wcjsonsync::ENABLE_DEBUG) { ?>
          <h2>Debug info</h2>
          <p>Products JSON: <code><?php echo esc_url(add_query_arg('wcjsonsync_products', '1', home_url())); ?></code></p>
          <p>Categories JSON: <code><?php echo esc_url(add_query_arg('wcjsonsync_cats', '1', home_url())); ?></code></p>
        <?php } ?>
        
      <?php else: ?>
          
         <?php do_action('wcjsonsync_admin_page', $tab); ?>
        
      <?php endif; ?>

   </div>
    <?php
  }

  function section_info() {
  }

}

new TCP_wcjsonsync_admin();