<?php
/**
 * Plugin Name: SaaS Panel
 * Description: Woo Subscriptions ile entegre müşteri paneli. Trendyol + Hepsiburada (test/rozet), N11/Çiçeksepeti formları, proje notları.
 * Version: 2.9.6-patch-test-redirect
 * Author: CK
 */
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/includes/saas-adapter-interface.php';
require_once __DIR__ . '/includes/adapters/class-saas-trendyol-adapter.php';

require_once __DIR__ . '/includes/saas-helpers.php';
require_once __DIR__ . '/includes/rest/class-saas-rest.php';
require_once __DIR__ . '/includes/rest/class-saas-rest-products.php';
require_once __DIR__ . '/includes/adapters/class-saas-trendyol-adapter-list-products.php';
require_once __DIR__ . '/includes/rest/class-saas-rest-orders.php';
require_once __DIR__ . '/includes/adapters/class-saas-trendyol-adapter-list-orders.php';

require_once __DIR__ . '/includes/adapters/class-saas-hepsiburada-adapter.php';
require_once __DIR__ . '/includes/adapters/class-saas-n11-adapter.php';
require_once __DIR__ . '/includes/adapters/class-saas-ciceksepeti-adapter.php';
require_once __DIR__ . '/includes/adapters/class-saas-shopify-adapter.php';
class CK_SaaS_Panel {
    private function shopify_client_id(){
        if (defined('SAAS_SHOPIFY_CLIENT_ID')) return SAAS_SHOPIFY_CLIENT_ID;
        return get_option('saas_shopify_client_id', '');
    }
    private function shopify_client_secret(){
        if (defined('SAAS_SHOPIFY_CLIENT_SECRET')) return SAAS_SHOPIFY_CLIENT_SECRET;
        return get_option('saas_shopify_client_secret', '');
    }
    private function shopify_scopes(){
        return get_option('saas_shopify_scopes', 'read_products,read_orders');
    }

    const ROLE_PRIMARY   = 'abone';
    const ROLE_COMPAT    = 'saas_customer';

    const META_API_KEY   = '_saas_api_key';
    const META_SERVICES  = '_saas_services';
    const META_LOGS      = '_saas_logs';
    const OPT_PANEL_ID   = '_saas_panel_page_id';

    const PANEL_SLUG     = 'panel';
    const TEMPLATE_FILE  = 'saas-portal-full.php';

    private $all_services = [
        'trendyol'      => ['Trendyol Entegrasyonu', 'Sipariş/Ürün senkronizasyonu'],
        'hepsiburada'   => ['Hepsiburada Entegrasyonu', 'Sipariş/Listing'],
        'n11'           => ['N11 Entegrasyonu', 'Sipariş/Listing'],
        'ciceksepeti'   => ['Çiçeksepeti Entegrasyonu', 'Sipariş/Ürün'],
        'shopify'       => ['Shopify Entegrasyonu', 'Shop bilgisi / Admin API'],
];

    public function __construct() {
        add_action('wp_ajax_saas_shopify_set_state', [$this, 'ajax_shopify_set_state']);
        add_action('wp_ajax_nopriv_saas_shopify_set_state', function(){ wp_send_json_error(['message'=>'Giriş gerekli'], 401); });

        register_activation_hook(__FILE__, [$this, 'on_activate']);
        add_action('init', [$this, 'register_roles']);
        add_action('init', [$this, 'maybe_ensure_panel_page']);

        
                add_action('rest_api_init', function(){
                    register_rest_route('saas-panel/v1','/shopify/oauth/callback', [
                        'methods' => 'GET',
                        'callback' => [$this, 'rest_shopify_callback'],
                        'permission_callback' => '__return_true',
                    ]);
                });
    add_shortcode('saas_panel',  [$this, 'render_panel']);
        add_shortcode('saas_portal', [$this, 'render_panel']);

        add_action('admin_menu', [$this, 'add_changelog_page']);

        add_action('woocommerce_subscription_status_active',    [$this, 'on_subscription_active']);
        add_action('woocommerce_subscription_status_cancelled', [$this, 'on_subscription_inactive']);
        add_action('woocommerce_subscription_status_expired',   [$this, 'on_subscription_inactive']);
        add_action('woocommerce_subscription_status_on-hold',   [$this, 'on_subscription_inactive']);
        add_action('woocommerce_subscription_status_changed',   [$this, 'on_subscription_status_changed'], 10, 3);

        add_filter('login_redirect', [$this, 'redirect_after_login'], 10, 3);
        add_action('template_redirect', [$this, 'block_admin_for_panel']);
        add_filter('show_admin_bar', [$this, 'hide_admin_bar']);

        add_action('woocommerce_product_options_general_product_data', [$this, 'product_services_field']);
        add_action('woocommerce_admin_process_product_object',         [$this, 'save_product_services_field']);

        add_action('rest_api_init', function () {
            register_rest_route('saas/v1', '/profile', [
                'methods' => 'GET',
                'callback' => [$this, 'rest_profile'],
                'permission_callback' => '__return_true',
            ]);
        });
        // AJAX: Shopify API Test
        add_action('wp_ajax_saas_shopify_test', [$this, 'ajax_shopify_test']);
        add_action('wp_ajax_nopriv_saas_shopify_test', function(){ wp_send_json_error(['message'=>'Giriş gerekli'], 401); });
    

        add_filter('template_include', [$this, 'force_fullscreen_template'], 9999);

        add_shortcode('saas_login_button', function(){
            if (!is_user_logged_in()) return '<a href="'.esc_url(wp_login_url()).'" class="btn">Müşteri Girişi</a>';
            return '<a href="'.esc_url($this->get_panel_url()).'" class="btn">Panel</a> | <a href="'.esc_url(wp_logout_url(home_url('/'))).'" class="btn">Çıkış</a>';
        });

        add_action('plugins_loaded', function(){
            require_once plugin_dir_path(__FILE__) . 'includes/services/trendyol/Client.php';
            require_once plugin_dir_path(__FILE__) . 'includes/services/hepsiburada/Client.php';
            require_once plugin_dir_path(__FILE__) . 'includes/services/n11/Client.php';
            require_once plugin_dir_path(__FILE__) . 'includes/services/ciceksepeti/Client.php';
        });

        add_action('wp_ajax_saas_trendyol_test',        [$this, 'ajax_trendyol_test']);
        add_action('wp_ajax_nopriv_saas_trendyol_test', function(){ wp_send_json_error(['message'=>'Giriş gerekli'], 401); });
        add_action('wp_ajax_saas_hb_test',              [$this, 'ajax_hb_test']);
                add_action('wp_ajax_saas_cs_test',            [$this, 'ajax_cs_test']);
        add_action('wp_ajax_nopriv_saas_cs_test',     function(){ wp_send_json_error(['message'=>'Giriş gerekli'], 401); });
add_action('wp_ajax_saas_n11_test',             [$this, 'ajax_n11_test']);
        add_action('wp_ajax_nopriv_saas_n11_test',      function(){ wp_send_json_error(['message'=>'Giriş gerekli'], 401); });
        add_action('wp_ajax_nopriv_saas_hb_test',       function(){ wp_send_json_error(['message'=>'Giriş gerekli'], 401); });
    }

