<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DueCollect;
use App\Models\Party;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleReturn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class AcnooInvoiceController extends Controller
{
    public function index(Request $request)
{
    $user = auth()->user();
    $business_id = $user->business_id;
    
    $request->validate([
        'party_id' => 'nullable|exists:parties,id',
        'customer_id' => 'nullable|exists:customers,id'
    ]);

    if (!$request->party_id && !$request->customer_id) {
        return response()->json([
            'message' => 'party_id or customer_id is required'
        ], 422);
    }

    // PARTY → PURCHASES
    if ($request->party_id) {

        $party = Party::select('id','due','name','type')
            ->where('business_id', $business_id)
            ->findOrFail($request->party_id);

        $data = $party->load([
            'purchases_dues:id,party_id,dueAmount,paidAmount,totalAmount,invoiceNumber,purchaseDate'
        ]);

    } 
    // CUSTOMER → SALES
    else {

        $customer = \App\Models\Customer::select('id','name','due')
            ->where('business_id', $business_id)
            ->findOrFail($request->customer_id);

        $data = $customer->load([
            'sales_dues:id,customer_id,dueAmount,paidAmount,totalAmount,invoiceNumber,saleDate'
        ]);
    }

    return response()->json([
        'message' => __('Data fetched successfully.'),
        'data' => $data
    ]);
}

    public function newInvoice(Request $request)
    {
        $request->validate([
            'platform' => 'required|in:sales,purchases,due_collects,sales_return,purchases_return'
        ]);

        $user = auth()->user();
        $business_id = $user->business_id;
        $platform = $request->platform;

        $cacheKey = "invoice_count_{$business_id}_{$platform}";
        
        if ($platform == 'sales') {
            $prefix = 'S-';
            $id = Cache::remember($cacheKey, 60, function () use ($business_id) {
                return Sale::where('business_id', $business_id)->count();
            });
        } elseif ($platform == 'purchases') {
            $prefix = 'P-';
            $id = Cache::remember($cacheKey, 60, function () use ($business_id) {
                return Purchase::where('business_id', $business_id)->count();
            });
        } elseif ($platform == 'sales_return') {
            $prefix = 'SR-';
            $id = Cache::remember($cacheKey, 60, function () use ($business_id) {
                return SaleReturn::where('business_id', $business_id)->count();
            });
        } elseif ($platform == 'purchases_return') {
            // $prefix = 'PR-';
            // $id = Purchase::where('business_id', $business_id)->count();
            $prefix = '';
            $id = 0;
        }
         else {
            $prefix = 'D-';
            $id = Cache::remember($cacheKey, 60, function () use ($business_id) {
                return DueCollect::where('business_id', $business_id)->count();
            });
        }

        $invoice = $prefix . str_pad($id + 1, 5, '0', STR_PAD_LEFT);

        return response()->json($invoice);
    }
}
