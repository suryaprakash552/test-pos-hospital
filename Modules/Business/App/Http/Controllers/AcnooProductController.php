<?php

namespace Modules\Business\App\Http\Controllers;

use App\Rules\TenantOwned;

use App\Models\Unit;
use App\Models\Brand;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Category;
use App\Helpers\HasUploader;
use App\Services\ProductTransactionGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Storage;
use Modules\Business\App\Exports\ExportProduct;

class AcnooProductController extends Controller
{
    use HasUploader;

    public function index()
    {
        $products = Product::with('unit:id,unitName', 'brand:id,brandName','manufacturer:id,manufacturerName', 'category:id,categoryName','stocks')->withSum('stocks', 'productStock')->where('business_id', auth()->user()->business_id)->latest()->paginate(20);
        return view('business::products.index', compact('products'));
    }

    public function acnooFilter(Request $request)
    {
        $search = $request->input('search');
        $products = Product::with('unit:id,unitName', 'brand:id,brandName','manufacturer:id,manufacturerName', 'category:id,categoryName')->where('business_id', auth()->user()->business_id)->when($search, function ($q) use ($search) {
            $q->where(function ($q) use ($search) {
                $q->where('productName', 'like', '%' . $search . '%')
                  ->orWhere('productCode', 'like', '%' . $search . '%')
                  ->orWhere('productPurchasePrice', 'like', '%' . $search . '%')
                  ->orWhere('productSalePrice', 'like', '%' . $search . '%')
                  ->orWhere('productStock', 'like', '%' . $search . '%')
                    ->orWhereHas('category', function ($q) use ($search) {
                        $q->where('categoryName', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('brand', function ($q) use ($search) {
                        $q->where('brandName', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('manufacturer', function ($q) use ($search) {
                        $q->where('manufacturerName', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('unit', function ($q) use ($search) {
                        $q->where('unitName', 'like', '%' . $search . '%');
                    });

            });
        })
            ->latest()
            ->paginate($request->per_page ?? 10);

        if ($request->ajax()) {
            return response()->json([
                'data' => view('business::products.datas', compact('products'))->render()
            ]);
        }

        return redirect(url()->previous());
    }


    public function create()
    {
        $categories = Category::where('business_id', auth()->user()->business_id)->whereStatus(1)->latest()->get();
        $brands = Brand::where('business_id', auth()->user()->business_id)->whereStatus(1)->latest()->get();
        $manufacturers = Manufacturer::where('business_id', auth()->user()->business_id)->whereStatus(1)->latest()->get();
        $units = Unit::where('business_id', auth()->user()->business_id)->whereStatus(1)->latest()->get();
        $product_id = (Product::max('id') ?? 0) + 1;
        $code = str_pad($product_id , 4, '0', STR_PAD_LEFT);

        return view('business::products.create', compact('categories', 'brands', 'manufacturers','units', 'code'));
    }


    public function store(Request $request)
{
    $request->validate([
        'productName' => 'required|string|max:255',
        'unit_id' => ['nullable', TenantOwned::in('units')],
        'brand_id' => ['nullable', TenantOwned::in('brands')],
        'manufacturer_id' => ['nullable', TenantOwned::in('manufacturers')],
        'category_id' => ['required', TenantOwned::in('categories')],
        'productCode' => 'required|string|max:255|unique:products,productCode',
        'productPicture' => 'nullable|image|mimes:jpg,png,jpeg|max:2048',
        'productDealerPrice' => 'nullable|numeric|min:0',
        'productPurchasePrice' => 'required|numeric|min:0',
        'productSalePrice' => 'required|numeric|min:0',
        'productWholeSalePrice' => 'nullable|numeric|min:0',
        'productStock' => 'nullable|integer|min:0',
    ]);

    $product_id = (Product::max('id') ?? 0) + 1;
    $code = str_pad($product_id, 4, '0', STR_PAD_LEFT);

    // ✅ SAVE PRODUCT INTO VARIABLE
    $product = Product::create(
        $request->except(['productPicture', 'productCode', 'productDealerPrice', 'productWholeSalePrice', 'productStock'])
        + [
            'productCode' => $request->productCode ?? $code,
            'productPicture' => $request->productPicture ? $this->upload($request, 'productPicture') : null,
            'productSalePrice' => $request->productSalePrice,
            'productDealerPrice' => $request->productDealerPrice ?? $request->productSalePrice,
            'productWholeSalePrice' => $request->productWholeSalePrice ?? $request->productSalePrice,
            // Creating a product is not a stock movement.
            //
            // This wrote the posted quantity onto the product and onto a new
            // batch below, with no purchase lot behind either. Availability is
            // summed from the lots, so that stock could never be sold, while
            // the tally it inflated is what the legacy screens and reports
            // read - which is how batches that hold a quantity with no lot at
            // all come to exist. Opening stock needs a source document of its
            // own; until there is one, a new product starts empty.
            'productStock' => 0,
            'business_id' => auth()->user()->business_id,
        ]
    );

    // ✅ CREATE STOCK USING CREATED PRODUCT ID
    Stock::create([
        'business_id' => auth()->user()->business_id,
        'product_id' => $product->id,
        'branch_id' => \App\Support\ActiveBranch::forWrite(),
        'productStock' => 0,
        'productPurchasePrice' => $request->productPurchasePrice ?? 0,
        'profit_percent' => $request->profit_percent ?? 0,
        'productSalePrice' => $request->productSalePrice ?? 0,
        'productWholeSalePrice' => $request->productWholeSalePrice ?? 0,
        'productDealerPrice' => $request->productDealerPrice ?? 0,
        'mfg_date' => $request->mfg_date ?? null,
        'expire_date' => $request->expire_date ?? null,
    ]);

    return response()->json([
        'message' => __('Product saved successfully.'),
        'redirect' => route('business.products.index')
    ]);
}



    public function edit($id)
    {
        $product = Product::where('business_id', auth()->user()->business_id)->findOrFail($id);
        $categories = Category::where('business_id', auth()->user()->business_id)->whereStatus(1)->latest()->get();
        $brands = Brand::where('business_id', auth()->user()->business_id)->whereStatus(1)->latest()->get();
        $manufacturers = Manufacturer::where('business_id', auth()->user()->business_id)->whereStatus(1)->latest()->get();
        $units = Unit::where('business_id', auth()->user()->business_id)->whereStatus(1)->latest()->get();

        return view('business::products.edit', compact('categories', 'brands', 'manufacturers', 'units', 'product'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'productName' => 'required|string|max:255',
            'unit_id' => ['nullable', TenantOwned::in('units')],
            'brand_id' => ['nullable', TenantOwned::in('brands')],
            'category_id' => ['required', TenantOwned::in('categories')],
            'productPicture' => 'nullable|image|mimes:jpg,png,jpeg|max:2048',
            'productDealerPrice' => 'nullable|numeric|min:0',
            'productPurchasePrice' => 'required|numeric|min:0',
            'productSalePrice' => 'required|numeric|min:0',
            'productWholeSalePrice' => 'nullable|numeric|min:0',
            'productStock' => 'nullable|integer|min:0',
            'size' => 'nullable|string|max:255',
            'type' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:255',
            'weight' => 'nullable|string|max:255',
            'capacity' => 'nullable|string|max:255',
            'manufacturer_id' => ['nullable', TenantOwned::in('manufacturers')],
        ]);

        $product = Product::where('business_id', auth()->user()->business_id)->findOrFail($id);

        $product->update($request->except(['productPicture', 'productDealerPrice', 'productWholeSalePrice', 'productStock']) + [
            'productPicture' => $request->productPicture ? $this->upload($request, 'productPicture', $product->productPicture) : $product->productPicture,
            'productSalePrice' => $request->productSalePrice,
            'productDealerPrice' => $request->productDealerPrice ?? $request->productSalePrice,
            'productWholeSalePrice' => $request->productWholeSalePrice ?? $request->productSalePrice,
            'business_id' => auth()->user()->business_id,
        ]);
        // Prices cascade to the product's batches; quantity does not.
        //
        // `productStock` was in this list, so editing a product set *every one*
        // of its batches to the same number - a product held across four
        // batches came out holding that quantity four times over, with the
        // purchase lots untouched and still saying otherwise.
        Stock::where('product_id', $product->id)->update([
            'productSalePrice' => $request->productSalePrice,
            'productPurchasePrice' => $request->productPurchasePrice,
            'productDealerPrice' => $request->productDealerPrice ?? $request->productSalePrice,
            'productWholeSalePrice' => $request->productWholeSalePrice ?? $request->productSalePrice,
            'expire_date' => $request->expire_date ?? null,
        ]);

        return response()->json([
            'message' => __('Data saved successfully.'),
            'redirect' => route('business.products.index')
        ]);
    }


    public function destroy($id)
    {
        $product = Product::where('business_id', auth()->user()->business_id)->findOrFail($id);

        // The same protection the API applies. This path had none at all, so a
        // product could be removed here that the app refused to remove - and
        // with it, by cascade, every batch recorded against it.
        $blockers = ProductTransactionGuard::deletionBlockers($product);

        if ($blockers !== []) {
            return response()->json(
                ProductTransactionGuard::protectedResponse($blockers),
                409
            );
        }

        if ($product->productPicture && file_exists($product->productPicture)) {
            Storage::delete($product->productPicture);
        }

        $product->delete();

        return response()->json([
            'message' => __('Product deleted successfully'),
            'redirect' => route('business.products.index')
        ]);
    }

    /**
     * Deletes several products, or none of them.
     *
     * Two faults, both load-bearing. The selection was `Product::whereIn('id')`
     * with no tenant scope, so ids belonging to another shop were fetched and
     * deleted along with the caller's own. And nothing checked history: the
     * database refuses a product carrying purchase or sale rows, but it refuses
     * it *mid-loop*, as an unhandled exception after earlier products in the
     * same request had already gone.
     *
     * So it is all-or-nothing now. Every id is resolved within the tenant and
     * checked before anything is removed, and one protected product refuses the
     * whole request - a partial delete the caller cannot see is worse than a
     * refusal they can.
     */
    public function deleteAll(Request $request)
    {
        $validated = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer'],
        ]);

        $businessId = auth()->user()->business_id;
        $ids = array_values(array_unique($validated['ids']));

        $products = Product::where('business_id', $businessId)
            ->whereIn('id', $ids)
            ->get();

        // An id that resolves to nothing within this tenant is either deleted or
        // someone else's. Both answer the same way, so the response cannot be
        // used to discover which products exist in another shop.
        if ($products->count() !== count($ids)) {
            return response()->json([
                'success' => false,
                'code'    => 'PRODUCT_NOT_FOUND',
                'message' => __('One or more selected products could not be found.'),
            ], 404);
        }

        $protected = [];

        foreach ($products as $product) {
            $blockers = ProductTransactionGuard::deletionBlockers($product);

            if ($blockers !== []) {
                $protected[$product->id] = [
                    'productName' => $product->productName,
                    'history'     => $blockers,
                ];
            }
        }

        if ($protected !== []) {
            return response()->json([
                'success'   => false,
                'code'      => 'PRODUCT_HISTORY_PROTECTED',
                'message'   => __('This product cannot be deleted because inventory or transaction history exists.'),
                'action'    => 'archive_or_disable',
                'archive_available' => false,
                'protected' => $protected,
                'deleted'   => 0,
            ], 409);
        }

        DB::transaction(function () use ($products) {
            foreach ($products as $product) {
                $product->delete();
            }
        });

        // Only once the rows are gone, so a failed delete cannot leave a product
        // pointing at a file that is no longer there.
        foreach ($products as $product) {
            if ($product->productPicture && file_exists($product->productPicture)) {
                Storage::delete($product->productPicture);
            }
        }

        return response()->json([
            'message'   => __('Selected product deleted successfully'),
            'deleted'   => $products->count(),
            'redirect'  => route('business.products.index')
        ]);
    }

    public function generatePDF(Request $request)
    {
        $products = Product::with('unit:id,unitName', 'brand:id,brandName', 'manufacturer:id,manufacturerName', 'category:id,categoryName')->where('business_id', auth()->user()->business_id)->latest()->get();
        $pdf = Pdf::loadView('business::products.pdf', compact('products'));
        return $pdf->download('product.pdf');
    }

    public function exportExcel()
    {
        return Excel::download(new ExportProduct, 'product.xlsx');
    }

    public function exportCsv()
    {
        return Excel::download(new ExportProduct, 'product.csv');
    }
}
