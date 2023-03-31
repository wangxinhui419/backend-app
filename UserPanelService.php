<?php

namespace App\Services\UserPanel;

use DB;
use App\Helper;
use Carbon\Carbon;
use App\BulkCommodity;
use App\ChargeDetail;
use App\Commodity;
use App\Exceptions\LogicException;
use App\Warehouse;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Arr;

class UserPanelService
{
    protected $client;

    const CHARGE_MODE_ONCE = 'once';
    const CHARGE_MODE_CYCLE = 'cycle';
    const CHARGE_MODE_MUTI = 'muti';

    /**
     * 之后修改这里Name常量的数量时候，同步修改下 `GetUserPanelNameList` 函数
     */
    const NAME_SHIPPING = "shipping";
    const NAME_HANDLE = "handle";
    const NAME_PACKING = "packing";
    const NAME_STORAGE = "storage";
    const NAME_TRANSPORT_STORAGE = "transport_storage";
    const NAME_STORAGE_BUSY_SEASON_ADDTION = "storage_busy_season_addtion";
    const NAME_CHANGE_BARCODE = "change_barcode";
    const NAME_PICKUP_SELF = "pickup_self";
    const NAME_LIVE_UNLOADING = "live_unloading";

    /**
     * 之后修改这里Mode常量的数量时候，同步修改下 `GetUserPanelModeList` 函数
     */
    const MODE_DEFAULT = "default";
    const MODE_BY_MONTH = "by_month";
    const MODE_BY_VENDOR = "by_vendor";
    const MODE_BY_CARGO = "by_cargo";

    const NAME_VENDOR_SYS_STORAGE = "SYS_STORAGE";

    /**
     * 之后修改这里Product_type 常量的时候，通过修改下 GetUserPanelProductTypeList 函数
     */
    const TYPE_COMMODITY = "commodity";
    const TYPE_VIRTUAL_COMMODITY = "virtual_commodity";
    const TYPE_BULK_COMMODITY = "bulk_commodity";

    const KEY_PRODUCT_ACTIVE_MAX = "active_max";
    const KEY_PRODUCT_HAS_BATTERY = "has_battery";
    const KEY_PRODUCT_TO_COMMODITY = "tranform_to_commodity";
    const KEY_PRODUCT_TO_BULK_COMMODITY = "tranform_to_bulk_commodity";

    public function __construct()
    {
        $this->client = new Client([
            "base_uri" => config("services.userpanel.server_uri")
        ]);
    }

    /**
     * 整理返回用户面板中定义的product_type常量，暂时用途主要用于数据验证
     * @return string[]
     */
    public static function getUserPanelProductTypeList(): array
    {
        return [
            self::TYPE_COMMODITY,
            self::TYPE_VIRTUAL_COMMODITY,
            self::TYPE_BULK_COMMODITY,
        ];
    }

    /**
     * 整理返回用户面板中定义的mode常量，暂时用途主要用于数据验证
     * @return string[]
     */
    public static function getUserPanelModeList(): array
    {
        return [
            self::MODE_DEFAULT,
            self::MODE_BY_MONTH,
            self::MODE_BY_VENDOR,
            self::MODE_BY_CARGO,
        ];
    }

    /**
     * 整理返回用户面板中定义的name常量，暂时用途主要用于数据验证
     * @return string[]
     */
    public static function getUserPanelNameList(): array
    {
        return [
            self::NAME_SHIPPING,
            self::NAME_HANDLE,
            self::NAME_PACKING,
            self::NAME_STORAGE,
            self::NAME_TRANSPORT_STORAGE,
            self::NAME_STORAGE_BUSY_SEASON_ADDTION,
            self::NAME_CHANGE_BARCODE,
            self::NAME_PICKUP_SELF,
            self::NAME_LIVE_UNLOADING,
        ];
    }

    /**
     * 前端某些请求是直接和用户面板通讯的，所以这里保持原本的用户面板接口的返回数据直接传递给前端。
     * @param string $name
     * @param string $method
     * @param string $url
     * @param mixed $data
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     * @throws GuzzleException
     */
    public function requestAuthentic(string $name, string $method, string $url, mixed $data, string $key = "data", mixed $default = null): mixed
    {
        try {
            Helper::WriteLog("user-service-api", "Request Authentic-{$name}: " . json_encode([$name, $method, $url, $data, $key, $default]));
            $response = $this->client->request($method, $url, $data);

            $body = (string)$response->getBody();
            Helper::WriteLog("user-service-api", "Response Authentic-{$name}: " . $body);
            // 不做数据解析直接返回, 如果是导出的话，直接返回body
            if ($default === 'export') {
                return $body;
            }
            return json_decode($body, true);
        } catch (ClientException $exception) {
            $message = $exception->getMessage();
            $requestBody = json_encode($exception->getRequest());
            $responseBody = (string)$exception->getResponse()->getBody();
            Helper::WriteLog("user-service-api", "Authentic-Error: {$message}, Request: {$requestBody}, Response: {$responseBody}");
            // 保持原汁原味的错误返回
            $responseBody = json_decode($responseBody, true);
            if (empty($responseBody['data'])) {
                $responseBody['data'] = [];
            }
            return $responseBody;
        }
    }

