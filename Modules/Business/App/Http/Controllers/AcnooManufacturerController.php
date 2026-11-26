<?php
namespace Modules\Business\App\Http\Controllers;

use App\Models\Manufacturer;
use Illuminate\Http\Request;
use App\Helpers\HasUploader;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;

class AcnooManufacturerController extends Controller
{
    use HasUploader;

    public function index()
    {
        $manufacturers = Manufacturer::where('business_id', auth()->user()->business_id)
            ->latest()->paginate(20);

        return view('business::manufacturers.index', compact('manufacturers'));
    }

    public function acnooFilter(Request $request)
    {
        $manufacturers = Manufacturer::where('business_id', auth()->user()->business_id)
            ->when($request->search, function ($q) use ($request) {
                $q->where('manufacturerName', 'like', "%{$request->search}%")
                  ->orWhere('description', 'like', "%{$request->search}%");
            })
            ->latest()
            ->paginate($request->per_page ?? 10);

        if ($request->ajax()) {
            return response()->json([
                'data' => view('business::manufacturers.datas', compact('manufacturers'))->render()
            ]);
        }

        return back();
    }

    public function store(Request $request)
    {
        $request->validate([
            'manufacturerName' => 'required|string|max:255',
            'description' => 'nullable|string',
            'icon' => 'nullable|image',
        ]);

        Manufacturer::create($request->except('icon') + [
            'business_id' => auth()->user()->business_id,
            'icon' => $request->icon ? $this->upload($request, 'icon') : null,
        ]);

        return response()->json([
            'message' => __('Manufacturer created successfully'),
            'redirect' => route('business.manufacturers.index'),
        ]);
    }

    public function update(Request $request, $id)
    {
        $manufacturer = Manufacturer::where('business_id', auth()->user()->business_id)->findOrFail($id);

        $request->validate([
            'manufacturerName' => 'required|string|max:255',
            'description' => 'nullable|string',
            'icon' => 'nullable|image',
        ]);

        $manufacturer->update([
            'manufacturerName' => $request->manufacturerName,
            'description' => $request->description,
            'icon' => $request->icon
                ? $this->upload($request, 'icon', $manufacturer->icon)
                : $manufacturer->icon,
        ]);

        return response()->json([
            'message' => __('Manufacturer updated successfully'),
            'redirect' => route('business.manufacturers.index'),
        ]);
    }

    public function destroy($id)
    {
        $manufacturer = Manufacturer::where('business_id', auth()->user()->business_id)->findOrFail($id);

        if ($manufacturer->icon && file_exists($manufacturer->icon)) {
            Storage::delete($manufacturer->icon);
        }

        $manufacturer->delete();

        return response()->json([
            'message' => __('Manufacturer deleted successfully'),
            'redirect' => route('business.manufacturers.index'),
        ]);
    }

    public function status(Request $request, $id)
    {
        Manufacturer::whereId($id)->update(['status' => $request->status]);
        return response()->json(['message' => __('Status updated')]);
    }

    public function deleteAll(Request $request)
    {
        DB::transaction(function () use ($request) {
            Manufacturer::whereIn('id', $request->ids)->delete();
        });

        return response()->json([
            'message' => __('Selected manufacturers deleted'),
            'redirect' => route('business.manufacturers.index'),
        ]);
    }
}
