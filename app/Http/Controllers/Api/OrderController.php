<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderRequest;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\OrderStatusHistory;
use App\Models\Variant;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    // Danh sách đơn hàng theo người dùng
    public function getAllOrderByUser(Request $request, $userId)
    {
        try {
            $orders = Order::with([
                'user',
                'province',
                'district',
                'ward',
                'orderDetails',
                'orderDetails.variant',
                'orderDetails.variant.product',
                'orderDetails.variant.weight',
            ]);

            if (!empty($request->code)) {
                $orders = $orders->where('code', $request->code);
            }

            $orders = $orders->where('user_id', $userId)
                ->orderBy('id', 'desc')
                ->get();

            if ($orders->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Không có đơn hàng nào!'
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'status' => true,
                'message' => 'Danh sách đơn hàng đã được lấy thành công.',
                'data' => $orders
            ], Response::HTTP_OK);
        } catch (QueryException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Đã xảy ra lỗi với cơ sở dữ liệu.',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Lỗi models không tạo.',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Exception $e) {
            // Lỗi hệ thống
            return response()->json([
                'status' => false,
                'message' => 'Đã xảy ra lỗi khi truy xuất dữ liệu',
                'errors' => [$e->getMessage()],
                'code' => $e->getCode()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // chung
    public function getRevenueAndProfitData(Request $request)
    {
        // Lấy giá trị bộ lọc từ yêu cầu
        $filter = $request->input('filter');
        $date = $request->input('date');
        $year = $request->input('year'); // Đổi từ yearFilter sang year_filter
        $yearMonth = $request->input('yearMonth'); // Đổi từ yearFilter sang year_filter
        $month = $request->input('month');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $startDate = null;
        $endDate = Carbon::now()->endOfDay();

        // Xác định khoảng thời gian dựa vào bộ lọc
        if ($filter === 'year') {
            // Thống kê theo năm
            if ($year < 2000 || $year > Carbon::now()->year) {
                return response()->json(['error' => 'Năm không hợp lệ, phải từ 2000 đến hiện tại.'], 400);
            }
            $startDate = Carbon::create($year, 1, 1)->startOfDay();
            $endDate = Carbon::create($year, 12, 31)->endOfDay();
        } elseif ($filter === 'day' && $date) {
            // Thống kê theo ngày
            $startDate = Carbon::parse($date)->startOfDay();
            $endDate = Carbon::parse($date)->endOfDay();
        } elseif ($filter === 'month') {
            // Thống kê theo tháng
            if ($month < 1 || $month > 12) {
                return response()->json(['error' => 'Tháng không hợp lệ, phải từ 1 đến 12.'], 400);
            }
            $startDate = Carbon::create($yearMonth, $month, 1)->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();
        } elseif ($filter === 'range') {
            // Thống kê theo khoảng thời gian
            if ($request->input('start_date') && $request->input('end_date')) {
                $startDate = Carbon::parse($request->input('start_date'))->startOfDay();
                $endDate = Carbon::parse($request->input('end_date'))->endOfDay();
                if ($startDate->diffInDays($endDate) > 60) {
                    return response()->json(['error' => 'Khoảng thời gian tối đa là 60 ngày'], 400);
                }
            } elseif ($request->input('start_date')) {
                $startDate = Carbon::parse($request->input('start_date'))->startOfDay();
                $endDate = Carbon::now()->endOfDay(); // Đến thời điểm hiện tại
            }
        } else {
            // Mặc định là tháng hiện tại
            $startDate = Carbon::now()->startOfMonth();
            $endDate = Carbon::now()->endOfMonth();
        }

        // Lấy dữ liệu doanh thu trong khoảng thời gian đã xác định
        $data = Order::where('status', 'completed')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with([
                'orderDetails' => function ($query) {
                    $query->select('order_id', 'price', 'quantity', 'variant_id');
                }
            ])
            ->get();

        // Khởi tạo kết quả
        $result = [
            'labels' => [],
            'revenue' => [],
            'profit' => []
        ];

        // Tính toán doanh thu và lợi nhuận theo bộ lọc
        if ($filter === 'year') {
            for ($month = 1; $month <= 12; $month++) {
                $monthlyStart = Carbon::create($year, $month, 1)->startOfDay();
                $monthlyEnd = $monthlyStart->copy()->endOfMonth();
                $monthlyOrders = $data->filter(function ($order) use ($monthlyStart, $monthlyEnd) {
                    return $order->created_at->between($monthlyStart, $monthlyEnd);
                });

                $revenue = $monthlyOrders->sum(function ($order) {
                    return $order->orderDetails->sum(function ($detail) {
                        return $detail->price * $detail->quantity;
                    });
                });

                $profit = $monthlyOrders->sum(function ($order) {
                    return $order->orderDetails->sum(function ($detail) {
                        $variant = Variant::find($detail->variant_id);
                        $importPrice = $variant ? $variant->import_price : 0;
                        return ($detail->price - $importPrice) * $detail->quantity;
                    });
                });

                $result['labels'][] = "Tháng $month";
                $result['revenue'][] = $revenue;
                $result['profit'][] = $profit;
            }
        } elseif ($filter === 'day') {
            // Thống kê theo ngày (từng giờ)
            for ($hour = 0; $hour < 24; $hour++) {
                $hourStart = Carbon::parse($date)->setHour($hour)->startOfHour();
                $hourEnd = $hourStart->copy()->endOfHour();
                $hourlyOrders = $data->filter(function ($order) use ($hourStart, $hourEnd) {
                    return $order->created_at->between($hourStart, $hourEnd);
                });

                $revenue = $hourlyOrders->sum(function ($order) {
                    return $order->orderDetails->sum(function ($detail) {
                        return $detail->price * $detail->quantity;
                    });
                });

                $profit = $hourlyOrders->sum(function ($order) {
                    return $order->orderDetails->sum(function ($detail) {
                        $variant = Variant::find($detail->variant_id);
                        $importPrice = $variant ? $variant->import_price : 0;
                        return ($detail->price - $importPrice) * $detail->quantity;
                    });
                });

                $result['labels'][] = "$hour:00";
                $result['revenue'][] = $revenue;
                $result['profit'][] = $profit;
            }
        } elseif ($filter === 'month') {
            // Tạo danh sách các ngày trong tháng
            $currentDate = $startDate->copy();
            while ($currentDate->lte($endDate)) {
                $orders = $data->filter(function ($order) use ($currentDate) {
                    return $order->created_at->isSameDay($currentDate);
                });

                $revenue = $orders->sum(function ($order) {
                    return $order->orderDetails->sum(function ($detail) {
                        return $detail->price * $detail->quantity;
                    });
                });

                $profit = $orders->sum(function ($order) {
                    return $order->orderDetails->sum(function ($detail) {
                        $variant = Variant::find($detail->variant_id);
                        $importPrice = $variant ? $variant->import_price : 0;
                        return ($detail->price - $importPrice) * $detail->quantity;
                    });
                });

                $result['labels'][] = $currentDate->format('d-m-Y');
                $result['revenue'][] = $revenue;
                $result['profit'][] = $profit;

                $currentDate->addDay();
            }
        } else {
            // Thống kê theo khoảng thời gian
            $currentDate = $startDate->copy();
            while ($currentDate->lte($endDate)) {
                $dailyOrders = $data->filter(function ($order) use ($currentDate) {
                    return $order->created_at->isSameDay($currentDate);
                });

                $revenue = $dailyOrders->sum(function ($order) {
                    return $order->orderDetails->sum(function ($detail) {
                        return $detail->price * $detail->quantity;
                    });
                });

                $profit = $dailyOrders->sum(function ($order) {
                    return $order->orderDetails->sum(function ($detail) {
                        $variant = Variant::find($detail->variant_id);
                        $importPrice = $variant ? $variant->import_price : 0;
                        return ($detail->price - $importPrice) * $detail->quantity;
                    });
                });

                $result['labels'][] = $currentDate->format('d/m/Y');
                $result['revenue'][] = $revenue;
                $result['profit'][] = $profit;

                $currentDate->addDay();
            }
        }

        return response()->json($result);
    }

    // Chi tiết đơn hàng hiển thị sản phẩm của id order đó
    public function getOrderDetails($orderId)
    {
        try {
            $order = Order::with([
                'user',
                'province',
                'district',
                'ward',
                'orderDetails',
                'orderDetails.variant',
                'orderDetails.variant.product',
                'orderDetails.variant.weight',
            ])
                ->where('id', $orderId)
                ->first();

            if (!$order) {
                return response()->json([
                    'status' => false,
                    'message' => 'Không tìm thấy đơn hàng!'
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'status' => true,
                'message' => 'Danh sách chi tiết đơn hàng',
                'data' => $order
            ], Response::HTTP_OK);
        } catch (QueryException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Đã xảy ra lỗi với cơ sở dữ liệu.',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Lỗi models không tạo.',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Exception $e) {
            // Lỗi hệ thống
            return response()->json([
                'status' => false,
                'message' => 'Đã xảy ra lỗi khi truy xuất dữ liệu',
                'errors' => [$e->getMessage()],
                'code' => $e->getCode()
            ]);
        }
    }
    // Hủy đơn hàng
    public function cancelOrder($orderId)
    {
        DB::beginTransaction();
        try {
            // Lấy đơn hàng theo ID
            $order = Order::find($orderId);

            if (!$order) {
                return response()->json([
                    'status' => false,
                    'message' => 'Không tìm thấy đơn hàng!'
                ], Response::HTTP_NOT_FOUND);
            }

            // Kiểm tra trạng thái đơn hàng
            if ($order->status !== 'pending') {
                return response()->json([
                    'status' => false,
                    'message' => 'Chỉ có thể hủy đơn hàng khi đang ở trạng thái "Chờ xác nhận".'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Lấy danh sách các sản phẩm trong đơn hàng (OrderDetail)
            $orderDetails = OrderDetail::where('order_id', $order->id)->get();

            // Duyệt qua từng sản phẩm trong chi tiết đơn hàng
            foreach ($orderDetails as $detail) {
                // Lấy biến thể sản phẩm từ bảng variants
                $variant = Variant::find($detail->variant_id);

                if ($variant) {
                    // Cập nhật lại số lượng cho biến thể (cộng lại số lượng đã mua)
                    $variant->quantity += $detail->quantity;
                    $variant->save();
                }
            }

            // Cập nhật trạng thái đơn hàng thành "Đã hủy"
            $order->update([
                'status' => 'cancelled'
            ]);

            OrderStatusHistory::create([
                'order_id' => $orderId,
                'old_status' => 'pending',
                'new_status' => 'cancelled',
                'changed_by' => request()->user_id ?? 0,
                'note' => 'Hủy đơn hàng',
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Đơn hàng đã hủy thành công',
                'order' => $order
            ], Response::HTTP_OK);
        } catch (QueryException $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Đã xảy ra lỗi với cơ sở dữ liệu.',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Lỗi models không tạo.',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Đã xảy ra lỗi khi truy xuất dữ liệu',
                'errors' => [$e->getMessage()],
                'code' => $e->getCode()
            ]);
        }
    }

    // Cập nhật trạng thái đã nhận được hàng
    public function markAsCompleted($orderId)
    {
        DB::beginTransaction();
        try {
            // Lấy đơn hàng theo ID
            $order = Order::find($orderId);

            if (!$order) {
                return response()->json([
                    'status' => false,
                    'message' => 'Không tìm thấy đơn hàng!'
                ], Response::HTTP_NOT_FOUND);
            }

            // Kiểm tra trạng thái đơn hàng
            if ($order->status !== 'delivering') {
                return response()->json([
                    'status' => false,
                    'message' => 'Chỉ có thể hoàn thành đơn hàng khi đang ở trạng thái "Đang giao hàng".'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Cập nhật trạng thái đơn hàng thành "Hoàn thành" và "Đã thanh toán"
            $order->update([
                'status' => 'completed',
                'payment_status' => 'paid',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Lưu lại lịch sử trạng thái đơn hàng
            OrderStatusHistory::create([
                'order_id' => $order->id,
                'old_status' => 'delivering',
                'new_status' => 'completed',
                'changed_by' => request()->user_id ?? 0, 
                'note' => 'Nhận hàng thành công.',
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Đơn hàng đã được hoàn thành thành công',
                'order' => $order
            ], Response::HTTP_OK);
        } catch (QueryException $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Đã xảy ra lỗi với cơ sở dữ liệu.',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Lỗi models không tạo.',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Đã xảy ra lỗi khi truy xuất dữ liệu',
                'errors' => [$e->getMessage()],
                'code' => $e->getCode()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function generateRandomOrderCode($length = 8)
    {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, strlen($characters) - 1)];
        }

        return $randomString;
    }

    public function storeOrder(Request $request)
    {
        DB::beginTransaction();
        try {
            // Lấy thông tin voucher nếu có
            $voucher = null;
            $discountValue = 0;

            if ($request->voucher_id) {
                $voucher = Voucher::find($request->voucher_id); // Lấy voucher giảm giá theo id voucher
                if ($voucher) {
                    if ($request->total_price >= $voucher->discount_min_price) {
                        $discountValue = $voucher->discount_value; // Giá trị giảm giá
                    } else {
                        return response()->json([
                            'status' => false,
                            'message' => 'Giá trị đơn hàng không đủ điều kiện sử dụng voucher.',
                        ]);
                    }
                } else {
                    return response()->json([
                        'status' => false,
                        'message' => 'Voucher không tồn tại.',
                    ]);
                }

                if ($voucher->total_uses <= 0) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Voucher đã hết lượt sử dụng.',
                    ], Response::HTTP_NOT_FOUND);
                }
            }
            // Tính toán giá trị cuối cùng
            $finalPrice = $request->total_price - $discountValue;

            // Kiểm tra phương thức thanh toán
            if ($request->payment_method == 'vnpay') {
                $orderCode = $this->generateRandomOrderCode(8);
                $vnpayUrl = $this->generateVnpayUrl($orderCode, $request->total_price);

                // Tạo đơn hàng tạm thời với trạng thái chờ thanh toán
                $order = Order::create([
                    'province_id' => $request->province_id,
                    'district_id' => $request->district_id,
                    'ward_id' => $request->ward_id,
                    'user_id' => $request->user_id ?? null,
                    'code' => $orderCode,
                    'name' => $request->name,
                    'address' => $request->address,
                    'phone' => $request->phone,
                    'total_price' => $request->total_price,
                    'discount_value' => $discountValue, // Thêm giá trị giảm giá
                    'final_price' => $finalPrice, // Thêm giá trị cuối cùng
                    'shipping_fee' => $request->shipping_fee, // Thêm phí ship
                    'status' => 'pending',
                    'payment_method' => 'vnpay',
                    'payment_status' => 'unpaid',
                    'voucher_id' => $voucher ? $voucher->id : null, // Thêm ID voucher
                ]);

                // Lưu chi tiết sản phẩm trong đơn hàng
                foreach ($request->products as $product) {
                    $variant = Variant::lockForUpdate()->find($product['variant_id']);
                    if ($variant && $variant->quantity >= $product['quantity']) {
                        $variant->update([
                            'quantity' => $variant->quantity - $product['quantity']
                        ]);

                        OrderDetail::create([
                            'order_id' => $order->id,
                            'variant_id' => $product['variant_id'] ?? null,
                            'price' => $product['price'],
                            'quantity' => $product['quantity'],
                            'total' => $product['price'] * $product['quantity'],
                        ]);
                    } else {
                        DB::rollBack();
                        return response()->json([
                            'status' => false,
                            'message' => 'Sản phẩm đã hết hàng.',
                        ]);
                    }
                }

                DB::commit();
                return response()->json([
                    'status' => true,
                    'message' => 'Chuyển hướng đến VNPay.',
                    'vnpay_url' => $vnpayUrl['data'],
                    'payment_method' => 'vnpay'
                ]);
            } else {
                // Xử lý thanh toán khi nhận hàng (COD)
                $order = Order::create([
                    'province_id' => $request->province_id,
                    'district_id' => $request->district_id,
                    'ward_id' => $request->ward_id,
                    'user_id' => $request->user_id ?? null,
                    'code' => $this->generateRandomOrderCode(8),
                    'name' => $request->name,
                    'address' => $request->address,
                    'phone' => $request->phone,
                    'total_price' => $request->total_price,
                    'discount_value' => $discountValue, // Thêm giá trị giảm giá
                    'final_price' => $finalPrice, // Thêm giá trị cuối cùng
                    'shipping_fee' => $request->shipping_fee, // Thêm phí ship
                    'status' => $request->status ?? 'pending',
                    'payment_method' => $request->payment_method ?? 'cod',
                    'payment_status' => 'unpaid',
                    'voucher_id' => $voucher ? $voucher->id : null, // Thêm ID voucher
                ]);

                // Lưu chi tiết sản phẩm trong đơn hàng
                foreach ($request->products as $product) {
                    $variant = Variant::lockForUpdate()->find($product['variant_id']);
                    if ($variant && $variant->quantity >= $product['quantity']) {
                        $variant->update([
                            'quantity' => $variant->quantity - $product['quantity']
                        ]);

                        OrderDetail::create([
                            'order_id' => $order->id,
                            'variant_id' => $product['variant_id'] ?? null,
                            'price' => $product['price'],
                            'quantity' => $product['quantity'],
                            'total' => $product['price'] * $product['quantity'],
                        ]);
                    } else {
                        DB::rollBack();
                        return response()->json([
                            'status' => false,
                            'message' => 'Sản phẩm đã hết hàng.',
                        ]);
                    }
                }
                // Lưu lại lịch sử trạng thái đơn hàng ban đầu
                OrderStatusHistory::create([
                    'order_id' => $order->id,
                    'old_status' => $order->status,
                    'new_status' => $order->status,
                    'changed_by' => $request->user_id ?? 0,
                    'note' => 'Đặt hàng'
                ]);

                // Xóa sản phẩm trong giỏ hàng sau khi đặt hàng thành công
                foreach ($request->products as $product) {
                    Cart::where('user_id', $request->user_id)
                        ->where('variant_id', $product['variant_id'])
                        ->delete();
                }

                if (!empty($request->voucher_id)) {
                    $voucher = Voucher::find($order->voucher_id);

                    $voucher->update([
                        'total_uses' => $voucher->total_uses - 1
                    ]);
                }

                DB::commit();
                return response()->json([
                    'status' => true,
                    'message' => 'Đơn hàng đã được tạo thành công với phương thức thanh toán COD.',
                    'order_id' => $order->id,
                    'payment_method' => 'cod'
                ], 201);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Đã xảy ra lỗi trong quá trình tạo đơn hàng',
                'errors' => [$e->getMessage()],
                'code' => $e->getCode(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    // Thanh toán qua VNPay
    public function generateVnpayUrl($orderCode, $totalPrice) // Thêm tham số $request
    {
        $vnp_Url = "https://sandbox.vnpayment.vn/paymentv2/vpcpay.html";
        $vnp_Returnurl = route('orders.vnpayReturn');
        $vnp_TmnCode = "G2QKJU4Y";
        $vnp_HashSecret = "PMDGOWFWONAWTIYOLFWSEKPJGHNIQBJE";

        $vnp_TxnRef = $orderCode;
        $vnp_OrderInfo = "Thanh toán đơn hàng";
        $vnp_OrderType = "billpayment";
        $vnp_Amount = $totalPrice * 100;
        $vnp_Locale = "vn";
        $vnp_BankCode = "NCB";
        $vnp_IpAddr = $_SERVER['REMOTE_ADDR'];

        // Thêm các dữ liệu thanh toán
        $inputData = [
            "vnp_Version" => "2.1.0",
            "vnp_TmnCode" => $vnp_TmnCode,
            "vnp_Amount" => $vnp_Amount,
            "vnp_Command" => "pay",
            "vnp_CreateDate" => date('YmdHis'),
            "vnp_CurrCode" => "VND",
            "vnp_IpAddr" => $vnp_IpAddr,
            "vnp_Locale" => $vnp_Locale,
            "vnp_OrderInfo" => $vnp_OrderInfo,
            "vnp_OrderType" => $vnp_OrderType,
            "vnp_ReturnUrl" => $vnp_Returnurl,
            "vnp_TxnRef" => $vnp_TxnRef
        ];

        if (isset($vnp_BankCode) && $vnp_BankCode != "") {
            $inputData['vnp_BankCode'] = $vnp_BankCode;
        }
        if (isset($vnp_Bill_State) && $vnp_Bill_State != "") {
            $inputData['vnp_Bill_State'] = $vnp_Bill_State;
        }

        ksort($inputData);
        $query = "";
        $i = 0;
        $hashdata = "";
        foreach ($inputData as $key => $value) {
            if ($i == 1) {
                $hashdata .= '&' . urlencode($key) . "=" . urlencode($value);
            } else {
                $hashdata .= urlencode($key) . "=" . urlencode($value);
                $i = 1;
            }
            $query .= urlencode($key) . "=" . urlencode($value) . '&';
        }

        $vnp_Url = $vnp_Url . "?" . $query;
        if (isset($vnp_HashSecret)) {
            $vnpSecureHash =   hash_hmac('sha512', $hashdata, $vnp_HashSecret); //  
            $vnp_Url .= 'vnp_SecureHash=' . $vnpSecureHash;
        }

        return [
            'code' => '00',
            'message' => 'success',
            'data' => $vnp_Url
        ];
    }

    // Thanh toán qua VNPay
    public function vnpayReturn(Request $request)
    {
        $vnp_SecureHash = $request->get('vnp_SecureHash');
        $vnp_TxnRef = $request->get('vnp_TxnRef');
        $vnp_Amount = $request->get('vnp_Amount') / 100;
        $vnp_ResponseCode = $request->get('vnp_ResponseCode');

        // Kiểm tra mã phản hồi
        if ($vnp_ResponseCode == '00') {
            // Thanh toán thành công
            DB::beginTransaction();
            try {
                // Tìm đơn hàng dựa trên mã giao dịch
                $order = Order::with('orderDetails')->where('code', $vnp_TxnRef)->first();

                if ($order) {
                    $order->update([
                        'payment_status' => 'paid',
                        'total_price' => $vnp_Amount,
                    ]);

                    OrderStatusHistory::create([
                        'order_id' => $order->id,
                        'old_status' => 'pending',
                        'new_status' => 'pending',
                        'changed_by' => $order->user_id ?? 0,
                        'note' => 'Đặt hàng'
                    ]);

                    // Xóa sản phẩm trong giỏ hàng của người dùng
                    foreach ($order->orderDetails as $orderDetail) {
                        Cart::where('user_id', $order->user_id)
                            ->where('variant_id', $orderDetail->variant_id)
                            ->delete();
                    }

                    // Kiểm tra và cập nhật số lần sử dụng của voucher
                    if ($order->voucher_id) {
                        $voucher = Voucher::find($order->voucher_id);
                        if ($voucher) {
                            $voucher->update([
                                'total_uses' => $voucher->total_uses - 1
                            ]);
                        }
                    }

                    DB::commit();

                    return redirect()->to('http://localhost:5173/confirm');
                } else {
                    return response()->json([
                        'status' => false,
                        'message' => 'Không tìm thấy đơn hàng.',
                    ]);
                }
            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'Đã xảy ra lỗi khi xử lý đơn hàng.',
                    'errors' => [$e->getMessage()],
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        } else {
            DB::beginTransaction();
            try {
                $order = Order::where('code', $vnp_TxnRef)->first();

                if ($order) {
                    $order->update([
                        'payment_status' => 'unpaid',
                        'status' => 'cancelled', // Trạng thái bạn muốn đặt
                    ]);

                    OrderStatusHistory::create([
                        'order_id' => $order->id,
                        'old_status' => 'pending',
                        'new_status' => 'cancelled',
                        'changed_by' =>  $order->user_id ?? 0,
                        'note' => 'Người dùng hủy đơn không thanh toán'
                    ]);
                    $orderDetails = OrderDetail::where('order_id', $order->id)->get();

                    // Duyệt qua từng sản phẩm trong chi tiết đơn hàng
                    foreach ($orderDetails as $detail) {
                        // Lấy biến thể sản phẩm từ bảng variants
                        $variant = Variant::find($detail->variant_id);

                        if ($variant) {
                            // Cập nhật lại số lượng cho biến thể (cộng lại số lượng đã mua)
                            $variant->quantity += $detail->quantity;
                            $variant->save();
                        }
                    }
                    DB::commit();

                    return redirect()->to('http://localhost:5173/confirm-cancel');
                } else {
                    return response()->json([
                        'status' => false,
                        'message' => 'Không tìm thấy đơn hàng.',
                    ]);
                }
            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'Đã xảy ra lỗi khi cập nhật trạng thái đơn hàng.',
                    'errors' => [$e->getMessage()],
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }
    }
}