    public function request($name, $method, $url, $data, $key = "data", $default = null)
    {
        try {
            Helper::WriteLog("user-service-api", "Request {$name}: " . json_encode([$name, $method, $url, $data, $key, $default]));
            $response = $this->client->request($method, $url, $data);
            $body = (string)$response->getBody();
            Helper::WriteLog("user-service-api", "Response {$name}: " . $body);

            $res = json_decode($body, true);

            return Arr::get($res, $key, $default);
        } catch (ClientException $exception) {
            $message = $exception->getMessage();
            $requestBody = json_encode($exception->getRequest());
            $responseBody = (string)$exception->getResponse()->getBody();
            Helper::WriteLog("user-service-api", "Error: {$message}, Request: {$requestBody}, Response: {$responseBody}");

            if ($default == "throw") {
                $res = json_decode($responseBody, true);
                if (isset($res['message'])) {
                    $message = $res['message'];
                }
                throw new LogicException($message);
            }

            return $default;
        }
    }

    public function getStatedProducts($state, $userId, $warehouseId, $productType, $compared = false)
    {
        if ($productType == Commodity::class) {
            $productType = 'commodity';
        } else if ($productType == BulkCommodity::class) {
            $productType = 'bulk_commodity';
        }

        $query = [
            "user_id" => $userId,
            "warehouse_id" => $warehouseId,
            "product_type" => $productType,
        ];

        if ($state == "active" && $compared) {
            $query["compare_max"] = true;
        }

        return $this->request(strtoupper($state) . " Products", "GET", "/products/{$state}", [
            "query" => $query
        ], "data", []);
    }

    public function hasMaxActiveProducts($userId, $warehouseId, $productType)
    {
        return $this->getStatedProducts("active", $userId, $warehouseId, $productType, true);
    }

    public function getUsers($userIds = null, $status = null)
    {
        $json = [];

        if ($userIds) {
            $json["user_id"] = $userIds;
        }
        if ($status) {
            $json["status"] = $status;
        }

        return $this->request("Users", "POST", "/users/list", [
            "json" => $json,
        ], "data", []);
    }

    public function getUser($userId)
    {
        return $this->request("User", "GET", "/users/{$userId}", [], "data", []);
    }

    public function getVendorConfig($userId, $warehouseId, $name, $mode = "shipping", $path = null)
    {
        $config = $this->request("Vendors", "GET", "/vendors/u/{$userId}", [
            "query" => [
                "warehouse_id" => $warehouseId,
                "name" => $name,
                "mode" => $mode,
            ],
        ], "data.0.config", []);

        if ($config && $path) {
            $config = Arr::get($config, $path, null);
        }

        return $config;
    }

    /**
     *
     * 去用户面板里面拿用户的折扣数据，作为前端中转接口，直接将数据进行返回，不进行额外加工
     * @param int $userId 用户id
     * @param array $queryData 查询数据数组
     * @return mixed
     * @throws GuzzleException
     */
    public function getOneDiscountForTransfer(int $userId, array $queryData): mixed
    {
        return $this->requestAuthentic("Discounts", "GET", "/discounts/u/{$userId}", [
            'query' => $queryData,
        ]);
    }

    /**
     * 去用户面板里面保存折扣数据，作为前端中转接口，直接将数据进行返回，不进行额外加工
     * @param int $userId 用户id
     * @param array $postData 保存折扣数据
     * @return mixed
     * @throws GuzzleException
     */
    public function postDiscountSaveForTransfer(int $userId, array $postData): mixed
    {
        return $this->requestAuthentic("Discounts", "POST", "/discounts/u/{$userId}/save", [
            'json' => $postData,
        ], 'data', 'authentic');
    }

