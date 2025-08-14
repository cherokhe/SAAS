<?php
namespace DM\SaaS\Services\Ciceksepeti;
if (!defined('ABSPATH')) exit;

class Client {
    /** @var string|null */
    public $sellerId = null;

    private $user_id;
    private $apiKey;
    private $prodBase = 'https://apis.ciceksepeti.com/api/v1';
    private $testBase = 'https://sandbox-apis.ciceksepeti.com/api/v1';

    public function __construct($user_id){
        $this->user_id = (int)$user_id;
        $this->apiKey  = trim((string) get_user_meta($this->user_id, '_cfg_ciceksepeti_key', true));
        $this->sellerId = trim((string) get_user_meta($this->user_id, '_cfg_ciceksepeti_seller_id', true));
    }
    public function is_ready(){ return $this->apiKey !== ''; }

    private function get($url){
        $args = [
            'timeout' => 20,
            'sslverify' => true,
            'headers' => array_filter([
                'x-api-key' => $this->apiKey,
                'x-seller-id' => $this->sellerId ?: null,
                'Accept' => 'application/json',
                'User-Agent' => 'ck-saas-cs/1.0'
            ]),
        ];
        return wp_remote_get($url, $args);
    }

    /** Basit test: Categories (önce prod, 2xx değilse sandbox) */
    public function test_connection(){
        if(!$this->is_ready()){
            return new \WP_Error('missing_key','Eksik Çiçeksepeti API bilgisi (x-api-key).');
        }
        $urlProd = $this->prodBase . '/Categories';
        $res = $this->get($urlProd);
        if (!is_wp_error($res)){
            $code = (int) wp_remote_retrieve_response_code($res);
            if ($code >= 200 && $code < 300){
                return ['ok'=>true, 'code'=>$code, 'endpoint'=>'/api/v1/Categories (prod)'];
            }
        } else {
            return $res;
        }
        // fallback: sandbox
        $urlTest = $this->testBase . '/Categories';
        $res2 = $this->get($urlTest);
        if (is_wp_error($res2)) return $res2;
        $code2 = (int) wp_remote_retrieve_response_code($res2);
        if ($code2 >= 200 && $code2 < 300){
            return ['ok'=>true, 'code'=>$code2, 'endpoint'=>'/api/v1/Categories (sandbox)'];
        }
        $msg = 'HTTP '.$code2.' - '.substr((string)wp_remote_retrieve_body($res2),0,200);
        return new \WP_Error('cs_error', $msg, ['status'=>$code2]);
    }
}
