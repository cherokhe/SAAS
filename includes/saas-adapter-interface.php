<?php
if (!defined('ABSPATH')) exit;

interface SaaS_Channel_Adapter {
    public function __construct(int $user_id);
    /** Return ['ok'=>bool, 'message'=>string, 'details'=>array] */
    public function test(): array;

    /** List recent orders; normalized array. Accepts args: start, end, page, size, status, etc. */
    public function list_orders(array $args = []): array;

    /** List products; accepts args: page, size, status, brand, category, q, etc. */
    public function list_products(array $args = []): array;

    /** Push or update a single product */
    public function push_product(int $product_id): array;

    /** Update stock for SKU */
    public function update_stock(string $sku, int $qty): array;

    /** Update price for SKU */
    public function update_price(string $sku, float $price, string $currency = 'TRY'): array;

    /** Acknowledge/ship an order with optional tracking */
    public function ship(string $remote_order_id, string $tracking_no = '', string $carrier = ''): array;
}
