<?php

namespace App\Models;

use App\Contracts\ModuleResource;
use App\Models\Concerns\HasResourcePermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptDocument extends Model implements ModuleResource
{
    use HasResourcePermissions;

    protected $fillable = [
        'owner_user_id',
        'is_private',
        'financial_transaction_id',
        'vehicle_cost_id',
        'file_path',
        'mime_type',
        'merchant',
        'description',
        'invoice_number',
        'invoice_date',
        'amount',
        'currency',
        'tax_amount',
        'fuel_liters',
        'fuel_unit_price',
        'fuel_odometer_value',
        'idempotency_key',
        'file_digest',
        'request_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'invoice_date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'fuel_liters' => 'decimal:3',
            'fuel_unit_price' => 'decimal:4',
            'fuel_odometer_value' => 'decimal:3',
        ];
    }

    public function financialTransaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class);
    }

    public function vehicleCost(): BelongsTo
    {
        return $this->belongsTo(VehicleCost::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function moduleKey(): string
    {
        return 'receipts';
    }

    public function ownerUserId(): ?int
    {
        return (int) $this->owner_user_id;
    }

    public function isPrivate(): bool
    {
        return (bool) $this->is_private;
    }
}
