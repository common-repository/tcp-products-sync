<?php
defined('ABSPATH') or exit;

/*
Resellers' source URL: <wp_url>/?wcjsonsync_products=1&page=<page_num>&per_page=<per_page>
Category list: <wp_url>/?wcjsonsync_cats=1
*/

class TCP_wcjsonsync_json {

  function __construct() {
    add_filter('query_vars', [$this, 'query_vars']);
    add_filter('woocommerce_rest_product_object_query', [$this, 'rest_product_object_query'], 10, 2);

    add_action('parse_request', [$this, 'parse_request']);
  }

  //-----------------------------------------------------------------------------
  // hooks
  //-----------------------------------------------------------------------------

  function query_vars($vars) {
    $vars[] = 'wcjsonsync_products';
    $vars[] = 'wcjsonsync_cats';
    return $vars;
  }

  function rest_product_object_query($args, $request) {
    if (isset($request['status']) && $request['status'] == 'all') {
      // when reseller run sync, host can't determine whether user has access to private products.
      // rest_query_vars allows to modify query post_status.
      // here add post_status including 'private'.
      $args['post_status'] = ['publish', 'pending', 'private'];
    }
    if (isset($request['excl_category']) && !empty($request['excl_category'])) {
      if (!isset($args['tax_query']) || !is_array($args['tax_query'])) {
        $args['tax_query'] = [];
      }
      $args['tax_query'][] = [
        'taxonomy' => 'product_cat',
        'field' => 'term_id',
        'terms' => $request['excl_category'],
        'operator' => 'NOT IN',
        'include_children' => false,
      ];
    }
    if (isset($request['incl_category']) && !empty($request['incl_category'])) {
      if (!isset($args['tax_query']) || !is_array($args['tax_query'])) {
        $args['tax_query'] = [];
      }
      $args['tax_query'][] = [
        'taxonomy' => 'product_cat',
        'field' => 'term_id',
        'terms' => $request['incl_category'],
        'operator' => 'IN',
        'include_children' => false,
      ];
    }
    return $args;
  }

