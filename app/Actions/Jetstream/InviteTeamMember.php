<?php

namespace App\Actions\Jetstream;

use App\Exceptions\Monetization\PlanLimitExceededException;
use App\Models\Team;
use App\Models\User;
use App\Services\Monetization\PlanLimitDecisionService;
use App\Support\Monetization\MonetizationKeys;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\InvitesTeamMembers;
use Laravel\Jetstream\Events\InvitingTeamMember;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Mail\TeamInvitation;
use Laravel\Jetstream\Rules\Role;

class InviteTeamMember implements InvitesTeamMembers
{
    public function __construct(
        private readonly PlanLimitDecisionService $limitDecisionService
    ) {
    }

    public function invite(
        User $user,
        Team $team,
        string $email,
        ?string $role = null
    ): void {
        Gate::forUser($user)->authorize('addTeamMember', $team);

        try {
            $this->limitDecisionService->ensureCanConsume(
                $team,
                MonetizationKeys::LIMIT_MAX_TEAM_MEMBERS,
                1
            );
        } catch (PlanLimitExceededException $exception) {
            throw ValidationException::withMessages([
                'email' => [$exception->getMessage()],
            ])->errorBag('addTeamMember');
        }

        $this->validate($team, $email, $role);

        InvitingTeamMember::dispatch($team, $email, $role);

        $invitation = $team->teamInvitations()->create([
            'email' => $email,
            'role' => $role,
        ]);

        Mail::to($email)->send(new TeamInvitation($invitation));
    }

    protected function validate(
        Team $team,
        string $email,
        ?string $role
    ): void {
        Validator::make([
            'email' => $email,
            'role' => $role,
        ], $this->rules($team), [
            'email.unique' => __(
                'This user has already been invited to the team.'
            ),
        ])->after(
            $this->ensureUserIsNotAlreadyOnTeam($team, $email)
        )->validateWithBag('addTeamMember');
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    protected function rules(Team $team): array
    {
        return array_filter([
            'email' => [
                'required',
                'email',
                Rule::unique(
                    Jetstream::teamInvitationModel()
                )->where(function (Builder $query) use ($team) {
                    $query->where('team_id', $team->id);
                }),
            ],
            'role' => Jetstream::hasRoles()
                ? ['required', 'string', new Role]
                : null,
        ]);
    }

    protected function ensureUserIsNotAlreadyOnTeam(
        Team $team,
        string $email
    ): Closure {
        return function ($validator) use ($team, $email): void {
            $validator->errors()->addIf(
                $team->hasUserWithEmail($email),
                'email',
                __('This user already belongs to the team.')
            );
        };
    }
}
