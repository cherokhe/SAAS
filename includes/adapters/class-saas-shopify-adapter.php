<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/../saas-adapter-interface.php';

class SaaS_Adapter_Shopify implements SaaS_Channel_Adapter {
  protected $user_id;
  public function __construct(int $user_id){ $this->user_id = $user_id; }
  public function test(): array { return ['ok'=>true,'message'=>'Shopify test başarılı (stub)']; }
  public function list_orders(array $args = []): array { return []; }
  public function list_products(array $args = []): array { return ['products'=>[], 'categories'=>[], 'brands'=>[], 'stats'=>['total'=>0,'active'=>0,'inactive'=>0,'total_pages'=>0,'current_page'=>1]]; }
  public function push_product(int $product_id): array { return ['ok'=>false]; }
  public function update_stock(string $sku, int $qty): array { return ['ok'=>false]; }
  public function update_price(string $sku, float $price, string $currency='TRY'): array { return ['ok'=>false]; }
  public function ship(string $remote_order_id, string $tracking_no = '', string $carrier = ''): array { return ['ok'=>false]; }
}