  function parse_request($wp) {
    global $wpdb;

    // get products list
    if (array_key_exists('wcjsonsync_products', $wp->query_vars)) {
      
      $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
      if ($page < 1) {
        $page = 1;
      }
      $per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : TCP_wcjsonsync::PER_PAGE;
      $cid = isset($_GET['cid']) ? sanitize_text_field($_GET['cid']) : '';
      $cat_ids = [];
      if (!empty($cid)) {
        $cat_ids = array_unique(array_map('intval', explode(',', $cid)));
      }
      $excl_cat_ids = [];
      $ecid = isset($_GET['ecid']) ? sanitize_text_field($_GET['ecid']) : '';
      if (!empty($ecid)) {
        $excl_cat_ids = array_unique(array_map('intval', explode(',', $ecid)));
      }
      $excl_prod_ids = [];
      $epid = isset($_GET['epid']) ? sanitize_text_field($_GET['epid']) : '';
      if (!empty($epid)) {
        $excl_prod_ids = array_unique(array_map('intval', explode(',', $epid)));
      }
      
      if ($this->json_allowed()) {
        
        // category list + cache
        $categories = [];
        if ($page > 1) {
          $categories = get_transient('wcjsonsync_categories');
        }
        if ($page == 1 || empty($categories)) {
          $categories = $this->get_categories();
          set_transient('wcjsonsync_categories', $categories, HOUR_IN_SECONDS);
        }

        add_filter('woocommerce_rest_check_permissions', [$this, 'rest_check_permissions'], 20, 4);
        add_filter('woocommerce_rest_query_vars', [$this, 'rest_query_vars'], 20);
        $req = new WP_REST_Request('GET');
        $req->set_param('per_page', $per_page);
        $req->set_param('page', $page);
        $req->set_param('orderby', 'id');
        $req->set_param('order', 'asc');
        $req->set_param('status', 'all');
        // note, missing params:
        // - low_stock_amount
        // - review_count
        if (!empty($cat_ids)) {
          $req->set_param('incl_category', $cat_ids);
        } else if (!empty($excl_cat_ids)) {
          $req->set_param('excl_category', $excl_cat_ids);
        }
        if (!empty($excl_prod_ids)) {
          $req->set_param('exclude', $excl_prod_ids);
        }
        // $req->set_param('type', 'variable');
        $ctrl = new WC_REST_Products_Controller();
        $resp = $ctrl->get_items($req);
        remove_filter('woocommerce_rest_check_permissions', [$this, 'rest_check_permissions']);
        remove_filter('woocommerce_rest_query_vars', [$this, 'rest_query_vars']);

        header('Content-Type: application/json');
        if (is_wp_error($resp)) {
          echo json_encode([
            'error' => $resp->get_error_message()
          ]);
        } else if ($resp->is_error()) {
          echo json_encode([
            'error' => 'HTTP '. $resp->get_status()
          ]);
        } else {
          $headers = $resp->get_headers();
          $header_keys = [
            'X-WP-Total',
            'X-WP-TotalPages'
          ];
          foreach ($header_keys as $key) {
            if (isset($headers[$key]) && !empty($headers[$key])) {
              header($key .': '. $headers[$key]);
            }
          }
          $data = $resp->get_data();
          $json = [];
          $attr_orderbys = [];
          foreach ($data as $k => $v) {
            
            // product variations
            if ($v['type'] == 'variable') {
              add_filter('woocommerce_rest_check_permissions', [$this, 'rest_check_permissions'], 20, 4);
              add_filter('woocommerce_rest_query_vars', [$this, 'rest_query_vars'], 20);
              $rq = new WP_REST_Request('GET');
              $rq->set_param('product_id', $v['id']);
              $rq->set_param('per_page', '100');
              $rq->set_param('page', '1');
              $rq->set_param('orderby', 'id');
              $rq->set_param('status', 'all');
              $ct = new WC_REST_Product_Variations_Controller();
              $rs = $ct->get_items($rq);
              remove_filter('woocommerce_rest_check_permissions', [$this, 'rest_check_permissions']);
              remove_filter('woocommerce_rest_query_vars', [$this, 'rest_query_vars']);
              
              if (!is_wp_error($rs) && !$rs->is_error()) {
                foreach ($rs->get_data() as $vd) {
                  $v_attrs = [];
                  foreach ($vd['attributes'] as $att) {
                    $tax = wc_attribute_taxonomy_name($att['name']); // returns 'pa_' . sanitized(name)
                    $v_attrs['attribute_'. $tax] = wc_sanitize_taxonomy_name($att['option']); // returns slug
                  }
                  $v['product_variations'][] = [
                    'variation_id' => $vd['id'],
                    'status' => $vd['status'],
                    'on_sale' => $vd['on_sale'],
                    'price' => $vd['price'],
                    'regular_price' => $vd['regular_price'],
                    'sale_price' => $vd['sale_price'],
                    'date_on_sale_from' => $vd['date_on_sale_from'],
                    'date_on_sale_to' => $vd['date_on_sale_to'],
                    'sku' =>  $vd['sku'],
                    'description' => $vd['description'],
                    'quantity' => $vd['stock_quantity'],
                    'in_stock' => $vd['stock_status'] == 'instock',
                    'variation_image' => $vd['image'],
                    'variation_attributes' => $v_attrs,
                    'weight' => $vd['weight'],
                    'dimensions' => $vd['dimensions'],
                    'menu_order' => $vd['menu_order'],
                  ];
                }
              }
            }

            // categories + subcat
            foreach ($v['categories'] as $i => $cat) {
              foreach ($categories as $ct) {
                if (!empty($ct['sub_categories'])) {
                  foreach ($ct['sub_categories'] as $sct) {
                    if ($cat['id'] == $sct['id']) {
                      $v['categories'][$i]['parent'] = [
                        'id' => $ct['id'],
                        'slug' => $ct['slug'],
                        'name' => $ct['name'],
                      ];
                      break 2;
                    }
                  }
                }
              }
            }

            // attributes
            if (isset($v['attributes']) && !empty($v['attributes'])) {
              $attr_ids = array_column($v['attributes'], 'id');
              $attr_ids = array_filter($attr_ids, function($a) use ($attr_orderbys) {
                return !isset($attr_orderbys[$a]);
              });
              if (!empty($attr_ids)) {
                $results = $wpdb->get_results("SELECT attribute_id, attribute_orderby FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_id IN (". implode(',', $attr_ids) .")");
                foreach ($results as $row) {
                  $attr_orderbys[$row->attribute_id] = $row->attribute_orderby;
                }
              }
              $attrs = [];
              foreach ($v['attributes'] as $attr) {
                if (isset($attr_orderbys[$attr['id']])) {
                  $attr['orderby'] = $attr_orderbys[$attr['id']];
                }
                $attrs[] = $attr;
              }
              $v['attributes'] = $attrs;
            }

            $p = wc_get_product($v['id']);
            if ($p) {

              // low_stock_amount
              $v['low_stock_amount'] = $p->get_low_stock_amount('');

              // product images
              if (isset($v['images']) && !empty($v['images'])) {
                $product_image = $p->get_image_id();
                foreach ($v['images'] as $i => $img) {
                  if ($img['id'] == $product_image) {
                    $img['product_image'] = true;
                    $v['images'][$i] = $img;
                    break;
                  }
                }
              }
            }

            $json[$v['id']] = $v;
          }

          // post password
          $posts = get_posts([
            'post_type' => 'product',
            'include' => array_keys($json),
          ]);
          foreach ($posts as $post) {
            if (!empty($post->post_password)) {
              $json[$post->ID]['post_password'] = $post->post_password;
            }
          }

          echo json_encode($json);
        }
      } else {
        header('Content-Type: application/json');
        echo json_encode([
          'error' => 'HTTP 403'
        ]);
      }
      exit;
    }
    // get category list
    if (array_key_exists('wcjsonsync_cats', $wp->query_vars)) {
      if ($this->json_allowed()) {
        $categories = $this->get_categories();
        $json = [];
        foreach ($categories as $cat) {
          $json[] = [
            'id' => $cat['id'],
            'name' => $cat['name'],
          ];
          if (isset($cat['sub_categories']) && !empty($cat['sub_categories'])) {
            foreach ($cat['sub_categories'] as $subcat) {
              $json[] = [
                'id' => $subcat['id'],
                'name' => $cat['name'] .' &raquo; '. $subcat['name'],
              ];
            }
          }
        }
        header('Content-Type: application/json');
        echo json_encode($json);
      } else {
        header('Content-Type: application/json');
        echo json_encode([
          'error' => 'HTTP 403'
        ]);
      }
      exit;
    }
  }

