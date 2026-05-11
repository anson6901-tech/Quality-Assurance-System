<?php

namespace App\Http\Controllers\Quality;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SchoolController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        return Inertia::render('quality/schools/Index', [
            'schools' => School::query()
                ->with('dean:id,name,email')
                ->withCount(['departments', 'users', 'qualityReports'])
                ->orderBy('name')
                ->get(),
            'deans' => User::query()->orderBy('name')->get(['id', 'name', 'email', 'school_id']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('schools', 'name')],
            'code' => ['required', 'string', 'max:20', Rule::unique('schools', 'code')],
            'dean_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        School::create([
            ...$validated,
            'dean_id' => $validated['dean_id'] ?? null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('School created.')]);

        return to_route('quality.schools.index');
    }
}
