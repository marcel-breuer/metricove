<?php

use App\Models\McpAccessToken;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('module_states')->whereIn('module_key', ['finance', 'import'])->delete();
        DB::table('module_user_permissions')->whereIn('module_key', ['finance', 'import'])->delete();
        DB::table('household_reminders')->where('source_module', 'finance')->delete();

        DB::table('users')->whereNotNull('reminder_sources')->orderBy('id')->chunkById(100, function ($users): void {
            foreach ($users as $user) {
                $sources = json_decode((string) $user->reminder_sources, true);
                if (! is_array($sources) || ! array_key_exists('finance', $sources)) {
                    continue;
                }

                unset($sources['finance']);
                DB::table('users')->where('id', $user->id)->update(['reminder_sources' => json_encode($sources, JSON_THROW_ON_ERROR)]);
            }
        });

        foreach (McpAccessToken::query()->orderBy('id')->cursor() as $token) {
            $scopes = array_values(array_filter(
                $token->scopes ?? [],
                static fn (string $scope): bool => ! str_starts_with($scope, 'finance:') && ! str_starts_with($scope, 'import:'),
            ));
            $token->forceFill(['scopes' => $scopes])->save();
        }

        DB::table('receipt_documents')->whereNotNull('financial_transaction_id')->update(['financial_transaction_id' => null]);
    }

    public function down(): void
    {
        // Access grants and receipt links are intentionally not reconstructed after module removal.
    }
};
