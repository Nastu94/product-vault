<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    /**
     * Crea i primi permessi e assegna il ruolo account_owner
     * al proprietario di ogni team/workspace Jetstream.
     */
    public function run(): void
    {
        $permissions = [
            'documents.view',
            'documents.upload',
            'documents.update',
            'documents.delete',
            'documents.review',

            'products.view',
            'products.create',
            'products.update',
            'products.delete',

            'product_cases.view',
            'product_cases.create',
            'product_cases.update',
            'product_cases.close',
            'product_cases.delete',

            'warranties.view',
            'warranties.create',
            'warranties.update',
            'warranties.delete',

            'barcodes.create',
            'barcodes.delete',

            'account.members.view',
            'account.members.invite',
            'account.members.remove',
            'account.settings.update',
        ];

        foreach ($permissions as $permissionName) {
            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
        }

        Team::query()->each(function (Team $team) use ($permissions) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($team->id);

            $role = Role::firstOrCreate([
                'name' => 'account_owner',
                'guard_name' => 'web',
                'team_id' => $team->id,
            ]);

            $role->syncPermissions($permissions);

            $owner = User::find($team->user_id);

            if ($owner) {
                $owner->unsetRelation('roles');
                $owner->assignRole($role);
            }
        });

        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}