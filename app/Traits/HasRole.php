<?php

namespace App\Traits;

use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait HasRole
{
    /**
     * Get the role associated with the user.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Assign a role to the user by name or slug.
     */
    public function assignRole($roleNameOrSlug): self
    {
        $role = Role::where('name', $roleNameOrSlug)
            ->orWhere('slug', $roleNameOrSlug)
            ->first();

        if ($role) {
            $this->update(['role_id' => $role->id]);
        }

        return $this;
    }

    /**
     * Check if user has a specific role.
     */
    public function hasRole($roleNameOrSlug): bool
    {
        if (!$this->role) {
            return false;
        }

        return $this->role->name === $roleNameOrSlug || $this->role->slug === $roleNameOrSlug;
    }

    /**
     * Check if user has any of the given roles.
     */
    public function hasAnyRole(...$roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the role name.
     */
    public function getRoleName(): ?string
    {
        return $this->role?->name;
    }

    /**
     * Get the role slug.
     */
    public function getRoleSlug(): ?string
    {
        return $this->role?->slug;
    }

    /**
     * Remove the role from the user.
     */
    public function removeRole(): self
    {
        $this->update(['role_id' => null]);

        return $this;
    }
}
