<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'amount' => (float) $this->amount,
            'upi_amount' => (float) $this->upi_amount,
            'card_amount' => (float) $this->card_amount,
            'expense_for' => $this->expanseFor,
            'payment_type' => $this->paymentType,
            'reference_no' => $this->referenceNo,
            'note' => $this->note,
            'expense_date' => $this->expenseDate?->format('Y-m-d'),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
            'category' => $this->whenLoaded('category', function () {
                return [
                    'id' => $this->category->id,
                    'name' => $this->category->categoryName,
                ];
            }),
            'payment_type_detail' => $this->whenLoaded('payment_type', function () {
                return [
                    'id' => $this->payment_type->id,
                    'name' => $this->payment_type->name,
                ];
            }),
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                ];
            }),
        ];
    }
}