    public function on_activate(){ $this->register_roles(); $this->ensure_panel_page(); if (function_exists('flush_rewrite_rules')) flush_rewrite_rules(); }
    public function register_roles(){ if (!get_role(self::ROLE_PRIMARY)) add_role(self::ROLE_PRIMARY, 'Abone', ['read'=>true]); }

    public function on_subscription_status_changed($subscription, $new, $old){ if ($new === 'active') $this->activate_for_user($subscription->get_user_id(), $subscription); else $this->deactivate_for_user($subscription->get_user_id()); }
    public function on_subscription_active($subscription){ $this->activate_for_user($subscription->get_user_id(), $subscription); }
    public function on_subscription_inactive($subscription){ $this->deactivate_for_user($subscription->get_user_id()); }

    private function activate_for_user($user_id, $subscription){
        if (!$user_id) return;
        $user = get_user_by('id', $user_id); if (!$user) return;
        if (!array_intersect([self::ROLE_PRIMARY, self::ROLE_COMPAT], (array)$user->roles)) $user->add_role(self::ROLE_PRIMARY);
        if (!get_user_meta($user_id, self::META_API_KEY, true)) update_user_meta($user_id, self::META_API_KEY, $this->generate_api_key());
        $services = $this->collect_services_from_subscription($subscription);
        if ($services) update_user_meta($user_id, self::META_SERVICES, implode(',', array_unique($services)));
    }
    private function deactivate_for_user($user_id){ if ($user_id) delete_user_meta($user_id, self::META_SERVICES); }
    private function collect_services_from_subscription($subscription){
        $services=[]; if(!$subscription) return $services;
        foreach ($subscription->get_items() as $item){
            $product = $item->get_product(); if(!$product) continue;
            $val = $product->get_meta('saas_services');
            if ($val) foreach (explode(',', $val) as $sv){ $sv=trim(strtolower($sv)); if($sv) $services[]=$sv; }
        } return $services;
    }
    private function generate_api_key(){ return wp_hash(wp_generate_uuid4().'|'.time()); }

    public function redirect_after_login($redirect_to, $request, $user){
        if (is_wp_error($user) || !$user) return $redirect_to;
        if (user_can($user,'manage_options') || user_can($user,'manage_woocommerce')) return admin_url();
        if (array_intersect([self::ROLE_PRIMARY, self::ROLE_COMPAT], (array)$user->roles)) return $this->get_panel_url();
        return $redirect_to;
    }
    public function block_admin_for_panel(){
        if (is_admin() && !wp_doing_ajax()){
            $user = wp_get_current_user();
            if ($user && array_intersect([self::ROLE_PRIMARY, self::ROLE_COMPAT], (array)$user->roles)){
                if (user_can($user,'manage_options') || user_can($user,'manage_woocommerce')) return;
                wp_redirect($this->get_panel_url()); exit;
            }
        }
    }
    public function hide_admin_bar($show){
        $user = wp_get_current_user();
        if ($user && array_intersect([self::ROLE_PRIMARY, self::ROLE_COMPAT], (array)$user->roles)){
            if (user_can($user,'manage_options') || user_can($user,'manage_woocommerce')) return $show;
            return false;
        } return $show;
    }

    public function product_services_field(){
        echo '<div class="options_group">';
        woocommerce_wp_text_input(['id'=>'saas_services','label'=>__('SaaS Servisleri (virgülle)','saas-panel'),'desc_tip'=>true,'description'=>__('Örn: trendyol, hepsiburada, n11,  ciceksepeti','saas-panel')]);
        echo '</div>';
    }
    public function save_product_services_field($product){
        if (isset($_POST['saas_services'])) $product->update_meta_data('saas_services', sanitize_text_field($_POST['saas_services']));
    }

    public function maybe_ensure_panel_page(){ if (!get_option(self::OPT_PANEL_ID)) $this->ensure_panel_page(); }
    private function ensure_panel_page(){
        $page_id=(int) get_option(self::OPT_PANEL_ID);
        if ($page_id && get_post($page_id)) return;
        $existing=get_page_by_path(self::PANEL_SLUG); if(!$existing) $existing=get_page_by_title('Panel');
        if ($existing && !is_wp_error($existing)){
            $page_id=$existing->ID;
            if (stripos((string)$existing->post_content,'[saas_panel]')===false && stripos((string)$existing->post_content,'[saas_portal]')===false){
                wp_update_post(['ID'=>$page_id,'post_content'=>"[saas_panel]\n"]);
            }
        } else {
            $page_id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Panel','post_name'=>self::PANEL_SLUG,'post_content'=>"[saas_panel]\n"]);
        }
        if ($page_id && !is_wp_error($page_id)) update_option(self::OPT_PANEL_ID,(int)$page_id);
    }
    private function get_panel_url(){ $page_id=(int)get_option(self::OPT_PANEL_ID); if ($page_id && get_post($page_id)) return get_permalink($page_id); return home_url('/'.self::PANEL_SLUG.'/'); }
    public function force_fullscreen_template($template){
        $page_id=(int)get_option(self::OPT_PANEL_ID);
        if ($page_id && is_page($page_id)){
            $tpl=plugin_dir_path(__FILE__).'templates/'.self::TEMPLATE_FILE;
            if (file_exists($tpl)) return $tpl;
        } return $template;
    }

    private function append_log($user_id,$message){
        $logs=get_user_meta($user_id,self::META_LOGS,true);
        if(!is_array($logs)) $logs=[];
        $logs[]=['time'=>current_time('mysql'),'msg'=>sanitize_text_field($message)];
        update_user_meta($user_id,self::META_LOGS,$logs);
    }
    private function render_logs($user_id){
        $logs=get_user_meta($user_id,self::META_LOGS,true);
        if(!is_array($logs)||empty($logs)) return '<p class="muted">Log bulunamadı.</p>';
        $out='<ul class="saas-log">'; $c=0;
        foreach(array_reverse($logs) as $row){ $c++; if($c>10) break; $out.='<li><span class="log-time">'.esc_html($row['time']).'</span> — '.esc_html($row['msg']).'</li>'; }
        return $out.'</ul>';
    }

    private function has_active_subscription($user_id){
        if (!function_exists('wcs_get_users_subscriptions')) return false;
        $subs=wcs_get_users_subscriptions($user_id); if(empty($subs)) return false;
        foreach($subs as $sub){ if(!$sub) continue; if(in_array($sub->get_status(),['active','pending-cancel'],true)) return true; }
        return false;
    }

