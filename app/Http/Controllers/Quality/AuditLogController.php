<?php

namespace App\Http\Controllers\Quality;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->canViewAuditLogs(), 403);

        return Inertia::render('quality/audit-logs/Index', [
            'logs' => AuditLog::query()
                ->with('user:id,name,email')
                ->latest()
                ->limit(200)
                ->get()
                ->map(fn (AuditLog $log): array => [
                    'id' => $log->id,
                    'event' => $log->event,
                    'module' => $log->module,
                    'action' => $log->action,
                    'method' => $log->method,
                    'path' => $log->path,
                    'ipAddress' => $log->ip_address,
                    'metadata' => $log->metadata,
                    'createdAt' => $log->created_at->format('M j, Y g:i A'),
                    'user' => $log->user ? [
                        'id' => $log->user->id,
                        'name' => $log->user->name,
                        'email' => $log->user->email,
                    ] : null,
                ]),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()->canViewAuditLogs(), 403);

        $filename = 'audit-logs-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Date',
                'User name',
                'User email',
                'Event',
                'Module',
                'Method',
                'Action',
                'Path',
                'IP address',
                'Status',
                'Summary',
                'Route',
                'Target',
                'Submitted fields',
                'Actor role',
                'User agent',
            ]);

            AuditLog::query()
                ->with('user:id,name,email')
                ->latest()
                ->chunk(500, function ($logs) use ($handle): void {
                    foreach ($logs as $log) {
                        $metadata = $log->metadata ?? [];

                        fputcsv($handle, [
                            $log->created_at?->format('Y-m-d H:i:s'),
                            $log->user?->name ?? 'System',
                            $log->user?->email,
                            $log->event,
                            $log->module,
                            $log->method,
                            $log->action,
                            $log->path,
                            $log->ip_address,
                            $metadata['status'] ?? null,
                            $metadata['summary'] ?? null,
                            $metadata['route'] ?? null,
                            $this->formatTarget($metadata['target'] ?? null),
                            implode(', ', $metadata['submitted']['field_names'] ?? []),
                            $metadata['actor']['role'] ?? null,
                            $log->user_agent,
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $target
     */
    private function formatTarget(?array $target): ?string
    {
        if (! $target) {
            return null;
        }

        $label = $target['label'] ?? $target['value'] ?? $target['id'] ?? null;

        return trim(($target['type'] ?? 'Record').' '.($label ?? ''));
    }
}
