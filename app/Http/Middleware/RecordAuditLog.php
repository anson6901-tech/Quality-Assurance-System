<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

class RecordAuditLog
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->user() || ! $request->is('quality*')) {
            return $response;
        }

        $module = $this->moduleFor($request->path());

        if (! $module) {
            return $response;
        }

        if ($request->isMethod('GET')) {
            AuditLog::record($request, 'module_visit', $module, $this->actionFor($request), $this->metadataFor($request, $response, $module));
        } elseif (in_array($request->method(), ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
            AuditLog::record($request, 'action', $module, $this->actionFor($request), $this->metadataFor($request, $response, $module));
        }

        return $response;
    }

    private function moduleFor(string $path): ?string
    {
        return match (true) {
            str_starts_with($path, 'quality/reports') => 'Reports',
            str_starts_with($path, 'quality/approvals') => 'Approvals',
            str_starts_with($path, 'quality/print-queue') => 'Print Queue',
            str_starts_with($path, 'quality/users') => 'Users',
            str_starts_with($path, 'quality/roles-rights') => 'Roles & Rights',
            str_starts_with($path, 'quality/audit-logs') => 'Audit Logs',
            str_starts_with($path, 'quality/schools') => 'Schools',
            str_starts_with($path, 'quality/departments') => 'Departments',
            str_starts_with($path, 'quality/courses') => 'Courses',
            str_starts_with($path, 'quality/academic-years') => 'Academic Years',
            default => null,
        };
    }

    private function actionFor(Request $request): string
    {
        if ($request->isMethod('GET') && str_ends_with($request->path(), '/file')) {
            return 'preview_pdf';
        }

        return $request->route()?->getName() ?? strtolower($request->method());
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataFor(Request $request, Response $response, string $module): array
    {
        $routeName = $request->route()?->getName();
        $target = $this->targetFor($request);
        $submitted = $this->submittedDataFor($request);

        return array_filter([
            'summary' => $this->summaryFor($request, $module, $target),
            'route' => $routeName,
            'status' => $response->getStatusCode(),
            'target' => $target,
            'submitted' => $submitted,
            'query' => $request->query() ?: null,
            'actor' => $this->actorFor($request),
            'referrer' => $request->headers->get('referer'),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function targetFor(Request $request): ?array
    {
        $parameters = $request->route()?->parameters() ?? [];

        foreach ($parameters as $name => $value) {
            if (is_object($value) && method_exists($value, 'getKey')) {
                return array_filter([
                    'type' => str($name)->headline()->toString(),
                    'id' => $value->getKey(),
                    'label' => $this->labelForModel($value),
                ]);
            }

            if (is_scalar($value)) {
                return [
                    'type' => str($name)->headline()->toString(),
                    'value' => (string) $value,
                ];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function submittedDataFor(Request $request): ?array
    {
        if (! in_array($request->method(), ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
            return null;
        }

        $input = Arr::except($request->all(), [
            '_token',
            '_method',
            'password',
            'password_confirmation',
            'current_password',
            'two_factor_code',
            'recovery_code',
        ]);

        $fields = collect($input)
            ->map(fn (mixed $value): mixed => $this->safeValue($value))
            ->all();

        $sensitiveFieldsPresent = collect($request->only([
            'password',
            'password_confirmation',
            'current_password',
            'two_factor_code',
            'recovery_code',
        ]))
            ->filter(fn (mixed $value): bool => filled($value))
            ->keys()
            ->values()
            ->all();

        return array_filter([
            'fields' => $fields,
            'field_names' => array_keys($fields),
            'sensitive_fields_present' => $sensitiveFieldsPresent,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function actorFor(Request $request): ?array
    {
        $user = $request->user();

        if (! $user) {
            return null;
        }

        return array_filter([
            'role' => $user->university_role,
            'school_id' => $user->school_id,
            'department_id' => $user->department_id,
            'current_team_id' => $user->current_team_id,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>|string|int|float|bool|null
     */
    private function safeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return [
                    'count' => count($value),
                    'values' => collect($value)
                        ->take(20)
                        ->map(fn (mixed $item): mixed => is_scalar($item) ? str((string) $item)->limit(120)->toString() : '[complex value]')
                        ->values()
                        ->all(),
                ];
            }

            return collect($value)
                ->map(fn (mixed $item): mixed => $this->safeValue($item))
                ->all();
        }

        if (is_scalar($value) || $value === null) {
            return is_string($value) ? str($value)->limit(180)->toString() : $value;
        }

        return '[complex value]';
    }

    private function summaryFor(Request $request, string $module, ?array $target): string
    {
        if ($request->isMethod('GET')) {
            return sprintf('Visited %s%s.', $module, $target ? ' for '.$this->targetLabel($target) : '');
        }

        $verb = match ($request->method()) {
            'POST' => 'Created or submitted',
            'PATCH', 'PUT' => 'Updated',
            'DELETE' => 'Deleted',
            default => str($request->method())->headline()->toString(),
        };

        $fieldNames = array_keys(Arr::except($request->all(), ['_token', '_method', 'password', 'password_confirmation']));
        $fieldSummary = $fieldNames ? ' Fields: '.implode(', ', array_slice($fieldNames, 0, 8)).'.' : '';

        return sprintf('%s %s%s.%s', $verb, $module, $target ? ' for '.$this->targetLabel($target) : '', $fieldSummary);
    }

    private function targetLabel(array $target): string
    {
        return (string) ($target['label'] ?? $target['value'] ?? $target['id'] ?? 'selected record');
    }

    private function labelForModel(object $model): ?string
    {
        foreach (['name', 'title', 'label', 'email', 'code', 'role'] as $attribute) {
            if (isset($model->{$attribute}) && filled($model->{$attribute})) {
                return (string) $model->{$attribute};
            }
        }

        return null;
    }
}
