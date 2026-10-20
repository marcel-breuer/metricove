<?php

namespace App\Services\AI\Tools;

use App\Contracts\AssistantTool;
use App\Exceptions\InvalidAssistantToolCall;
use App\Models\User;
use App\Services\Finance\FinanceService;

class FinanceMonthSummaryTool implements AssistantTool
{
    public function __construct(private readonly FinanceService $finance) {}

    public function name(): string
    {
        return 'summarize_finance_month';
    }

    public function moduleKey(): string
    {
        return 'finance';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => 'Return visible household income and expense totals for one calendar month, separated by currency.',
                'strict' => true,
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['month' => ['type' => 'string', 'pattern' => '^\\d{4}-(0[1-9]|1[0-2])$']],
                    'required' => ['month'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * @param  array{month: string}  $arguments
     * @return array{month: string, totals: list<array{currency: string, income: string, expenses: string, net: string}>}
     */
    public function execute(User $actor, array $arguments): array
    {
        if (array_keys($arguments) !== ['month']
            || ! is_string($arguments['month'])
            || preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $arguments['month']) !== 1) {
            throw new InvalidAssistantToolCall('The finance tool received invalid arguments.');
        }

        $overview = $this->finance->overview($actor, $arguments['month']);

        return ['month' => $overview['month'], 'totals' => $overview['totals']];
    }
}
