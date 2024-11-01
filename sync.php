<?php
defined('ABSPATH') or exit;

class TCP_wcjsonsync_sync {

  var $timer;

  function __construct() {
    add_action('wcjsonsync_cron_hook', [$this, 'cron_callback']);
    add_action('admin_post_wcjsonsync', [$this, 'sync']);
    add_action('admin_post_nopriv_wcjsonsync', [$this, 'sync']);
    add_action('admin_post_wcjsonsync_dl_image', [$this, 'download_image']);
    add_action('admin_post_nopriv_wcjsonsync_dl_image', [$this, 'download_image']);
  }

  //---------------------------------------------------------------------------
  // hooks
  //---------------------------------------------------------------------------

  function cron_callback() {
    $this->run_background_request();
  }

  /// https://lukasznowicki.info/insert-new-woocommerce-product-programmatically/
  /// https://devnetwork.io/add-woocommerce-product-programmatically/
  /// https://stackoverflow.com/questions/52937409/create-programmatically-a-product-using-crud-methods-in-woocommerce-3/
  function sync() {
    global $tcp_wcjsonsync;
    if (!function_exists('wc_get_products')) {
      $notice = [
        'status' => 'error',
        'message' => __('WooCommerce not installed!', 'wcjsonsync'),
      ];
      set_transient('wcjsonsync_notice', $notice, 15);
      wp_redirect(admin_url('admin.php?page=wcjsonsync_admin'));
      exit;
    }
    $this->timer = microtime(true);

    // source url is from WP REST API
    $use_hub = (bool) get_option('wcjsonsync_source_url_hub');
    if (!TCP_wcjsonsync::ENABLE_DEBUG) {
      $use_hub = false;
    }
    $url = $use_hub ? 'http://www.uploadhub.com/tcp/api' : 'https://app.thecartpress.com/api';
    if (defined('TCP_wcjsonsync::DEBUG_SRC_URL') && TCP_wcjsonsync::ENABLE_DEBUG) {
      $url = TCP_wcjsonsync::DEBUG_SRC_URL;
    }
    
    // pagination
    $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
    if ($page < 1) { //
      $page = 1;
    }
    $is_cron = isset($_POST['cron']) && $_POST['cron'] == 1;
    $this->echo('Fetching page '. $page .'...');
    $params = [];
    $params['op'] = 'get_products';
    $params['pid'] = TCP_wcjsonsync::PLUGIN_ID;
    $params['key'] = '';
    $premium_info = (array) get_option('wcjsonsync_premium_info');
    if (isset($premium_info['key']) && !empty($premium_info['key'])) {
      $params['key'] = $premium_info['key'];
    }
    $params['page'] = $page;
    $url = add_query_arg($params, $url);
    
    // get stats
    if ($page == 1) {
      delete_option('wcjsonsync_stats');
    }
    $stats = get_option('wcjsonsync_stats');
    
    // get response body
    $referer = get_bloginfo('url');
    if (defined('TCP_wcjsonsync::DEBUG_REFERER') && TCP_wcjsonsync::ENABLE_DEBUG) {
      $referer = TCP_wcjsonsync::DEBUG_REFERER;
    }
    $remote = wp_remote_get($url, [
      'sslverify' => false,
      'headers' => [
        'Referer' => $referer,
      ]
    ]);
    $err = null;
    $body = null;
    $headers = [];
    $http_code = null;
    if (is_wp_error($remote)) {
      $err = $remote->get_error_message();
    } else {
      $body = wp_remote_retrieve_body($remote);
      $headers['x-wp-total'] = wp_remote_retrieve_header($remote, 'x-wp-total');
      $headers['x-wp-totalpages'] = wp_remote_retrieve_header($remote, 'x-wp-totalpages');
      $http_code = wp_remote_retrieve_response_code($remote);
    }

    if (empty($body)) {
      $error_message = 'HTTP '. $code .' - '. $err;
      if ($is_cron) { 
        $retry = isset($stats['retry']) ? $stats['retry'] : 0;
        if (isset($stats['total']) && $page <= $stats['total'] && $retry < TCP_wcjsonsync::MAX_RETRY) {
          $wait = 5;
          if ($stats['retry'] == 0) {
            $wait = 1;
          } else if ($stats['retry'] == 1) {
            $wait = 2;
          } else if ($stats['retry'] == 2) {
            $wait = 3;
          }
          $stats['retry'] += 1;
          update_option('wcjsonsync_stats', $stats, false);
          sleep($wait);
          $this->run_background_request($page);
          exit;
        }
        $stats['last_error'] = $error_message;
        update_option('wcjsonsync_stats', $stats, false);
        exit;
      }
      $notice = [
        'status' => 'error',
        'message' => sprintf(__('Failed to fetch JSON data! %s', 'wcjsonsync'), $error_message),
      ];
      set_transient('wcjsonsync_notice', $notice, 15);
      wp_redirect(admin_url('admin.php?page=wcjsonsync_admin'));
      exit;
    }

    $json = json_decode($body, true);
    
    // process json
    if (empty($json)) {
      if ($is_cron) {
        $stats['last_error'] = __('JSON data is empty!', 'wcjsonsync');
        update_option('wcjsonsync_stats', $stats, false);
        exit;
      }
      $notice = [
        'status' => 'error',
        'message' => __('JSON data is empty!', 'wcjsonsync'),
      ];
      set_transient('wcjsonsync_notice', $notice, 15);
      wp_redirect(admin_url('admin.php?page=wcjsonsync_admin'));
      exit;
    } else if (!isset($headers['x-wp-total']) || !isset($headers['x-wp-totalpages'])) {
      if ($is_cron) {
        $stats['last_error'] = __('Cannot get REST API headers! Source URL must use Wordpress REST API.', 'wcjsonsync');
        update_option('wcjsonsync_stats', $stats, false);
        exit;
      }
      $notice = [
        'status' => 'error',
        'message' => __('Cannot get REST API headers! Source URL must be a valid WooCommerce Products JSON Sync host.', 'wcjsonsync'),
      ];
      set_transient('wcjsonsync_notice', $notice, 15);
      wp_redirect(admin_url('admin.php?page=wcjsonsync_admin'));
      exit;
    } else {

      // sync data
      if ($page == 1 && isset($json['settings'])) {
        $v = $json['settings'];
        if (!empty($v)) {
          set_transient('wcjsonsync_syncdata', $v, 30 * MINUTE_IN_SECONDS);
          $syncdata = $v;
        }
      } else {
        $syncdata = get_transient('wcjsonsync_syncdata');
      }
      if (empty($syncdata)) {
        $syncdata = $this->get_syncdata();
      }

      $total = $headers['x-wp-total'];
      $total_pages = $headers['x-wp-totalpages'];
      $this->echo(sprintf(__('Found %d products in %d pages.', 'wcjsonsync'), $total, $total_pages));

      // update stats
      $this->echo('Update stats...');
      if (empty($stats)) {
        $stats = [
          'last_sync' => 0,
          'new' => 0,
          'update' => 0,
          'empty_data' => [],
          // 'empty_add' => [],
          // 'empty_update' => [],
          'total_page' => $total_pages,
          'total_product' => $total,
          'progress_page' => 0,
          'progress_product' => 0,
          'retry' => 0,
          'last_error' => '',
        ];
      }
      $stats['last_sync'] = time();
      $stats['progress_page'] = $page;
      $product_count = function() use ($json) {
        $i = count($json);
        if (array_key_exists('settings', $json)) {
          $i -= 1;
        }
        return $i;
      };
      $stats['progress_product'] = $stats['progress_product'] + $product_count(); 

      // compare products using slug
      $this->echo('Compare products using slug...');
      $json_data = [];
      $ids = [];
      $products = [];
      $add_products = [];
      $update_products = [];
      $c = 0;
      $go_next = $page < $total_pages;
      foreach ($json as $k => $v) {
        if ($k == 'settings' && $page == 1) {
          continue;
        }
        if (empty($v)) {
          $stats['empty_data'][] = $k;
          continue;
        }
        $c++;
        if (isset($v['slug'])) {
          $json_data[$v['slug']] = $v;
        }
      }
      if (!empty($json_data)) {
        $ids = get_posts([
          'fields' => 'ids',
          'post_type' => 'product',
          'post_name__in' => array_keys($json_data),
          'post_status' => ['publish', 'pending', 'draft', 'private'],
          'numberposts' => count($json_data),
        ]);
      }
      if (!empty($ids)) {
        $products = wc_get_products([
          'include' => $ids
        ]);
      }
      foreach ($products as $p) {
        $s = $p->get_slug();
        if (isset($json_data[$s])) {
          $update_products[] = [
            'p' => $p,
            'j' => $json_data[$s],
          ];
          unset($json_data[$s]);
        }
      }
      foreach ($json_data as $j) {
        $add_products[] = $j;
      }
      
      $ids_map = get_transient('wcjsonsync_ids_map');
      if (empty($ids_map) || $page == 1) {
        $ids_map = [];
      }
      
      function add_ids_map(&$ids_map, $id, $j, $is_new = false) {
        $ids_map[$j['id']] = [
          'id' => $id,
          'is_new' => $is_new,
        ];
        if (isset($j['upsell_ids']) && !empty($j['upsell_ids'])) {
          $ids_map[$j['id']]['upsell_ids'] = $j['upsell_ids'];
        }
        if (isset($j['cross_sell_ids']) && !empty($j['cross_sell_ids'])) {
          $ids_map[$j['id']]['cross_sell_ids'] = $j['cross_sell_ids'];
        }
      }

      $this->echo(sprintf(__('Updating %d products...', 'wcjsonsync'), count($update_products)));
      foreach ($update_products as $prod) {
        $id = $this->update_product($prod['p'], $prod['j'], $syncdata, $ids_map, $stats);
        add_ids_map($ids_map, $id, $prod['j']);
      }

      $this->echo(sprintf(__('Adding %d products...', 'wcjsonsync'), count($add_products)));
      foreach ($add_products as $j) {
        $id = $this->add_product($j, $syncdata, $ids_map, $stats);
        add_ids_map($ids_map, $id, $j, true);
      }
      $stats['retry'] = 0;
      update_option('wcjsonsync_stats', $stats, false);

      $auto_next_page = !defined('TCP_wcjsonsync::DEBUG_REFERER');
      if ($go_next) {
        set_transient('wcjsonsync_ids_map', $ids_map, 30 * MINUTE_IN_SECONDS);
        if ($is_cron) {
          $this->run_background_request($page + 1);
          return;
        }
        if ($auto_next_page) { ?>
          <p><?php _e('Loading next page...', 'wcjsonsync'); ?></p>
        <?php } ?>
        <form id="wcjsonsync_next" action="<?php echo esc_attr('admin-post.php'); ?>" method="post">
        <input type="hidden" name="action" value="wcjsonsync">
        <input type="hidden" name="page" value="<?php echo $page + 1; ?>">
        <?php if (!$auto_next_page) { ?>
          <input type="submit" name="submit" value="<?php _e('Next Page', 'wcjsonsync'); ?>">
        <?php } ?>
        </form>
        <?php if ($auto_next_page) { ?>
          <script>
            (function() {
              document.getElementById('wcjsonsync_next').submit();
            })();
          </script><?php
        }
      } else {
        /*
        $ids_map = [
          1400 => [
            'id' => 20, // local product id
            'is_new' => true,
            'upsell_ids' => [1,2,3],
            'cross_sell_ids' => [4,5],
          ],
        ];
        */
        $can_update = function($name, $is_new) use ($syncdata) {
          if ($is_new) {
            return isset($syncdata[$name]) && $syncdata[$name] >= 1;
          } else {
            return isset($syncdata[$name]) && $syncdata[$name] == 2;
          }
        };
        foreach ($ids_map as $v) {
          $product = wc_get_product($v['id']);
          if (!$product) {
            continue;
          }
          $upsell_ids = [];
          if (isset($v['upsell_ids']) && $can_update('upsell_ids', $v['is_new'])) {
            foreach ($v['upsell_ids'] as $rid) {
              if (isset($ids_map[$rid]) && !empty($ids_map[$rid]['id'])) {
                $upsell_ids[] = $ids_map[$rid]['id'];
              }
            }
          }
          $product->set_upsell_ids($upsell_ids);
          $cross_sell_ids = [];
          if (isset($v['cross_sell_ids']) && $can_update('cross_sell_ids', $v['is_new'])) {
            foreach ($v['cross_sell_ids'] as $rid) {
              if (isset($ids_map[$rid]) && !empty($ids_map[$rid]['id'])) {
                $cross_sell_ids[] = $ids_map[$rid]['id'];
              }
            }
          }
          $product->set_cross_sell_ids($cross_sell_ids);
          $product->save();
        }
        delete_transient('wcjsonsync_ids_map');
        delete_transient('wcjsonsync_syncdata');
        ?><p><?php _e('Done! Redirecting to admin page...', 'wcjsonsync'); ?></p><?php
        if ($auto_next_page) {
          $notice = [
            'status' => 'success',
            'message' => __('Sync success!', 'wcjsonsync'),
          ];
          set_transient('wcjsonsync_notice', $notice, 15);
          wp_redirect(admin_url('admin.php?page=wcjsonsync_admin'));
          exit;
        } else {
          $this->echo('<a href="'. esc_url(admin_url('admin.php?page=wcjsonsync_admin')) .'">admin page</a>');
        }
      }
    }
  }

