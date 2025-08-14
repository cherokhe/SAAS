<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/../saas-adapter-interface.php';

/**
 * SaaS_Adapter_Trendyol (unified)
 * Reads user_meta: _cfg_trendyol_key, _cfg_trendyol_secret, _cfg_trendyol_supplier_id
 */
class SaaS_Adapter_Trendyol implements SaaS_Channel_Adapter {
  protected $user_id;
  protected $key;
  protected $sec;
  protected $sid;
  protected $prod_base  = 'https://apigw.trendyol.com/integration/product';
  protected $order_base = 'https://apigw.trendyol.com/integration/order';

  public function __construct(int $user_id){
    $this->user_id = $user_id;
    $this->key = (string) get_user_meta($user_id, '_cfg_trendyol_key', true);
    $this->sec = (string) get_user_meta($user_id, '_cfg_trendyol_secret', true);
    $this->sid = (string) get_user_meta($user_id, '_cfg_trendyol_supplier_id', true);
  }

  protected function auth_header(){
    return 'Basic '. base64_encode("{$this->key}:{$this->sec}");
  }
  protected function ok_creds(): bool {
    return !empty($this->key) && !empty($this->sec) && !empty($this->sid);
  }

  public function test(): array {
    if (!$this->ok_creds()) return ['ok'=>false, 'message'=>'Trendyol bilgileri eksik'];
    $url = "{$this->prod_base}/sellers/{$this->sid}/products?page=1&size=1";
    $resp = wp_remote_get($url, ['headers'=>['Authorization'=>$this->auth_header(), 'Content-Type'=>'application/json'], 'timeout'=>25]);
    if (is_wp_error($resp)) return ['ok'=>false,'message'=>$resp->get_error_message()];
    $code = wp_remote_retrieve_response_code($resp);
    return ['ok'=> ($code>=200 && $code<300), 'message'=> "HTTP {$code}"];
  }

