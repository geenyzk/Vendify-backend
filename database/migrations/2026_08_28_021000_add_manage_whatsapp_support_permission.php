<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(
            ['slug' => 'manage_whatsapp_support'],
            ['name' => 'Manage WhatsApp Support', 'description' => 'Manage WhatsApp support agents and routing availability'],
        );

        Role::whereIn('slug', ['owner', 'co-owner'])->get()
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->id]));
    }

    public function down(): void
    {
        // Non-destructive: the permission may have been assigned to custom roles.
    }
};
