<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use DB;

class IndexController extends Controller
{
    public function __construct()
    {
    }

    public function getClient()
    {
        $client = new \Google_Client();
        $client->setApplicationName('Hasaki report');
        $client->setScopes(\Google_Service_Sheets::SPREADSHEETS);
        $client->setAuthConfig(config_path('credentials.json'));
        $client->setAccessType('offline');

        return $client;
    }

    public function updateDataSheet()
    {
        $start_day = date('Y-m-d 00:00:00', strtotime('-1 day'));
        $end_day = date('Y-m-d 23:59:59', strtotime('-1 day'));
        $sheet_name = date('d-m-Y', strtotime('-1 day'));

        $client = $this->getClient();
        $service = new \Google_Service_Sheets($client);
        $spreadsheetId = env('GOOGLE_SHEET_ID');

        try {
            $body = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest(array(
                'requests' => array(
                    'addSheet' => array(
                        'properties' => array(
                            'title' => "$sheet_name"
                        ),
                    )
                )
            ));
            $sheet = $service->spreadsheets->batchUpdate($spreadsheetId, $body);
        } catch (\Throwable $th) {
        }

        $range = "$sheet_name!A2";

        $data = [
            [
                'SKU',
                'Tên sản phẩm',
                'Thương hiệu',
                'Danh mục',
                'Giá Sản Phẩm',
                'Số lượng sản phẩm',
                'Tổng tiền',
                'Thời gian bán',
            ]
        ];

        $data_tmp = DB::table('sales_order')
            ->join('sales_order_item', 'sales_order_item.order_id', '=', 'sales_order.entity_id')
            ->where('sales_order.state', '=', 'complete')
            ->where([
                ['sales_order.created_at', '>', '2020-05-25 00:00:00'],
                ['sales_order.created_at', '<', '2020-05-25 23:59:59'],
            ])
            ->select(
                'sales_order_item.store_id',
                'sales_order_item.product_id',
                'sales_order_item.base_price',
                'sales_order.created_at',
                'sales_order_item.order_id',
                'sales_order_item.name',
                'sales_order_item.sku',
                DB::raw('sum(sales_order_item.base_price) as total_base_price'),
                DB::raw('count(sales_order_item.product_id) as quantity_product'),
                DB::raw('count(sales_order_item.order_id) as quantity_order')
            )
            ->groupBy('sales_order_item.product_id')
            ->get();

        $total = [
            'total_price' => collect($data_tmp)->sum('total_base_price'),
            'total_product' => collect($data_tmp)->sum('quantity_product'),
        ];

        foreach ($data_tmp as $value) {

            $cate = DB::table('catalog_category_product')
                ->where('catalog_category_product.product_id', $value->product_id)
                ->join('catalog_category_entity_varchar', 'catalog_category_product.category_id', '=', 'catalog_category_entity_varchar.entity_id')
                ->join('eav_attribute', 'catalog_category_entity_varchar.attribute_id', '=', 'eav_attribute.attribute_id')
                ->where('eav_attribute.attribute_id', 42)
                ->select('catalog_category_entity_varchar.value')
                ->first();

            $brand = DB::table('catalog_product_entity_int')
                ->join('eav_attribute', 'catalog_product_entity_int.attribute_id', '=', 'eav_attribute.attribute_id')
                ->join('hasaki_brand', 'catalog_product_entity_int.value', '=', 'hasaki_brand.id')
                ->where('eav_attribute.attribute_code', 'brand')
                ->where('catalog_product_entity_int.entity_id', $value->product_id)
                ->select('hasaki_brand.name')
                ->first();

            $data[] = [
                $value->sku,
                $value->name,
                $brand->name ?? '',
                $cate->value ?? '',
                $value->base_price,
                $value->quantity_product,
                $value->total_base_price,
                $value->created_at,
            ];
        }

        $requestBody = new \Google_Service_Sheets_ValueRange([
            'values' => $data
        ]);

        $params = [
            'valueInputOption' => 'USER_ENTERED'
        ];

        $service->spreadsheets_values->update($spreadsheetId, $range, $requestBody, $params);

        $range_total = "$sheet_name!J2";

        $data_total = [
            [
                'Tổng doanh thu',
                'Tổng sản phẩm'
            ],
            [
                $total['total_price'],
                $total['total_product']
            ]
        ];

        $requestBodyTotal = new \Google_Service_Sheets_ValueRange([
            'values' => $data_total
        ]);

        $service->spreadsheets_values->update($spreadsheetId, $range_total, $requestBodyTotal, $params);
        echo "SUCCESS \n";
        die;
    }
}