    /**
     * 去用户面板里面确认折扣数据，作为前端中转接口，直接将数据进行返回，不进行额外加工
     * @param array $postData 确认折扣的数据
     * @return mixed
     * @throws GuzzleException
     */
    public function postDiscountConfirmForTransfer(array $postData): mixed
    {
        return $this->requestAuthentic("Discounts", "POST", "/discounts/confirm", [
            'json' => $postData,
        ], 'data', 'authentic');
    }

    /**
     * 去用户面板里面拿取渠道配置数据，作为前端中转接口，直接将数据进行返回，不进行额外加工
     * @param int $userId
     * @param array $queryData
     * @return mixed
     * @throws GuzzleException
     */
    public function getVendorsForTransfer(int $userId, array $queryData): mixed
    {
        return $this->requestAuthentic("Vendors", "GET", "/vendors/u/{$userId}", [
            'query' => $queryData,
        ], 'data', 'authentic');
    }

    /**
     * 去用户面板里保存渠道配置数据，作为前端中转接口，直接将数据进行返回，不进行额外加工
     * @param int $userId
     * @param array $postData
     * @return mixed
     * @throws GuzzleException
     */
    public function postVendorsSaveForTransfer(int $userId, array $postData): mixed
    {
        return $this->requestAuthentic("Vendors", "POST", "/vendors/u/{$userId}/save", [
            'json' => $postData,
        ], 'data', 'authentic');
    }

    /**
     * 去用户面板里获取产品配置数据，作为前端中转接口，直接将数据进行返回，不进行额外加工
     * @param int $userId
     * @param array $queryData
     * @return mixed
     * @throws GuzzleException
     */
    public function getProductsForTransfer(int $userId, array $queryData): mixed
    {
        return $this->requestAuthentic("Products", "GET", "/products/u/{$userId}", [
            'query' => $queryData,
        ], 'data', 'authentic');
    }

    /**
     * 去用户面板里保存产品配置数据，作为前端中转接口，直接将数据进行返回，不进行额外加工
     * @param int $userId
     * @param array $postData
     * @return mixed
     * @throws GuzzleException
     */
    public function postProductsSaveForTransfer(int $userId, array $postData): mixed
    {
        return $this->requestAuthentic("Products", "POST", "/products/u/{$userId}/save", [
            'json' => $postData,
        ], 'data', 'authentic');
    }

    /**
     * 去用户面板里确认考核参数，作为前端中转接口，直接将数据进行返回，不进行额外加工
     * @param array $postData
     * @return mixed
     * @throws GuzzleException
     */
    public function postConfirmDiscountTestsForTransfer(array $postData): mixed
    {
        return $this->requestAuthentic("DiscountTests", "POST", "/discount_tests/confirm", [
            'json' => $postData,
        ], 'data', 'authentic');
    }

    /**
     * 去用户面板里获取账期配置，作为前端中转接口，直接将数据进行返回，不进行额外加工
     * @param int $userId
     * @return mixed
     * @throws GuzzleException
     */
    public function getUsersForTransfer(int $userId): mixed
    {
        return $this->requestAuthentic("Users", "GET", "/users/{$userId}", [], 'data', 'authentic');
    }

    /**
     * 去用户面板里保存账期配置，作为前端中转接口，直接将数据进行返回，不进行额外加工
     * @param int $userId
     * @param array $postData
     * @return mixed
     * @throws GuzzleException
     */
    public function saveUsersForTransfer(int $userId, array $postData): mixed
    {
        return $this->requestAuthentic("Users", "POST", "/users/{$userId}/save", [
            'json' => $postData
        ], 'data', 'authentic');
    }

    /**
     * 去用户面板里导出账单的数据，作为前端中转接口，直接将数据进行返回，不进行额外加工
     * @param mixed $uuid
     * @return mixed
     * @throws GuzzleException
     */
    public function postExportInvoicesForTransfer(mixed $uuid): mixed
    {
        return $this->requestAuthentic("Invoices", "POST", "/invoices/{$uuid}/export", [], 'data', null);
    }

    /**
     * 去用户面板里获取账单列表，作为前端中转接口，直接将数据进行返回，不进行额外加工
     * @param array $queryData
     * @return mixed
     * @throws GuzzleException
     */
    public function getInvoicesToBillsForTransfer(array $queryData): mixed
    {
        return $this->requestAuthentic("Invoices", "GET", "/invoices/to_bills", [
            'query' => $queryData
        ]);
    }

    /**
     * 去用户面板里获取用户流水，作为前端中转接口，直接将数据进行返回，不进行额外加工
     * @param array $queryData
     * @return mixed
     * @throws GuzzleException
     */
    public function getStatementsForTransfer(array $queryData): mixed
    {
        return $this->requestAuthentic("Statements", "GET", "/statements", [
            'query' => $queryData
        ]);
    }

