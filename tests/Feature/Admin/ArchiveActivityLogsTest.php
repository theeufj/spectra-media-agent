<?php

namespace Tests\Feature\Admin;

use App\Jobs\ArchiveActivityLogs;
use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Old audit logs move to storage; they do not simply disappear.
 *
 * The admin audit log now records every mutating request across nineteen
 * controllers, so the table grows steadily and the console only needs the recent
 * end of it. But this is the record of who changed what — deleting a row without
 * a durable copy would be a worse version of every bug found in this codebase,
 * because nothing afterwards would reveal the loss.
 *
 * The order is therefore write, verify, delete. Every test here is really about
 * that order.
 */
class ArchiveActivityLogsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The test environment carries real S3 credentials, so StorageHelper
        // would take its S3 branch and write into the live bucket. Clearing the
        // bucket config forces the local disk, which Storage::fake can hold.
        config([
            'filesystems.disks.s3.bucket' => null,
            'filesystems.disks.s3.key' => null,
            'activity.retention_days' => 30,
        ]);

        Storage::fake('public');
    }

    private function logAt(string $date, string $action = 'admin.settings.update'): ActivityLog
    {
        // activity_logs.user_id is a real foreign key, so the actor has to exist.
        $user = \App\Models\User::factory()->create();

        $log = ActivityLog::create([
            'user_id' => $user->id,
            'user_name' => 'Ops Admin',
            'user_email' => 'ops@example.com',
            'action' => $action,
            'description' => 'did a thing',
            'properties' => ['input' => ['x' => 'y']],
            'ip_address' => '127.0.0.1',
        ]);

        // created_at is not fillable, so passing it to create() is silently
        // ignored and every row would be stamped today — which would make this
        // whole suite pass against a job that does nothing.
        ActivityLog::where('id', $log->id)->update(['created_at' => $date, 'updated_at' => $date]);

        return $log->refresh();
    }

    public function test_logs_inside_the_window_are_left_alone(): void
    {
        $recent = $this->logAt(now()->subDays(5)->toDateTimeString());

        (new ArchiveActivityLogs)->handle();

        $this->assertDatabaseHas('activity_logs', ['id' => $recent->id]);
    }

    public function test_logs_older_than_the_window_are_written_to_storage(): void
    {
        $this->logAt(now()->subDays(40)->setTime(9, 0)->toDateTimeString());

        (new ArchiveActivityLogs)->handle();

        $day = now()->subDays(40)->toDateString();
        Storage::disk('public')->assertExists("activity-logs/{$day}.jsonl.gz");
    }

    public function test_archived_rows_are_removed_from_the_table(): void
    {
        $old = $this->logAt(now()->subDays(40)->toDateTimeString());

        (new ArchiveActivityLogs)->handle();

        $this->assertDatabaseMissing('activity_logs', ['id' => $old->id]);
    }

    public function test_the_archive_contains_the_actual_records(): void
    {
        // An archive that exists but is empty is the same as no archive, and
        // would be indistinguishable from success.
        $this->logAt(now()->subDays(40)->toDateTimeString(), 'admin.mcc-accounts.update');

        (new ArchiveActivityLogs)->handle();

        $day = now()->subDays(40)->toDateString();
        $decoded = gzdecode(Storage::disk('public')->get("activity-logs/{$day}.jsonl.gz"));

        $this->assertStringContainsString('admin.mcc-accounts.update', $decoded);
        $this->assertStringContainsString('ops@example.com', $decoded);
    }

    public function test_a_failed_upload_leaves_the_rows_in_place(): void
    {
        // The property that matters most. If the write fails, the only copy of
        // who did what must still be in the database for the next run.
        $old = $this->logAt(now()->subDays(40)->toDateTimeString());

        Storage::shouldReceive('disk')->andThrow(new \RuntimeException('storage unavailable'));

        (new ArchiveActivityLogs)->handle();

        $this->assertDatabaseHas('activity_logs', ['id' => $old->id]);
    }

    public function test_each_day_becomes_its_own_file(): void
    {
        // The granularity anyone investigating an incident actually asks for.
        $this->logAt(now()->subDays(40)->toDateTimeString());
        $this->logAt(now()->subDays(41)->toDateTimeString());

        (new ArchiveActivityLogs)->handle();

        Storage::disk('public')->assertExists('activity-logs/'.now()->subDays(40)->toDateString().'.jsonl.gz');
        Storage::disk('public')->assertExists('activity-logs/'.now()->subDays(41)->toDateString().'.jsonl.gz');
    }

    public function test_the_retention_window_is_configurable(): void
    {
        $sevenDaysOld = $this->logAt(now()->subDays(7)->toDateTimeString());

        (new ArchiveActivityLogs(3))->handle();

        $this->assertDatabaseMissing('activity_logs', ['id' => $sevenDaysOld->id]);
    }

    public function test_running_twice_is_harmless(): void
    {
        // The scheduler will retry, and a second pass must not fail or lose the
        // rows it already moved.
        $this->logAt(now()->subDays(40)->toDateTimeString());

        (new ArchiveActivityLogs)->handle();
        (new ArchiveActivityLogs)->handle();

        $this->assertSame(0, ActivityLog::count());
        Storage::disk('public')->assertExists('activity-logs/'.now()->subDays(40)->toDateString().'.jsonl.gz');
    }
}
