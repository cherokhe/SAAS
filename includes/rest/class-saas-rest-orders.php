<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/../saas-helpers.php';

class SaaS_Rest_Orders {
  public static function register(){
    register_rest_route('saas/v1','/orders', [
      'methods'  => 'GET',
      'callback' => [__CLASS__,'list'],
      'permission_callback' => function(){
        return is_user_logged_in() && current_user_can('read');
      }
    ]);
    register_rest_route('saas/v1','/orders/(?P<id>[A-Za-z0-9_-]+)/ship', [
      'methods'  => 'POST',
      'callback' => [__CLASS__,'ship'],
      'permission_callback' => function(){
        return is_user_logged_in() && current_user_can('read');
      }
    ]);
  }

  public static function list(WP_REST_Request $r){
    $user_id = get_current_user_id();
    $services = $r->get_param('services');
    if (!$services) $services = [ sanitize_key($r->get_param('service') ?: 'trendyol') ];
    if (!is_array($services)) $services = [$services];
    $services = array_map('sanitize_key', $services);

    $page = max(0, (int)($r->get_param('page') ?: 0));
    $size = min(100, max(10, (int)($r->get_param('size') ?: 50)));

    $start = $r->get_param('start') ? intval($r->get_param('start')) : (time()-14*24*60*60)*1000;
    $end   = $r->get_param('end')   ? intval($r->get_param('end'))   : time()*1000;
    $status= sanitize_text_field($r->get_param('status') ?: '');

    $all = [];
    foreach($services as $svc){
      if (!saas_user_has_service($user_id, $svc) && !current_user_can('manage_options')) {
        // skip services not enabled for this user
        continue;
      }
      $adapter = saas_get_adapter($svc, $user_id);
      if (!$adapter || !method_exists($adapter, 'list_orders')) continue;
      $chunk = $adapter->list_orders([
        'start' => $start, 'end' => $end, 'page' => $page, 'size' => $size, 'status' => $status
      ]);
      if (is_array($chunk)) $all = array_merge($all, $chunk);
    }

    // Server-side status filter (normalized statuses: pending/shipped/delivered/returned)
    if ($status){
      $status = strtolower($status);
      $all = array_values(array_filter($all, function($o) use ($status){
        return strtolower($o['status'] ?? '') === $status;
      }));
    }

    return rest_ensure_response([
      'orders' => $all,
      'page'   => $page,
      'size'   => $size,
      'count'  => count($all),
      'start'  => $start,
      'end'    => $end,
    ]);
  }

  public static function ship(WP_REST_Request $r){
    $user_id = get_current_user_id();
    $service = sanitize_key($r->get_param('service') ?: 'trendyol');
    $remote_id = sanitize_text_field($r->get_param('id'));
    $tracking  = sanitize_text_field($r->get_param('tracking') ?: '');
    $carrier   = sanitize_text_field($r->get_param('carrier') ?: '');

    if (!saas_user_has_service($user_id, $service) && !current_user_can('manage_options')) {
      return new WP_Error('forbidden','Bu servis bu kullanıcı için açık değil', ['status'=>403]);
    }
    $adapter = saas_get_adapter($service, $user_id);
    if (!$adapter || !method_exists($adapter, 'ship')) return new WP_Error('not_implemented','Adapter ship yok', ['status'=>501]);

    $res = $adapter->ship($remote_id, $tracking, $carrier);
    return rest_ensure_response($res);
  }
}
add_action('rest_api_init', ['SaaS_Rest_Orders','register']);
