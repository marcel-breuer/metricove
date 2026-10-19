<?php

namespace App\Services\Setup;

use App\Exceptions\SetupAlreadyCompleted;
use App\Models\HouseholdSettings;
use App\Models\User;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class CompleteInitialSetup
{
    /**
     * @param  array{name: string, email: string, password: string}  $attributes
     */
    public function complete(array $attributes): User
    {
        $create = function () use ($attributes): User {
            $settings = HouseholdSettings::query()
                ->whereKey(1)
                ->lockForUpdate()
                ->firstOrFail();

            if ($settings->setup_completed_at !== null || User::query()->exists()) {
                throw new SetupAlreadyCompleted;
            }

            // The conditional update is an atomic one-time claim. It also
            // serializes concurrent requests on databases without row locks.
            $now = now();
            $claimed = HouseholdSettings::query()
                ->whereKey(1)
                ->whereNull('setup_completed_at')
                ->update([
                    'setup_completed_at' => $now,
                    'updated_at' => $now,
                ]);

            if ($claimed !== 1) {
                throw new SetupAlreadyCompleted;
            }

            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
            ]);

            $user->is_admin = true;
            $user->is_active = true;
            $user->save();

            return $user;
        };

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction($create, 3);
            } catch (Throwable $exception) {
                $isConcurrencyError = $exception instanceof QueryException
                    && (new ConcurrencyErrorDetector)->causedByConcurrencyError($exception);

                if (! $isConcurrencyError || $attempt >= 3) {
                    throw $exception;
                }

                usleep(random_int(10000, 50000) * $attempt);
            }
        }
    }
}
