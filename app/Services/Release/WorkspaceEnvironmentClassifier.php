<?php

namespace App\Services\Release;

use App\Models\Team;

final class WorkspaceEnvironmentClassifier
{
    /**
     * @return array<string, mixed>
     */
    public function classify(Team $team): array
    {
        $matches = [];
        $name = (string) $team->name;

        foreach (
            config('release_readiness.fixture_workspace_patterns', [])
            as $pattern
        ) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            if (@preg_match($pattern, $name) === 1) {
                $matches[] = $pattern;
            }
        }

        $isFixtureLike = $matches !== [];

        return [
            'team_id' => (int) $team->getKey(),
            'workspace' => $name,
            'scope' => $isFixtureLike ? 'fixture_like' : 'application',
            'is_fixture_like' => $isFixtureLike,
            'matched_patterns' => $matches,
        ];
    }

    public function isFixtureLike(Team $team): bool
    {
        return (bool) $this->classify($team)['is_fixture_like'];
    }
}
