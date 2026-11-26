<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MasterNameRequest;
use App\Models\ProductModel;

class ProducModelController extends Controller
{

    public function index()
    {
        $data = ProductModel::forBusiness((int) auth()->user()->business_id)->latest()->get();

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $data,
        ]);
    }


    public function store(MasterNameRequest $request)
    {
        $request->validate([
            'name' => 'required|unique:product_models,name,NULL,id,business_id,' . auth()->user()->business_id,
        ]);

        $data = ProductModel::create($request->validated() + [
            'business_id' => auth()->user()->business_id
        ]);

        return response()->json([
            'message' => __('Data saved successfully.'),
            'data' => $data,
        ]);
    }

    public function update(MasterNameRequest $request, string $id)
    {
        $product_model = ProductModel::forBusiness((int) auth()->user()->business_id)->find($id);
        if (!$product_model) {
            return response()->json([
                'message' => __('Model not found.'),
                'data' => null,
            ], 404);
        }
        $request->validate([
            'name' => [
                'required',
                'unique:product_models,name,' . $product_model->id . ',id,business_id,' . auth()->user()->business_id,
            ],
        ]);

        $product_model->update($request->validated());

        return response()->json([
            'message' => __('Data saved successfully.'),
            'data' => $product_model,
        ]);
    }


    public function destroy(string $id)
    {
        $product_model = ProductModel::forBusiness((int) auth()->user()->business_id)->find($id);

        if (!$product_model) {
            return response()->json([
                'message' => __('Model not found.'),
            ], 404);
        }

        $product_model->delete();
        return response()->json([
            'message' => __('Data deleted successfully.'),
        ]);
    }
}
