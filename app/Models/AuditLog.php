<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

#[Fillable(['user_id', 'event', 'module', 'action', 'method', 'path', 'ip_address', 'user_agent', 'metadata'])]
class AuditLog extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function record(Request $request, string $event, ?string $module = null, ?string $action = null, array $metadata = []): void
    {
        self::query()->create([
            'user_id' => $request->user()?->id,
            'event' => $event,
            'module' => $module,
            'action' => $action,
            'method' => $request->method(),
            'path' => $request->path(),
            'ip_address' => $request->ip(),
            'user_agent' => str($request->userAgent() ?? '')->limit(500)->toString(),
            'metadata' => $metadata ?: null,
        ]);
    }
}