    /**
     * 去用户面板里获取导出用户流水数据，作为前端中转接口，直接将数据进行返回，不进行额外加工
     * @param array $postData
     * @return mixed
     * @throws GuzzleException
     */
    public function postExportStatementsForTransfer(array $postData): mixed
    {
        return $this->requestAuthentic("Statements", "POST", "/statements/export", [
            'json' => $postData
        ], 'data', 'export');
    }

    public function getOneDiscount($userId, $warehouseId, $name, $productType, $mode, $path = null)
    {
        $discount = $this->request("Discounts", "GET", "/discounts/u/{$userId}", [
            "query" => [
                "warehouse_id" => $warehouseId,
                "name" => $name,
                "product_type" => $productType,
                "mode" => $mode,
            ],
        ], "data.0.config", null);

        if ($discount) {
            if ($mode == static::MODE_DEFAULT) {
                return isset($discount["value"]) ? $discount["value"] : null;
            }

            if ($mode == static::MODE_BY_MONTH) {
                $month = "month_" . Carbon::now("UTC")->month;
                return isset($discount[$month]) ? $discount[$month] : null;
            }

            // vendor: {"ZIPTO_EXP": "0.8", "ZIPTO_EXP_PLUS": "0.8"}
            // cargo: {"T25": {"0_30": 0.8}}
            if ($mode == static::MODE_BY_VENDOR || $mode == static::MODE_BY_CARGO) {
                if (!$path) {
                    return null;
                }
                return Arr::get($discount, $path, null);
            }
        }

        return null;
    }

    public function pushCommodityHandleDiscounts08($userId)
    {
        // 创建用户时触发，IL1仓库小包处理费折扣0.8
        $warehouseIds = Warehouse::where("code", "IL1")->pluck("id");

        foreach ($warehouseIds as $warehouseId) {
            $discount = $this->request("Discount", "POST", "/discounts/u/{$userId}/save", [
                "json" => [
                    "warehouse_id" => $warehouseId,
                    "name" => "handle",
                    "product_type" => "commodity",
                    "mode" => "default",
                    "config_draft" => [
                        "value" => 0.8
                    ],
                ],
            ]);
            if ($discount) {
                $this->request("Discount", "POST", "/discounts/confirm", [
                    "json" => [
                        "verify_status" => "confirm",
                        "discount_id" => $discount["id"],
                        "remark" => "CREATED USER INIT COMMODITY HANDLE DISCOUNTS",
                    ],
                ]);
            }
        }
    }

    public function getOneProduct($userId, $warehouseId, $mode, $key = null)
    {
        $product = $this->request("Products", "GET", "/products/u/{$userId}", [
            "query" => [
                "warehouse_id" => $warehouseId,
                "mode" => $mode,
                "name" => "commodity",
            ],
        ], "data.0.config", null);

        if ($product) {
            if ($key == static::KEY_PRODUCT_ACTIVE_MAX && isset($product[static::KEY_PRODUCT_ACTIVE_MAX])) {
                return $product[static::KEY_PRODUCT_ACTIVE_MAX];
            }

            if ($key == static::KEY_PRODUCT_HAS_BATTERY && isset($product[static::KEY_PRODUCT_HAS_BATTERY])) {
                return !!$product[static::KEY_PRODUCT_HAS_BATTERY];
            }

            if ($key == static::KEY_PRODUCT_TO_COMMODITY && isset($product[static::KEY_PRODUCT_TO_COMMODITY])) {
                return !!$product[static::KEY_PRODUCT_TO_COMMODITY];
            }

            if ($key == static::KEY_PRODUCT_TO_BULK_COMMODITY && isset($product[static::KEY_PRODUCT_TO_BULK_COMMODITY])) {
                return !!$product[static::KEY_PRODUCT_TO_BULK_COMMODITY];
            }
        }

        return null;
    }

    public function writeProductLog($user, $warehouseId, $productId, $productType, $action, $meta = null)
    {
        return $this->request("Products", "POST", "/products/writelog", [
            "json" => [
                "user_id" => $user->id,
                "warehouse_id" => $warehouseId,
                "product_id" => $productId,
                "product_type" => $productType,
                "action" => $action,
                "meta" => $meta,
            ],
        ]);
    }

    public function changeUserStatus($userid, $status)
    {
        return $this->request("User", "POST", "/users/{$userid}/update_info", [
            "json" => [
                "status" => $status,
            ],
        ]);
    }

