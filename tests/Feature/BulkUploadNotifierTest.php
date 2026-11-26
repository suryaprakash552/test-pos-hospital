<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Notifications\SendNotification;
use App\Services\Import\BulkUploadNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BulkUploadNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_targets_only_the_uploading_employee_and_business(): void
    {
        Notification::fake();
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        BulkUploadNotifier::send($business->id, $user->id, 'sale', true, 5, 1);
        Notification::assertSentTo($user, SendNotification::class, function ($notification) use ($user) {
            $this->assertStringContainsString('5 rows imported, 1 rows rejected', $notification->toArray($user)['message']);
            return true;
        });
        Notification::assertCount(1);
        BulkUploadNotifier::send($business->id + 100000, $user->id, 'sale', false);
        Notification::assertCount(1);
    }
}
