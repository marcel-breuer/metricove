<?php

namespace App\Console\Commands;

use App\Services\Auth\MemberManagementService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class RecoverAdministrator extends Command
{
    protected $signature = 'metricove:recover-admin {userId : Existing household member ID to restore as an active administrator}';

    protected $description = 'Restore administrator access for an existing household member.';

    public function handle(MemberManagementService $members): int
    {
        $userId = filter_var($this->argument('userId'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($userId === false) {
            $this->error('The member ID must be a positive integer.');

            return self::INVALID;
        }

        try {
            $member = $members->recoverAdministrator($userId);
        } catch (ModelNotFoundException) {
            $this->error('No household member has that ID.');

            return self::FAILURE;
        }

        $this->info("Administrator access restored for member {$member->id}.");

        return self::SUCCESS;
    }
}
