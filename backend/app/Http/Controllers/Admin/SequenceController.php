<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Numbering\Actions\UpdateSequence;
use App\Domain\Numbering\Enums\ResetPeriod;
use App\Domain\Numbering\Listings\SequenceListing;
use App\Domain\Numbering\Models\DocumentSequence;
use App\Domain\Numbering\NumberGenerator;
use App\Http\Controllers\Controller;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Penomoran dokumen (05 §4.3, 07 §4). Izin dicek middleware route `can:sequences.*`. */
class SequenceController extends Controller
{
    #[UsesListing(SequenceListing::class)]
    public function index(Request $request, NumberGenerator $numbers): Response
    {
        $result = ListingQuery::apply(DocumentSequence::query(), new SequenceListing, $request)
            ->through(fn (DocumentSequence $s) => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'pattern' => $s->pattern,
                'reset_period' => $s->reset_period->value,
                'reset_label' => $s->reset_period->label(),
                'last_number' => $numbers->lastNumber($s),
                'next' => $numbers->preview($s, count: 1)[0],
            ]);

        return Inertia::render('sequences/index', [
            ...$result->toInertia(),
            'resetOptions' => collect(ResetPeriod::cases())
                ->map(fn (ResetPeriod $p) => ['value' => $p->value, 'label' => $p->label()])
                ->values(),
            'canUpdate' => $request->user()?->can('sequences.update') ?? false,
        ]);
    }

    public function update(Request $request, DocumentSequence $sequence, UpdateSequence $action): RedirectResponse
    {
        $data = $request->validate([
            'pattern' => ['required', 'string', 'max:100'],
            'reset_period' => ['required', Rule::enum(ResetPeriod::class)],
        ], [], ['pattern' => 'pola', 'reset_period' => 'periode reset']);

        $action->handle($sequence, $data['pattern'], ResetPeriod::from($data['reset_period']), $request->user()?->getAuthIdentifier());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Pola penomoran {$sequence->name} disimpan."]);

        return back();
    }

    /**
     * Pratinjau 3 nomor berikutnya saat admin mengetik pola. Tidak menaikkan counter.
     * Pola tidak sah dijawab 200 + daftar galat, karena itu keadaan normal selama mengetik.
     */
    public function preview(Request $request, NumberGenerator $numbers): JsonResponse
    {
        $data = $request->validate([
            'sequence_id' => ['required', 'integer', 'exists:document_sequences,id'],
            'pattern' => ['present', 'nullable', 'string', 'max:100'],
            'reset_period' => ['required', Rule::enum(ResetPeriod::class)],
        ]);

        $sequence = DocumentSequence::query()->findOrFail($request->integer('sequence_id'));
        $pattern = trim((string) $data['pattern']);
        $reset = ResetPeriod::from($data['reset_period']);

        $errors = UpdateSequence::patternErrors($pattern, $reset);

        return response()->json([
            'errors' => $errors,
            'numbers' => $errors === [] ? $numbers->preview($sequence, $pattern, $reset) : [],
        ]);
    }
}
