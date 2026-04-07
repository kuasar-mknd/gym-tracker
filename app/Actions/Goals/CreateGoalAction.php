<?php

declare(strict_types=1);

namespace App\Actions\Goals;

use App\Models\Goal;
use App\Models\User;
use App\Services\GoalService;

class CreateGoalAction
{
    public function __construct(protected GoalService $goalService)
    {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data): Goal
    {
        if (! isset($data['start_value'])) {
            $data['start_value'] = 0;
        }

        $goal = new Goal();
        $goal->fill($data);
        $goal->user_id = $user->id;
        $goal->save();

        $this->goalService->updateGoalProgress($goal);

        return $goal;
    }
}
