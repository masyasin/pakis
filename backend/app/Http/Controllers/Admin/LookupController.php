<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lookup untuk select/autocomplete (05 §4.11, BR-006). Bukan daftar `*.index`: tanpa paging, tetapi
 * SELALU dibatasi maksimal 20 baris di SQL, hanya entri aktif, dan melewati scope sumber.
 */
class LookupController extends Controller
{
    public const MAX_LIMIT = 20;

    public function show(Request $request, string $source, LookupRegistry $registry): JsonResponse
    {
        $lookup = $registry->resolve($source) ?? abort(404);

        $user = $request->user();
        abort_unless($user !== null && $lookup->authorize($user), 403);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'parent' => ['nullable', 'string', 'max:50'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'ids' => ['nullable', 'array', 'max:'.self::MAX_LIMIT],
            'ids.*' => ['integer', 'min:1'],
            ...$lookup->rules(),
        ]);

        $query = $lookup->query($data);
        $lookup->scope($query, $user);

        if (! empty($data['ids'])) {
            // Label untuk nilai yang sudah terpilih (chip filter). Tetap aktif + ber-scope.
            $query->whereKey(array_map('intval', $data['ids']));
        } else {
            $lookup->search()->apply($query, TextSearch::normalize($data['q'] ?? null));
        }

        $lookup->order($query);

        $options = $query->limit((int) ($data['limit'] ?? self::MAX_LIMIT))->get()
            ->map(fn ($model) => $lookup->option($model))
            ->values();

        return response()->json($options);
    }
}
