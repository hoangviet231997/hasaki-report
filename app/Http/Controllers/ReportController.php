<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use DB;
use App\Http\Controllers\IndexController;

class ReportController extends Controller
{
    public $index;

    public function __construct()
    {
        $this->index = new IndexController();
    }

    public function reportBydate()
    {
        $start_day = date('Y-m-d 00:00:00', strtotime('-1 day'));
        $end_day = date('Y-m-d 23:59:59', strtotime('-1 day'));
        $sheet_name = date('d-m-Y', strtotime('-1 day'));

        $client = $this->index->getClient();
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
                'Mã đơn hàng',
                'Tên sản phẩm',
                'Thương hiệu',
                'Danh mục',
                'Giá Sản Phẩm',
                'Số lượng sản phẩm',
                'Tổng tiền',
                'Thời gian tạo đơn hàng',
                'Thời gian cập nhật đơn hàng',
            ]
        ];

        $data_tmp = DB::table('sales_order')
            ->join('sales_order_item', 'sales_order_item.order_id', '=', 'sales_order.entity_id')
            ->join('sales_order_grid','sales_order.entity_id','=','sales_order_grid.entity_id')
            ->where('sales_order.state', '=', 'complete')
            ->where('sales_order_item.product_type', '=', 'simple')
            ->where([
                ['sales_order.created_at', '>=', '2020-05-25 00:00:00'],
                ['sales_order.created_at', '<=', '2020-05-25 23:59:59'],
            ])
            ->select(
                'sales_order_item.store_id',
                'sales_order_item.product_id',
                'sales_order_item.base_price',
                'sales_order.created_at',
                'sales_order.updated_at',
                'sales_order_item.order_id',
                'sales_order_item.name',
                'sales_order_item.sku',
                'sales_order_grid.increment_id',
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
            $list_cate[$category->product_id] = $category->cates;
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
                $value->increment_id,
                $value->name,
                $value->brand_name,
                $value->category_name,
                intval($value->base_price),
                $value->quantity_product,
                intval($value->total_base_price),
                date('Y-m-d',strtotime($value->created_at)),
                date('Y-m-d',strtotime($value->updated_at)),
            ];
        }

        $requestBody = new \Google_Service_Sheets_ValueRange([
            'values' => $data
        ]);

        $params = [
            'valueInputOption' => 'USER_ENTERED'
        ];

        $service->spreadsheets_values->update($spreadsheetId, $range, $requestBody, $params);

        $range_total = "$sheet_name!M2";

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

    public function reportByBrand()
    {
        $start_day = date('Y-m-d 00:00:00', strtotime('now'));
        $end_day = date('Y-m-d 00:00:00', strtotime('-45 day'));



        $data_tmp = DB::table('sales_order')
            ->join('sales_order_item', 'sales_order_item.order_id', '=', 'sales_order.entity_id')
            ->where('sales_order.state', '=', 'complete')
            ->where([
                ['sales_order.created_at', '<=', $start_day],
                ['sales_order.created_at', '>=', $end_day],
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
            ->where('catalog_product_entity_int.attribute_id', 137)
            ->whereIn('catalog_product_entity_int.entity_id', array_column($data_tmp, 'product_id'))
            ->join('hasaki_brand', 'catalog_product_entity_int.value', '=', 'hasaki_brand.id')
            ->select('hasaki_brand.name', 'hasaki_brand.id as brand_id', 'catalog_product_entity_int.entity_id as product_id')
            ->get();

        $list_cate = [];
        foreach ($categories as $category) {
            $list_cate[$category->product_id] = $category->cates;
        }

        $list_brand = [];
        foreach ($brands as $brand) {
            $list_brand[$brand->product_id] = $brand->name;
        }
        foreach ($data_tmp as $index => $element) {
            $element->category_name = isset($list_cate[$element->product_id]) ? $list_cate[$element->product_id] : '';
            $element->brand_name = isset($list_brand[$element->product_id]) ? $list_brand[$element->product_id] : '';
        }

        $client = $this->index->getClient();
        $service = new \Google_Service_Sheets($client);
        $spreadsheetId = env('GOOGLE_SHEET_ID');

        $data_tmp = collect($data_tmp)->groupBy('brand_name')->toArray();

        foreach ($data_tmp as $b => $value) {

            if ($b) {
                try {
                    $body = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest(array(
                        'requests' => array(
                            'addSheet' => array(
                                'properties' => array(
                                    'title' => "$b"
                                ),
                            )
                        )
                    ));
                    $sheet = $service->spreadsheets->batchUpdate($spreadsheetId, $body);
                } catch (\Throwable $th) {
                }

                $range = "$b!A2";

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

                foreach ($value as $v) {
                    $data[] = [
                        $v->sku,
                        $v->name,
                        $v->brand_name,
                        $v->category_name,
                        intval($v->base_price),
                        $v->quantity_product,
                        intval($v->total_base_price),
                        $v->created_at,
                    ];
                }

                $total = [
                    'total_price' => (int)collect($value)->sum('total_base_price'),
                    'total_product' => (int)collect($value)->sum('quantity_product'),
                ];

                $requestBody = new \Google_Service_Sheets_ValueRange([
                    'values' => $data
                ]);

                $params = [
                    'valueInputOption' => 'RAW'
                ];

                $service->spreadsheets_values->update($spreadsheetId, $range, $requestBody, $params);

                $range_total = "$b!J2";

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
            }
        }

        echo 'SUCCESS';
        die;
    }
}