  /*
  download product images concurrently
  - in run_background_request(), have to use fsockopen to be able to run in parallel
  - improvement: 1 product with 177 images: 116s -> 20s
  */
  function download_image() {
    set_time_limit(0);
    $product_id = isset($_POST['pid']) ? intval($_POST['pid']) : 0;
    $p = wc_get_product($product_id);
    if (empty($p)) {
      exit;
    }
    $images = get_post_meta($product_id, '_tmp_image_attachments', true);
    if (empty($images) || !is_array($images)) {
      exit;
    }

    /** 
     * get image name
     * $img array|int
     *   array = ['src' => '', 'name' => ''] // from json data
     *   int = attachment ID
     */
    $get_name = function($img, &$ext = null) use ($product_id) {
      if (is_array($img)) { // json data
        $src = $img['src'];
        $name = $img['name'];
        if (empty($name)) {
          $name = explode('?', pathinfo($src, PATHINFO_BASENAME))[0];
        }
        if (empty($name)) {
          parse_str(parse_url($src, PHP_URL_QUERY), $qs);
          if (isset($qs['txt']) && !empty($qs['txt'])) {
            $name = $qs['txt'];
          }
        }
        if (empty($name)) {
          $name = md5($src);
        }
        $name = sanitize_file_name($name);
      } else { // local data - image id
        $src = wp_get_attachment_url($img);
        $name = get_the_title($img);
      }
      $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
      if (!in_array($ext, wp_get_ext_types()['images'])) {
        $ext = 'png';
      }
      return $name;
    };

    /**
     * media upload function
     * $img array = ['src' => '', 'name' => '']
     */
    $download_image = function($img) use ($get_name, $product_id) {
      if (!function_exists('media_handle_upload')) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
      }
      if (empty($img['src'])) {
        return;
      }
      $tmp = download_url($img['src']);
      if (is_wp_error($tmp)) {
        return;
      }
      $name = $get_name($img, $ext);
      $f = [
        'name' => $name .'.'. $ext,
        'tmp_name' => $tmp,
        'type' => 'image/'. $ext,
      ];
      $attachment_id = media_handle_sideload($f, $product_id, $f['name']);
      if (is_wp_error($attachment_id)) {
        @unlink($f['tmp_name']);
        return;
      }
      return $attachment_id;
    };

