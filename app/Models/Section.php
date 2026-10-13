<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['program_id', 'type', 'is_weekend'])]
class Section extends Model
{
    public const TYPES = [
        'undergraduate' => 'Undergraduate',
        'postgraduate' => 'Postgraduate',
        'sandwich' => 'Sandwich',
        'access' => 'Access',
    ];

    protected function casts(): array
    {
        return [
            'is_weekend' => 'boolean',
        ];
    }

    /** @return BelongsTo<Program, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function label(): string
    {
        $type = self::TYPES[$this->type] ?? ucfirst($this->type);

        return $this->is_weekend ? "{$type} · Weekend" : $type;
    }

    /**
     * @return array{id: int, type: string, isWeekend: bool, name: string}
     */
    public function toOption(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'isWeekend' => $this->is_weekend,
            'name' => $this->label(),
        ];
    }
}
