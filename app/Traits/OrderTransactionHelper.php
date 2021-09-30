<?php

namespace App\Traits;

use App\Models\AvalaraTransactionLog;
use App\Models\ExciseByProduct;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Transaction;
use App\Services\AvalaraExciceHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

trait OrderTransactionHelper
{
    public static function transaction($shop, $data, $isOrderCreated = true)
    {
        list($companyId, $apiUsername, $apiUserPassword) = Helpers::avalaraCredentials($shop->id);
        $productForExcise = Helpers::productForExcise($shop->id);
        $productIdentifierForExcise = Helpers::productIdentifierForExcise($shop->id);
        $headers = [
            'Accept'       => 'application/json',
            'x-company-id' => $companyId,
        ];

        $taxableItem = 0;
        $isFailed = $hasTransactionLogId = false;
        if (!empty($data->note_attributes)) {

            foreach ($data->note_attributes as $noteAttribute) {

                if ($noteAttribute->name === 'checkout_failure' && $noteAttribute->value === 'true') {
                    $isFailed = true;
                }
                if ($noteAttribute->name === 'transaction_log_id' && $noteAttribute->value !== null &&  $noteAttribute->value !== 'N/A') {
                    $hasTransactionLogId = $noteAttribute->value;
                }
            }
        }
        if ($isFailed && $isOrderCreated) {
            $failedReason = 'Unknown Error';
            $exciseTax = 0;
            $taxableItem = 0;
            foreach ($data->line_items as $line_item) {
                if (!empty($line_item->sku)) {
                    $taxableItem += $line_item->fulfillable_quantity;
                }
            }
            if ($hasTransactionLogId) {
                $avalaraTransactionLog = AvalaraTransactionLog::where('id', $hasTransactionLogId)->first();
                if (is_object($avalaraTransactionLog->response)) {
                    $failureKey = 'failover_notification_2';
                } else {
                    $failureKey = 'failover_notification_1';
                }
                if (!empty($avalaraTransactionLog)) {
                    $failedReason = is_object($avalaraTransactionLog->response) ? 'Unknown Error' : $avalaraTransactionLog->response;
                }
            } else {
                $failureKey = 'failover_notification_2';
            }
            $failoverNotification = $shop->settings()->where('key', $failureKey)->first()->value ?? null;
            if ($failoverNotification) {
                $dbTags = $failoverNotification->tags ?? null;
                if ($dbTags) {
                    $tags = array_map('trim', explode(',', $data->tags));
                    foreach ($dbTags as $dbTag) {
                        $tags[] = $dbTag;
                    }

                    $orderObj = [
                        'id'   => $data->id,
                        'tags' => implode(',', $tags),
                    ];
                    $shop->api()->rest('PUT', '/admin/orders/'.$data->id.'.json', ['order' => $orderObj]);
                }
            }
        } elseif ($hasTransactionLogId) {
            $invoiceDate = Carbon::parse($data->created_at)->format('Y-m-d H:i:s');

            $transactionLines = $variantIds = $productIds = [];
            $itemCounter = 0;

            if (!empty($data->line_items)) {
                foreach ($data->line_items as $line_item) {
                    if (!empty($line_item->sku)) {

                        $productTags = $shop->api()->rest('GET', '/admin/products/'.$line_item->product_id.'.json');
                        if (isset($productTags['body']['product']) && !empty($productTags['body']['product'])) {
                            $productTags = $productTags['body']['product']['tags'];
                        }
                        $item = [];
                        $item['ProductCode'] = $item['itemSKU'] = Str::substr($line_item->sku, 0, 24);
                        $item['tags'] = $productTags;
                        if (!filterRequest($item, $productForExcise, $productIdentifierForExcise)) {
                            continue;
                        }
                        ++$itemCounter;
                        $variantIds[] = $line_item->variant_id;
                        $productIds[$itemCounter] = $line_item->product_id;
                        $line_item->invoice_line = $itemCounter;
                        $line_item->invoice_date = $invoiceDate;
                        $transactionLines[] = self::prepareTransactionLine($shop->id, $line_item,
                            $data->shipping_address, $isOrderCreated, $data);
                    }
                }
            }
            $avalaraRequestData = self::prepareAvalaraRequestData($shop->id, $invoiceDate, $data->order_number, $data);
            $avalaraRequestData['TransactionLines'] = $transactionLines;

            if (!empty($transactionLines)) {

                $newService = new AvalaraExciceHelper();
                $newService->setCredentials($apiUsername, $apiUserPassword, $companyId);
                $response = $newService->calculateExcice($avalaraRequestData);

                $newService->productCreate($productIds, $shop);

                AvalaraTransactionLog::create([
                    "ip"                       => "0.0.0.0",
                    'type'                     => $isOrderCreated ? 'create' : 'cancel',
                    "shop_id"                  => $shop->id,
                    "request_data"             => null,
                    "total_requested_products" => count($transactionLines),
                    "response"                 => $response->status() != 200 ? json_encode($response->body()) : $response->body(),
                    "filtered_request_data"    => json_encode($avalaraRequestData),
                    "status"                   => $response->status(),
                ]);

                $exciseTax = 0;
                $transactionError = null;

                if ($response->status() == 200) {
                    $responseTemp = json_decode($response->body());
                    $exciseTax = $responseTemp->TotalTaxAmount;
                    foreach ($responseTemp->TransactionTaxes as $key => $transactionTax) {
                        if (isset($productIds[$transactionTax->TransactionLine])) {
                            $exciseByProduct = ExciseByProduct::where('shop_id', $shop->id)
                                ->where('product_id', $productIds[$transactionTax->TransactionLine])
                                ->where('date', Carbon::parse($data->created_at)->format('Y-m-d'))
                                ->first();
                        }
                        if ($exciseByProduct) {
                            $exciseByProduct->excise_tax += $transactionTax->TaxAmount;
                            $exciseByProduct->save();
                        } else {
                            ExciseByProduct::create([
                                'shop_id'      => $shop->id,
                                'product_id'   => $productIds[$transactionTax->TransactionLine],
                                'excise_tax'   => $transactionTax->TaxAmount,
                                'date'         => Carbon::parse($data->created_at)->format('Y-m-d'),
                            ]);
                        }
                    }
                } else {
                    $transactionError = json_encode($response->body());
                }
                $taxableItem = count($transactionLines);
                $failedReason = $transactionError;
            }
        }
        $cancelledTransaction = Transaction::where('shop_id', $shop->id)->where('order_id',
            $data->id)->first();
        if ($cancelledTransaction) {
            $cancelledParentId = $cancelledTransaction->id;
        }
        if (!empty($data->note_attributes)) {
            $transactionObj = new Transaction();
            $transactionObj->shop_id = $shop->id;
            $transactionObj->parent_id = $cancelledParentId ?? null;
            $transactionObj->order_id = $data->id;
            $transactionObj->order_number = $data->order_number;
            $transactionObj->customer = isset($data->shipping_address) ? $data->shipping_address->name : '';
            $transactionObj->taxable_item = $taxableItem;
            $transactionObj->order_total = $data->total_price;
            $transactionObj->excise_tax = $exciseTax ?? 0;
            $transactionObj->status = Helpers::getOrderFulfillmentStatus($data->fulfillment_status);
            $transactionObj->financial_status = Helpers::getOrderFinancialStatus($data->financial_status);
            $transactionObj->order_date = $data->created_at;
            $transactionObj->state = isset($data->shipping_address) ? $data->shipping_address->province : '';
            $transactionObj->failed_reason = $failedReason;
            $transactionObj->save();
        }
    }

