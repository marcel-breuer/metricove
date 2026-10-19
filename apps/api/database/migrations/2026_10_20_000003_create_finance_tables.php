<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_private')->default(true);
            $table->string('type', 12);
            $table->string('name', 80);
            $table->timestampsTz();
            $table->index(['is_private', 'owner_user_id']);
            $table->index(['type', 'name']);
        });

        Schema::create('finance_recurring_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finance_category_id')->constrained('finance_categories')->restrictOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_private')->default(true);
            $table->string('type', 12);
            $table->string('description', 200);
            $table->decimal('amount', 18, 2);
            $table->char('currency', 3);
            $table->string('frequency', 12);
            $table->date('starts_on');
            $table->date('next_due_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->index(['is_private', 'owner_user_id']);
            $table->index(['is_active', 'next_due_on']);
        });

        Schema::create('finance_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finance_category_id')->constrained('finance_categories')->restrictOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_private')->default(true);
            $table->decimal('amount', 18, 2);
            $table->char('currency', 3);
            $table->date('period_start');
            $table->timestampsTz();
            $table->index(['is_private', 'owner_user_id']);
            $table->index(['period_start', 'currency']);
        });

        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finance_category_id')->constrained('finance_categories')->restrictOnDelete();
            $table->foreignId('finance_recurring_entry_id')->nullable()->constrained('finance_recurring_entries')->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_private')->default(true);
            $table->string('type', 12);
            $table->string('description', 200);
            $table->decimal('amount', 18, 2);
            $table->char('currency', 3);
            $table->date('occurred_on');
            $table->date('recurring_occurrence_on')->nullable();
            $table->timestampsTz();
            $table->index(['is_private', 'owner_user_id']);
            $table->index(['occurred_on', 'id']);
            $table->index(['finance_category_id', 'currency', 'occurred_on']);
            $table->unique(['finance_recurring_entry_id', 'recurring_occurrence_on'], 'finance_recurring_occurrence_unique');
        });

        DB::statement('CREATE UNIQUE INDEX finance_categories_private_name_unique ON finance_categories (owner_user_id, type, lower(name)) WHERE is_private = true');
        DB::statement('CREATE UNIQUE INDEX finance_categories_shared_name_unique ON finance_categories (type, lower(name)) WHERE is_private = false');
        DB::statement('CREATE UNIQUE INDEX finance_budgets_private_period_unique ON finance_budgets (owner_user_id, finance_category_id, period_start, currency) WHERE is_private = true');
        DB::statement('CREATE UNIQUE INDEX finance_budgets_shared_period_unique ON finance_budgets (finance_category_id, period_start, currency) WHERE is_private = false');

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE finance_categories ADD CONSTRAINT finance_categories_owner_visibility_check CHECK ((is_private AND owner_user_id IS NOT NULL) OR (NOT is_private AND owner_user_id IS NULL))');
            DB::statement("ALTER TABLE finance_categories ADD CONSTRAINT finance_categories_type_check CHECK (type IN ('income', 'expense'))");
            DB::statement('ALTER TABLE finance_recurring_entries ADD CONSTRAINT finance_recurring_owner_visibility_check CHECK ((is_private AND owner_user_id IS NOT NULL) OR (NOT is_private AND owner_user_id IS NULL))');
            DB::statement("ALTER TABLE finance_recurring_entries ADD CONSTRAINT finance_recurring_type_check CHECK (type IN ('income', 'expense'))");
            DB::statement("ALTER TABLE finance_recurring_entries ADD CONSTRAINT finance_recurring_frequency_check CHECK (frequency IN ('weekly', 'monthly', 'quarterly', 'yearly'))");
            DB::statement('ALTER TABLE finance_recurring_entries ADD CONSTRAINT finance_recurring_amount_check CHECK (amount > 0)');
            DB::statement("ALTER TABLE finance_recurring_entries ADD CONSTRAINT finance_recurring_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
            DB::statement('ALTER TABLE finance_recurring_entries ADD CONSTRAINT finance_recurring_dates_check CHECK ((ends_on IS NULL OR ends_on >= starts_on) AND (next_due_on IS NULL OR next_due_on >= starts_on))');
            DB::statement('ALTER TABLE finance_budgets ADD CONSTRAINT finance_budgets_owner_visibility_check CHECK ((is_private AND owner_user_id IS NOT NULL) OR (NOT is_private AND owner_user_id IS NULL))');
            DB::statement('ALTER TABLE finance_budgets ADD CONSTRAINT finance_budgets_amount_check CHECK (amount > 0)');
            DB::statement("ALTER TABLE finance_budgets ADD CONSTRAINT finance_budgets_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
            DB::statement('ALTER TABLE finance_budgets ADD CONSTRAINT finance_budgets_month_start_check CHECK (EXTRACT(DAY FROM period_start) = 1)');
            DB::statement('ALTER TABLE financial_transactions ADD CONSTRAINT financial_transactions_owner_visibility_check CHECK ((is_private AND owner_user_id IS NOT NULL) OR (NOT is_private AND owner_user_id IS NULL))');
            DB::statement("ALTER TABLE financial_transactions ADD CONSTRAINT financial_transactions_type_check CHECK (type IN ('income', 'expense'))");
            DB::statement('ALTER TABLE financial_transactions ADD CONSTRAINT financial_transactions_amount_check CHECK (amount > 0)');
            DB::statement("ALTER TABLE financial_transactions ADD CONSTRAINT financial_transactions_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS finance_budgets_shared_period_unique');
        DB::statement('DROP INDEX IF EXISTS finance_budgets_private_period_unique');
        DB::statement('DROP INDEX IF EXISTS finance_categories_shared_name_unique');
        DB::statement('DROP INDEX IF EXISTS finance_categories_private_name_unique');

        Schema::dropIfExists('financial_transactions');
        Schema::dropIfExists('finance_budgets');
        Schema::dropIfExists('finance_recurring_entries');
        Schema::dropIfExists('finance_categories');
    }
};
