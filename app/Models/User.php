<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    public const CLOSED = 'closed';

    /**
     * `status` is deliberately not mass assignable: only administrators change it.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** Mirrors the column default so new instances are active before being re-read. */
    protected $attributes = [
        'status' => self::ACTIVE,
    ];

    /** Per-instance cache of permission names, cleared by flushAccessCache(). */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->withTimestamps();
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(UserSetting::class);
    }

    public function riskProfile(): HasOne
    {
        return $this->hasOne(RiskProfile::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->currentlyActive()->latestOfMany('expires_at');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function exchangeAccounts(): HasMany
    {
        return $this->hasMany(ExchangeAccount::class);
    }

    public function brokerAccounts(): HasMany
    {
        return $this->hasMany(BrokerAccount::class);
    }

    public function tradingBots(): HasMany
    {
        return $this->hasMany(TradingBot::class);
    }

    public function miningDevices(): HasMany
    {
        return $this->hasMany(MiningDevice::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function hasRole(string ...$roles): bool
    {
        return $this->roles->whereIn('name', $roles)->isNotEmpty();
    }

    public function isStaff(): bool
    {
        return $this->hasRole(...Role::STAFF);
    }

    public function hasPermission(string $permission): bool
    {
        $this->permissionCache ??= $this->roles()
            ->with('permissions:id,name')
            ->get()
            ->flatMap->permissions
            ->pluck('name')
            ->unique()
            ->values()
            ->all();

        return in_array($permission, $this->permissionCache, true);
    }

    public function assignRole(string $role): void
    {
        $this->roles()->syncWithoutDetaching([Role::where('name', $role)->firstOrFail()->id]);
        $this->flushAccessCache();
    }

    public function flushAccessCache(): void
    {
        $this->permissionCache = null;
        $this->unsetRelation('roles');
    }
}