    public static function prepareTransactionLine(
        int $shopId,
        object $line_item,
        object $shipping_address = null,
        bool $isOrderCreated = true,
        object $orderData = null
    ) {
        list(
            $titleTransferCode, $transactionType, $transportationModeCode, $seller, $buyer, $unitOfMeasure, $currency, $origin, $orderCustomString1, $orderCustomString2,
            $orderCustomString3, $orderCustomNumeric1, $orderCustomNumeric2, $orderCustomNumeric3, $itemCustomString1, $itemCustomString2, $itemCustomString3, $itemCustomNumeric1,
            $itemCustomNumeric2, $itemCustomNumeric3, $previousSeller, $nextBuyer, $middleman,
            ) = Helpers::staticSettings($shopId);

        $lineItem = [
            "TransactionLineMeasures"         => null,
            "OriginSpecialJurisdictions"      => [],
            "DestinationSpecialJurisdictions" => [],
            "SaleSpecialJurisdictions"        => [],
            "InvoiceLine"                     => $line_item->invoice_line, //Custom
            "ProductCode"                     => $line_item->sku ? Str::substr($line_item->sku, 0, 24) : '',
            "UnitPrice"                       => $line_item->price,
            "NetUnits"                        => $line_item->quantity,
            "GrossUnits"                      => $line_item->quantity,
            "BilledUnits"                     => $isOrderCreated ? $line_item->quantity : -$line_item->quantity,
            "BillOfLadingDate"                => $line_item->invoice_date, //Custom
            "Origin"                          => $origin,
            "OriginAddress1"                  => null,
            "OriginAddress2"                  => null,
            "DestinationCountryCode"          => $shipping_address->country_code ?? '',
            "DestinationJurisdiction"         => $shipping_address->province_code ?? '',
            "DestinationCounty"               => "",
            "DestinationCity"                 => $shipping_address->city ?? '',
            "DestinationPostalCode"           => $shipping_address->zip ?? '',
            "DestinationAddress1"             => $shipping_address->address1 ?? '',
            "DestinationAddress2"             => $shipping_address->address2 ?? '',
            "Currency"                        => $currency,
            "UnitOfMeasure"                   => $unitOfMeasure,
        ];

        if ($orderData) {
            $lineItem["CustomString1"] = $itemCustomString1 ? getCustomString($itemCustomString1, $orderData) : null;
            $lineItem["CustomString2"] = $itemCustomString2 ? getCustomString($itemCustomString2, $orderData) : null;
            $lineItem["CustomString3"] = $itemCustomString3 ? getCustomString($itemCustomString3, $orderData) : null;
            $lineItem["CustomNumeric1"] = $itemCustomNumeric1 ? getCustomNumeric($itemCustomNumeric1,
                $orderData) : null;
            $lineItem["CustomNumeric2"] = $itemCustomNumeric2 ? getCustomNumeric($itemCustomNumeric2,
                $orderData) : null;
            $lineItem["CustomNumeric3"] = $itemCustomNumeric3 ? getCustomNumeric($itemCustomNumeric3,
                $orderData) : null;
        }

        return $lineItem;
    }