  //-----------------------------------------------------------------------------
  // functions
  //-----------------------------------------------------------------------------

  function json_allowed() {
    global $tcp_wcjsonsync;

    $premium_info = (array) get_option('wcjsonsync_premium_info');
    $valid_key = isset($premium_info['valid_key']) && $premium_info['valid_key'];
    $is_reseller = isset($premium_info['is_reseller']) ? (bool) $premium_info['is_reseller'] : false;
    if (!$valid_key || $is_reseller) {
      return false;
    }
    $allowed = false;
    $allowed_referer = 'https://app.thecartpress.com';
    $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
    if (substr($referer, 0, strlen($allowed_referer)) === $allowed_referer) {
      $allowed = true;
    }
    if (TCP_wcjsonsync::ENABLE_DEBUG && isset($_GET['key']) && $_GET['key'] === 'abc123') {
      $allowed = true;
    }
    return $allowed;
  }


  function get_categories() {
    $args = [
      'taxonomy' => 'product_cat',
      'orderby' => 'name',
      'hierarchical' => 1,
      'hide_empty' => 0,
    ];
    $cats = get_categories($args);
    $json = [];
    foreach ($cats as $cat) {
      if ($cat->category_parent == 0) {
        $cat_id = $cat->term_id;
        $v = [
          'id' => $cat_id,
          'name' => $cat->name,
          'slug' => $cat->slug,
          'sub_categories' => [],
        ];
        $arg2 = [
          'taxonomy' => 'product_cat',
          'orderby' => 'name',
          'parent' => $cat_id,
          'hierarchical' => 1,
          'hide_empty' => 0
        ];
        $sub_cats = get_categories($arg2);
        if ($sub_cats) {
          foreach ($sub_cats as $sub_cat) {
            $v['sub_categories'][] = [
              'id' => $sub_cat->term_id,
              'name' => $sub_cat->name,
              'slug' => $sub_cat->slug,
            ];
          }
        }
        $json[] = $v;
      }
    }
    return $json;
  }

  function rest_check_permissions($permission, $context, $object_id, $post_type) {
    return true;
  }

  function rest_query_vars($query_vars) {
    $query_vars[] = 'post_status';
    return $query_vars;
  }
}

new TCP_wcjsonsync_json();