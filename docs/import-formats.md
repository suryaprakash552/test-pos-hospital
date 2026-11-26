# Sales and Purchase Excel/CSV import

Download the matching sample from **Import → Download Import Samples**.
Use XLSX or comma-separated UTF-8 CSV. Keep one item per row and the header below.

```csv
Invoice Number,Document Date,Product Name,Product Code,Batch No,Quantity,Free Qty,Rate,MRP,Discount %,GST %,Expiry Date,Mfg Date
PUR-001,2026-09-18,Paracetamol 500mg,MED001,B001,10,0,18,25,0,5,2028-06-30,2026-07-01
```

Product Name or Product Code is required and must identify an existing Inventory
product. Uncertain matches require review; imports do not create products silently.

For purchases, enter Quantity and supplier Rate. Include Batch No, Expiry Date,
Mfg Date and MRP to preserve the delivery details. Free Qty and Discount % are
optional. Dates use YYYY-MM-DD; numbers must not contain currency symbols.

For sales, Quantity defaults to 1 if absent. Inventory determines the current
selling price and tax at checkout; document Rate and GST % are reference values,
not overrides. Available non-expired batches are allocated earliest-expiry first,
splitting quantities when necessary. Insufficient stock or missing Inventory
prices require correction or removing the affected item.

Inventory manufacturer, rack and shelf remain Inventory master data, not supplier
bill overrides. Invoice Number and Document Date identify the source document.
Review all mappings and the cart before normal checkout; uploading alone does not
post a sale or purchase. Image recognition is Upcoming; use a text PDF, XLSX or CSV.
