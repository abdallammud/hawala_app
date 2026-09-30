<?php

namespace App\Models;

use App\Enums\LedgerAccountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CompanyAccount extends Model
{
    use LogsActivity;

    protected $fillable = ['name', 'location', 'is_active', 'ledger_account_id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Derived from the ledger's own owner columns rather than the
     * ledger_account_id FK: only the create form ever populated that column,
     * so an account made by a seeder or an import reported a $0 balance while
     * really holding money.
     */
    public function ledgerAccount(): MorphOne
    {
        return $this->morphOne(LedgerAccount::class, 'owner')
            ->where('type', LedgerAccountType::Company);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'location', 'is_active'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