    public static function prepareAvalaraRequestData(
        int $shopId,
        $invoiceDate,
        $orderNumber = null,
        object $orderData = null
    ) {
        list(
            $titleTransferCode, $transactionType, $transportationModeCode, $seller, $buyer, $unitOfMeasure, $currency, $origin, $orderCustomString1, $orderCustomString2,
            $orderCustomString3, $orderCustomNumeric1, $orderCustomNumeric2, $orderCustomNumeric3, $itemCustomString1, $itemCustomString2, $itemCustomString3, $itemCustomNumeric1,
            $itemCustomNumeric2, $itemCustomNumeric3, $previousSeller, $nextBuyer, $middleman,
            ) = Helpers::staticSettings($shopId);

        $avalaraRequestData = [
            'TransactionLines'         => [],
            'TransactionExchangeRates' => [],
            'EffectiveDate'            => $invoiceDate,
            'InvoiceDate'              => $invoiceDate,
            'InvoiceNumber'            => $orderNumber ?? '',
            'TitleTransferCode'        => $titleTransferCode,
            'TransactionType'          => $transactionType,
            'TransportationModeCode'   => $transportationModeCode,
            'Seller'                   => $seller,
            'Buyer'                    => $buyer,
            'PreviousSeller'           => $previousSeller ?? '',
            'NextBuyer'                => $nextBuyer ?? '',
            'Middleman'                => $middleman ?? '',
        ];

        if ($orderData) {
            $avalaraRequestData["CustomString1"] = $orderCustomString1 ? getCustomString($orderCustomString1,
                $orderData) : null;
            $avalaraRequestData["CustomString2"] = $orderCustomString2 ? getCustomString($orderCustomString2,
                $orderData) : null;
            $avalaraRequestData["CustomString3"] = $orderCustomString3 ? getCustomString($orderCustomString3,
                $orderData) : null;
            $avalaraRequestData["CustomNumeric1"] = $orderCustomNumeric1 ? getCustomNumeric($orderCustomNumeric1,
                $orderData) : null;
            $avalaraRequestData["CustomNumeric2"] = $orderCustomNumeric2 ? getCustomNumeric($orderCustomNumeric2,
                $orderData) : null;
            $avalaraRequestData["CustomNumeric3"] = $orderCustomNumeric3 ? getCustomNumeric($orderCustomNumeric3,
                $orderData) : null;
        }

        return $avalaraRequestData;
    }
}
