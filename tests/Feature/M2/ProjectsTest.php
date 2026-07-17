<?php

use App\Models\Project;

test('projects can be created, updated and deleted', function () {
    $user = ukDoctor();

    $this->actingAs($user)->post('/projects', [
        'kind' => 'objective',
        'title' => 'Improve paediatric reporting',
        'status' => 'open',
    ])->assertRedirect();

    $project = $user->projects()->firstOrFail();
    expect($project->title)->toBe('Improve paediatric reporting');

    $this->actingAs($user)->put("/projects/{$project->id}", [
        'kind' => 'objective',
        'title' => 'Improve paediatric reporting',
        'status' => 'achieved',
    ])->assertRedirect();

    expect($project->fresh()->status->value)->toBe('achieved');

    $this->actingAs($user)->delete("/projects/{$project->id}")->assertRedirect();
    expect($user->projects()->count())->toBe(0);
});

test('users cannot modify other users\' projects', function () {
    $owner = ukDoctor();
    $project = Project::factory()->for($owner)->create();

    $this->actingAs(ukDoctor())
        ->put("/projects/{$project->id}", ['kind' => 'project', 'title' => 'Hijacked', 'status' => 'open'])
        ->assertForbidden();
});
