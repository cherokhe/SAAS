<?php
if (!defined('ABSPATH')) exit;

/** Return true if the user has the given service enabled */
function saas_user_has_service(int $user_id, string $service): bool {
    $csv = (string) get_user_meta($user_id, 'saas_services', true);
    if (!$csv) return false;
    $services = array_filter(array_map('trim', explode(',', strtolower($csv))));
    return in_array(strtolower($service), $services, true);
}

/** A minimal registry that returns an adapter instance per service */
function saas_get_adapter(string $service, int $user_id) {
    $service = strtolower($service);
    switch ($service) {
        case 'trendyol':
            require_once __DIR__ . '/adapters/class-saas-trendyol-adapter.php';
            return new SaaS_Adapter_Trendyol($user_id);
        // case 'hepsiburada': return new SaaS_Adapter_Hepsiburada($user_id);
        // case 'n11': return new SaaS_Adapter_N11($user_id);
        // case 'ciceksepeti': return new SaaS_Adapter_Ciceksepeti($user_id);
        // case 'shopify': return new SaaS_Adapter_Shopify($user_id);
        default:
            return null;
    }
}
