<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Reference\Actions\CreateReferenceItem;
use App\Domain\Reference\Actions\DeleteReferenceItem;
use App\Domain\Reference\Actions\ReorderReferenceItems;
use App\Domain\Reference\Actions\UpdateReferenceItem;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Listings\ReferenceListing;
use App\Domain\Reference\Models\ReferenceItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reference\ReferenceItemRequest;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Referensi dinamis (05 §4.3, 07 §4, BR-026). Izin dicek middleware route `can:references.*`. */
class ReferenceController extends Controller
{
    #[UsesListing(ReferenceListing::class)]
    public function index(Request $request): Response
    {
        $result = ListingQuery::apply(ReferenceItem::query(), new ReferenceListing, $request)
            ->through(fn (ReferenceItem $item) => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'description' => $item->description,
                'sort_order' => $item->sort_order,
                'is_active' => $item->is_active,
                'is_system' => $item->is_system,
            ]);

        // Filter grup wajib: state selalu berisi grup yang sah (lihat Filter::required()).
        $group = ReferenceGroup::from((string) $result->state()['filters']['group']);

        return Inertia::render('references/index', [
            ...$result->toInertia(),
            'group' => $group->value,
            'groups' => collect(ReferenceGroup::cases())
                ->map(fn (ReferenceGroup $g) => ['value' => $g->value, 'label' => $g->label()])
                ->values(),
            // Dialog "Atur Urutan" butuh SEMUA item grup (grup kecil, < 50 item), bukan hanya halaman ini.
            'orderItems' => fn () => ReferenceItem::query()->forGroup($group)
                ->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'code', 'name', 'is_active', 'is_system']),
            'canManage' => $request->user()?->can('references.manage') ?? false,
        ]);
    }

    public function store(ReferenceItemRequest $request, CreateReferenceItem $action): RedirectResponse
    {
        /** @var array{group: string, code: string, name: string, description: string|null, is_active: bool} $data */
        $data = $request->validated();
        $item = $action->handle(ReferenceGroup::from($data['group']), $data, $request->user()?->getAuthIdentifier());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Item \"{$item->name}\" ditambahkan."]);

        return back();
    }

    public function update(ReferenceItemRequest $request, ReferenceItem $item, UpdateReferenceItem $action): RedirectResponse
    {
        /** @var array{code: string, name: string, description: string|null, is_active: bool} $data */
        $data = $request->validated();
        $action->handle($item, $data, $request->user()?->getAuthIdentifier());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Item \"{$item->name}\" disimpan."]);

        return back();
    }

    public function destroy(ReferenceItem $item, DeleteReferenceItem $action): RedirectResponse
    {
        $action->handle($item);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Item \"{$item->name}\" dihapus."]);

        return back();
    }

    public function reorder(Request $request, ReorderReferenceItems $action): RedirectResponse
    {
        $data = $request->validate([
            'group' => ['required', Rule::enum(ReferenceGroup::class)],
            // `list`: kunci 0..n berurutan, karena posisi di array = urutan baru.
            'ids' => ['required', 'array', 'list', 'max:500'],
            'ids.*' => ['integer'],
        ], [], ['group' => 'grup', 'ids' => 'urutan']);

        $action->handle(
            ReferenceGroup::from($data['group']),
            array_values(array_map('intval', $data['ids'])),
            $request->user()?->getAuthIdentifier(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Urutan disimpan.']);

        return back();
    }
}
