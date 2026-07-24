<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Program extends Model
{
    protected $fillable = ['department_id', 'name', 'code', 'max_level'];

    protected function casts(): array
    {
        return [
            'max_level' => 'integer',
        ];
    }

    /** @return array<int, string> */
    public function levels(): array
    {
        return array_map(
            static fn (int $level): string => (string) $level,
            range(100, $this->max_level, 100),
        );
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    public function courseOfferings(): HasMany
    {
        return $this->hasMany(CourseOffering::class);
    }
}