    public function updateUserInfo($userid, $email)
    {
        return $this->request("User", "POST", "/users/{$userid}/update_info", [
            "json" => [
                "email" => $email,
            ],
        ]);
    }

    public function pushUser($user)
    {
        $this->request("User", "POST", "/users", [
            "json" => [
                "id" => $user->id,
                "username" => $user->username,
                "email" => $user->email,
                // 用户初始化一律0信用0账期；余额会在同步账单时扣减；
                "balance" => 0,
                "credit" => 0,
                "bill_term" => 0,
                "bill_term_type" => "cycled",
                "latest_billed_at" => Carbon::now("UTC")->startOfDay(),
                "status" => $user->state == "disabled" ? ($user->state ?: "unsubmit") : "active",
            ],
        ]);
        $this->pushCommodityHandleDiscounts08($user->id);
    }

    public function pay($user, $warehouseId, $mode, $details, $amount, $orderId = null, $tracking = null, $remark = null, $meta = null, $forceCost = false)
    {
        return $this->request("User Pay", "POST", "/users/{$user->id}/pay", [
            "json" => [
                "details" => $this->toInvoices($details, $mode),
                "warehouse_id" => $warehouseId,
                "amount" => $amount,
                "order_id" => $orderId,
                "tracking_number" => "" . $tracking,
                "remark" => $remark,
                "meta" => $meta,
                "force_cost" => $forceCost
            ],
        ], 'data', 'throw');
    }

    public function charge($user, $amount, $isOnline = true, $orderId = null, $remark = null, $meta = null)
    {
        return $this->request("User Charge", "POST", "/users/{$user->id}/charge", [
            "json" => [
                "amount" => $amount,
                "order_id" => $orderId,
                "remark" => $remark,
                "meta" => $meta,
                "is_online" => $isOnline,
            ],
        ], 'data', 'throw');
    }

    private function toInvoices($details, $mode)
    {
        $invoices = [];

        foreach ($details as $detail) {
            $planAmount = is_array($detail) ? $detail['plan_amount'] : $detail->plan_amount;
            if ($planAmount == 0) { // 如果是0就不生成invoice
                continue;
            }
            if (is_array($detail)) {
                $invoices[] = $detail;
                continue;
            }

            $invoices[] = [
                "name" => $this->toName($detail->name),
                "plan_amount" => round($detail->plan_amount, 2),
                "charged_at" => Carbon::now("UTC"),
                "identifier_type" => $detail->chargeable_type,
                "identifier" => (string)$detail->chargeable_id,
                "mode" => $mode,
            ];
        }

        return $invoices;
    }

    private function toName($name)
    {
        switch ($name) {
            case "运费":
                return "SHIPPING";
            case "运费附加费":
                return "SHIPPING_ADDITIONAL";
            case "签收费":
                return "SIGCON";
            case "处理费":
                return "HANDLE";
            case "包材费":
                return "PACKING";
            case "服务费":
                return "SERVICE";
            case "燃油费":
                return "FUEL";
            case "换标费":
                return "CHANGE_BARCODE";
            case "滞纳金":
                return "LATE_PAYMENT";
            case "品类费":
                return "CATEGORY";
            case "仓储费":
                return "STORAGE";
            case "仓储旺季附加费":
                return "STORAGE_BUSY_SEASON_ADDITIONAL";
            case "弃置费":
                return "DISCARD";
            case "拍照费":
                return "TAKE_PHOTO";
            case "自提费":
                return "PICKUP_SELF";
            case "清点费":
                return "CHECK";
            case "优先入库费":
                return "INBOUND_FIRST";
            case "优先出库费":
                return "OUTBOUND_FIRST";
            case "延迟处理费":
                return "HANDLE_TIMEOUT";
            case "卸柜费":
                return "UNLOADING";
            case "当场卸柜费":
                return "LIVE_UNLOADING";
            case "卸柜箱数附加费":
                return "UNLOADING_BOXES";
            case "卸柜SKU附加费":
                return "UNLOADING_SKUS";
            case "住宅地址附加费":
                return "RESIDENCE";
            case "超长附加费":
                return "OVER_LONG";
            case "超重附加费":
                return "OVER_WEIGHT";
            case "超大件附加费":
                return "OVER_LARGE";
            case "旺季附加费":
                return "BUSY_SEASON";
            case "偏远地址附加费":
                return "ADDRESS_REMOTE";
            default:
                return null;
        }
    }
}