    // product image
    $image_id = $p->get_image_id();
    $r_image_id = null;
    foreach ($images as $img) {
      if (isset($img['product_image']) && $img['product_image']) {
        $r_image_id = $img['id'];
        if (empty($image_id) || $get_name($image_id) != $get_name($img)) {
          $att_id = $download_image($img);
          if (!empty($att_id)) {
            if (!empty($image_id)) {
              wp_delete_attachment($image_id, true);
            }
            $p->set_image_id($att_id);
            $image_id = $att_id;
          }
        }
        break;
      }
    }

    // variation images
    foreach ($images as $img) {
      if (isset($img['variation_id'])) {
        $v = wc_get_product($img['variation_id']);
        if ($v) {
          if ($img['id'] == $r_image_id) {
            $v->set_image_id($image_id);
          } else {
            $v_image_id = $v->get_image_id();
            if (empty($v_image_id) || $get_name($img['variation_id']) != $get_name($img)) {
              $att_id = $download_image($img);
              if (!empty($att_id)) {
                if (!empty($v_image_id)) {
                  wp_delete_attachment($v_image_id, true);
                }
                $v->set_image_id($att_id);
                $v->save();
              }
            }
          }
        }
      }
    }

    // product gallery images
    $gallery_image_ids = $p->get_gallery_image_ids();
    if (!empty($gallery_image_ids)) {
      $del_gallery_ids = [];
      foreach ($gallery_image_ids as $gid) {
        $img_name = $get_name($gid);
        $found = false;
        foreach ($images as $i => $img) {
          $skip = (isset($img['product_image']) && $img['product_image']) || isset($img['variation_id']);
          if (!$skip && $img_name == $get_name($img)) { // existing, no changes
            $images[$i]['attachment_id'] = $gid;
            $found = true;
            break;
          }
        }
        if (!$found) {
          $del_gallery_ids[] = $gid;
        }
      }
      foreach ($del_gallery_ids as $did) {
        wp_delete_attachment($did, true);
      }
    }

