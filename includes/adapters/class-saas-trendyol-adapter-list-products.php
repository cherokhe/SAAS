<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/../saas-adapter-interface.php';

/** Extend existing Trendyol adapter stub with list_products() */
if (!class_exists('SaaS_Adapter_Trendyol')){
  class SaaS_Adapter_Trendyol implements SaaS_Channel_Adapter {
    public function __construct(int $user_id){ $this->user_id = $user_id; }
    public function test(): array { return ['ok'=>true,'message'=>'stub']; }
    public function list_orders(string $since): array { return []; }
    public function push_product(int $product_id): array { return ['ok'=>false]; }
    public function update_stock(string $sku, int $qty): array { return ['ok'=>false]; }
    public function update_price(string $sku, float $price, string $currency='TRY'): array { return ['ok'=>false]; }
    public function ship(string $remote_order_id, string $tracking_no = '', string $carrier = ''): array { return ['ok'=>false]; }
  }
}

if (!method_exists('SaaS_Adapter_Trendyol','list_products')){
  class SaaS_Adapter_Trendyol_ListProducts_Ext extends SaaS_Adapter_Trendyol {
    protected $base = 'https://apigw.trendyol.com/integration/product';

    public function list_products(array $args = []) : array {
      $sid = get_user_meta($this->user_id, '_cfg_trendyol_supplier_id', true);
      $key = get_user_meta($this->user_id, '_cfg_trendyol_key', true);
      $sec = get_user_meta($this->user_id, '_cfg_trendyol_secret', true);
      if (!$sid || !$key || !$sec){
        return ['products'=>[], 'categories'=>[], 'brands'=>[], 'stats'=>['total'=>0,'active'=>0,'inactive'=>0,'total_pages'=>0,'current_page'=>1]];
      }
      $page = max(1, (int)($args['page'] ?? 1));
      $size = min(100, max(1, (int)($args['size'] ?? 25)));
      $url  = "{$this->base}/sellers/{$sid}/products?page={$page}&size={$size}";
      $auth = base64_encode("{$key}:{$sec}");

      $resp = wp_remote_get($url, [
        'headers' => ['Authorization' => "Basic {$auth}", 'Content-Type'=>'application/json'],
        'timeout' => 45,
      ]);
      if (is_wp_error($resp)){
        return ['products'=>[], 'categories'=>[], 'brands'=>[], 'stats'=>['total'=>0,'active'=>0,'inactive'=>0,'total_pages'=>0,'current_page'=>$page]];
      }
      $json = json_decode(wp_remote_retrieve_body($resp), true);
      if (!$json || empty($json['content'])){
        return ['products'=>[], 'categories'=>[], 'brands'=>[], 'stats'=>['total'=>0,'active'=>0,'inactive'=>0,'total_pages'=>0,'current_page'=>$page]];
      }

      // Normalize like your class-product-import.php
      $grouped = [];
      $brands  = [];
      $total_active = 0; $inactive = 0;
      foreach ($json['content'] as $item){
        $mainId = $item['productMainId'];
        if (!isset($grouped[$mainId])){
          $brand = $this->extract_brand($item);
          $brands[$brand] = $brand;
          $grouped[$mainId] = [
            'name'              => $item['title'] ?? '',
            'description'       => $item['description'] ?? '',
            'model'             => $mainId,
            'short_description' => $item['productContent'] ?? '',
            'price'             => $item['listPrice'] ?? 0,
            'sale_price'        => $item['salePrice'] ?? ($item['listPrice'] ?? 0),
            'category'          => $item['categoryName'] ?? '',
            'category_id'       => $item['categoryId'] ?? 0,
            'images'            => array_column($item['images'] ?? [], 'url'),
            'brand'             => $brand,
            'variants'          => [],
            'quantity'          => 0,
            'colors'            => [],
            'sizes'             => [],
            'all_attributes'    => [],
            'product_url'       => $item['productUrl'] ?? '',
          ];
        }
        // attributes → renk/beden + all_attributes
        $color_value = '';
        $size_values = [];
        foreach (($item['attributes'] ?? []) as $attr){
          $attrName = $attr['attributeName'] ?? '';
          $attrValue= sanitize_text_field($attr['attributeValue'] ?? '');
          $grouped[$mainId]['all_attributes'][] = ['name'=>$attrName, 'value'=>$attrValue];
          if (preg_match('/^renk$/iu', $attrName)){
            $clean = $this->clean_color_value($attrValue);
            if ($clean){ $color_value = $clean; if (!in_array($clean, $grouped[$mainId]['colors'], true)) $grouped[$mainId]['colors'][] = $clean; }
          }
          if (preg_match('/beden|size/iu', $attrName)){
            $sv = array_map('trim', preg_split('/[,;\/]/', $attrValue));
            foreach ($sv as $s){ if ($s && !in_array($s, $grouped[$mainId]['sizes'], true)) $grouped[$mainId]['sizes'][] = $s; }
            $size_values = array_values(array_unique(array_filter($sv)));
          }
        }
        if (!$color_value){
          foreach (($item['attributes'] ?? []) as $attr){
            $attrName = $attr['attributeName'] ?? ''; $attrValue = sanitize_text_field($attr['attributeValue'] ?? '');
            if (preg_match('/renk|color/iu', $attrName) && !preg_match('/^renk$/iu', $attrName)){
              $clean = $this->clean_color_value($attrValue);
              if ($clean){ $color_value = $clean; if (!in_array($clean, $grouped[$mainId]['colors'], true)) $grouped[$mainId]['colors'][] = $clean; }
            }
          }
        }
        $grouped[$mainId]['variants'][] = [
          'attributes' => $item['attributes'] ?? [],
          'barcode'    => $item['barcode'] ?? '',
          'stockCode'  => $item['stockCode'] ?? '',
          'quantity'   => intval($item['quantity'] ?? 0),
          'color'      => $color_value,
          'sizes'      => $size_values,
          'price'      => $item['salePrice'] ?? ($item['listPrice'] ?? 0),
        ];
        $grouped[$mainId]['quantity'] += intval($item['quantity'] ?? 0);
        // status sayacı (aktif/pasif) — kaba yaklaşım: quantity>0 aktif sayılabilir
        if (intval($item['quantity'] ?? 0) > 0) $total_active++; else $inactive++;
      }

      // Server-side filters
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
        if ($q){
          $hay = strtolower(($p['name'] ?? '').' '.($p['model'] ?? '').' '.($p['brand'] ?? '').' '.($p['category'] ?? ''));
          if (strpos($hay, $q) === false) return false;
        }
        return true;
      });

      $cats = array_values(array_unique(array_map(fn($p)=>$p['category'], $products)));
      $brands = array_values(array_unique(array_map(fn($p)=>$p['brand'], $products)));
      $total = (int)($json['totalElements'] ?? count($products));
      $total_pages = (int)($json['totalPages'] ?? max(1, ceil($total / max(1,(int)$size))));
      $current_page = (int)($json['page'] ?? $page);

      return [
        'products'   => array_values($products),
        'categories' => $cats,
        'brands'     => $brands,
        'stats'      => [
          'total' => $total,
          'active' => $total_active,
          'inactive' => $inactive,
          'total_pages' => $total_pages,
          'current_page'=> $current_page,
        ],
      ];
    }

    protected function extract_brand(array $item): string {
      // Heuristic from your class: from item fields / attributes
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
      $v = trim($v);
      $v = preg_replace('/\s+/',' ', $v);
      return $v;
    }
  }
}