  /*** PRODUCTS ***/
  public function list_products(array $args = []) : array {
    if (!$this->ok_creds()) return ['products'=>[], 'categories'=>[], 'brands'=>[], 'stats'=>['total'=>0,'active'=>0,'inactive'=>0,'total_pages'=>0,'current_page'=>1]];
    $page = max(1, (int)($args['page'] ?? 1));
    $size = min(100, max(1, (int)($args['size'] ?? 25)));
    $url  = "{$this->prod_base}/sellers/{$this->sid}/products?page={$page}&size={$size}";

    $resp = wp_remote_get($url, ['headers'=>['Authorization'=>$this->auth_header(), 'Content-Type'=>'application/json'], 'timeout'=>45]);
    if (is_wp_error($resp)) return ['products'=>[], 'categories'=>[], 'brands'=>[], 'stats'=>['total'=>0,'active'=>0,'inactive'=>0,'total_pages'=>0,'current_page'=>$page]];
    $json = json_decode(wp_remote_retrieve_body($resp), true);
    if (!$json || empty($json['content'])) return ['products'=>[], 'categories'=>[], 'brands'=>[], 'stats'=>['total'=>0,'active'=>0,'inactive'=>0,'total_pages'=>0,'current_page'=>$page]];

    $grouped = []; $total_active=0; $inactive=0;
    foreach ($json['content'] as $item){
      $mainId = $item['productMainId'];
      if (!isset($grouped[$mainId])){
        $brand = $this->extract_brand($item);
        $grouped[$mainId] = [
          'name' => $item['title'] ?? '', 'description'=>$item['description'] ?? '', 'model'=>$mainId,
          'short_description'=>$item['productContent'] ?? '',
          'price'=>$item['listPrice'] ?? 0, 'sale_price'=>$item['salePrice'] ?? ($item['listPrice'] ?? 0),
          'category'=>$item['categoryName'] ?? '', 'category_id'=>$item['categoryId'] ?? 0,
          'images'=> array_column($item['images'] ?? [], 'url'), 'brand'=>$brand,
          'variants'=>[], 'quantity'=>0, 'colors'=>[], 'sizes'=>[], 'all_attributes'=>[],
          'product_url'=>$item['productUrl'] ?? '',
        ];
      }
      $color_value = ''; $size_values = [];
      foreach (($item['attributes'] ?? []) as $attr){
        $n = $attr['attributeName'] ?? ''; $v = sanitize_text_field($attr['attributeValue'] ?? '');
        $grouped[$mainId]['all_attributes'][] = ['name'=>$n,'value'=>$v];
        if (preg_match('/^renk$/iu',$n)){ $clean=$this->clean_color_value($v); if ($clean){ $color_value=$clean; if(!in_array($clean,$grouped[$mainId]['colors'],true)) $grouped[$mainId]['colors'][]=$clean; } }
        if (preg_match('/beden|size/iu',$n)){ $sv = array_map('trim', preg_split('/[,;\/]/',$v)); foreach ($sv as $s){ if($s && !in_array($s,$grouped[$mainId]['sizes'],true)) $grouped[$mainId]['sizes'][]=$s; } $size_values = array_values(array_unique(array_filter($sv))); }
      }
      if (!$color_value){
        foreach (($item['attributes'] ?? []) as $attr){
          $n = $attr['attributeName'] ?? ''; $v = sanitize_text_field($attr['attributeValue'] ?? '');
          if (preg_match('/renk|color/iu',$n) && !preg_match('/^renk$/iu',$n)){ $clean=$this->clean_color_value($v); if($clean){ $color_value=$clean; if(!in_array($clean,$grouped[$mainId]['colors'],true)) $grouped[$mainId]['colors'][]=$clean; } }
        }
      }
      $grouped[$mainId]['variants'][] = [
        'attributes'=>$item['attributes'] ?? [], 'barcode'=>$item['barcode'] ?? '', 'stockCode'=>$item['stockCode'] ?? '',
        'quantity'=>intval($item['quantity'] ?? 0), 'color'=>$color_value, 'sizes'=>$size_values,
        'price'=>$item['salePrice'] ?? ($item['listPrice'] ?? 0),
      ];
      $grouped[$mainId]['quantity'] += intval($item['quantity'] ?? 0);
      if (intval($item['quantity'] ?? 0) > 0) $total_active++; else $inactive++;
    }

    // simple filters server-side
    $status = strtolower(trim((string)($args['status'] ?? '')));
    $brand  = strtolower(trim((string)($args['brand'] ?? '')));
    $cat    = strtolower(trim((string)($args['category'] ?? '')));
    $q      = strtolower(trim((string)($args['q'] ?? '')));

    $products = array_values($grouped);
    $products = array_filter($products, function($p) use ($status,$brand,$cat,$q){
      if ($status==='active'  && ($p['quantity'] ?? 0) <= 0) return false;
      if ($status==='inactive'&& ($p['quantity'] ?? 0) >  0) return false;
      if ($brand && strtolower($p['brand'] ?? '') !== $brand) return false;
      if ($cat   && strtolower($p['category'] ?? '') !== $cat) return false;
      if ($q){ $hay = strtolower(($p['name'] ?? '').' '.($p['model'] ?? '').' '.($p['brand'] ?? '').' '.($p['category'] ?? '')); if (strpos($hay,$q)===false) return false; }
      return true;
    });

    $cats = array_values(array_unique(array_map(fn($p)=>$p['category'], $products)));
    $brands2 = array_values(array_unique(array_map(fn($p)=>$p['brand'], $products)));
    $total = (int)($json['totalElements'] ?? count($products));
    $total_pages = (int)($json['totalPages'] ?? max(1, ceil($total / max(1,(int)$size))));
    $current_page = (int)($json['page'] ?? $page);

    return ['products'=>array_values($products), 'categories'=>$cats, 'brands'=>$brands2,
      'stats'=>['total'=>$total, 'active'=>$total_active, 'inactive'=>$inactive, 'total_pages'=>$total_pages, 'current_page'=>$current_page]];
  }

