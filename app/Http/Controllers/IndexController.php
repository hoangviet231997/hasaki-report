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
            ->get()
            ->toArray();

        $total = [
            'total_price' => collect($data_tmp)->sum('total_base_price'),
            'total_product' => collect($data_tmp)->sum('quantity_product'),
        ];

        $categories = DB::table('catalog_category_product')
            ->join('catalog_category_entity_varchar', 'catalog_category_product.category_id', '=', 'catalog_category_entity_varchar.entity_id')
            ->whereIn('catalog_category_product.product_id', array_column($data_tmp, 'product_id'))
            ->where('catalog_category_entity_varchar.attribute_id', 42)
            ->select(
                'catalog_category_product.product_id',
                DB::raw('GROUP_CONCAT(catalog_category_entity_varchar.value) as cates')
            )
            ->groupBy('catalog_category_product.product_id')
            ->get();

        $brands = DB::table('catalog_product_entity_int')
            ->whereIn('catalog_product_entity_int.entity_id', array_column($data_tmp, 'product_id'))
            ->where('catalog_product_entity_int.attribute_id', 137)
            ->join('hasaki_brand', 'catalog_product_entity_int.value', '=', 'hasaki_brand.id')
            ->select('hasaki_brand.name', 'catalog_product_entity_int.entity_id as product_id')
            ->get();

        $list_cate = [];
        foreach ($categories as $category) {
            $list[$category->product_id] = $category->cates;
        }

        $list_brand = [];
        foreach ($brands as $brand) {
            $list_brand[$brand->product_id] = $brand->name;
        }

        foreach ($data_tmp as $index => $element) {
            $element->category_name = isset($list_cate[$element->product_id]) ? $list_cate[$element->product_id] : '';
            $element->brand_name = $list_brand[$element->product_id] ?? '';
            $data_tmp[$index] = $element;
        }
        foreach ($data_tmp as $value) {

            $data[] = [
                $value->sku,
                $value->name,
                $value->brand_name,
                $value->category_name,
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
