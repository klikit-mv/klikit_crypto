<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    public const SUPER_ADMIN = 'super_admin';

    public const ADMIN = 'admin';

    public const MANAGER = 'manager';

    public const SUPPORT = 'support';

    public const PREMIUM_USER = 'premium_user';

    public const STANDARD_USER = 'standard_user';

    /** Roles that may enter the admin area. */
    public const STAFF = [self::SUPER_ADMIN, self::ADMIN, self::MANAGER, self::SUPPORT];

    protected $fillable = [
        'name',
        'label',
        'description',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')->withTimestamps();
    }
}