  /*** ORDERS ***/
  public function list_orders(array $args = []) : array {
    if (!$this->ok_creds()) return [];
    $start = isset($args['start']) ? intval($args['start']) : (time()-14*24*60*60)*1000;
    $end   = isset($args['end'])   ? intval($args['end'])   : time()*1000;
    $page  = max(0, (int)($args['page'] ?? 0));
    $size  = min(100, max(10, (int)($args['size'] ?? 50)));
    $url = "{$this->order_base}/sellers/{$this->sid}/orders?startDate={$start}&endDate={$end}&page={$page}&size={$size}";

    $resp = wp_remote_get($url, ['headers'=>['Authorization'=>$this->auth_header(), 'Content-Type'=>'application/json'], 'timeout'=>45]);
    if (is_wp_error($resp)) return [];
    $json = json_decode(wp_remote_retrieve_body($resp), true);
    if (!$json || empty($json['content'])) return [];

    $out = [];
    foreach ($json['content'] as $order){
      $o = [
        'service'=>'trendyol',
        'id'=>$order['orderNumber'] ?? '',
        'orderNumber'=>$order['orderNumber'] ?? '',
        'created'=>(int)($order['orderDate'] ?? ($order['createdDate'] ?? 0)),
        'customer'=> trim(($order['customerFirstName'] ?? '').' '.($order['customerLastName'] ?? '')),
        'items'=>[],
        'status'=> $this->map_status(strtolower($order['status'] ?? '')),
        'raw_status'=> strtolower($order['status'] ?? ''),
        'shipment'=>[
          'cargoProviderName'=>$order['cargoProviderName'] ?? '',
          'trackingNumber'=>$order['cargoTrackingNumber'] ?? '',
          'address'=>$order['shipmentAddress']['fullAddress'] ?? '',
          'phone'=>$order['shipmentAddress']['phone'] ?? '',
        ],
        'billing'=>[
          'address'=>$order['invoiceAddress']['fullAddress'] ?? '',
          'phone'=>$order['invoiceAddress']['phone'] ?? '',
        ],
      ];
      foreach (($order['lines'] ?? $order['orderLines'] ?? []) as $line){
        $o['items'][] = [
          'productName'=>$line['productName'] ?? '',
          'quantity'=>(int)($line['quantity'] ?? 0),
          'color'=>$line['color'] ?? ($line['attributes']['color'] ?? ''),
          'size'=>$line['size'] ?? ($line['attributes']['size'] ?? ''),
        ];
      }
      $out[] = $o;
    }
    return $out;
  }

  public function push_product(int $product_id): array { return ['ok'=>false,'message'=>'Not implemented']; }
  public function update_stock(string $sku, int $qty): array { return ['ok'=>false,'message'=>'Not implemented']; }
  public function update_price(string $sku, float $price, string $currency='TRY'): array { return ['ok'=>false,'message'=>'Not implemented']; }
  public function ship(string $remote_order_id, string $tracking_no = '', string $carrier = ''): array {
    return ['ok'=>true, 'message'=>'Stub: shipment acknowledged', 'id'=>$remote_order_id];
  }

  protected function map_status(string $s): string {
    if (in_array($s, ['shipped'])) return 'shipped';
    if (in_array($s, ['delivered'])) return 'delivered';
    if (in_array($s, ['returned'])) return 'returned';
    return 'pending';
  }
  protected function extract_brand(array $item): string {
    if (!empty($item['brand'])){
      if (is_array($item['brand']) && !empty($item['brand']['name'])) return (string)$item['brand']['name'];
      if (is_string($item['brand'])) return $item['brand'];
    }
    foreach (($item['attributes'] ?? []) as $attr){
      $n = strtolower($attr['attributeName'] ?? '');
      if ($n === 'marka' || $n === 'brand') return (string)($attr['attributeValue'] ?? '');
    }
    return '';
  }
  protected function clean_color_value(string $v): string {
    $v = trim($v); $v = preg_replace('/\s+/', ' ', $v); return $v;
  }
}
