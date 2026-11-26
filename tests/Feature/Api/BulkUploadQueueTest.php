<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessProductBulkUploadJob;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BulkUploadQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_bulk_upload_is_queued_not_processed_inline(): void
    {
        Queue::fake();
        Storage::fake('public');
        config(['filesystems.default' => 'public']);

        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/bulk-uploads', [
            'file' => UploadedFile::fake()->create('products.csv', 12, 'text/csv'),
        ]);

        $response->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.queue', 'imports');

        Queue::assertPushed(ProcessProductBulkUploadJob::class);
    }

    public function test_product_bulk_upload_rejects_large_files(): void
    {
        Queue::fake();
        Storage::fake('public');
        config(['filesystems.default' => 'public']);

        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/bulk-uploads', [
            'file' => UploadedFile::fake()->create('products.csv', 11000, 'text/csv'),
        ]);

        $response->assertStatus(422);
        Queue::assertNothingPushed();
    }
}
