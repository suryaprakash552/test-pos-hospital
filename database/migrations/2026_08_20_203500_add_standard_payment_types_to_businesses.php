<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('businesses')->orderBy('id')->chunkById(250, function ($businesses) use ($now): void {
            $rows = [];
            $businessIds = $businesses->pluck('id')->all();
            $existing = DB::table('payment_types')
                ->whereIn('business_id', $businessIds)
                ->get(['business_id', 'name'])
                ->mapWithKeys(fn ($type) => [
                    $type->business_id . ':' . strtolower(trim($type->name)) => true,
                ]);
            foreach ($businesses as $business) {
                foreach (['Cash', 'UPI', 'Card', 'Split'] as $name) {
                    if ($existing->has($business->id . ':' . strtolower($name))) {
                        continue;
                    }
                    $rows[] = [
                        'business_id' => $business->id,
                        'name' => $name,
                        'status' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            if ($rows !== []) {
                DB::table('payment_types')->insert($rows);
            }
        });
    }

    public function down(): void
    {
        // These are real tender settings once used; rolling back must not
        // delete payment methods referenced by invoices.
    }
};
