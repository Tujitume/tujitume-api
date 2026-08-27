<?php

namespace Database\Factories;

use App\Models\Organizations\Organization;
use App\Models\Organizations\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();
        return ['organization_id' => Organization::factory(), 'name' => $name, 'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'), 'workspace_status' => 'active'];
    }
}
