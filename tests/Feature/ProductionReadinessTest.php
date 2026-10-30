<?php

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_sent(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_health_check_reports_dependencies(): void
    {
        $this->getJson('/health')->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.scheduler', 'warn');

        $this->artisan('schedule:test', ['--name' => 'scheduler-heartbeat'])->assertSuccessful();
        $this->getJson('/health')->assertJsonPath('checks.scheduler', 'ok');
    }

    public function test_horizon_requires_system_monitor_permission(): void
    {
        $this->seedPlatform();
        $this->actingAs($this->trader())->get('/horizon')->assertForbidden();
        $this->actingAs($this->staff(Role::ADMIN))->get('/horizon')->assertOk();
    }

    public function test_database_backup_is_written_and_pruned(): void
    {
        Storage::fake('backups');
        Storage::disk('backups')->put('db-old.sql.gz', 'x');
        touch(Storage::disk('backups')->path('db-old.sql.gz'), now()->subDays(30)->getTimestamp());
        $file = storage_path('framework/testing-db.sqlite');
        file_put_contents($file, 'SQLite format 3');
        config(['database.connections.backup_test' => ['driver' => 'sqlite', 'database' => $file]]);

        $this->artisan('backup:database', ['--connection' => 'backup_test'])->assertSuccessful();

        $files = Storage::disk('backups')->files();
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('db-2', $files[0]);
        $this->assertSame('SQLite format 3', gzdecode(Storage::disk('backups')->get($files[0])));
        @unlink($file);
    }
}
