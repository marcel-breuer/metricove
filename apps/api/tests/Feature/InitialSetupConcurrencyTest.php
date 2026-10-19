<?php

namespace Tests\Feature;

use App\Exceptions\SetupAlreadyCompleted;
use App\Models\HouseholdSettings;
use App\Models\User;
use App\Services\Setup\CompleteInitialSetup;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

class InitialSetupConcurrencyTest extends TestCase
{
    public function test_parallel_first_registration_attempts_create_only_one_active_admin(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Process control extensions are required for the setup race regression.');
        }

        $databasePath = tempnam(sys_get_temp_dir(), 'metricove-setup-');
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

            for ($worker = 0; $worker < 2; $worker++) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    $this->fail('Could not create both concurrent setup workers.');
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
                            app(CompleteInitialSetup::class)->complete([
                                'name' => 'Setup '.$worker,
                                'email' => 'setup-'.$worker.'@example.test',
                                'password' => Str::random(32),
                            ]);

                            file_put_contents($readyDirectory.'/'.$worker.'.result', 'created');
                        }
                    } catch (SetupAlreadyCompleted) {
                        file_put_contents($readyDirectory.'/'.$worker.'.result', 'conflict');
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

            $this->assertSame(['conflict', 'created'], $results);

            DB::purge();

            $this->assertSame(1, User::query()->count());
            $this->assertSame(1, User::query()->where('is_admin', true)->where('is_active', true)->count());
            $this->assertNotNull(HouseholdSettings::query()->sole()->setup_completed_at);
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
            unlink($gatePath);
            unlink($databasePath);
        }
    }
}
