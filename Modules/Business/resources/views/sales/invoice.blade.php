@extends('business::layouts.blank')

@section('title')
    {{ __('Invoice') }}
@endsection

@section('main_content')

<style>
@page { size: A4; margin: 0; }

.medical-wrapper {
    width: 210mm;
    min-height: 297mm;
    margin: 0 auto;
    background: white;
    position: relative;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

/* HEADER */
.medical-header {
    background: linear-gradient(135deg, #003399 0%, #00aaff 100%);
    height: 180px;
    color: white;
    padding: 40px 60px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    border-bottom: 8px solid #00d4ff;
}

.medical-header h1 {
    margin: 0;
    font-size: 38px;
    letter-spacing: 3px;
    font-weight: 800;
    color:white;
}

.medical-header p {
    margin: 0;
    font-size: 14px;
    letter-spacing: 2px;
    text-transform: uppercase;
}

/* BODY */
.medical-body {
    padding: 40px 60px;
}

.invoice-label {
    color: #fbfcff;
    font-size: 55px;
    font-weight: 900;
    margin: 10px 223px;
}

.info-section {
    display: flex;
    justify-content: end;
    margin-top: 20px;
    margin-bottom: 40px;
}

.bill-to h3 {
    margin-bottom: 5px;
    color: #333;
}

.bill-to p {
    margin: 2px 0;
    color: #555;
    font-size: 15px;
}

.meta-data {
    text-align: right;
}

.meta-data div {
    margin-bottom: 10px;
}

/* TABLE */
.medical-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 30px;
}

.medical-table thead th {
    background-color: #0055d4;
    color: white;
    padding: 12px;
    text-align: left;
    font-size: 14px;
    text-transform: uppercase;
}

.medical-table tbody td {
    padding: 12px;
    border-bottom: 1px solid #f0f0f0;
    font-size: 13px;
    color: #444;
}

.medical-table tbody tr:nth-child(even) {
    background-color: #f2f7ff;
}

/* SUMMARY */
.summary-area {
    display: flex;
    justify-content: flex-end;
}

.summary-table {
    width: 300px;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #ddd;
}

.total-row {
    background-color: #0055d4;
    color: white;
    padding: 12px;
    font-weight: bold;
    display: flex;
    justify-content: space-between;
}

/* FOOTER */
.footer {
    margin-top: 80px;
    display: flex;
    justify-content: space-between;
}

.payment-info {
    text-align: right;
    font-size: 13px;
}

@media print {
    body { background: none; }
}

.invoice-actions .action-btn {
    background: #0055d4;
    color: white;
    border: none;
    padding: 8px 16px;
    font-size: 13px;
    cursor: pointer;
    border-radius: 4px;
    text-decoration: none;
    display: inline-block;
}

.invoice-actions .action-btn:hover {
    background: #003399;
}

/* Hide buttons when printing */
@media print {
    .invoice-actions {
        display: none;
    }
}

.info-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-top: 20px;
    margin-bottom: 40px;
}

.bill-to {
    width: 50%;
}

.meta-data {
    width: 40%;
    text-align: right;
}

.meta-data div {
    margin-bottom: 8px;
}
</style>


<div class="medical-wrapper">
    

    {{-- HEADER --}}
    <div class="medical-header">
        <div class="invoice-label">INVOICE</div>
        <h1>{{ $sale->business->companyName ?? '' }}</h1>
       <p style="color:white;">MEDICAL CENTER</p>
    </div>

    <div class="medical-body">

    {{-- BILL + META ROW --}}
    <div class="info-row">

        <div class="bill-to">
            <h3>Bill To:</h3>
            <p><strong>{{ $sale->party->name ?? 'Guest' }}</strong></p>
            <p>{{ $sale->party->phone ?? '' }}</p>
            <p>{{ $sale->business->phoneNumber ?? '' }}</p>
        </div>

        <div class="meta-data">
            <div><b>DATE</b> {{ formatted_date($sale->saleDate ?? '') }}</div>
            <div><b>INVOICE NUMBER</b> {{ $sale->invoiceNumber ?? '' }}</div>
            <div><b>SELLS BY</b> {{ $sale->user->name ?? '' }}</div>
        </div>
        
    </div>

        @php $subtotal = 0; @endphp

        {{-- TABLE --}}
        <table class="medical-table">
            <thead>
                <tr>
                    <th>S.NO</th>
                    <th>Product Name</th>
                    <th>Qty</th>
                    <th>Unit Price</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sale->details as $detail)
                    @php
                        $productTotal = ($detail->price ?? 0) * ($detail->quantities ?? 0);
                        $subtotal += $productTotal;
                    @endphp
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $detail->product->productName ?? '' }}</td>
                        <td>{{ $detail->quantities ?? '' }}</td>
                        <td>{{ currency_format($detail->price ?? 0, 'icon', 2, business_currency()) }}</td>
                        <td>{{ currency_format($productTotal, 'icon', 2, business_currency()) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- SUMMARY --}}
        <div class="summary-area">
            <div class="summary-table">

                <div class="summary-row">
                    <span>Subtotal</span>
                    <span>{{ currency_format($subtotal, 'icon', 2, business_currency()) }}</span>
                </div>

                <div class="summary-row">
                    <span>Vat</span>
                    <span>{{ currency_format($sale->vat_amount, 'icon', 2, business_currency()) }}</span>
                </div>

                <div class="summary-row">
                    <span>Discount</span>
                    <span>{{ currency_format($sale->discountAmount, 'icon', 2, business_currency()) }}</span>
                </div>

                <div class="total-row">
                    <span>Total Amount Due</span>
                    <span>{{ currency_format($sale->totalAmount, 'icon', 2, business_currency()) }}</span>
                </div>

            </div>
        </div>

        {{-- FOOTER --}}
        <div class="footer">
            <div>
                <h2>Thank You</h2>
                <p><b>For Your Purchase</b></p>
                <br>
                <p>{{ $sale->business->phoneNumber ?? '' }}</p>
            </div>

            <div class="payment-info">
                <p><strong>Payment Method:</strong>
                {{ $sale->paymentType }}</p>

                <p><strong>Paid:</strong>
                {{ currency_format($sale->paidAmount, 'icon', 2, business_currency()) }}</p>

                <p><strong>Due:</strong>
                {{ currency_format($sale->dueAmount, 'icon', 2, business_currency()) }}</p>
            </div>
        </div>
        {{-- INFO SECTION --}}
        <div class="info-section">
            <div class="invoice-actions" style="display:flex; justify-content:flex-end; gap:10px; margin-bottom:20px;">

    <form action="{{ route('business.sales.mail', ['sale_id' => $sale->id]) }}"
          method="POST">
        @csrf
        <button type="submit" class="action-btn">
            Email
        </button>
    </form>

    <a href="{{ route('business.sales.pdf', ['sale_id' => $sale->id]) }}"
       class="action-btn">
        Download PDF
    </a>

    <button onclick="window.print()" class="action-btn">
        Print
    </button>

</div>

    </div>
</div>

@endsection
