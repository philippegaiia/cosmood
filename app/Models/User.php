<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\ProductionStatus;
use EslamRedaDiv\FilamentCopilot\Concerns\HasCopilotChat;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    private const ROLE_MANAGER = 'manager';

    private const ROLE_OPERATOR = 'operator';

    private const ROLE_PLANNER = 'planner';

    use HasCopilotChat;
    use HasFactory;
    use HasRoles;
    use Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Only operational roles may enter the Filament admin panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasOperationalRole([
            self::ROLE_OPERATOR,
            self::ROLE_PLANNER,
            self::ROLE_MANAGER,
        ]);
    }

    /**
     * Whether the user can move a production into execution.
     */
    public function canStartProductionRuns(): bool
    {
        return $this->hasOperationalRole([
            self::ROLE_OPERATOR,
            self::ROLE_PLANNER,
            self::ROLE_MANAGER,
        ]);
    }

    /**
     * Whether the user can manage production planning decisions such as
     * confirming and rescheduling batches.
     */
    public function canManageProductionPlanning(): bool
    {
        return $this->hasOperationalRole([
            self::ROLE_PLANNER,
            self::ROLE_MANAGER,
        ]);
    }

    /**
     * Whether the user can close a production with final outputs declared.
     */
    public function canFinishProductionRuns(): bool
    {
        return $this->canManageProductionPlanning();
    }

    /**
     * Whether the user can permanently delete pre-start productions.
     */
    public function canDeleteProductionRuns(): bool
    {
        return $this->hasOperationalRole([
            self::ROLE_MANAGER,
        ]);
    }

    /**
     * Whether the user can approve, start, complete, or cancel waves.
     */
    public function canManageWaveLifecycle(): bool
    {
        return $this->hasOperationalRole([
            self::ROLE_MANAGER,
        ]);
    }

    /**
     * Whether the user can permanently delete waves through the guarded service.
     */
    public function canDeleteWaves(): bool
    {
        return $this->canManageWaveLifecycle();
    }

    /**
     * Whether the user can perform inventory-affecting stock lot operations.
     */
    public function canManageSupplyInventory(): bool
    {
        return $this->hasOperationalRole([
            self::ROLE_MANAGER,
        ]);
    }

    /**
     * Whether the user can access stock ledger and manual stock interventions.
     */
    public function canAccessStockMovements(): bool
    {
        return $this->canManageSupplyInventory();
    }

    /**
     * Whether the user may receive supplier order lines into stock.
     */
    public function canReceiveSupplierOrdersIntoStock(): bool
    {
        return $this->canManageSupplyInventory();
    }

    /**
     * Whether the user may select the given production status transition in UI.
     */
    public function canSetProductionStatus(ProductionStatus $from, ProductionStatus $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return match ($to) {
            ProductionStatus::Ongoing => $this->canStartProductionRuns(),
            ProductionStatus::Finished => $this->canFinishProductionRuns(),
            default => $this->canManageProductionPlanning(),
        };
    }

    /**
     * Whether the user may select the given supplier order status transition in UI.
     */
    public function canSetSupplierOrderStatus(OrderStatus $from, OrderStatus $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return match ($to) {
            OrderStatus::Delivered, OrderStatus::Checked => $this->canReceiveSupplierOrdersIntoStock(),
            default => $this->canManageProductionPlanning(),
        };
    }

    /**
     * Whether the user may override an active edit presence lock.
     */
    public function canForceReleaseResourceLocks(): bool
    {
        return $this->hasOperationalRole([
            self::ROLE_MANAGER,
        ]);
    }

    /**
     * Super admin role remains outside the operational matrix and bypasses
     * all explicit action checks in the Filament panel.
     *
     * @param  array<int, string>  $roles
     */
    private function hasOperationalRole(array $roles): bool
    {
        return $this->hasRole(config('filament-shield.super_admin.name', 'super_admin'))
            || $this->hasAnyRole($roles);
    }
}
