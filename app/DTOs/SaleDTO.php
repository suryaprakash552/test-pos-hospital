<?php

namespace App\DTOs;

class SaleDTO extends BaseDTO
{
    public ?string $invoiceNumber;
    public ?string $saleDate;
    public ?int $customer_id;
    public ?int $user_id;
    public ?int $business_id;
    public ?int $branch_id;
    public ?int $payment_type_id;
    public ?int $doctor_id;
    public ?float $totalAmount;
    public ?float $paidAmount;
    public ?float $dueAmount;
    public ?float $discountAmount;
    public ?float $changeAmount;
    public ?float $cashAmount;
    public ?float $upiAmount;
    public ?float $cardAmount;
    public ?string $paymentNote;
    public ?string $saleNote;
    public ?string $shippingAddress;
    public ?array $details;
    public ?string $rounding_option;
    public ?float $rounded_amount;
    public ?int $order_management_id;

    public function __construct(array $data)
    {
        $this->invoiceNumber = $data['invoiceNumber'] ?? null;
        $this->saleDate = $data['saleDate'] ?? null;
        $this->customer_id = $data['customer_id'] ?? null;
        $this->user_id = $data['user_id'] ?? null;
        $this->business_id = $data['business_id'] ?? null;
        $this->branch_id = $data['branch_id'] ?? null;
        $this->payment_type_id = $data['payment_type_id'] ?? null;
        $this->doctor_id = $data['doctor_id'] ?? null;
        $this->totalAmount = isset($data['totalAmount']) ? (float) $data['totalAmount'] : null;
        $this->paidAmount = isset($data['paidAmount']) ? (float) $data['paidAmount'] : null;
        $this->dueAmount = isset($data['dueAmount']) ? (float) $data['dueAmount'] : null;
        $this->discountAmount = isset($data['discountAmount']) ? (float) $data['discountAmount'] : null;
        $this->changeAmount = isset($data['changeAmount']) ? (float) $data['changeAmount'] : null;
        $this->cashAmount = isset($data['cashAmount']) ? (float) $data['cashAmount'] : null;
        $this->upiAmount = isset($data['upiAmount']) ? (float) $data['upiAmount'] : null;
        $this->cardAmount = isset($data['cardAmount']) ? (float) $data['cardAmount'] : null;
        $this->paymentNote = $data['paymentNote'] ?? null;
        $this->saleNote = $data['saleNote'] ?? null;
        $this->shippingAddress = $data['shippingAddress'] ?? null;
        $this->details = $data['details'] ?? [];
        $this->rounding_option = $data['rounding_option'] ?? null;
        $this->rounded_amount = isset($data['rounded_amount']) ? (float) $data['rounded_amount'] : null;
        $this->order_management_id = $data['order_management_id'] ?? null;
    }
}
