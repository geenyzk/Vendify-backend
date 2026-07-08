<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = ['name', 'slug', 'is_staff'];

    protected $casts = [
        'is_staff' => 'boolean',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    // Accepts either identifier: legacy rows carry a display name
    // ("Customers") with the machine key in slug ("customers"); rows created
    // by the current seeder carry the key in both columns.
    public function hasPermission(string $name): bool
    {
        return $this->is_staff && $this->permissions->contains(
            fn (Permission $permission) => $permission->slug === $name || $permission->name === $name
        );
    }
}
