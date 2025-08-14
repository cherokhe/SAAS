<?php
namespace DM\SaaS\Services\N11;
if (!defined('ABSPATH')) exit;

class Client {
    private $user_id, $appKey, $appSecret;
    private $productUrl   = 'https://api.n11.com/ms/product-query?page=0&size=1';
    private $categoriesUrl= 'https://api.n11.com/cdn/categories';

    public function __construct($user_id){
        $this->user_id   = (int)$user_id;
        $this->appKey    = trim((string)get_user_meta($this->user_id,'_cfg_n11_app_key', true));
        $this->appSecret = trim((string)get_user_meta($this->user_id,'_cfg_n11_app_secret', true));
    }

    public function is_ready(){
        return ($this->appKey !== '' && $this->appSecret !== '');
    }

    private function get($url, $headers){
        $args = [
            'timeout' => 20,
            'sslverify' => true,
            'headers' => $headers,
        ];
        return wp_remote_get($url, $args);
    }

    /** Test: Önce product-query (appKey+appSecret), olmazsa categories (appKey) */
    public function test_connection(){
        if(!$this->is_ready()){
            return new \WP_Error('missing_credentials','Eksik N11 API bilgileri (appKey/appSecret).');
        }
        // 1) product-query
        $res = $this->get($this->productUrl, ['appKey' => $this->appKey, 'appSecret' => $this->appSecret]);
        if (!is_wp_error($res)){
            $code = (int) wp_remote_retrieve_response_code($res);
            if ($code >= 200 && $code < 300){
                return ['ok'=>true, 'code'=>$code, 'data'=>['endpoint'=>'/ms/product-query']];
            }
        } else {
            return $res;
        }
        // 2) categories
        $res2 = $this->get($this->categoriesUrl, ['appKey' => $this->appKey]);
        if (is_wp_error($res2)) return $res2;
        $code2 = (int) wp_remote_retrieve_response_code($res2);
        if ($code2 >= 200 && $code2 < 300){
            return ['ok'=>true, 'code'=>$code2, 'data'=>['endpoint'=>'/cdn/categories']];
        }
        $msg = 'HTTP '.$code2.' - '.substr((string)wp_remote_retrieve_body($res2),0,200);
        return new \WP_Error('n11_error', $msg, ['status'=>$code2]);
    }
}
