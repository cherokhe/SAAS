<?php
namespace DM\SaaS\Services\Trendyol;
if (!defined('ABSPATH')) exit;

class Client {
    private $user_id, $api_key, $api_secret, $supplier_id;
    private $base = 'https://apigw.trendyol.com';

    public function __construct($user_id){
        $this->user_id    = (int)$user_id;
        $this->api_key    = trim((string)get_user_meta($this->user_id, '_cfg_trendyol_key', true));
        $this->api_secret = trim((string)get_user_meta($this->user_id, '_cfg_trendyol_secret', true));
        $this->supplier_id= trim((string)get_user_meta($this->user_id, '_cfg_trendyol_supplier_id', true));
    }

    public function is_ready(){
        return ($this->api_key !== '' && $this->api_secret !== '');
    }

    private function headers(){
        $auth = base64_encode($this->api_key . ':' . $this->api_secret);
        return [
            'Authorization' => 'Basic ' . $auth,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ];
    }

    private function endpoint($path){
        $path = ltrim($path, '/');
        return rtrim($this->base, '/') . '/' . ltrim($path,'/');
    }

    private function get($path, $args = []){
        $url = $this->endpoint($path);
        if (!empty($args)) {
            $url = add_query_arg($args, $url);
        }
        $res = wp_remote_get($url, [
            'timeout'   => 20,
            'headers'   => $this->headers(),
            'user-agent' => 'SaaSPanel/2.9.5 (+https://example.local)',
            'sslverify' => true
        ]);
        return $res;
    }

    public function test_connection(){
        if(!$this->is_ready()){
            return new \WP_Error('missing_credentials','Eksik Trendyol API bilgileri (Key/Secret).');
        }
        $res = $this->get('integration/product/product-categories', []);
        if (is_wp_error($res)) return $res;
        $code = (int) wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        $json = json_decode($body, true);
        if ($code >= 200 && $code < 300) {
            return ['ok'=>true, 'code'=>$code, 'data'=> is_array($json) ? $json : ['raw'=>$body]];
        }
        $msg = 'HTTP ' . $code;
        $rm  = wp_remote_retrieve_response_message($res);
        if ($rm) $msg .= ' ' . $rm;
        if (!empty($body)) $msg .= ' - ' . substr($body, 0, 300);
        return new \WP_Error('trendyol_error', $msg, ['status'=>$code, 'body'=>$body]);
    }
}