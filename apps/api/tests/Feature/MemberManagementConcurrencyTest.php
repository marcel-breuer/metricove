<?php

namespace Tests\Feature;

use App\Exceptions\LastActiveAdministrator;
use App\Models\HouseholdAuditEvent;
use App\Models\HouseholdSettings;
use App\Models\User;
use App\Services\Auth\MemberManagementService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

class MemberManagementConcurrencyTest extends TestCase
{
    public function test_parallel_admin_demotion_and_removal_leave_one_active_administrator(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Process control extensions are required for the administrator race regression.');
        }

        $databasePath = tempnam(sys_get_temp_dir(), 'metricove-admin-');
        $readyDirectory = $databasePath.'.ready';
        $gatePath = $databasePath.'.go';
        $workerPids = [];
        $originalConnection = config('database.default');
        $originalDatabasePath = config('database.connections.sqlite.database');
        $originalBusyTimeout = config('database.connections.sqlite.busy_timeout');

        mkdir($readyDirectory, 0700);

        try {
            config([
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => $databasePath,
                'database.connections.sqlite.busy_timeout' => 10000,
            ]);
            DB::purge();
            DB::setDefaultConnection('sqlite');
            Artisan::call('migrate:fresh', ['--force' => true]);
            DB::purge();

            HouseholdSettings::query()->whereKey(1)->update(['setup_completed_at' => now()]);
            $admins = collect(range(1, 2))->map(fn (int $index): User => User::factory()->create([
                'name' => 'Admin '.$index,
                'email' => "admin-{$index}@example.test",
                'password' => Str::random(32),
                'is_admin' => true,
                'is_active' => true,
            ]));

            foreach ($admins as $worker => $admin) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    $this->fail('Could not create both concurrent member-management workers.');
                }

                if ($pid === 0) {
                    DB::purge();
                    file_put_contents($readyDirectory.'/'.$worker, 'ready');

                    $deadline = microtime(true) + 10;
                    while (! is_file($gatePath) && microtime(true) < $deadline) {
                        usleep(10000);
                    }

                    try {
                        if (! is_file($gatePath)) {
                            file_put_contents($readyDirectory.'/'.$worker.'.result', 'error:barrier-timeout');
                        } elseif ($worker === 0) {
                            app(MemberManagementService::class)->update($admin, $admin->id, ['is_admin' => false]);
                            file_put_contents($readyDirectory.'/'.$worker.'.result', 'changed');
                        } else {
                            app(MemberManagementService::class)->remove($admin, $admin->id);
                            file_put_contents($readyDirectory.'/'.$worker.'.result', 'changed');
                        }
                    } catch (LastActiveAdministrator) {
                        file_put_contents($readyDirectory.'/'.$worker.'.result', 'blocked');
                    } catch (Throwable $exception) {
                        $driverCode = $exception instanceof QueryException
                            ? ($exception->errorInfo[1] ?? $exception->getCode())
                            : $exception->getCode();

                        file_put_contents(
                            $readyDirectory.'/'.$worker.'.result',
                            'error:'.$exception::class.':'.$driverCode,
                        );
                    }

                    posix_kill(getmypid(), SIGKILL);
                }

