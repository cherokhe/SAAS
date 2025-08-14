<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/../saas-helpers.php';

class SaaS_Rest_Products {
  public static function register(){
    register_rest_route('saas/v1','/products', [
      'methods'  => 'GET',
      'callback' => [__CLASS__,'list'],
      'permission_callback' => function(){
        return is_user_logged_in() && current_user_can('read');
      }
    ]);
  }

  public static function list(WP_REST_Request $r){
    $user_id = get_current_user_id();
    $service = sanitize_key($r->get_param('service') ?: 'trendyol');
    if (!saas_user_has_service($user_id, $service) && !current_user_can('manage_options')) {
      return new WP_Error('forbidden','Bu servis bu kullanıcı için açık değil', ['status'=>403]);
    }
    $page = max(1, (int)$r->get_param('page'));
    $size = min(100, max(1, (int)($r->get_param('size') ?: 25)));
    $status = sanitize_text_field($r->get_param('status') ?: '');
    $brand  = sanitize_text_field($r->get_param('brand') ?: '');
    $cat    = sanitize_text_field($r->get_param('category') ?: '');
    $q      = sanitize_text_field($r->get_param('q') ?: '');

    $adapter = saas_get_adapter($service, $user_id);
    if (!$adapter || !method_exists($adapter, 'list_products')) {
      return new WP_Error('not_implemented','Adapter list_products yok', ['status'=>501]);
    }

    $res = $adapter->list_products([
      'page' => $page,
      'size' => $size,
      'status' => $status,
      'brand' => $brand,
      'category' => $cat,
      'q' => $q,
    ]);

    if (!is_array($res) || empty($res['products'])) {
      return rest_ensure_response([
        'products' => [], 'categories'=>[], 'brands'=>[], 'stats'=>['total'=>0,'active'=>0,'inactive'=>0,'total_pages'=>0,'current_page'=>$page]
      ]);
    }
    return rest_ensure_response($res);
  }
}
add_action('rest_api_init', ['SaaS_Rest_Products','register']);
