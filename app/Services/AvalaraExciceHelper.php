<?php


namespace App\Services;


use App\Models\Product;
use App\Models\ProductVariant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class AvalaraExciceHelper
{
    protected $userName;
    protected $password;
    protected $companyId;

    public function setCredentials($userName, $password, $companyId) {
        $this->userName = $userName;
        $this->password = $password;
        $this->companyId = $companyId;
    }

    public function calculateExcice(array $requestDataAdjust) {
        $headers = [
            'Accept' => 'application/json',
            'x-company-id' => $this->companyId,
        ];
        $http = Http::timeout(60)->withHeaders($headers);
        $http->withBasicAuth($this->userName, $this->password);
        return $http->post(env('AVALARA_API_ENDPOINT') . '/AvaTaxExcise/transactions/create', $requestDataAdjust);
    }

    public function dataStore($productIds, $shop, $requestDataAdjust, $transactionLines, $response) {
        $products_chunk = array_chunk($productIds, 250);
        for ($i = 0; $i < count($products_chunk); $i++) {
            $ids = $products_chunk[$i];
            $ids = implode(",", $ids);
            $param = ['limit' => 250, 'ids' => $ids];
            $data250 = $shop->api()->rest('GET', '/admin/products.json', $param);
            if (isset($data250['body']['products'])) {
                foreach ($data250['body']['products'] as $key => $product) {
                    $tags = explode(",", $product['tags']);
                    $tags = array_map('trim', $tags);
                    Product::updateOrCreate([
                        'shop_id' => $shop->id,
                        'shopify_product_id' => $product['id'],
                    ],[
                        'shop_id' => $shop->id,
                        'shopify_product_id' => $product['id'],
                        'title' => $product['title'],
                        'handle' => $product['handle'],
                        'vendor' => $product['vendor'],
                        'tags' => $tags,
                        'image_url' => !empty($product['image']) ? $product['image']['src'] : null,
                    ]);

                    foreach ($product['variants'] as $variant) {
                        ProductVariant::updateOrCreate([
                            'shop_id' => $shop->id,
                            'variant_id' => $variant['id'],
                        ],[
                            'shop_id' => $shop->id,
                            'product_id' => $product['id'],
                            'variant_id' => $variant['id'],
                            'option_1_name' => isset($product['options'][0]) ? $product['options'][0]['name'] : null,
                            'option_1_value' => $variant['option1'],
                            'option_2_name' => isset($product['options'][1]) ? $product['options'][1]['name'] : null,
                            'option_2_value' => $variant['option2'],
                            'option_3_name' => isset($product['options'][2]) ? $product['options'][2]['name'] : null,
                            'option_3_value' => $variant['option3'],
                            'sku' => $variant['sku'],
                            'barcode' => $variant['barcode'],
                            'price' => $variant['price'],
                            'compare_at_price' => $variant['compare_at_price'],
                            'quantity' => $variant['inventory_quantity'],
                        ]);
                    }
                }
            }
        }

        DB::table('avalara_transaction_log')->insert([
            "ip" => "0.0.0.0",
            "shop_id" => $shop->id,
            "request_data" => json_encode($requestDataAdjust),
            "total_requested_products" => count($transactionLines),
            "response" => $response->status() != 200 ? json_encode($response->body()) : $response->body(),
            "filtered_request_data" => json_encode($requestDataAdjust),
            "status" =>$response->status(),
            "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
            "updated_at" => Carbon::now()->format('Y-m-d H:i:s')
        ]);

        $exciseTax = 0;
        $transactionError = null;
    }
}
