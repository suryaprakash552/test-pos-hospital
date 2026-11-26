<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Vat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\Api\StoreVatRequest;
use App\Http\Requests\Api\UpdateVatRequest;
use App\Http\Resources\VatResource;
use App\Helpers\DataSanitizer;
use App\Support\LookupDeletionGuard;

class AcnooVatController extends Controller
{
    /**
     * §19 lists "Configure GST rates" as its own permission, and `gst.rates` has
     * been grantable since the permission map was written - it was simply never
     * checked, so anyone who could reach the endpoint could edit the rate master.
     *
     * Reading stays open: every product form needs the list of rates, and gating
     * it would break pricing for staff who are not meant to edit rates at all.
     */
    public function __construct()
    {
        $this->middleware('check.permission:gst.rates')
            ->only(['store', 'update', 'destroy']);
    }

    public function index()
    {
        $vats = Vat::where('business_id', auth()->user()->business_id)
                    ->when(request('type') == 'single', function ($query) {
                        $query->whereNull('sub_vat');
                    })
                    ->when(request('type') == 'group', function ($query) {
                        $query->whereNotNull('sub_vat');
                    })
                    ->when(request('status'), function ($query) {
                        $query->where('status', request('status') == 'active' ? 1 : 0);
                    })
                    ->latest()
                    ->get();

        return response()->json([
            'message' => 'Data fetched successfully.',
            'data' => VatResource::collection($vats),
        ]);
    }

    public function store(StoreVatRequest $request)
    {
        $user = auth()->user();
        $business_id = $user->business_id;

        if ($request->rate && !$request->vat_ids) {

            $vat = Vat::create([
                'name' => DataSanitizer::sanitizeString($request->name),
                'rate' => $request->rate,
                'status' => $request->status ?? 1,
                'business_id' => $business_id,
            ]);

        } elseif (!$request->rate && $request->vat_ids) {

            $vats = Vat::whereIn('id', $request->vat_ids)
                ->where('business_id', $business_id)
                ->select('id', 'name', 'rate')
                ->get();

            $tax_rate = 0;
            $sub_vats = [];

            foreach ($vats as $vat) {
                $sub_vats[] = [
                    'id' => $vat->id,
                    'name' => $vat->name,
                    'rate' => $vat->rate,
                ];
                $tax_rate += $vat->rate;
            }

            $vat = Vat::create([
                'rate' => $tax_rate,
                'sub_vat' => $sub_vats,
                'name' => DataSanitizer::sanitizeString($request->name),
                'status' => $request->status ?? 1,
                'business_id' => $business_id,
            ]);

        } else {
            return response()->json([
                'message' => 'Invalid data format.',
            ], 406);
        }
        
        Log::info('VAT created', [
            'business_id' => $business_id,
            'user_id' => $user->id,
            'vat_id' => $vat->id,
        ]);

        return response()->json([
            'message' => 'Data created successfully.',
            'data' => new VatResource($vat),
        ]);
    }

    public function update(UpdateVatRequest $request, Vat $vat)
    {
        $user = auth()->user();
        
        if ($vat->business_id != $user->business_id) {
            return response()->json([
                'message' => __('Unauthorized.')
            ], 403);
        }

        if ($request->rate && !$request->vat_ids) {

             $vat->update([
                 'name' => DataSanitizer::sanitizeString($request->name),
                 'rate' => $request->rate,
                 'status' => $request->status ?? $vat->status,
             ]);

            $vatGroupExist = Vat::where('sub_vat', 'LIKE', '%"id":' . $vat->id . '%')->get();
            foreach ($vatGroupExist as $group) {
                $subVats = collect($group->sub_vat)->map(function ($subVat) use ($vat) {
                    if ($subVat['id'] == $vat->id) {
                        $subVat['rate'] = $vat->rate;
                        $subVat['name'] = $vat->name;
                    }
                    return $subVat;
                });

                $group->update([
                    'rate' => $subVats->sum('rate'),
                    'sub_vat' => $subVats->toArray(),
                ]);
            }

        } elseif (!$request->rate && $request->vat_ids) {

            $vats = Vat::whereIn('id', $request->vat_ids)
                ->where('business_id', $user->business_id)
                ->select('id', 'name', 'rate')
                ->get();

            $tax_rate = 0;
            $sub_vats = [];

            foreach ($vats as $single_tax) {
                $sub_vats[] = [
                    'id' => $single_tax->id,
                    'name' => $single_tax->name,
                    'rate' => $single_tax->rate,
                ];
                $tax_rate += $single_tax->rate;
            }

             $vat->update([
                'rate' => $tax_rate,
                'sub_vat' => $sub_vats,
                'name' => DataSanitizer::sanitizeString($request->name),
                'status' => $request->status ?? $vat->status,
            ]);

        } else {
            return response()->json([
                'message' => 'Invalid data format.',
            ], 406);
        }
        
        Log::info('VAT updated', [
            'business_id' => $user->business_id,
            'user_id' => $user->id,
            'vat_id' => $vat->id,
        ]);

        return response()->json([
            'message' => 'Data updated successfully.',
            'data' => new VatResource($vat),
        ]);
    }


    public function destroy(Vat $vat)
    {
        $user = auth()->user();
        
        if ($vat->business_id != $user->business_id) {
            return response()->json([
                'message' => __('Unauthorized.')
            ], 403);
        }
        
        // When sub_vat is null, check if the VAT exists in any other VAT's sub_vat
        if (is_null($vat->sub_vat) && Vat::where('sub_vat', 'LIKE', '%"id":' . $vat->id . '%')->exists()) {
            return response()->json([
                'message' => 'Cannot delete. This VAT is part of a VAT group.',
            ], 404);
        }

        // products.vat_id cascaded, while sales.vat_id and purchases.vat_id -
        // added in the same migration block - were nullOnDelete. Deleting a tax
        // rate therefore deleted every product on it.
        $blocked = LookupDeletionGuard::blockedMessage('vat_id', $vat->id, 'VAT rate');

        if ($blocked !== null) {
            return response()->json(['message' => $blocked], 409);
        }

        try {
            $vat->delete();
            
            Log::info('VAT deleted', [
                'business_id' => $user->business_id,
                'user_id' => $user->id,
                'vat_id' => $vat->id,
            ]);

            return response()->json([
                'message' => 'Data deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('VAT deletion failed', [
                'business_id' => $user->business_id,
                'vat_id' => $vat->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => __('Failed to delete VAT.')
            ], 500);
        }
    }

}
