<?php

use App\Models\Effort;
use App\Models\Initiative;
use App\Models\Organization;
use App\Models\Program;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Test Org']);
    $this->workspace = $this->org->workspaces()->create(['name' => 'Test WS']);
});

// ── Relationship Tests ──────────────────────────────────────────────────

it('creates program with correct relationships', function () {
    $program = Program::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Q1 Campaign',
        'code' => 'Q1-2026',
    ]);

    expect($program->workspace->id)->toBe($this->workspace->id);
    expect($program->initiatives)->toBeEmpty();
});

it('creates initiative linked to program', function () {
    $program = Program::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Q1 Campaign',
        'code' => 'Q1-2026',
    ]);

    $initiative = Initiative::create([
        'workspace_id' => $this->workspace->id,
        'program_id' => $program->id,
        'name' => 'Email Blast',
        'code' => 'EMAIL-001',
        'budget' => 5000.00,
    ]);

    expect($initiative->program->id)->toBe($program->id);
    expect($initiative->workspace->id)->toBe($this->workspace->id);
    expect($initiative->budget)->toBe('5000.00');
});

it('creates effort linked to initiative', function () {
    $program = Program::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Q1 Campaign',
        'code' => 'Q1-2026',
    ]);

    $initiative = Initiative::create([
        'workspace_id' => $this->workspace->id,
        'program_id' => $program->id,
        'name' => 'Email Blast',
        'code' => 'EMAIL-001',
    ]);

    $effort = Effort::create([
        'workspace_id' => $this->workspace->id,
        'initiative_id' => $initiative->id,
        'name' => 'March Newsletter',
        'code' => 'NEWS-MAR',
        'channel_type' => 'email',
        'executed_at' => now(),
    ]);

    expect($effort->initiative->id)->toBe($initiative->id);
    expect($effort->workspace->id)->toBe($this->workspace->id);
    expect($effort->channel_type)->toBe('email');
});

it('program has efforts through initiatives', function () {
    $program = Program::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Q1 Campaign',
        'code' => 'Q1-2026',
    ]);

    $initiative = Initiative::create([
        'workspace_id' => $this->workspace->id,
        'program_id' => $program->id,
        'name' => 'Email Blast',
        'code' => 'EMAIL-001',
    ]);

    Effort::create([
        'workspace_id' => $this->workspace->id,
        'initiative_id' => $initiative->id,
        'name' => 'Newsletter',
        'code' => 'NEWS-001',
    ]);

    expect($program->efforts)->toHaveCount(1);
});

// ── Cascade Delete Tests ────────────────────────────────────────────────

it('soft-deleting program cascades to initiatives and efforts', function () {
    $program = Program::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Q1 Campaign',
        'code' => 'Q1-2026',
    ]);

    $initiative = Initiative::create([
        'workspace_id' => $this->workspace->id,
        'program_id' => $program->id,
        'name' => 'Email Blast',
        'code' => 'EMAIL-001',
    ]);

    $effort = Effort::create([
        'workspace_id' => $this->workspace->id,
        'initiative_id' => $initiative->id,
        'name' => 'Newsletter',
        'code' => 'NEWS-001',
    ]);

    $program->delete();

    expect(Program::find($program->id))->toBeNull();
    expect(Initiative::find($initiative->id))->toBeNull();
    expect(Effort::find($effort->id))->toBeNull();

    // But they still exist in trash
    expect(Program::withTrashed()->find($program->id))->not->toBeNull();
    expect(Initiative::withTrashed()->find($initiative->id))->not->toBeNull();
    expect(Effort::withTrashed()->find($effort->id))->not->toBeNull();
});

it('soft-deleting initiative cascades to efforts', function () {
    $program = Program::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Q1 Campaign',
        'code' => 'Q1-2026',
    ]);

    $initiative = Initiative::create([
        'workspace_id' => $this->workspace->id,
        'program_id' => $program->id,
        'name' => 'Email Blast',
        'code' => 'EMAIL-001',
    ]);

    $effort = Effort::create([
        'workspace_id' => $this->workspace->id,
        'initiative_id' => $initiative->id,
        'name' => 'Newsletter',
        'code' => 'NEWS-001',
    ]);

    $initiative->delete();

    expect(Initiative::find($initiative->id))->toBeNull();
    expect(Effort::find($effort->id))->toBeNull();
    expect(Program::find($program->id))->not->toBeNull(); // Program should remain
});

