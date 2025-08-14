<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/../saas-helpers.php';

class SaaS_Rest {
    public static function register() {
        register_rest_route('saas/v1', '/channels/(?P<service>[a-z0-9_-]+)/test', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'channel_test'],
            'permission_callback' => function() {
                return is_user_logged_in() && current_user_can('read');
            },
        ]);
    }

    public static function channel_test(WP_REST_Request $r) {
        $service = sanitize_key($r->get_param('service'));
        $user_id = get_current_user_id();
        if (!$service) return new WP_Error('bad_request', 'Eksik servis', ['status'=>400]);
        if (!saas_user_has_service($user_id, $service) && !current_user_can('manage_options')) {
            return new WP_Error('forbidden', 'Bu servis bu kullanıcı için açık değil', ['status'=>403]);
        }
        $adapter = saas_get_adapter($service, $user_id);
        if (!$adapter) return new WP_Error('not_implemented','Adapter yok', ['status'=>501]);
        $res = $adapter->test();
        return rest_ensure_response($res);
    }
}

add_action('rest_api_init', ['SaaS_Rest', 'register']);
