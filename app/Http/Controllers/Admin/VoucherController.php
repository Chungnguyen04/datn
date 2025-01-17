<?php

namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Http\Requests\VoucherRequest;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VoucherController extends Controller
{
    public function index()
    {
        $vouchers = Voucher::orderBy('id', 'desc')->paginate(10);
        return view('admin.pages.vouchers.index', compact('vouchers'));
    }

    public function create()
    {
        return view('admin.pages.vouchers.create');
    }

    public function store(VoucherRequest $request)
    {
        try {
            DB::beginTransaction();

            Voucher::create([
                'code' => $request->code,
                'name' => $request->name,
                'discount_value' => $request->discount_value,
                'discount_min_price' => $request->discount_min_price,
                'discount_type' => $request->discount_type,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'total_uses' => $request->total_uses,
            ]);

            DB::commit();

            return redirect()->route('vouchers.index')->with('status_succeed', 'Thêm voucher thành công');
        } catch (\Exception $e) {
            DB::rollback();

            Log::error($e->getMessage());

            return back()->with('status_failed', 'Đã xảy ra lỗi!');
        }
    }

    public function edit($id)
    {
        $voucher = Voucher::find($id);
        return view('admin.pages.vouchers.edit', compact('voucher'));
    }

    public function update(VoucherRequest $request, $id)
    {
        try {
            DB::beginTransaction();

            $voucher = Voucher::find($id);

            $voucher->update([
                'code' => $request->code,
                'name' => $request->name,
                'discount_value' => $request->discount_value,
                'discount_min_price' => $request->discount_min_price,
                'discount_type' => $request->discount_type,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'total_uses' => $request->total_uses,
            ]);

            DB::commit();

            return redirect()->route('vouchers.index')->with('status_succeed', 'Cập nhật voucher thành công');
        } catch (\Exception $e) {
            DB::rollback();

            Log::error($e->getMessage());

            return back()->with('status_failed', 'Đã xảy ra lỗi!');
        }
    }

    public function delete($id)
    {
        try {
            DB::beginTransaction();

            $voucher = Voucher::find($id);

            $voucher->delete();

            DB::commit();

            return redirect()->route('vouchers.index')->with('status_succeed', 'Xóa voucher thành công');
        } catch (\Exception $e) {
            DB::rollback();

            Log::error($e->getMessage());

            return back()->with('status_failed', 'Đã xảy ra lỗi!');
        }
    }
}