                $workerPids[] = $pid;
            }

            $deadline = microtime(true) + 10;
            while (count(glob($readyDirectory.'/*')) < 2 && microtime(true) < $deadline) {
                usleep(10000);
            }

            $this->assertCount(2, glob($readyDirectory.'/*'), 'Both workers should reach the start barrier.');
            file_put_contents($gatePath, 'go');

            foreach ($workerPids as $pid) {
                pcntl_waitpid($pid, $status);
            }
            $workerPids = [];

            $results = [
                file_get_contents($readyDirectory.'/0.result'),
                file_get_contents($readyDirectory.'/1.result'),
            ];
            sort($results);

            $this->assertSame(['blocked', 'changed'], $results);

            DB::purge();

            $this->assertSame(1, User::query()->where('is_admin', true)->where('is_active', true)->count());
            $this->assertContains(User::query()->count(), [1, 2]);
            $this->assertDatabaseCount('household_audit_events', 1);
        } finally {
            foreach ($workerPids as $pid) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }

            DB::purge();
            DB::setDefaultConnection($originalConnection);
            config([
                'database.connections.sqlite.database' => $originalDatabasePath,
                'database.connections.sqlite.busy_timeout' => $originalBusyTimeout,
            ]);

            foreach (glob($readyDirectory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($readyDirectory);
            @unlink($gatePath);
            @unlink($databasePath);
        }
    }

    public function test_postgres_row_lock_serializes_parallel_admin_demotion_and_removal(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('This integration regression requires PostgreSQL.');
        }

        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Process control extensions are required for the PostgreSQL race regression.');
        }

        $readyDirectory = sys_get_temp_dir().'/metricove-pg-admin-'.Str::random(12);
        $gatePath = $readyDirectory.'.go';
        $workerPids = [];
        $adminIds = [];
        mkdir($readyDirectory, 0700);

        try {
            $suffix = Str::lower(Str::random(12));
            foreach (range(1, 2) as $index) {
                $adminIds[] = User::factory()->create([
                    'name' => 'Postgres Admin '.$index,
                    'email' => "pg-admin-{$index}-{$suffix}@example.test",
                    'password' => Str::random(32),
                    'is_admin' => true,
                    'is_active' => true,
                ])->id;
            }

            foreach ($adminIds as $worker => $adminId) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    $this->fail('Could not create both concurrent PostgreSQL workers.');
                }

                if ($pid === 0) {
                    DB::purge();
                    file_put_contents($readyDirectory.'/'.$worker, 'ready');

                    $deadline = microtime(true) + 10;
                    while (! is_file($gatePath) && microtime(true) < $deadline) {
                        usleep(10000);
                    }

                    try {
                        $admin = User::query()->findOrFail($adminId);
                        if ($worker === 0) {
                            app(MemberManagementService::class)->update($admin, $adminId, ['is_admin' => false]);
                        } else {
                            app(MemberManagementService::class)->remove($admin, $adminId);
                        }

                        file_put_contents($readyDirectory.'/'.$worker.'.result', 'changed');
                    } catch (LastActiveAdministrator) {
                        file_put_contents($readyDirectory.'/'.$worker.'.result', 'blocked');
                    } catch (Throwable $exception) {
                        file_put_contents($readyDirectory.'/'.$worker.'.result', 'error:'.$exception::class);
                    }

                    posix_kill(getmypid(), SIGKILL);
                }

                $workerPids[] = $pid;
            }

            $deadline = microtime(true) + 10;
            while (count(glob($readyDirectory.'/*')) < 2 && microtime(true) < $deadline) {
                usleep(10000);
            }

            $this->assertCount(2, glob($readyDirectory.'/*'), 'Both PostgreSQL workers should reach the start barrier.');
            file_put_contents($gatePath, 'go');

            foreach ($workerPids as $pid) {
                pcntl_waitpid($pid, $status);
            }
            $workerPids = [];

            $results = [
                file_get_contents($readyDirectory.'/0.result'),
                file_get_contents($readyDirectory.'/1.result'),
            ];
            sort($results);

            $this->assertSame(['blocked', 'changed'], $results);

            DB::purge();

            $this->assertSame(1, User::query()
                ->whereIn('id', $adminIds)
                ->where('is_admin', true)
                ->where('is_active', true)
                ->count());
            $this->assertSame(1, HouseholdAuditEvent::query()->whereIn('subject_user_id', $adminIds)->count());
        } finally {
            foreach ($workerPids as $pid) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }

            DB::purge();
            HouseholdAuditEvent::query()->whereIn('subject_user_id', $adminIds)->delete();
            User::query()->whereIn('id', $adminIds)->delete();

            foreach (glob($readyDirectory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($readyDirectory);
            @unlink($gatePath);
        }
    }
}