    public function render_panel(){
        if (!is_user_logged_in()){
            return '<div style="max-width:720px;margin:60px auto;text-align:center"><h2>Giriş Yapın</h2><p>Paneli görmek için giriş yapmalısınız.</p></div>';
        }
        $user_id=get_current_user_id();
        $services_csv=get_user_meta($user_id,self::META_SERVICES,true);
        $api_key=get_user_meta($user_id,self::META_API_KEY,true);
        if(!$api_key){ $api_key=$this->generate_api_key(); update_user_meta($user_id,self::META_API_KEY,$api_key); }

        // --- HANDLE POSTS BEFORE OUTPUT ---
        $saved_flag = false;
        if (isset($_POST['saas_notes_nonce']) && wp_verify_nonce($_POST['saas_notes_nonce'],'saas_notes_save')){
            if (current_user_can('read')){
                $notes = isset($_POST['saas_project_notes']) ? wp_kses_post($_POST['saas_project_notes']) : '';
                update_option('saas_project_notes', $notes);
                update_option('saas_project_notes_updated', current_time('mysql'));
            }
            $saved_flag = true;
        }
        if (isset($_POST['saas_nonce']) && wp_verify_nonce($_POST['saas_nonce'],'saas_service_save')){
            $service = sanitize_key($_POST['saas_service'] ?? '');
            if ($service && array_key_exists($service,$this->all_services)){
                $cfg = (array)($_POST['cfg'] ?? []);
                if (isset($cfg['key']))    update_user_meta($user_id, '_cfg_'.$service.'_key', sanitize_text_field($cfg['key']));
                if (isset($cfg['secret'])) update_user_meta($user_id, '_cfg_'.$service.'_secret', sanitize_text_field($cfg['secret']));
                if ($service==='trendyol'){
                    update_user_meta($user_id,'_cfg_trendyol_supplier_id', sanitize_text_field($cfg['supplier_id'] ?? ''));
                } elseif ($service==='hepsiburada'){
                    update_user_meta($user_id,'_cfg_hepsiburada_username', sanitize_text_field($cfg['username'] ?? ''));
                    update_user_meta($user_id,'_cfg_hepsiburada_password', sanitize_text_field($cfg['password'] ?? ''));
                    update_user_meta($user_id,'_cfg_hepsiburada_merchant_id', sanitize_text_field($cfg['merchant_id'] ?? ''));
                    $env = isset($cfg['env']) ? sanitize_text_field($cfg['env']) : 'prod'; if(!in_array($env,['prod','sit'],true)) $env='prod';
                    update_user_meta($user_id,'_cfg_hepsiburada_env', $env);
                } elseif ($service==='n11'){
                    update_user_meta($user_id,'_cfg_n11_app_key', sanitize_text_field($cfg['app_key'] ?? ''));
                    update_user_meta($user_id,'_cfg_n11_app_secret', sanitize_text_field($cfg['app_secret'] ?? ''));
                }  
                elseif ($service==='shopify'){
                    update_user_meta($user_id,'_cfg_shopify_domain', sanitize_text_field($cfg['domain'] ?? ''));
                    update_user_meta($user_id,'_cfg_shopify_token', sanitize_text_field($cfg['token'] ?? ''));
                    if (isset($cfg['client_id'])) update_option('saas_shopify_client_id', sanitize_text_field($cfg['client_id']));
                    if (isset($cfg['client_secret'])) update_option('saas_shopify_client_secret', sanitize_text_field($cfg['client_secret']));
                    if (isset($cfg['redirect_uri'])) update_option('saas_shopify_redirect_uri', esc_url_raw($cfg['redirect_uri']));
                    if (isset($cfg['scopes'])) update_option('saas_shopify_scopes', sanitize_text_field($cfg['scopes']));
                }
                  elseif ($service==='ciceksepeti'){
                    update_user_meta($user_id,'_cfg_ciceksepeti_seller_id', sanitize_text_field($cfg['seller_id'] ?? ''));
                }
                $curr=get_user_meta($user_id,self::META_SERVICES,true);
                $arr=array_filter(array_map('trim', explode(',', strtolower((string)$curr))));
                if (!in_array($service,$arr,true)){ $arr[]=$service; update_user_meta($user_id,self::META_SERVICES,implode(',',$arr)); }
                $this->append_log($user_id, strtoupper($service).' API bilgileri kaydedildi');
                $saved_flag = true;
            }
        }
        
        if (isset($_POST['saas_delete']) && $_POST['saas_delete']=='1' && isset($_POST['saas_service'])){
            $service = sanitize_key($_POST['saas_service']);
            if ($service==='ciceksepeti'){
                delete_user_meta($user_id,'_cfg_ciceksepeti_key');
                delete_user_meta($user_id,'_cfg_ciceksepeti_secret');
                update_user_meta($user_id,'_cfg_ciceksepeti_ok', 0);
                update_user_meta($user_id,'_cfg_ciceksepeti_ok_time', current_time('mysql'));
                $this->append_log($user_id, 'Çiçeksepeti API bilgileri silindi');
                $saved_flag = true;
            }
        }
    // --- END HANDLE POSTS ---

        $granted=array_filter(array_map('trim', explode(',', strtolower((string)$services_csv))));
        $user=wp_get_current_user();
        $has_role=(bool) array_intersect([self::ROLE_PRIMARY, self::ROLE_COMPAT], (array)$user->roles);
        $has_sub=$this->has_active_subscription($user_id);
        $is_active=$has_role || !empty($granted) || $has_sub || current_user_can('manage_options');

        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard';
        $current_user = wp_get_current_user();
        $name = trim($current_user->first_name.' '.$current_user->last_name); if(!$name) $name=$current_user->display_name;

        ob_start(); ?>
        <style>
        :root{--bg:#0b1020;--panel:#0f172a;--panel-2:#111827;--text:#e5e7eb;--muted:#a1a1aa;--border:#233047;--brand:#3b82f6;}
        html,body{margin:0;padding:0;width:100vw;height:100vh;background:var(--bg);overflow:hidden;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;}
        .saas-shell{display:flex;width:100vw;height:100vh;color:var(--text);} .saas-aside{width:300px;background:linear-gradient(180deg,#0f172a 0%,#0d1326 100%);padding:18px;height:100vh;overflow:auto;box-shadow:1px 0 0 rgba(255,255,255,.06) inset;}
        .saas-brand{font-size:18px;font-weight:700;margin-bottom:18px;color:#fff;display:flex;align-items:center;gap:10px;}
        .saas-nav a{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;color:var(--text);text-decoration:none;margin-bottom:6px;border:1px solid transparent;}
        .saas-nav a.active{background:rgba(59,130,246,.12);border-color:rgba(59,130,246,.25);} .saas-nav a:hover{background:#18233a;}
        .saas-main{flex:1;height:100vh;overflow:auto;} .saas-inner{padding:18px;} .saas-header{display:flex;justify-content:space-between;align-items:center;margin:0 0 12px;}
        .badge{display:inline-flex;gap:8px;align-items:center;padding:6px 10px;border-radius:999px;font-size:12px;background:#12213b;border:1px solid var(--border);}
        .saas-card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:18px;box-shadow:0 10px 25px rgba(0,0,0,.22);}
        .saas-grid{display:grid;grid-template-columns:repeat(12,1fr);gap:14px;margin-top:14px;} .col-12{grid-column:span 12;} .col-4{grid-column:span 4;} @media(max-width:900px){.saas-aside{width:240px}.col-4{grid-column:span 12;}}
        .btn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border:1px solid var(--border);border-radius:10px;text-decoration:none;color:var(--text);background:#111827;} .btn.primary{background:#3b82f6;border-color:#3b82f6;color:#fff;} .btn.block{display:flex;width:91%;justify-content:center;}
        .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;} .form-grid input,.form-grid select{width:87%;padding:12px;border-radius:10px;border:1px solid var(--border);background:#0b132a;color:var(--text);outline:none;}
        .alert{padding:12px 14px;border-radius:10px;border:1px solid var(--border);background:#0b132a;margin:0 0 12px 0;}
        </style>
        <div class="saas-shell">
            <aside class="saas-aside">
                <div class="saas-brand">Müşteri Paneli</div>
                <nav class="saas-nav">
                    <a href="<?php echo esc_url($this->get_panel_url()); ?>" class="<?php echo $tab==='dashboard'?'active':''; ?>">📊 Dashboard</a>
                    <a href="<?php echo esc_url(add_query_arg(['tab'=>'settings'],$this->get_panel_url())); ?>" class="<?php echo $tab==='settings'?'active':''; ?>">⚙️ Ayarlar</a>
                    <a href="<?php echo esc_url(add_query_arg(['tab'=>'channels'], $this->get_panel_url())); ?>" class="<?php echo $tab==='channels'?'active':''; ?>">🔌 Kanallar</a>
                    <a href="<?php echo esc_url(add_query_arg(['tab'=>'orders'], $this->get_panel_url())); ?>" class="<?php echo $tab==='orders'?'active':''; ?>">🛒 Siparişler</a>
                    <a href="<?php echo esc_url(add_query_arg(['tab'=>'products'], $this->get_panel_url())); ?>" class="<?php echo $tab==='products'?'active':''; ?>">📦 Ürünler</a>
                    <a href="<?php echo esc_url(add_query_arg(['tab'=>'inventory'], $this->get_panel_url())); ?>" class="<?php echo $tab==='inventory'?'active':''; ?>">🔁 Stok & Fiyat</a>
                    <a href="<?php echo esc_url(add_query_arg(['tab'=>'logs'],$this->get_panel_url())); ?>" class="<?php echo $tab==='logs'?'active':''; ?>">🗒️ Loglar</a>
                    <a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">🚪 Çıkış</a>
                </nav>
            </aside>
            <main class="saas-main"><div class="saas-inner">
                <div class="saas-header">
                    <h1>Hoş geldiniz, <?php echo esc_html($name); ?></h1>
                    <span class="badge"><?php echo $is_active ? 'Abonelik: Aktif' : 'Abonelik: Pasif'; ?></span>
                </div>

                <?php if ($saved_flag): ?><div class="alert">✅ Ayarlar kaydedildi.</div><?php endif; ?>

                <?php if ($tab==='dashboard'): ?>
                    <div class="saas-card col-12"><h3>API Anahtarınız</h3><code><?php echo esc_html($api_key ?: 'Anahtar oluşturulamadı'); ?></code></div>
                    <div class="saas-grid">
                        <?php foreach ($this->all_services as $key=>$meta): ?>
                        <div class="saas-card col-4"><h3><?php echo esc_html($meta[0]); ?></h3><p class="muted"><?php echo esc_html($meta[1]); ?></p>
                            <a class="btn primary block" href="<?php echo esc_url(add_query_arg(['tab'=>'settings','service'=>$key], $this->get_panel_url())); ?>">Ayarları Aç</a>
                        </div><?php endforeach; ?>
                    </div>

                <?php elseif ($tab==='settings'): ?>
                    <div class="saas-card col-12">
                        <h3>Bilgilendirme / Proje Notları</h3>
                        <form method="post" style="margin-top:8px">
                            <?php wp_nonce_field('saas_notes_save','saas_notes_nonce'); ?>
                            <textarea name="saas_project_notes" rows="6" style="width:100%;padding:12px;border-radius:10px;border:1px solid var(--border);background:#0b132a;color:var(--text);"><?php echo esc_textarea(get_option('saas_project_notes','Son Durum:
- Hepsiburada API (Cari No, Servis Anahtarı, Merchant ID, Ortam) + API Test (OMS + MerchantId header) hazır.
- Trendyol API entegrasyonu ve test aktif.
- N11,  Çiçeksepeti form alanları eklendi (test butonları sırada).

Yapılacaklar:
- N11/Çiçeksepeti için API Test ve ✅/❌ rozetleri.
- Dashboard’da servis durum özetleri ve rozetler.
- Log ekranına filtre/arama.
')); ?></textarea>
                            <div style="display:flex;gap:8px;align-items:center;margin-top:8px;">
                                <button class="btn" type="submit">Notları Kaydet</button>
                                <span class="muted">Son güncelleme: <?php echo esc_html(get_option('saas_project_notes_updated','-')); ?></span>
                            </div>
                        </form>
                    </div>

                    <div class="saas-card col-12"><h3>Ayarlar</h3><p class="muted">Servislerin API bilgilerini kaydedin ve test edin. Hepsiburada için yalnızca Cari No, Servis Anahtarı (Şifre), Merchant ID ve Ortam alanlarını kullanıyoruz.</p></div>
                    <div class="saas-grid">
                    <?php foreach ($this->all_services as $key=>$meta):
                        $val_key = get_user_meta($user_id, '_cfg_'.$key.'_key', true);
                        $val_secret = get_user_meta($user_id, '_cfg_'.$key.'_secret', true);
                        $val_supplier = get_user_meta($user_id, '_cfg_'.$key.'_supplier_id', true);
                    ?>
                        <div class="saas-card col-4">
                            <h3><?php echo esc_html($meta[0]); ?></h3>
                            <form method="post" class="saas-service-form">
                                <?php wp_nonce_field('saas_service_save','saas_nonce'); ?>
                                <input type="hidden" name="saas_service" value="<?php echo esc_attr($key); ?>">
                                <div class="form-grid" style="margin:12px 0">
                                    <?php if ($key!=='hepsiburada' && $key!=='n11' && $key!=='ciceksepeti' && $key!=='shopify'): ?>
                                        <label>API Key<br><input type="text" name="cfg[key]" value="<?php echo esc_attr($val_key); ?>"></label>
                                        <label>API Secret<br><input type="text" name="cfg[secret]" value="<?php echo esc_attr($val_secret); ?>"></label>
                                    <?php endif; ?>
                                    
                                    <?php if ($key==='ciceksepeti'): ?>
                                        <label>Satıcı (Seller) ID<br><input type="text" name="cfg[seller_id]" value="<?php echo esc_attr(get_user_meta($user_id,'_cfg_ciceksepeti_seller_id',true)); ?>" placeholder="ör: 1500017663"></label>
                                        <label>API Key<br><input type="text" name="cfg[key]" value="<?php echo esc_attr($val_key); ?>" placeholder="x-api-key"></label>
                                    <?php endif; ?>


                                    <?php if ($key==='trendyol'): ?>
                                        <label>Supplier ID<br><input type="text" name="cfg[supplier_id]" value="<?php echo esc_attr($val_supplier); ?>"></label><div></div>
                                    <?php endif; ?>

                                    
                  
                  
                  
                  
                  
                  
                  
                  
                  
                  














                                    <?php if ($key==='hepsiburada'): ?>
                                        <label>Cari No (Kullanıcı Adı)<br><input type="text" name="cfg[username]" value="<?php echo esc_attr(get_user_meta($user_id,'_cfg_hepsiburada_username',true)); ?>"></label>
                                        <label>Servis Anahtarı (Şifre)<br><input type="text" name="cfg[password]" value="<?php echo esc_attr(get_user_meta($user_id,'_cfg_hepsiburada_password',true)); ?>"></label>
                                        <label>Merchant ID (GUID)<br><input type="text" name="cfg[merchant_id]" value="<?php echo esc_attr(get_user_meta($user_id,'_cfg_hepsiburada_merchant_id',true)); ?>"></label>
                                        <label>Ortam<br><select name="cfg[env]"><?php $env=get_user_meta($user_id,'_cfg_hepsiburada_env',true)?:'prod'; ?><option value="prod" <?php selected($env,'prod'); ?>>Production</option><option value="sit" <?php selected($env,'sit'); ?>>Sandbox (SIT)</option></select></label>
                                    <?php endif; ?>

                                    <?php if ($key==='n11'): ?>
                                        <label>App Key<br><input type="text" name="cfg[app_key]" value="<?php echo esc_attr(get_user_meta($user_id,'_cfg_n11_app_key',true)); ?>"></label>
                                        <label>App Secret<br><input type="text" name="cfg[app_secret]" value="<?php echo esc_attr(get_user_meta($user_id,'_cfg_n11_app_secret',true)); ?>"></label>
                                    <?php endif; ?>

                                    





                                    
</div>
                                <?php
                                $status_badge='';
                                if ($key==='trendyol'){
                                    $ok=(int)get_user_meta($user_id,'_cfg_trendyol_ok',true); $ok_time=get_user_meta($user_id,'_cfg_trendyol_ok_time',true);
                                    $status_badge = $ok===1 ? '<span id="saas-status-trendyol" class="badge" style="background:#0f3a21;border-color:#0f3a21;">✅ Bağlantı Tamam</span>'
                                                            : ($ok===0 ? '<span id="saas-status-trendyol" class="badge" style="background:#3a0f12;border-color:#3a0f12;">❌ Hata</span>'
                                                                       : '<span id="saas-status-trendyol" class="badge">Durum bilinmiyor</span>');
                                    if ($ok_time) $status_badge.='<span class="muted" style="margin-left:8px;">'.esc_html($ok_time).'</span>';
                                }
                                if ($key==='shopify'){
                                    $ok=(int)get_user_meta($user_id,'_cfg_shopify_ok',true);
                                    $ok_time=get_user_meta($user_id,'_cfg_shopify_ok_time',true);
                                    $status_badge = $ok===1 ? '<span id="saas-status-shopify" class="badge" style="background:#0f3a21;border-color:#0f3a21;">✅ Bağlantı Tamam</span>'
                                                            : ($ok===0 ? '<span id="saas-status-shopify" class="badge" style="background:#3a0f12;border-color:#3a0f12;">❌ Hata</span>'
                                                                       : '<span id="saas-status-shopify" class="badge">Durum bilinmiyor</span>');
                                    if ($ok_time) $status_badge .= '<span class="muted" style="margin-left:8px;">'.esc_html($ok_time).'</span>';
                                }
                                if ($key==='hepsiburada'){
                                    $ok=(int)get_user_meta($user_id,'_cfg_hepsiburada_ok',true); $ok_time=get_user_meta($user_id,'_cfg_hepsiburada_ok_time',true);
                                    $status_badge = $ok===1 ? '<span id="saas-status-hb" class="badge" style="background:#0f3a21;border-color:#0f3a21;">✅ Bağlantı Tamam</span>'
                                                            : ($ok===0 ? '<span id="saas-status-hb" class="badge" style="background:#3a0f12;border-color:#3a0f12;">❌ Hata</span>'
                                                                       : '<span id="saas-status-hb" class="badge">Durum bilinmiyor</span>');
                                    if ($ok_time) $status_badge.='<span class="muted" style="margin-left:8px;">'.esc_html($ok_time).'</span>';
                                } ?>
                                <div style="display:flex;gap:8px;align-items:center;">
                                    <?php if ($key!=='shopify'): ?>
                                        <button class="btn primary" type="submit">Kaydet</button>
                                    <?php endif; ?>
                                    <?php if ($key==='trendyol'): ?>
                                        <button type="button" class="btn" onclick="saasTestTrendyol(this)">API Test</button><?php echo isset($status_badge) ? $status_badge : ''; ?>
                                    <?php endif; ?>
                                    <?php if ($key==='n11'): ?>
                                    <button type="button" class="btn" onclick="saasTestN11(this)">API Test</button>
                                <?php endif; ?>
                                    <?php if ($key==='ciceksepeti'): ?>
                                        <button type="button" class="btn" onclick="saasTestCS(this)">API Test</button>
                                    <?php endif; ?>
                                    

<?php if ($key==='shopify'): ?>
<style>
  .shopify-panel{padding:10px;border:1px solid #1e293b;border-radius:12px;background:#0b1220;margin:0}
  .shopify-panel h4{margin:0 0 8px 0;font-size:15px;line-height:1.2}
  .shopify-panel label{display:block;margin:6px 0;font-size:13px}
  .shopify-panel input[type="text"], .shopify-panel input[type="password"]{width:95%;padding:6px}
  .shopify-actions{display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap}
  .shopify-oauth{margin-top:10px;display:flex;gap:8px;align-items:center}
  .badge{margin-left:0}
</style>
<div class="shopify-panel">
  <h4>Shopify Mağaza Bağlantısı</h4>
  <div class="muted" style="margin:6px 0 0 0">Shopify OAuth redirect patch: <strong>ACTIVE</strong></div>
  <label>Shop Domain
    <input type="text" name="cfg[domain]" value="<?php echo esc_attr(get_user_meta($user_id,'_cfg_shopify_domain',true)); ?>" placeholder="example.myshopify.com">
  </label>
  <label>Admin API Access Token
    <input type="password" name="cfg[token]" value="<?php echo esc_attr(get_user_meta($user_id,'_cfg_shopify_token',true)); ?>" placeholder="shpat_...">
  </label>
  <?php
    $ok=(int)get_user_meta($user_id,'_cfg_shopify_ok',true);
    $ok_time=get_user_meta($user_id,'_cfg_shopify_ok_time',true);
    $status_label = $ok===1 ? '✅ Bağlantı Tamam' : ($ok===-1 ? '❌ Hata' : 'Durum bilinmiyor');
    if ($ok_time) $status_label .= ' <span class=\'muted\' style=\'margin-left:6px;\'>'.esc_html($ok_time).'</span>';
  ?>
  <div class="shopify-actions">
    <button class="btn primary" type="submit">Kaydet</button>
    <button type="button" class="btn" onclick="saasTestShopify(this)">API Test</button>
    <span id="saas-status-shopify" class="badge"><?php echo $status_label; ?></span>
  </div>
  <div class="shopify-oauth">
    <strong>Kolay Bağlantı:</strong>
    <button type="button" class="btn" onclick="saasShopifyOAuthStart(this)">OAuth 2.0 ile Bağlan</button>






  </div>
</div>
<?php endif; ?>


                                    <?php if ($key==='hepsiburada'): ?>
                                        <button type="button" class="btn" onclick="saasTestHB(this)">API Test</button><?php echo $status_badge; ?>
                                    <?php endif; ?>
                                <?php if ($key==='ciceksepeti'): ?>
                                        <button type="submit" name="saas_delete" value="1" class="btn danger" onclick="return confirm('Çiçeksepeti API bilgisini silmek istediğinize emin misiniz?')">API Bilgilerini Sil</button>
                                    <?php endif; ?>
                                </div>
                                <div class="muted" id="saas-test-msg-<?php echo esc_attr($key); ?>" style="margin-top:8px;"></div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                    </div>

                    <script>
(function(){
    function saasAjaxTest(action, nonce, msgEl){
        try{
            msgEl.innerHTML = '⏳ Test ediliyor...';
            var fd = new FormData();
            fd.append('action', action);
            fd.append('_ajax_nonce', nonce);
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            })
            .then(async function(r){
                var status = r.status;
                var ct = r.headers.get('content-type') || '';
                if (ct.indexOf('application/json') !== -1){
                    var data = null;
                    try { data = await r.json(); } catch(e){}
                    if (data && data.success){
                        var code = (data.data && data.data.code) ? data.data.code : status;
                        msgEl.innerHTML = '✅ Bağlantı Tamam (HTTP '+code+')';
                    } else {
                        var code = (data && data.data && data.data.code) ? data.data.code : status;
                        var text = (data && data.data && data.data.message) ? data.data.message : 'Hata';
                        msgEl.innerHTML = '❌ '+text+' (HTTP '+code+')';
                    }
                } else {
                    var t = await r.text();
                    if (t.trim() === '-1'){
                        msgEl.innerHTML = '❌ Güvenlik anahtarı (nonce) geçersiz ya da oturum süresi doldu. Lütfen sayfayı yenileyip tekrar deneyin. (HTTP '+status+')';
                    } else {
                        msgEl.innerHTML = '❌ İstek hatası (HTTP '+status+') ' + t.substring(0,200);
                    }
                }
            })
            .catch(function(err){
                msgEl.innerHTML = '❌ İstek hatası: ' + (err && err.message ? err.message : 'bağlantı');
            });
        }catch(e){
            msgEl.innerHTML = '❌ İstek hatası (JS): ' + e.message;
        }
    }

    window.saasTestTrendyol = function(btn){
        var form = btn.closest('form');
        var msg = form.parentElement.querySelector('#saas-test-msg-trendyol');
        if(!msg){ msg=document.createElement('div'); msg.className='muted'; msg.id='saas-test-msg-trendyol'; form.parentElement.appendChild(msg); }
        saasAjaxTest('saas_trendyol_test','<?php echo wp_create_nonce('saas_trendyol_test'); ?>', msg);
    };
    window.saasTestHB = function(btn){
        var form = btn.closest('form');
        var msg = form.parentElement.querySelector('#saas-test-msg-hb');
        if(!msg){ msg=document.createElement('div'); msg.className='muted'; msg.id='saas-test-msg-hb'; form.parentElement.appendChild(msg); }
        saasAjaxTest('saas_hb_test','<?php echo wp_create_nonce('saas_hb_test'); ?>', msg);
    };
    window.saasTestN11 = function(btn){
        var form = btn.closest('form');
        var msg = form.parentElement.querySelector('#saas-test-msg-n11');
        if(!msg){ msg=document.createElement('div'); msg.className='muted'; msg.id='saas-test-msg-n11'; form.parentElement.appendChild(msg); }
        saasAjaxTest('saas_n11_test','<?php echo wp_create_nonce('saas_n11_test'); ?>', msg);
    };
    window.saasTestCS = function(btn){
        var card = btn.closest('.saas-card') || btn.closest('form');
        var msg = card.querySelector('#saas-test-msg-cs');
        if(!msg){ msg=document.createElement('div'); msg.className='muted'; msg.id='saas-test-msg-cs'; card.appendChild(msg); }
        saasAjaxTest('saas_cs_test','<?php echo wp_create_nonce('saas_cs_test'); ?>', msg);
    };

    window.saasTestShopify = function(btn){
        var form = btn.closest('form') || btn.closest('.shopify-panel') || document;
        var msg = form.parentElement.querySelector('#saas-test-msg-shopify');
        if(!msg){ msg=document.createElement('div'); msg.className='muted'; msg.id='saas-test-msg-shopify'; form.parentElement.appendChild(msg); }
        saasAjaxTest('saas_shopify_test','<?php echo wp_create_nonce('saas_shopify_test'); ?>', msg);
    };

})();

    window.saasShopifyOAuthStart = function(btn){
      var form = btn.closest('form') || btn.closest('.shopify-panel') || document;
      var domainInput = form.querySelector('input[name="cfg[domain]"]');
      var shop = (domainInput && domainInput.value || '').trim();
      if(!shop){ alert('Lütfen Shop Domain giriniz.'); return; }
      var state = (Math.random().toString(36).slice(2)) + Date.now();
      var fd = new FormData();
      fd.append('action','saas_shopify_set_state');
      fd.append('_ajax_nonce','<?php echo wp_create_nonce('saas_shopify_state'); ?>');
      fd.append('state', state);
      fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method:'POST', credentials:'same-origin', body: fd })
        .then(function(r){ if(!r.ok) throw new Error('State kaydedilemedi'); })
        .then(function(){
          var clientId   = '<?php echo esc_js( $this->shopify_client_id() ); ?>';
          var scopes     = '<?php echo esc_js( $this->shopify_scopes() ); ?>';
          var redirectUri= '<?php echo esc_js( get_option("saas_shopify_redirect_uri", rest_url("saas-panel/v1/shopify/oauth/callback")) ); ?>';
          var base = 'https://' + shop.replace(/^https?:\/\//,'') + '/admin/oauth/authorize';
          var qs = [
            'client_id=' + encodeURIComponent(clientId),
            'scope=' + encodeURIComponent(scopes),
            'redirect_uri=' + encodeURIComponent(redirectUri),
            'state=' + encodeURIComponent(state)
          ].join('&');
          window.location.href = base + '?' + qs;
        })
        .catch(function(e){ alert('Hata: ' + e.message); });
    };
</script>

                <?php elseif ($tab==='logs'): ?>
                    <div class="saas-card col-12"><h3>Sistem Logları (Son 10)</h3><?php echo $this->render_logs($user_id); ?></div>
                <?php endif; ?>
            </div></main>
        </div>
        <?php
        return ob_get_clean();
    }

    public function rest_profile(\WP_REST_Request $req){
        $key=$req->get_header('X-API-Key'); if(!$key) return new \WP_REST_Response(['error'=>'missing_api_key'],401);
        $user=$this->get_user_by_api_key($key); if(!$user) return new \WP_REST_Response(['error'=>'invalid_api_key'],403);
        $services_csv=get_user_meta($user->ID,self::META_SERVICES,true);
        return ['user_id'=>$user->ID,'email'=>$user->user_email,'services'=>array_filter(array_map('trim', explode(',', strtolower($services_csv))))];
    }
    
    public function rest_shopify_callback(\WP_REST_Request $req){
        $panel = $this->get_panel_url(['tab'=>'settings','service'=>'shopify']);
if (isset($_GET['test']) || isset($_GET['force'])) {
    nocache_headers();
    if (!headers_sent()) { status_header(302); header('Location: ' . esc_url_raw($panel)); }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta http-equiv="refresh" content="0;url='.esc_url($panel).'">';
    echo '<script>window.top.location.replace("'.esc_js($panel).'");</script>';
    exit;
}
$shop = sanitize_text_field($req->get_param('shop'));
        $code = sanitize_text_field($req->get_param('code'));
        $state = sanitize_text_field($req->get_param('state'));
        if (!$shop || !$code){
            return new \WP_REST_Response(['error'=>'missing_params'], 400);
        }
        $user_id = get_current_user_id();
        $expected = get_user_meta($user_id, '_cfg_shopify_state', true);
        if ($expected && $expected !== $state){
            return new \WP_REST_Response(['error'=>'invalid_state'], 400);
        }
        $client_id = defined('SAAS_SHOPIFY_CLIENT_ID') ? SAAS_SHOPIFY_CLIENT_ID : get_option('saas_shopify_client_id','');
        $client_secret = defined('SAAS_SHOPIFY_CLIENT_SECRET') ? SAAS_SHOPIFY_CLIENT_SECRET : get_option('saas_shopify_client_secret','');
        if (!$client_id || !$client_secret){
            return new \WP_REST_Response(['error'=>'missing_app_credentials'], 400);
        }
        $token_url = 'https://'.$shop.'/admin/oauth/access_token';
        $args = [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json', 'User-Agent' => 'SaaSPanel/2.9' ],
            'body' => wp_json_encode([
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'code' => $code
            ])
        ];
        $res = wp_remote_post($token_url, $args);
        if (is_wp_error($res)){
            return new \WP_REST_Response(['error'=>$res->get_error_message()], 500);
        }
        $code_http = wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ($code_http!==200 || empty($body['access_token'])){
            return new \WP_REST_Response(['error'=>'token_exchange_failed','details'=>$body], 500);
        }
        update_user_meta($user_id,'_cfg_shopify_domain', $shop);
        update_user_meta($user_id,'_cfg_shopify_token', sanitize_text_field($body['access_token']));
        update_user_meta($user_id,'_cfg_shopify_ok', 1);
        update_user_meta($user_id,'_cfg_shopify_ok_time', current_time('Y-m-d H:i'));
        $panel = $this->get_panel_url(['tab'=>'settings','service'=>'shopify','oauth'=>'ok']);
        $panel = $this->get_panel_url(['tab'=>'settings','service'=>'shopify','oauth'=>'ok']);

// --- Begin: Solution A (minimal, safe) ---
nocache_headers();
wp_safe_redirect( $panel, 302 );
exit;
// --- End: Solution A ---
    }
    private function get_user_by_api_key($api_key){ $q=new \WP_User_Query(['meta_key'=>self::META_API_KEY,'meta_value'=>$api_key,'number'=>1,'fields'=>'all']); $u=$q->get_results(); return $u?$u[0]:null; }

    public function ajax_trendyol_test(){
        check_ajax_referer('saas_trendyol_test');
        
// SaaS Panel patch: rate limit
$__rl_key = 'saas_ajax_' . get_current_user_id() . '_' . ( isset($_REQUEST['action']) ? sanitize_key($_REQUEST['action']) : 'unknown' ) . '_' . saas_client_ip();
if ( function_exists('saas_rate_limit_check') && ! saas_rate_limit_check($__rl_key, 60, 600) ) {
    wp_send_json_error( ['message'=>'rate_limited'], 429 );
    exit;
}
if(!is_user_logged_in()) wp_send_json_error(['message'=>'Giriş gerekli'],401);
        $user_id=get_current_user_id(); $client=new \DM\SaaS\Services\Trendyol\Client($user_id);
        if(!$client->is_ready()){
            update_user_meta($user_id,'_cfg_trendyol_ok',0); update_user_meta($user_id,'_cfg_trendyol_ok_time', current_time('mysql'));
            wp_send_json_error(['message'=>'Eksik Trendyol API bilgileri.'],400);
        }
        $result=$client->test_connection();
        if(is_wp_error($result)){
            $msg=$result->get_error_message(); $data=$result->get_error_data(); $code=(is_array($data)&&isset($data['status']))?(int)$data['status']:400;
            $this->append_log($user_id,'TRENDYOL API testi başarısız: '.$msg);
            update_user_meta($user_id,'_cfg_trendyol_ok',0); update_user_meta($user_id,'_cfg_trendyol_ok_time', current_time('mysql'));
            wp_send_json_error(['message'=>$msg],$code);
        } else {
            $this->append_log($user_id,'TRENDYOL API bağlantısı başarılı.');
            update_user_meta($user_id,'_cfg_trendyol_ok',1); update_user_meta($user_id,'_cfg_trendyol_ok_time', current_time('mysql'));
            wp_send_json_success(['code'=>$result['code'],'data'=>$result['data']]);
        }
    }

    public 
function ajax_hb_test(){
        check_ajax_referer('saas_hb_test');
        if(!is_user_logged_in()) wp_send_json_error(['message'=>'Giriş gerekli'],401);

        $user_id = get_current_user_id();
        $client  = new \DM\SaaS\Services\Hepsiburada\Client($user_id);

        if(!$client->is_ready()){
            update_user_meta($user_id,'_cfg_hepsiburada_ok', 0);
            update_user_meta($user_id,'_cfg_hepsiburada_ok_time', current_time('mysql'));
            wp_send_json_error(['message'=>'Eksik Hepsiburada API bilgileri (MerchantId / Servis Anahtarı).'],400);
        }

        $res = $client->test_connection();
        if (is_wp_error($res)){
            $data = $res->get_error_data();
            $code = (int) (is_array($data) && isset($data['status']) ? $data['status'] : 400);
            update_user_meta($user_id,'_cfg_hepsiburada_ok', 0);
            update_user_meta($user_id,'_cfg_hepsiburada_ok_time', current_time('mysql'));
            wp_send_json_error(['message'=>$res->get_error_message(),'code'=>$code], $code ?: 400);
        }

        update_user_meta($user_id,'_cfg_hepsiburada_ok', 1);
        update_user_meta($user_id,'_cfg_hepsiburada_ok_time', current_time('mysql'));
        wp_send_json_success(['code'=>$res['code'],'data'=>$res['data'],'env'=>$res['env']]);
    }

    public function add_changelog_page(){
        add_submenu_page(
            'options-general.php',
            'SaaS Panel Sürüm Notları',
            'SaaS Panel Sürüm Notları',
            'manage_options',
            'saas-panel-changelog',
            [$this, 'render_changelog_page']
        );
    }
    public function render_changelog_page(){
        echo '<div class="wrap"><h1>SaaS Panel — Sürüm Notları</h1>';
        $file = plugin_dir_path(__FILE__) . 'CHANGELOG.md';
        if (file_exists($file)) {
            $md = file_get_contents($file);
            // Basit dönüştürme: satır sonlarını <br> yapalım
            $html = nl2br(esc_html($md));
            echo '<div style="background:#fff;padding:16px;border:1px solid #e2e8f0;border-radius:8px;">'.$html.'</div>';
        } else {
            echo '<p>CHANGELOG.md bulunamadı.</p>';
        }
        echo '</div>';
    }

    public function ajax_n11_test(){
        check_ajax_referer('saas_n11_test');
        if(!is_user_logged_in()) wp_send_json_error(['message'=>'Giriş gerekli'],401);
        $user_id = get_current_user_id();
        $client  = new \DM\SaaS\Services\N11\Client($user_id);

        if(!$client->is_ready()){
            update_user_meta($user_id,'_cfg_n11_ok', 0);
            update_user_meta($user_id,'_cfg_n11_ok_time', current_time('mysql'));
            wp_send_json_error(['message'=>'Eksik N11 API bilgileri (appKey/appSecret).'],400);
        }

        $res = $client->test_connection();
        if (is_wp_error($res)){
            $data = $res->get_error_data();
            $code = (int) (is_array($data) && isset($data['status']) ? $data['status'] : 400);
            update_user_meta($user_id,'_cfg_n11_ok', 0);
            update_user_meta($user_id,'_cfg_n11_ok_time', current_time('mysql'));
            wp_send_json_error(['message'=>$res->get_error_message(),'code'=>$code], $code ?: 400);
        }

        update_user_meta($user_id,'_cfg_n11_ok', 1);
        update_user_meta($user_id,'_cfg_n11_ok_time', current_time('mysql'));
        wp_send_json_success(['code'=>$res['code'] ?? 200]);
    }

    public function ajax_cs_test()
    {
{
        check_ajax_referer('saas_cs_test');
        if(!is_user_logged_in()) wp_send_json_error(['message'=>'Giriş gerekli'],401);
        $user_id = get_current_user_id();
        try{
            $client  = new \DM\SaaS\Services\Ciceksepeti\Client($user_id);
            if(!$client->is_ready()){
                wp_send_json_error(['message'=>'Eksik Çiçeksepeti API bilgisi (x-api-key).'],400);
            }
            $res = $client->test_connection();
            if (is_wp_error($res)){
                $data = $res->get_error_data();
                $code = (int) (is_array($data) && isset($data['status']) ? $data['status'] : 400);
                update_user_meta($user_id,'_cfg_ciceksepeti_ok', 0);
                update_user_meta($user_id,'_cfg_ciceksepeti_ok_time', current_time('mysql'));
                wp_send_json_error(['message'=>$res->get_error_message(),'code'=>$code], $code ?: 400);
            }
            update_user_meta($user_id,'_cfg_ciceksepeti_ok', 1);
            update_user_meta($user_id,'_cfg_ciceksepeti_ok_time', current_time('mysql'));
            wp_send_json_success(['code'=>$res['code'],'endpoint'=>$res['endpoint']]);
        } catch (\Throwable $e){
            error_log('[CS TEST] '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
            wp_send_json_error(['message'=>'Sunucu hatası: '.$e->getMessage(),'code'=>500],500);
        }
    }
}
    public function ajax_shopify_test(){
        if (!check_ajax_referer('saas_shopify_test','_ajax_nonce', false)){
            wp_send_json_error(['message'=>'Geçersiz istek','code'=>400]);
        }
        if (!is_user_logged_in()){
            wp_send_json_error(['message'=>'Yetkisiz','code'=>401]);
        }
        $user_id = get_current_user_id();
        $domain = trim((string) get_user_meta($user_id,'_cfg_shopify_domain', true));
        $token  = trim((string) get_user_meta($user_id,'_cfg_shopify_token',  true));
        if (!$domain || !$token){
            wp_send_json_error(['message'=>'Eksik bilgi','code'=>400]);
        }
        $domain = preg_replace('~^https?://~','',$domain);
        $url = 'https://'.$domain.'/admin/api/2025-07/shop.json';
        $res = wp_remote_get($url, [
            'timeout'=>20,
            'headers'=>[
                'X-Shopify-Access-Token'=>$token,
                'Accept'=>'application/json'
            ]
        ]);
        if (is_wp_error($res)){
            wp_send_json_error(['message'=>'İstek hatası','code'=>500]);
        }
        $code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        if ($code===200){
            update_user_meta($user_id,'_cfg_shopify_ok',1);
            update_user_meta($user_id,'_cfg_shopify_ok_time', current_time('mysql'));
            wp_send_json_success(['message'=>'OK','code'=>$code,'peek'=>substr($body,0,120)]);
        } else {
            update_user_meta($user_id,'_cfg_shopify_ok',0);
            update_user_meta($user_id,'_cfg_shopify_ok_time', current_time('mysql'));
            $msg = 'HTTP '.$code;
            $data = json_decode($body,true);
            if (is_array($data) && isset($data['errors'])){ $msg = json_encode($data['errors']); }
            wp_send_json_error(['message'=>$msg,'code'=>$code]);
        }
    }


    public function ajax_shopify_set_state(){
        check_ajax_referer('saas_shopify_state');
        if (!is_user_logged_in()) wp_send_json_error(['message'=>'Giriş gerekli'],401);
        $user_id = get_current_user_id();
        $state   = sanitize_text_field($_POST['state'] ?? '');
        if (!$state) wp_send_json_error(['message'=>'Geçersiz state'],400);
        update_user_meta( $user_id, '_cfg_shopify_state', $state );
        wp_send_json_success();
    }

}

new CK_SaaS_Panel();


// === SaaS Panel: Lightweight rate limit helper ===
if ( ! function_exists('saas_rate_limit_check') ) {
    /**
     * Simple transient-based rate limiter.
     * @param string $key Unique key per user/IP/action
     * @param int $limit Max requests in window
     * @param int $window Window seconds
     * @return bool true if allowed, false if limited
     */
    function saas_rate_limit_check( $key, $limit = 30, $window = 600 ) {
        $bucket = get_transient( $key );
        if ( ! is_array( $bucket ) ) {
            $bucket = ['count' => 0, 'reset' => time() + $window];
        }
        if ( time() > intval($bucket['reset']) ) {
            $bucket = ['count' => 0, 'reset' => time() + $window];
        }
        $bucket['count'] = intval($bucket['count']) + 1;
        set_transient( $key, $bucket, $window );
        return $bucket['count'] <= $limit;
    }
}

if ( ! function_exists('saas_client_ip') ) {
    function saas_client_ip() {
        foreach (['HTTP_CLIENT_IP','HTTP_X_FORWARDED_FOR','HTTP_X_FORWARDED','HTTP_X_CLUSTER_CLIENT_IP','HTTP_FORWARDED_FOR','HTTP_FORWARDED','REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ips = explode(',', $_SERVER[$k]);
                return trim($ips[0]);
            }
        }
        return '0.0.0.0';
    }
}


// === SaaS Panel: token masking helper ===
if ( ! function_exists('saas_mask_secret') ) {
    function saas_mask_secret( $v, $visible = 4 ) {
        $v = (string) $v;
        $len = strlen($v);
        if ($len <= $visible) return str_repeat('*', max(0,$len-1)) . substr($v, -1);
        return str_repeat('*', $len - $visible) . substr($v, -$visible);
    }
}
