<?php

namespace App\Http\Controllers\Quality;

use App\Http\Controllers\Controller;
use App\Models\DocumentFile;
use App\Models\DocumentFolder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Document Storage: a shared folder explorer for keeping any documents. Folders nest, files
 * of any type can be uploaded, and everything can be searched, opened and downloaded later.
 */
class DocumentController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeModule($request);

        $folder = $request->integer('folder') ? DocumentFolder::query()->findOrFail($request->integer('folder')) : null;
        $search = trim((string) $request->query('q', ''));

        return Inertia::render('quality/documents/Index', [
            'folder' => $folder ? ['id' => $folder->id, 'name' => $folder->name, 'parentId' => $folder->parent_id] : null,
            'breadcrumbs' => collect($folder?->ancestry() ?? [])->map(fn (DocumentFolder $item) => ['id' => $item->id, 'name' => $item->name])->values(),
            'search' => $search,
            'folders' => $search === '' ? $this->folders($folder?->id) : $this->searchFolders($search),
            'files' => $search === '' ? $this->files($folder?->id) : $this->searchFiles($search),
            'maxFileMegabytes' => config('documents.max_upload_kilobytes') / 1024,
            'maxFiles' => config('documents.max_files_per_upload'),
            'tree' => self::folderTree(),
        ]);
    }

    public function storeFolder(Request $request): RedirectResponse
    {
        $this->authorizeModule($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'parent_id' => ['nullable', 'integer', 'exists:document_folders,id'],
        ]);
        $name = $this->cleanName($validated['name']);
        $this->ensureUniqueFolder($validated['parent_id'] ?? null, $name);

        DocumentFolder::create(['parent_id' => $validated['parent_id'] ?? null, 'name' => $name, 'created_by' => $request->user()->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Folder ":name" created.', ['name' => $name])]);

        return back();
    }

    public function renameFolder(Request $request, DocumentFolder $folder): RedirectResponse
    {
        $this->authorizeModule($request);

        $name = $this->cleanName($request->validate(['name' => ['required', 'string', 'max:150']])['name']);
        $this->ensureUniqueFolder($folder->parent_id, $name, $folder->id);
        $folder->update(['name' => $name]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Folder renamed.')]);

        return back();
    }

    /**
     * Deletes a folder with everything inside it, including the stored files.
     */
    public function destroyFolder(Request $request, DocumentFolder $folder): RedirectResponse
    {
        $this->authorizeModule($request);

        $ids = $folder->descendantIds();
        $paths = DocumentFile::query()->whereIn('folder_id', $ids)->pluck('path')->all();
        $parentId = $folder->parent_id;

        DB::transaction(fn () => $folder->delete());
        Storage::disk('local')->delete($paths);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Folder ":name" and its contents deleted.', ['name' => $folder->name])]);

        return to_route('quality.documents.index', array_filter(['folder' => $parentId]));
    }

    /**
     * Deletes several folders (with everything inside them) and files at once.
     */
    public function destroySelected(Request $request): RedirectResponse
    {
        $this->authorizeModule($request);

        $validated = $request->validate([
            'folder_ids' => ['array'],
            'folder_ids.*' => ['integer', 'distinct', 'exists:document_folders,id'],
            'file_ids' => ['array'],
            'file_ids.*' => ['integer', 'distinct', 'exists:document_files,id'],
        ]);

        $folders = DocumentFolder::query()->whereIn('id', $validated['folder_ids'] ?? [])->get();
        $folderIds = $folders->flatMap(fn (DocumentFolder $folder) => $folder->descendantIds())->unique()->all();
        $files = DocumentFile::query()
            ->where(fn (Builder $query) => $query
                ->whereIn('id', $validated['file_ids'] ?? [])
                ->orWhereIn('folder_id', $folderIds))
            ->get(['id', 'path']);
        $count = $folders->count() + count($validated['file_ids'] ?? []);

        if ($count === 0) {
            throw ValidationException::withMessages(['selection' => __('Select something to delete.')]);
        }

        DB::transaction(function () use ($files, $folders): void {
            DocumentFile::query()->whereIn('id', $files->pluck('id'))->delete();
            $folders->each->delete();
        });
        Storage::disk('local')->delete($files->pluck('path')->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(
            '{1} One item deleted.|[2,*] :count items deleted.',
            $count,
            ['count' => $count],
        )]);

        return back();
    }

    /**
     * Moves folders and files into another folder (or the top level). A folder cannot go
     * inside itself; a name already used in the destination gets a number added.
     */
    public function moveSelected(Request $request): RedirectResponse
    {
        $this->authorizeModule($request);

        $validated = $request->validate([
            'folder_ids' => ['array'],
            'folder_ids.*' => ['integer', 'distinct', 'exists:document_folders,id'],
            'file_ids' => ['array'],
            'file_ids.*' => ['integer', 'distinct', 'exists:document_files,id'],
            'target_id' => ['nullable', 'integer', 'exists:document_folders,id'],
        ]);

        $targetId = $validated['target_id'] ?? null;
        $folders = DocumentFolder::query()->whereIn('id', $validated['folder_ids'] ?? [])->get();
        $files = DocumentFile::query()->whereIn('id', $validated['file_ids'] ?? [])->get();

        if ($folders->isEmpty() && $files->isEmpty()) {
            throw ValidationException::withMessages(['selection' => __('Select something to move.')]);
        }

        if ($targetId && $folders->contains(fn (DocumentFolder $folder) => in_array($targetId, $folder->descendantIds(), true))) {
            throw ValidationException::withMessages(['target_id' => __('A folder cannot be moved into itself or into one of its own folders.')]);
        }

        DB::transaction(function () use ($folders, $files, $targetId): void {
            foreach ($folders as $folder) {
                $folder->update(['parent_id' => $targetId, 'name' => $this->uniqueFolderName($targetId, $folder->name, $folder->id)]);
            }

            foreach ($files as $file) {
                $file->update(['folder_id' => $targetId, 'name' => DocumentFile::uniqueName($targetId, $file->name, $file->id)]);
            }
        });

        $count = $folders->count() + $files->count();
        $destination = $targetId ? DocumentFolder::query()->find($targetId)?->name : __('Documents');

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(
            '{1} One item moved to ":folder".|[2,*] :count items moved to ":folder".',
            $count,
            ['count' => $count, 'folder' => $destination],
        )]);

        return back();
    }

    /**
     * Every folder as {id, name, parentId}, for folder pickers.
     *
     * @return array<int, array{id: int, name: string, parentId: int|null}>
     */
    public static function folderTree(): array
    {
        return DocumentFolder::query()
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id'])
            ->map(fn (DocumentFolder $folder) => ['id' => $folder->id, 'name' => $folder->name, 'parentId' => $folder->parent_id])
            ->all();
    }

    /**
     * Uploads files into a folder (or the top level). A file with the same name in that
     * folder gets a number added, so nothing is overwritten.
     */
    public function upload(Request $request): RedirectResponse
    {
        $this->authorizeModule($request);

        $validated = $request->validate([
            'folder_id' => ['nullable', 'integer', 'exists:document_folders,id'],
            'files' => ['required', 'array', 'min:1', 'max:'.config('documents.max_files_per_upload')],
            'files.*' => ['file', 'max:'.config('documents.max_upload_kilobytes')],
        ], [
            'files.*.max' => __('Each file must be :size MB or smaller.', ['size' => config('documents.max_upload_kilobytes') / 1024]),
            'files.*.uploaded' => __('A file could not be uploaded. It may be larger than the server allows.'),
        ]);

        $folderId = $validated['folder_id'] ?? null;

        foreach ($validated['files'] as $file) {
            $name = DocumentFile::uniqueName($folderId, $this->cleanName(basename((string) $file->getClientOriginalName())) ?: 'file');

            DocumentFile::create([
                'folder_id' => $folderId,
                'name' => $name,
                'path' => $file->storeAs('documents', Str::uuid().'.'.($file->getClientOriginalExtension() ?: 'bin'), 'local'),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => $request->user()->id,
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(
            '{1} One file uploaded.|[2,*] :count files uploaded.',
            count($validated['files']),
            ['count' => count($validated['files'])],
        )]);

        return back();
    }

    public function renameFile(Request $request, DocumentFile $file): RedirectResponse
    {
        $this->authorizeModule($request);

        $name = $this->cleanName($request->validate(['name' => ['required', 'string', 'max:200']])['name']);

        if (DocumentFile::query()->where('folder_id', $file->folder_id)->where('name', $name)->whereKeyNot($file->id)->exists()) {
            throw ValidationException::withMessages(['name' => __('A file with that name is already in this folder.')]);
        }

        $file->update(['name' => $name]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('File renamed.')]);

        return back();
    }

    public function destroyFile(Request $request, DocumentFile $file): RedirectResponse
    {
        $this->authorizeModule($request);

        Storage::disk('local')->delete($file->path);
        $file->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('":name" deleted.', ['name' => $file->name])]);

        return back();
    }

    /**
     * Opens a file in the browser when it is a safe type to show (PDF, image, text);
     * anything else is downloaded.
     */
    public function open(Request $request, DocumentFile $file): StreamedResponse
    {
        $this->authorizeModule($request);
        $viewable = in_array($file->mime_type, config('documents.viewable_types'), true);

        return $this->stream($file, $viewable ? 'inline' : 'attachment');
    }

    public function download(Request $request, DocumentFile $file): StreamedResponse
    {
        $this->authorizeModule($request);

        return $this->stream($file, 'attachment');
    }

    private function stream(DocumentFile $file, string $disposition): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        $response = Storage::disk('local')->download($file->path, $file->name, [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        if ($disposition === 'inline') {
            $response->headers->set('Content-Disposition', $response->headers->get('Content-Disposition') ? str_replace('attachment', 'inline', $response->headers->get('Content-Disposition')) : 'inline');
        }

        return $response;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function folders(?int $parentId): array
    {
        return DocumentFolder::query()
            ->where('parent_id', $parentId)
            ->withCount(['children', 'files'])
            ->with('creator:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (DocumentFolder $folder) => $this->serializeFolder($folder))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function files(?int $folderId): array
    {
        return DocumentFile::query()
            ->where('folder_id', $folderId)
            ->with('uploader:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (DocumentFile $file) => $this->serializeFile($file))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchFolders(string $search): array
    {
        return DocumentFolder::query()
            ->tap(fn (Builder $query) => $this->whereWords($query, $search))
            ->withCount(['children', 'files'])
            ->with('creator:id,name')
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->map(fn (DocumentFolder $folder) => $this->serializeFolder($folder, withLocation: true))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchFiles(string $search): array
    {
        return DocumentFile::query()
            ->tap(fn (Builder $query) => $this->whereWords($query, $search))
            ->with('uploader:id,name')
            ->orderBy('name')
            ->limit(200)
            ->get()
            ->map(fn (DocumentFile $file) => $this->serializeFile($file, withLocation: true))
            ->all();
    }

    /**
     * Every word must appear in the name.
     */
    private function whereWords(Builder $query, string $search): void
    {
        foreach (collect(preg_split('/\s+/', $search))->filter()->take(6) as $word) {
            $query->where('name', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $word).'%');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFolder(DocumentFolder $folder, bool $withLocation = false): array
    {
        return [
            'id' => $folder->id,
            'name' => $folder->name,
            'items' => $folder->children_count + $folder->files_count,
            'createdBy' => $folder->creator?->name,
            'updatedAt' => $folder->updated_at?->toIso8601String(),
            'location' => $withLocation ? $this->location($folder->parent_id) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFile(DocumentFile $file, bool $withLocation = false): array
    {
        return [
            'id' => $file->id,
            'name' => $file->name,
            'mimeType' => $file->mime_type,
            'size' => $file->size,
            'uploadedBy' => $file->uploader?->name,
            'updatedAt' => $file->updated_at?->toIso8601String(),
            'viewable' => in_array($file->mime_type, config('documents.viewable_types'), true),
            'folderId' => $file->folder_id,
            'location' => $withLocation ? $this->location($file->folder_id) : null,
        ];
    }

    /**
     * Where an item sits, e.g. "Documents / 2025 / Minutes", for search results.
     */
    private function location(?int $folderId): string
    {
        $folder = $folderId ? DocumentFolder::query()->find($folderId) : null;

        return collect($folder?->ancestry() ?? [])->pluck('name')->prepend(__('Documents'))->implode(' / ');
    }

    private function cleanName(string $name): string
    {
        return trim((string) preg_replace('/[\\\\\/\x00-\x1F]+/', ' ', $name));
    }

    private function ensureUniqueFolder(?int $parentId, string $name, ?int $exceptId = null): void
    {
        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('Enter a folder name.')]);
        }

        $exists = DocumentFolder::query()
            ->where('parent_id', $parentId)
            ->where('name', $name)
            ->when($exceptId, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => __('A folder with that name is already here.')]);
        }
    }

    private function uniqueFolderName(?int $parentId, string $name, int $exceptId): string
    {
        $candidate = $name;
        $number = 1;

        while (DocumentFolder::query()->where('parent_id', $parentId)->where('name', $candidate)->whereKeyNot($exceptId)->exists()) {
            $candidate = $name.' ('.(++$number).')';
        }

        return $candidate;
    }

    private function authorizeModule(Request $request): void
    {
        abort_unless($request->user()->canManageDocuments(), 403);
    }
}