// ── Scope Tests ─────────────────────────────────────────────────────────

it('active scope filters by status', function () {
    Program::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Active',
        'code' => 'ACTIVE-1',
        'status' => 'active',
    ]);

    Program::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Archived',
        'code' => 'ARCH-1',
        'status' => 'archived',
    ]);

    // +1 for the default program auto-created by WorkspaceObserver
    expect(Program::active()->count())->toBe(2);
});

it('forWorkspace scope filters by workspace', function () {
    $otherWorkspace = $this->org->workspaces()->create(['name' => 'Other WS']);

    Program::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'WS1 Program',
        'code' => 'WS1-001',
    ]);

    Program::create([
        'workspace_id' => $otherWorkspace->id,
        'name' => 'WS2 Program',
        'code' => 'WS2-001',
    ]);

    // +1 each for default programs auto-created by WorkspaceObserver
    expect(Program::forWorkspace($this->workspace)->count())->toBe(2);
    expect(Program::forWorkspace($otherWorkspace)->count())->toBe(2);
});

it('forChannel scope filters efforts by channel type', function () {
    $program = Program::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Program',
        'code' => 'P-001',
    ]);

    $initiative = Initiative::create([
        'workspace_id' => $this->workspace->id,
        'program_id' => $program->id,
        'name' => 'Initiative',
        'code' => 'I-001',
    ]);

    Effort::create([
        'workspace_id' => $this->workspace->id,
        'initiative_id' => $initiative->id,
        'name' => 'Email',
        'code' => 'E-001',
        'channel_type' => 'email',
    ]);

    Effort::create([
        'workspace_id' => $this->workspace->id,
        'initiative_id' => $initiative->id,
        'name' => 'Social',
        'code' => 'E-002',
        'channel_type' => 'social',
    ]);

    expect(Effort::forChannel('email')->count())->toBe(1);
    expect(Effort::forChannel('social')->count())->toBe(1);
});

// ── Unique Constraint Tests ─────────────────────────────────────────────

it('enforces unique workspace_id + code constraint', function () {
    Program::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'First',
        'code' => 'UNIQUE-CODE',
    ]);

    expect(fn () => Program::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Duplicate',
        'code' => 'UNIQUE-CODE',
    ]))->toThrow(QueryException::class);
});

// ── Fillable Tests ──────────────────────────────────────────────────────

it('has correct fillable attributes on Program', function () {
    $program = new Program;
    expect($program->getFillable())->toContain('name', 'code', 'description', 'status', 'start_date', 'end_date');
});

it('has correct fillable attributes on Initiative', function () {
    $initiative = new Initiative;
    expect($initiative->getFillable())->toContain('name', 'code', 'description', 'status', 'budget');
});

it('has correct fillable attributes on Effort', function () {
    $effort = new Effort;
    expect($effort->getFillable())->toContain('name', 'code', 'channel_type', 'status', 'executed_at');
});

// ── Cast Tests ──────────────────────────────────────────────────────────

it('casts program dates correctly', function () {
    $program = Program::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Date Test',
        'code' => 'DATE-001',
        'start_date' => '2026-01-01',
        'end_date' => '2026-03-31',
    ]);

    expect($program->start_date)->toBeInstanceOf(Carbon::class);
    expect($program->end_date)->toBeInstanceOf(Carbon::class);
});

it('casts effort executed_at as datetime', function () {
    $program = Program::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'P',
        'code' => 'P-001',
    ]);

    $initiative = Initiative::create([
        'workspace_id' => $this->workspace->id,
        'program_id' => $program->id,
        'name' => 'I',
        'code' => 'I-001',
    ]);

    $effort = Effort::create([
        'workspace_id' => $this->workspace->id,
        'initiative_id' => $initiative->id,
        'name' => 'E',
        'code' => 'E-001',
        'executed_at' => '2026-03-14 10:30:00',
    ]);

    expect($effort->executed_at)->toBeInstanceOf(Carbon::class);
});
