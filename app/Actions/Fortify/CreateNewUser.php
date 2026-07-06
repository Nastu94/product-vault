<?php

namespace App\Actions\Fortify;

use App\Models\Team;
use App\Models\User;
use App\Models\Plan;
use App\Models\TeamType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Jetstream\Jetstream;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => $this->passwordRules(),
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature() ? ['accepted', 'required'] : '',
        ])->validate();

        return DB::transaction(function () use ($input) {
            return tap(User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
            ]), function (User $user) {
                $this->createTeam($user);

                $team = $user->ownedTeams()
                    ->where('personal_team', true)
                    ->first();

                if ($team) {
                    // Assegna il piano gratuito al team personale.
                    $freePlan = Plan::query()
                        ->where('code', 'free')
                        ->first();

                    if ($freePlan) {
                        $team->forceFill([
                            'plan_id' => $freePlan->id,
                        ])->save();
                    }

                    // Assegna il tipo "personal" al workspace personale appena creato.
                    $personalTeamType = TeamType::query()
                        ->where('code', 'personal')
                        ->first();

                    if ($personalTeamType) {
                        $team->forceFill([
                            'team_type_id' => $personalTeamType->id,
                        ])->save();
                    }

                    // Permessi base necessari per iniziare il flusso documenti.
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

                    // Imposta il team personale Jetstream come contesto dei permessi Spatie.
                    app(PermissionRegistrar::class)->setPermissionsTeamId($team->id);

                    // Crea i permessi se non esistono ancora.
                    foreach ($permissions as $permissionName) {
                        Permission::firstOrCreate([
                            'name' => $permissionName,
                            'guard_name' => 'web',
                        ]);
                    }

                    // Crea il ruolo account_owner per il team appena creato.
                    $role = Role::firstOrCreate([
                        'name' => 'account_owner',
                        'guard_name' => 'web',
                        'team_id' => $team->id,
                    ]);

                    // Collega i permessi al ruolo owner del workspace.
                    $role->syncPermissions($permissions);

                    // Assegna il ruolo account_owner al nuovo utente.
                    $user->assignRole($role);

                    // Resetta il contesto team per evitare effetti collaterali.
                    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
                    app(PermissionRegistrar::class)->forgetCachedPermissions();
                }
            });
        });
    }

    /**
     * Create a personal team for the user.
     */
    protected function createTeam(User $user): void
    {
        $user->ownedTeams()->save(Team::forceCreate([
            'user_id' => $user->id,
            'name' => explode(' ', $user->name, 2)[0]."'s Team",
            'personal_team' => true,
        ]));
    }
}
