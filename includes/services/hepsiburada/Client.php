<?php
namespace DM\SaaS\Services\Hepsiburada;
if (!defined('ABSPATH')) exit;

class Client {
    private $user_id, $username, $password, $merchantId, $env;

    public function __construct($user_id){
        $this->user_id   = (int)$user_id;
        // Entegratör kullanıcı adı (User-Agent olarak kullanılacak)
        $this->username  = trim((string)get_user_meta($this->user_id, '_cfg_hepsiburada_username', true));
        // Servis Anahtarı
        $this->password  = trim((string)get_user_meta($this->user_id, '_cfg_hepsiburada_password', true));
        // Merchant ID (UUID)
        $this->merchantId= trim((string)get_user_meta($this->user_id, '_cfg_hepsiburada_merchant_id', true));
        $env             = get_user_meta($this->user_id, '_cfg_hepsiburada_env', true);
        $this->env       = ($env === 'sit' || $env === 'prod') ? $env : 'prod';
    }

    private function base_url(){
        return $this->env === 'sit'
            ? 'https://oms-external-sit.hepsiburada.com'
            : 'https://oms-external.hepsiburada.com';
    }

    private function endpoint($path){
        $path = ltrim($path, '/');
        return rtrim($this->base_url(), '/') . '/' . $path;
    }

    private function headers(){
        // Basic Auth: username = merchantId, password = service key
        $auth = base64_encode($this->merchantId . ':' . $this->password);
        $headers = [
            'Authorization' => 'Basic ' . $auth,
            // Hepsiburada'nın yeni auth düzeninde entegratör kullanıcı adı User-Agent olarak gönderilir
            'User-Agent'    => ($this->username !== '' ? $this->username : 'hepsiapi_dev'),
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ];
        return $headers;
    }

    private function get($path, $args = []){
        // orders endpoint'i için doğru pattern: /orders/merchantid/{merchantId}?limit=1&offset=0
        $url = $this->endpoint($path);
        if (!empty($args)) {
            $url = add_query_arg($args, $url);
        }
        $res = wp_remote_get($url, [
            'timeout'   => 20,
            'headers'   => $this->headers(),
            'user-agent' => ($this->username !== '' ? $this->username : 'hepsiapi_dev'),
            'sslverify' => true,
        ]);
        return $res;
    }

    public function is_ready(){
        // username User-Agent içindir, auth için merchantId + password zorunlu
        return ($this->merchantId !== '' && $this->password !== '');
    }

    public function test_connection(){
        if(!$this->is_ready()){
            return new \WP_Error('missing_creds','Eksik Hepsiburada API bilgileri (MerchantId / Servis Anahtarı).');
        }
        $path = 'orders/merchantid/' . rawurlencode($this->merchantId);
        $res  = $this->get($path, ['limit'=>1, 'offset'=>0]);
        if (is_wp_error($res)) return $res;

        $code = (int) wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        $json = json_decode($body, true);

        if ($code >=200 && $code < 300) {
            return ['ok'=>true, 'code'=>$code, 'data'=> is_array($json) ? $json : ['raw'=>$body], 'env'=>$this->env];
        }

        $msg = 'HTTP ' . $code;
        $rm  = wp_remote_retrieve_response_message($res);
        if ($rm) $msg .= ' ' . $rm;
        if (!empty($body)) $msg .= ' - ' . substr($body, 0, 300);
        return new \WP_Error('hb_error', $msg, ['status'=>$code, 'body'=>$body, 'env'=>$this->env]);
    }
}
