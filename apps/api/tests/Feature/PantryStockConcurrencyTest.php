<?php

namespace Tests\Feature;

use App\Models\HouseholdSettings;
use App\Models\ModuleUserPermission;
use App\Models\PantryItem;
use App\Models\PantryStockMovement;
use App\Models\User;
use App\Services\Modules\ModuleManagementService;
use App\Services\Modules\ModuleStateService;
use App\Services\Pantry\PantryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;
use Throwable;

class PantryStockConcurrencyTest extends TestCase
{
    public function test_postgres_serializes_parallel_adjustments_using_the_same_item_version(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('This integration regression requires PostgreSQL.');
        }

        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Process control extensions are required for the pantry race regression.');
        }

        $readyDirectory = sys_get_temp_dir().'/metricove-pg-pantry-'.Str::random(12);
        $gatePath = $readyDirectory.'.go';
        $workerPids = [];
        $admin = null;
        $itemId = null;
        $wasEnabled = app(ModuleStateService::class)->isEnabled('pantry');
        mkdir($readyDirectory, 0700);

        try {
            $suffix = Str::lower(Str::random(12));
            HouseholdSettings::query()->whereKey(1)->update(['setup_completed_at' => now()]);
            $admin = User::factory()->create([
                'name' => 'Pantry Race Admin',
                'email' => "pantry-race-{$suffix}@example.test",
                'is_admin' => true,
                'is_active' => true,
            ]);
            ModuleUserPermission::query()->create([
                'user_id' => $admin->id,
                'module_key' => 'pantry',
                'can_view' => true,
                'can_create' => true,
                'can_edit' => true,
                'can_manage' => true,
            ]);
            app(ModuleManagementService::class)->setEnabled($admin, 'pantry', true);
            $item = app(PantryService::class)->create($admin, [
                'name' => 'Concurrent stock',
                'quantity' => '1.000',
                'unit' => 'kg',
                'location' => null,
                'best_before_on' => null,
                'visibility' => 'private',
            ]);
            $itemId = (int) $item['id'];

            foreach ([0, 1] as $worker) {
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
                        if (! is_file($gatePath)) {
                            file_put_contents($readyDirectory.'/'.$worker.'.result', 'error:barrier-timeout');
                        } else {
                            $actor = User::query()->findOrFail($admin->id);
                            app(PantryService::class)->adjust($actor, $itemId, [
                                'expected_version' => 1,
                                'quantity_delta' => '0.500',
                                'note' => null,
                            ]);
                            file_put_contents($readyDirectory.'/'.$worker.'.result', 'changed');
                        }
                    } catch (ConflictHttpException) {
                        file_put_contents($readyDirectory.'/'.$worker.'.result', 'conflict');
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
            $this->assertSame(['changed', 'conflict'], $results);

            DB::purge();
            $final = PantryItem::query()->findOrFail($itemId);
            $this->assertSame('1.500', (string) $final->quantity);
            $this->assertSame(2, $final->version);
            $this->assertSame(2, PantryStockMovement::query()->where('pantry_item_id', $itemId)->count());
        } finally {
            foreach ($workerPids as $pid) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }

            DB::purge();
            if ($admin instanceof User) {
                app(ModuleManagementService::class)->setEnabled($admin, 'pantry', $wasEnabled);
                if ($itemId !== null) {
                    PantryItem::query()->whereKey($itemId)->delete();
                }
                ModuleUserPermission::query()->where('user_id', $admin->id)->where('module_key', 'pantry')->delete();
                User::query()->whereKey($admin->id)->delete();
            }

            foreach (glob($readyDirectory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($readyDirectory);
            @unlink($gatePath);
        }
    }
}
