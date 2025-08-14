<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('SaaS_Adapter_Trendyol')){
  require_once __DIR__ . '/../saas-adapter-interface.php';
  class SaaS_Adapter_Trendyol implements SaaS_Channel_Adapter {
    protected $user_id;
    public function __construct(int $user_id){ $this->user_id = $user_id; }
    public function test(): array { return ['ok'=>true,'message'=>'stub']; }
    public function list_orders(string $since): array { return []; }
    public function push_product(int $product_id): array { return ['ok'=>false]; }
    public function update_stock(string $sku, int $qty): array { return ['ok'=>false]; }
    public function update_price(string $sku, float $price, string $currency='TRY'): array { return ['ok'=>false]; }
    public function ship(string $remote_order_id, string $tracking_no = '', string $carrier = ''): array { return ['ok'=>false]; }
  }
}

if (!method_exists('SaaS_Adapter_Trendyol','list_orders')){
  class SaaS_Adapter_Trendyol_ListOrders_Ext extends SaaS_Adapter_Trendyol {
    protected $order_base = 'https://apigw.trendyol.com/integration/order';
    public function list_orders($args){
      $sid = get_user_meta($this->user_id, '_cfg_trendyol_supplier_id', true);
      $key = get_user_meta($this->user_id, '_cfg_trendyol_key', true);
      $sec = get_user_meta($this->user_id, '_cfg_trendyol_secret', true);
      if (!$sid || !$key || !$sec) return [];

      $start = isset($args['start']) ? intval($args['start']) : (time()-14*24*60*60)*1000;
      $end   = isset($args['end'])   ? intval($args['end'])   : time()*1000;
      $page  = max(0, (int)($args['page'] ?? 0));
      $size  = min(100, max(10, (int)($args['size'] ?? 50)));

      $url = "{$this->order_base}/sellers/{$sid}/orders?startDate={$start}&endDate={$end}&page={$page}&size={$size}";
      $auth = base64_encode("{$key}:{$sec}");
      $resp = wp_remote_get($url, [
        'headers' => ['Authorization'=>"Basic {$auth}", 'Content-Type'=>'application/json'],
        'timeout' => 45,
      ]);
      if (is_wp_error($resp)) return [];
      $json = json_decode(wp_remote_retrieve_body($resp), true);
      if (!$json || empty($json['content'])) return [];

      $out = [];
      foreach ($json['content'] as $order){
        $o = [
          'service'     => 'trendyol',
          'id'          => $order['orderNumber'] ?? '',
          'orderNumber' => $order['orderNumber'] ?? '',
          'created'     => (int)($order['orderDate'] ?? ($order['createdDate'] ?? 0)),
          'customer'    => trim(($order['customerFirstName'] ?? '').' '.($order['customerLastName'] ?? '')),
          'items'       => [],
          'status'      => $this->map_status(strtolower($order['status'] ?? '')),
          'raw_status'  => strtolower($order['status'] ?? ''),
          'shipment'    => [
            'cargoProviderName' => $order['cargoProviderName'] ?? '',
            'trackingNumber'    => $order['cargoTrackingNumber'] ?? '',
            'address'           => $order['shipmentAddress']['fullAddress'] ?? '',
            'phone'             => $order['shipmentAddress']['phone'] ?? '',
          ],
          'billing'     => [
            'address' => $order['invoiceAddress']['fullAddress'] ?? '',
            'phone'   => $order['invoiceAddress']['phone'] ?? '',
          ],
        ];
        foreach (($order['lines'] ?? $order['orderLines'] ?? []) as $line){
          $o['items'][] = [
            'productName' => $line['productName'] ?? '',
            'quantity'    => (int)($line['quantity'] ?? 0),
            'color'       => $line['color'] ?? ($line['attributes']['color'] ?? ''),
            'size'        => $line['size'] ?? ($line['attributes']['size'] ?? ''),
          ];
        }
        $out[] = $o;
      }
      return $out;
    }

    protected function map_status(string $s): string {
      // Normalize to: pending|shipped|delivered|returned
      if (in_array($s, ['shipped'])) return 'shipped';
      if (in_array($s, ['delivered'])) return 'delivered';
      if (in_array($s, ['returned'])) return 'returned';
      // created, approved, packaged, picking, readytoship, etc.
      return 'pending';
    }

    public function ship(string $remote_order_id, string $tracking_no = '', string $carrier = ''): array {
      // TODO: implement when endpoint clear; for now just echo
      return ['ok'=>true, 'message'=>'Stub: shipment acknowledged', 'id'=>$remote_order_id];
    }
  }
}
