<?php

namespace Tests\Feature\Controllers;

use App\Models\Customer;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * @group integration
 * @group controllers
 */
class NotificationControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        if (!env('RUN_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Set RUN_INTEGRATION_TESTS=true to run.');
        }

        $this->user     = User::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->customer->users()->attach($this->user->id, ['role' => 'owner']);
    }

    public function test_notifications_page_returns_200(): void
    {
        $response = $this->actingAs($this->user)->get(route('notifications.index'));

        $response->assertStatus(200);
    }

    public function test_index_endpoint_returns_json(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/notifications');

        $response->assertStatus(200)
                 ->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_mark_as_read_updates_notification(): void
    {
        $notification = Notification::factory()->create([
            'user_id'   => $this->user->id,
            'read_at'   => null,
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/notifications/{$notification->id}/read");

        $response->assertStatus(200);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_mark_all_as_read_marks_all_for_user(): void
    {
        Notification::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'read_at' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/notifications/mark-all-read');

        $response->assertStatus(200);

        $unread = Notification::where('user_id', $this->user->id)
            ->whereNull('read_at')
            ->count();

        $this->assertSame(0, $unread);
    }

    public function test_destroy_removes_notification(): void
    {
        $notification = Notification::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/notifications/{$notification->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }
}