    // update gallery images
    $gallery_image_ids = [];
    foreach ($images as $i => $img) {
      if ((isset($img['product_image']) && $img['product_image']) || isset($img['variation_id'])) {
        continue;
      }
      if (isset($img['attachment_id'])) {
        $gallery_image_ids[] = $img['attachment_id'];
      } else {
        $att_id = $download_image($img);
        if (!empty($att_id)) {
          $gallery_image_ids[] = $att_id;
        }
      }
    }
    if (!empty($gallery_image_ids)) {
      $p->set_gallery_image_ids($gallery_image_ids);
    }
    $p->save();
    delete_post_meta($product_id, '_tmp_image_attachments');
  }

  //---------------------------------------------------------------------------
  // functions
  //---------------------------------------------------------------------------

  function get_syncdata() {
    return [
      // 0 - don't add
      // 1 - add only
      // 2 - add & update
      'name' => 2,
      'slug' => 2,
      'date_created' => 2,
      'date_modified' => 2,
      'status' => 1,
      'featured' => 1,
      'catalog_visibility' => 2,
      'description' => 0,
      'short_description' => 0,
      'sku' => 2,
      'price' => 2,
      'regular_price' => 2,
      'sale_price' => 1,
      'on_sale' => 1,
      'date_on_sale_from' => 1,
      'date_on_sale_to' => 1,
      'total_sales' => 2,
      'tax_status' => 1,
      'tax_class' => 1,
      'manage_stock' => 2,
      'stock_quantity' => 2,
      'stock_status' => 2,
      'backorders' => 2,
      'low_stock_amount' => 2,
      'sold_individually' => 2,
      'weight' => 1,
      'length' => 1,
      'width' => 1, 
      'height' => 1,
      'upsell_ids' => 2,
      'cross_sell_ids' => 2,
      'parent_id' => 1,
      'reviews_allowed' => 1,
      'purchase_note' => 1,
      'default_attributes' => 1,
      'menu_order' => 1,
      'post_password' => 1,
      'virtual' => 1,
      'downloadable' => 1,
      'categories' => 2,
      'tags' => 2,
      'shipping_class_id' => 1,
      'downloads' => 1,
      'download_limit' => 1,
      'download_expiry' => 1,
      'rating_count' => 0,
      'average_rating' => 0,
      'review_count' => 0,
      'attributes' => 2,
      'product_variations' => 2,
      'images' => 2,
      'v_variation_attributes' => 2,
      // 'v_status' => 1,
      'v_description' => 0,
      'v_sku' => 1,
      'v_regular_price' => 2,
      'v_sale_price' => 1,
      'v_on_sale' => 2,
      'v_quantity' => 2,
      'v_in_stock' => 1,
      'v_variation_image' => 1,
      'v_weight' => 1,
      'v_length' => 1,
      'v_width' => 1, 
      'v_height' => 1,
    ];
  }

  function run_background_request($page = 1, $download_images_product_id = null) {
    $params = [
      'action' => 'wcjsonsync',
      'page' => $page,
      'cron' => 1,
    ];
    $url = admin_url('admin-post.php');
    $product_id = (int) $download_images_product_id;
    if (!empty($product_id)) {
      $params = [
        'action' => 'wcjsonsync_dl_image',
        'pid' => $product_id,
      ];
      $url = str_replace('https://', 'http://', $url);
    }
    $parts = parse_url($url);
    $ssl = strpos($url, 'https://') !== false; // is_ssl();
    if ($ssl) {
      $post_content = http_build_query($params);
      $ctx = stream_context_create([
        'http' => [
          'method' => 'POST',
          'header' => [
            'Content-Type: application/x-www-form-urlencoded',
            'Content-Length: '. strlen($post_content),
            'Connection: Close',
          ],
          'content' => $post_content,
        ],
        'ssl' => [
          'verify_peer' => false,
          'verify_peer_name' => false,
        ],
      ]);
      $fp = fopen($url, 'r', false, $ctx);
      if ($fp !== false) {
        fclose($fp);
      }
    } else {
      $fp = fsockopen($parts['host'], 80, $errno, $errstr, 30);
      if ($fp !== false) {
        $post_params = [];
        foreach ($params as $k => $v) {
          $post_params[] = $k . '=' . urlencode($v);
        }
        $post_string = implode('&', $post_params);
        $out  = "POST ". $parts['path'] ." HTTP/1.1\r\n";
        $out .= "Host: ". $parts['host'] ."\r\n";
        $out .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $out .= "Content-Length: ". strlen($post_string) ."\r\n";
        $out .= "Connection: Close\r\n\r\n";
        $out .= $post_string;
        fwrite($fp, $out);
        fclose($fp);
      }
    }
  }

  function get_attribute_id($slug) {
    global $wpdb;
    $attribute_id = $wpdb->get_col("SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name LIKE '$slug'");
    return reset($attribute_id);
  }

  function save_product_attribute($slug, $attr) {
    global $wpdb;
    $attribute_id = $this->get_attribute_id($slug);
    if (empty($attribute_id)) {
      $attribute_id = null;
    }
    $args = [
      'attribute_id' => $attribute_id,
      'attribute_name' => $slug,
      'attribute_label' => $attr['name'],
      'attribute_type' => 'select',
      'attribute_orderby' => isset($attr['orderby']) ? $attr['orderby'] : 'menu_order',
      'attribute_public' => 0,
    ];
    if (empty($attribute_id)) {
      $wpdb->insert("{$wpdb->prefix}woocommerce_attribute_taxonomies", $args);
    } else {
      $wpdb->update("{$wpdb->prefix}woocommerce_attribute_taxonomies", $args, ['attribute_id' => $attribute_id]);
    }
    $attributes = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name != '' ORDER BY attribute_name ASC;");
    set_transient('wc_attribute_taxonomies', $attributes);
  }

  /**
   * @param WC_Product $p
   * @param array $j 
   * @param $syncdata = ['name' => 2, ...]
   * @param array $ids_map
   * @param array $stats
   * @param bool $is_new
   */
  function update_product($p, $j, $syncdata, &$ids_map, &$stats, $is_new = false) {
    set_time_limit(0);
    
    $can_update = function($name) use ($j, $syncdata, $is_new) {
      $exist = false;
      if (in_array($name, ['length', 'width', 'height']) && isset($j['dimensions'][$name])) {
        $exist = true;
      } else if (isset($j[$name])) {
        $exist = true;
      }
      if ($exist) {
        if ($is_new) {
          return isset($syncdata[$name]) && $syncdata[$name] >= 1;
        } else {
          return isset($syncdata[$name]) && $syncdata[$name] == 2;
        }
      }
      return false;
    };

    if ($can_update('name')) {
      $p->set_name($j['name']);
    }
    if ($can_update('slug')) {
      $p->set_slug($j['slug']);
    }
    if ($can_update('date_created')) {
      $p->set_date_created($j['date_created']);
    }
    if ($can_update('date_modified')) {
      $p->set_date_modified($j['date_modified']);
    }
    if ($can_update('status')) {
      if (isset($j['type']) && $j['type'] == 'woosb') { // product bundle
        $p->set_status('draft');
      } else {
        $p->set_status($j['status']);
      }
    }
    if ($can_update('featured')) {
      $p->set_featured($j['featured']);
    }
    if ($can_update('catalog_visibility')) {
      try {
        $p->set_catalog_visibility($j['catalog_visibility']);
      } catch (Exception $e) {
      }
    }
    if ($can_update('description')) {
      $p->set_description($j['description']);
    }
    if ($can_update('short_description')) {
      $p->set_short_description($j['short_description']);
    }
    if ($can_update('sku')) {
      try {
        $p->set_sku($j['sku']);
      } catch (Exception $e) {
      }
    }
    
    // update price
    $price = null;
    if ($can_update('price') && !empty($j['price'])) {
      $price = $j['price'];
    }
    if ($can_update('regular_price')) {
      if (empty($j['regular_price'])) {
        $p->set_regular_price($price);
      } else {
        $p->set_regular_price($j['regular_price']);
        if (is_null($price)) {
          $price = $j['regular_price'];
        }
      }
    }
    if ($can_update('sale_price') && !empty($j['sale_price'])) {
      $p->set_sale_price($j['sale_price']);
    }
    if (!is_null($price)) {
      $p->set_price($price);
    }

    if ($can_update('on_sale')) {
      if ($can_update('date_on_sale_from')) {
        $p->set_date_on_sale_from($j['date_on_sale_from']);
      }
      if ($can_update('date_on_sale_to')) {
        $p->set_date_on_sale_to($j['date_on_sale_to']);
      }
    }
    if ($can_update('total_sales')) {
      $p->set_total_sales($j['total_sales']);
    }
    if ($can_update('tax_status')) {
      try {
        $p->set_tax_status($j['tax_status']);
      } catch (Exception $e) {
      }
    }
    if ($can_update('tax_class')) {
      $p->set_tax_class($j['tax_class']);
    }
    if ($can_update('stock_quantity')) {
      $p->set_stock_quantity($j['stock_quantity']);
    }
    if ($can_update('stock_status')) {
      $p->set_stock_status($j['stock_status']);
    }
    if ($can_update('manage_stock')) {
      $p->set_manage_stock($j['manage_stock']);
    }
    if ($can_update('backorders')) {
      $p->set_backorders($j['backorders']);
    }
    if ($can_update('low_stock_amount')) {
      $p->set_low_stock_amount($j['low_stock_amount']);
    }
    if ($can_update('sold_individually')) {
      $p->set_sold_individually($j['sold_individually']);
    }
    if ($can_update('weight')) {
      $p->set_weight($j['weight']);
    }
    if ($can_update('length')) {
      if (isset($j['dimensions']['length'])) {
        $p->set_length($j['dimensions']['length']);
      } else if (isset($j['length'])) {
        $p->set_length($j['length']);
      }
    }
    if ($can_update('width')) {
      if (isset($j['dimensions']['width'])) {
        $p->set_width($j['dimensions']['width']);
      } else if (isset($j['width'])) {
        $p->set_width($j['width']);
      }
    }
    if ($can_update('height')) {
      if (isset($j['dimensions']['height'])) {
        $p->set_height($j['dimensions']['height']);
      } else if (isset($j['height'])) {
        $p->set_height($j['height']);
      }
    }
    if ($can_update('parent_id')) {
      $p->set_parent_id($j['parent_id']);
    }
    if ($can_update('reviews_allowed')) {
      $p->set_reviews_allowed($j['reviews_allowed']);
    }
    if ($can_update('purchase_note')) {
      $p->set_purchase_note($j['purchase_note']);
    }
    if ($can_update('default_attributes')) {
      $p->set_default_attributes($j['default_attributes']);
    }
    if ($can_update('menu_order')) {
      $p->set_menu_order($j['menu_order']);
    }
    if ($can_update('post_password')) {
      if (method_exists($p, 'set_post_password')) {
        $p->set_post_password($j['post_password']);
      }
    }
    if ($can_update('virtual')) {
      $p->set_virtual($j['virtual']);
    }
    if ($can_update('downloadable')) {
      $p->set_downloadable($j['downloadable']);
    }
    
    // Add product category
    $category_ids_map = [];
    if ($can_update('categories') && !empty($j['categories']) && is_array($j['categories'])) {
      $category_ids = $p->get_category_ids();
      if (!is_array($category_ids)) {
        $category_ids = [];
      }
      foreach ($j['categories'] as $c) {
        $cid = 0;
        $category = get_term_by('slug', $c['slug'], 'product_cat');
        if ($category === false) {
          $args = [
            'slug' => $c['slug'],
          ];
          if (isset($c['parent'], $c['parent']['name'], $c['parent']['slug']) && !empty($c['parent']['slug'])) {
            $parent_cat = get_term_by('slug', $c['parent']['slug'], 'product_cat');
            if ($parent_cat === false) {
              $pcat = wp_insert_term($c['parent']['name'], 'product_cat', [
                'slug' => $c['parent']['slug']
              ]);
              if (is_array($pcat) && !empty($pcat['term_id'])) {
                $args['parent'] = $pcat['term_id'];
              }
            } else {
              $args['parent'] = (int) $parent_cat->term_id;
            }
          }
          $term = wp_insert_term($c['name'], 'product_cat', $args);
          if (is_array($term) && !empty($term['term_id'])) {
            $cid = $term['term_id'];
          }
        } else {
          $cid = (int) $category->term_id;
          if (isset($c['parent'], $c['parent']['name'], $c['parent']['slug']) && !empty($c['parent']['slug']) && empty($category->parent)) {
            $parent_cat = get_term_by('slug', $c['parent']['slug'], 'product_cat');
            $args = [];
            if ($parent_cat === false) {
              $pcat = wp_insert_term($c['parent']['name'], 'product_cat', [
                'slug' => $c['parent']['slug']
              ]);
              if (is_array($pcat) && !empty($pcat['term_id'])) {
                $args['parent'] = $pcat['term_id'];
              }
            } else {
              $args['parent'] = (int) $parent_cat->term_id;
            }
            wp_update_term($cid, 'product_cat', $args);
          }
        }
        if (!empty($cid)) {
          $category_ids[] = $cid;
          $category_ids_map[$c['id']] = $cid;
        }
      }
      $p->set_category_ids(array_unique($category_ids));
    }

    // Add product tags
    if ($can_update('tags') && !empty($j['tags']) && is_array($j['tags'])) {
      $tag_ids = $p->get_tag_ids();
      if (!is_array($tag_ids)) {
        $tag_ids = [];
      }
      foreach ($j['tags'] as $t) {
        $tid = 0;
        $tag = get_term_by('slug', $t['slug'], 'product_tag');
        if ($tag === false) {
          $term = wp_insert_term($t['name'], 'product_tag', [
            'slug' => $t['slug']
          ]);
          if (is_array($term) && !empty($term['term_id'])) {
            $tid = $term['term_id'];
          }
        } else {
          $tid = (int) $tag->term_id;
        }
        if (!empty($tid)) {
          $tag_ids[] = $tid;
        }
      }
      $p->set_tag_ids(array_unique($tag_ids));
    }

    if ($can_update('shipping_class_id')) {
      $p->set_shipping_class_id($j['shipping_class_id']);
    }
    if ($can_update('downloads')) {
      try {
        $p->set_downloads($j['downloads']);
      } catch (Exception $e) {
      }
    }
    if ($can_update('download_limit')) {
      $p->set_download_limit($j['download_limit']);
    }
    if ($can_update('download_expiry')) {
      $p->set_download_expiry($j['download_expiry']);
    }
    if ($can_update('rating_count')) {
      $p->set_rating_counts($j['rating_count']);
    }
    if ($can_update('average_rating')) {
      $p->set_average_rating($j['average_rating']);
    }
    if ($can_update('review_count')) {
      $p->set_review_count($j['review_count']);
    }
    $has_changes = !empty($p->get_changes());
    $product_id = $p->save();
    
    // post password
    if (isset($j['post_password']) && !empty($j['post_password'])) {
      wp_update_post([
        'ID' => $product_id,
        'post_password' => $j['post_password'],
      ]);
    }

    // Set Yoast SEO primary category
    // https://wordpress.stackexchange.com/questions/314633/set-primary-category-using-the-yoast-seo-plugin
    if (isset($j['meta_data']) && is_array($j['meta_data'])) {
      $key = '_yoast_wpseo_primary_product_cat';
      foreach ($j['meta_data'] as $m) {
        if (isset($m['key']) && $m['key'] == $key && isset($m['value']) && isset($category_ids_map[$m['value']])) {
          update_post_meta($product_id, $key, $category_ids_map[$m['value']]);
          break;
        }
      }
    }
    
    // Add product attributes
    // https://stackoverflow.com/a/47844054/1784450
    $attrs_tax = [];
    $attrs_val = [];
    if ($can_update('attributes') && ($p->get_type() == 'variable' || $j['type'] == 'variable') && is_array($j['attributes'])) {
      $product_attributes = [];
      foreach ($j['attributes'] as $v) {
        /*
        $v = {
          "id": 8,
          "name": "Upgrade",
          "position": 0,
          "visible": true,
          "variation": true,
          "options": ["Photo reviews (US, IN)", "Text reviews (US, IN)", "Photo reviews (Europe, UK, CA)", "Text reviews (Europe, UK, CA)"],
          "orderby": "name_num"
        }
        */
        $taxonomy = wc_attribute_taxonomy_name($v['name']); // returns 'pa_' . sanitized(name)
        $slug = wc_sanitize_taxonomy_name($v['name']);
        $attrs_tax[$taxonomy] = [
          'name' => $v['name'],
          'slug' => $slug
        ];
        $this->save_product_attribute($slug, $v);
        $product_attributes[$taxonomy] = [
          'name' => $taxonomy,
          'value' => '',
          'position' => isset($v['position']) ? $v['position'] : '',
          'is_visible' => isset($v['visible']) && $v['visible'] ? 1 : 0,
          'is_variation' => isset($v['variation']) && $v['variation'] ? 1 : 0,
          'is_taxonomy' => 1,
        ];
        if (is_array($v['options'])) {
          foreach ($v['options'] as $i => $val) {
            $term_slug = sanitize_title($val);
            $menu_order = null;
            // sanitize_title() "4,000 YouTube Views – Save $15" returns "4000-youtube-views-save-15"
            // but in json, it is "4-000-youtube-views-save-15", so need to compare text similarity
            // and use value from json
            if (isset($j['product_variations']) && is_array($j['product_variations'])) {
              $candidate = '';
              $highest = 0;
              foreach ($j['product_variations'] as $pv) {
                if (isset($pv['variation_attributes']) && is_array($pv['variation_attributes'])) {
                  foreach ($pv['variation_attributes'] as $att => $va) {
                    $tx = 'attribute_' . $taxonomy;
                    if ($att != $tx) {
                      continue;
                    }
                    similar_text($term_slug, $va, $pct);
                    if ($pct == 100) {
                      $candidate = '';
                      $menu_order = $pv['menu_order'];
                      break 2;
                    } else if ($pct > $highest) {
                      $highest = $pct;
                      $candidate = $va;
                      $menu_order = $pv['menu_order'];
                    }
                  }
                }
              }
              if (!empty($candidate)) {
                $term_slug = $candidate;
              }
            }
            if (empty($menu_order)) {
              $menu_order = $i + 1;
            }
            $t = term_exists($val, $taxonomy);
            $term_id = null;
            if (is_array($t) && isset($t['term_id'])) {
              $term_id = $t['term_id'];
              wp_update_term($term_id, $taxonomy, [
                'name' => $val,
                'slug' => $term_slug
              ]);
            } else {
              $t = wp_insert_term($val, $taxonomy, [
                'slug' => $term_slug
              ]);
              if (is_array($t) && isset($t['term_id'])) {
                $term_id = $t['term_id'];
              }
            }
            if (!empty($term_id)) {
              update_term_meta($term_id, 'order', $menu_order);
              update_term_meta($term_id, 'order_'. $taxonomy, $menu_order);
            }
            wp_set_post_terms($product_id, [$val], $taxonomy, true);
            $attrs_val[$term_slug] = $val;
          }
        }
      }
      update_post_meta($product_id, '_product_attributes', $product_attributes);
      $has_changes = !empty($p->get_changes()) || $has_changes;
      $p->save();
    }

    // Simple product changed to variable product
    // https://gist.github.com/Musilda/b77a94b43dfe508d6a30d0ecc20051bb
    if (!$is_new) {
      $id = $p->get_id();
      if ($p->get_type() == 'simple' && $j['type'] == 'variable') {
        wp_remove_object_terms($id, 'simple', 'product_type');
        wp_set_object_terms($id, 'variable', 'product_type', true);
        $p = wc_get_product($id);
      }
    } 
    
    // Add product variation
    // https://stackoverflow.com/a/47766413/1784450
    if ($can_update('product_variations') && $p->get_type() == 'variable' && is_array($j['product_variations'])) {
      $product_image_id = null;
      foreach ($j['images'] as $img) {
        if (isset($img['product_image']) && $img['product_image']) {
          $product_image_id = $img['id'];
          break;
        }
      }

      foreach ($j['product_variations'] as $i => $v) {
        $can_update_variation = function($name) use ($v, $syncdata, $is_new) {
          $exist = false;
          if (in_array($name, ['length', 'width', 'height']) && isset($v['dimensions'][$name])) {
            $exist = true;
          } else if (isset($v[$name])) {
            $exist = true;
          }
          $name = 'v_' . $name;
          if ($exist) {
            if ($is_new) {
              return isset($syncdata[$name]) && $syncdata[$name] >= 1;
            } else {
              return isset($syncdata[$name]) && $syncdata[$name] == 2;
            }
          }
          return false;
        };
        /*
        $v = {
          "variation_id": 1095,
          "status": "publish",
          "on_sale": false,
          "price": 20,
          "regular_price": 20,
          "sale_price": 0,
          "date_on_sale_from": null,
          "date_on_sale_from_gmt": null,
          "date_on_sale_to": null,
          "date_on_sale_to_gmt": null,
          "currency": "&#36;",
          "sku": "SKU--576",
          "description": "",
          "quantity": "",
          "in_stock": true,
          "variation_image": {
            "id": 378,
            "name": "name",
            "src": "http"
          },
          "variation_attributes": {
            "attribute_pa_packages": "4-amazon-keyword-search-purchases"
          }
        }
        */
        $slug = 'product-'. $product_id .'-variation-'. $v['variation_id'];
        $posts = get_posts([
          'name' => $slug,
          'post_type' => 'product_variation',
          'post_parent' => $product_id,
          'post_status' => ['publish', 'private'], // disabled variation has post_status=private
        ]);
        $is_new_variation = empty($posts);
        if ($is_new_variation) {
          $variation_id = wp_insert_post([
            'post_title' => $p->get_title(),
            'post_name' => $slug,
            'post_status' => $v['status'],
            'post_parent' => $product_id,
            'post_type' => 'product_variation',
            'guid' => $p->get_permalink(),
          ]);
          $variation = new WC_Product_Variation($variation_id);
        } else {
          $variation_id = $posts[0]->ID;
          $variation = wc_get_product($variation_id);
        }
        $ids_map[$v['variation_id']] = [
          'id' => $variation_id
        ];
        $variation->set_menu_order($i + 1);
        if ($can_update_variation('variation_attributes') && is_array($v['variation_attributes'])) {
          foreach ($v['variation_attributes'] as $attr => $term_name) {
            $taxonomy = str_replace('attribute_', '', $attr);
            if (!taxonomy_exists($taxonomy) && isset($attrs_tax[$taxonomy])) {
              $label = $attrs_tax[$taxonomy]['name'];
              $slug = $attrs_tax[$taxonomy]['slug'];
              register_taxonomy($taxonomy, 'product_variation', [
                'hierarchical' => false,
                'label' => $label,
                'query_var' => true,
                'rewrite' => [
                  'slug' => $slug
                ]
              ]);
            }
            /*
            $attrs_val = [
              '63-facebook-followers' => '63 Facebook Followers'
            ];
            $taxonomy = 'pa_packages'
            */
            if (isset($attrs_val[$term_name])) {
              $term_label = $attrs_val[$term_name];
              $post_term_names = wp_get_post_terms($product_id, $taxonomy, [
                'fields' => 'names'
              ]);
              if (!in_array($term_label, $post_term_names)) {
                wp_set_post_terms($product_id, [$term_label], $taxonomy, true);
              }
            }
            update_post_meta($variation_id, 'attribute_'. $taxonomy, $term_name);
          }
        }
        if ($can_update('status')) {
          $variation->set_status($v['status']);
        }
        if ($can_update_variation('description')) {
          $variation->set_description($v['description']);
        }
        if ($can_update_variation('sku')) {
          try {
            $variation->set_sku($v['sku']);
          } catch (Exception $e) {
          }
        }
        if ($can_update_variation('regular_price')) {
          $price = null;
          if (isset($v['price']) && !empty($v['price'])) {
            $price = $v['price'];
          }
          if (is_null($price) && !empty($v['regular_price'])) {
            $price = $v['regular_price'];
          }
          if (!is_null($price)) {
            $variation->set_price($price);
          }
          $variation->set_regular_price($v['regular_price']);
        }
        if ($can_update_variation('sale_price')) {
          $variation->set_sale_price($v['sale_price']);
        }
        if ($can_update_variation('on_sale')) {
          if (isset($v['date_on_sale_from'])) {
            $variation->set_date_on_sale_from($v['date_on_sale_from']);
          }
          if (isset($v['date_on_sale_to'])) {
            $variation->set_date_on_sale_to($v['date_on_sale_to']);
          }
        }
        if ($can_update_variation('quantity')) {
          $variation->set_stock_quantity($v['quantity']);
        }
        if ($can_update_variation('in_stock')) {
          $variation->set_stock_status($v['in_stock'] ? 'instock' : 'outofstock');
        }
        if ($can_update_variation('weight')) {
          $p->set_weight($v['weight']);
        }
        if ($can_update_variation('length')) {
          $p->set_length($v['dimensions']['length']);
        }
        if ($can_update_variation('width')) {
          $p->set_width($v['dimensions']['width']);
        }
        if ($can_update_variation('height')) {
          $p->set_height($v['dimensions']['height']);
        }
        $has_changes = !empty($variation->get_changes()) || $has_changes;
        $variation->save();
        if ($can_update_variation('variation_image') && is_array($v['variation_image']) && isset($v['variation_image']['src'])) {
          $j['product_variations'][$i]['variation_image']['variation_id'] = $variation_id;
        }
      }
    }

    // Upload product attachment
    // https://codex.wordpress.org/Function_Reference/media_handle_sideload
    if ($can_update('images') && !empty($j['images'])) {
      if (isset($j['product_variations']) && !empty($j['product_variations'])) {
        foreach ($j['product_variations'] as $v) {
          if (isset($v['variation_image'], $v['variation_image']['variation_id'])) {
            $j['images'][] = $v['variation_image'];
          }
        }
      }
      update_post_meta($product_id, '_tmp_image_attachments', $j['images']);
      $this->run_background_request(0, $product_id);
    }

    // stats
    if ($is_new) {
      $stats['new'] = $stats['new'] + 1;
    } else if ($has_changes) {
      $stats['update'] = $stats['update'] + 1;

      // using wp-super-cache and product got changes - delete cache
      if (function_exists('wpsc_delete_post_cache')) {
        wpsc_delete_post_cache($product_id);
      }
    }
    return $product_id;
  }

  /**
   * @param array $j
   * @param array $syncdata
   * @param array $stats
   */
  function add_product($j, $syncdata, &$ids_map, &$stats) {
    if ($j['type'] == 'variable') {
      $p = new WC_Product_Variable();
    } else if ($j['type'] == 'grouped') {
      $p = new WC_Product_Grouped();
    } else if ($j['type'] == 'external') {
      $p = new WC_Product_External();
    } else {
      $p = new WC_Product_Simple();
    }
    return $this->update_product($p, $j, $syncdata, $ids_map, $stats, true);
  }

  function echo($v) {
    echo '<p>('. number_format(microtime(true) - $this->timer, 3) .'): '. $v .'</p>';
    $gzip = true;
    if (!$gzip) {
      ob_end_flush();
      ob_flush();
      flush();
      ob_start();
    }
  }

}

new TCP_wcjsonsync_sync();