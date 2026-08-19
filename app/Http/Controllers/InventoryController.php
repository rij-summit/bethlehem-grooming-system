<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Services\InventoryBatchBalanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    private const MAX_MONEY = 999999.99;

    private const MAX_QUANTITY = 99999999.99;

    public function __construct(private readonly InventoryBatchBalanceService $batchBalances)
    {
    }

    // ── Auth guard ─────────────────────────────────────────────────────────────

    private function requireAuth(): \App\Models\User
    {
        $user = auth('sanctum')->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }
        return $user;
    }

    // ── Formatters ─────────────────────────────────────────────────────────────

    private function formatItem(InventoryItem $item, ?array $balance = null): array
    {
        $balance ??= $this->batchBalances->forItem((int) $item->item_id);
        $physicalQuantity = (float) $item->quantity_on_hand;

        return [
            'item_id'          => $item->item_id,
            'item_name'        => $item->item_name,
            'barcode'          => $item->barcode,
            'category'         => $item->category,
            'unit'             => $item->unit,
            'description'      => $item->description,
            'unit_cost'        => $item->unit_cost,
            'selling_price'    => $item->selling_price,
            'quantity_on_hand' => $item->quantity_on_hand,
            'unexpired_quantity' => $balance['unexpired_quantity'],
            'expired_quantity' => $balance['expired_quantity'],
            'expiry_unknown_quantity' => $balance['unknown_expiry_quantity'],
            'historically_unsafe_quantity' => $balance['historically_unsafe_stock_out_quantity'],
            'untracked_quantity' => round(max(
                0,
                $physicalQuantity - (float) $balance['tracked_remaining_quantity'],
            ), 2),
            'reorder_level'    => $item->reorder_level,
            'low_stock'        => $item->low_stock,
            'is_active'        => $item->is_active,
            'created_at'       => $item->created_at,
            'updated_at'       => $item->updated_at,
        ];
    }

    private function formatItems(iterable $items): Collection
    {
        $items = collect($items);
        $balances = $this->batchBalances->forItems($items->pluck('item_id'));

        return $items->map(fn (InventoryItem $item) => $this->formatItem(
            $item,
            $balances[(int) $item->item_id] ?? $this->batchBalances->emptyBalance(),
        ));
    }

    private function currentExpiryAlerts(): Collection
    {
        $today = now()->startOfDay();
        $cutoff = $today->copy()->addDays(30)->toDateString();
        $items = InventoryItem::where('is_active', 1)
            ->where('quantity_on_hand', '>', 0)
            ->get();
        $balances = $this->batchBalances->forItems($items->pluck('item_id'));

        return $items->map(function (InventoryItem $item) use ($balances, $today, $cutoff) {
            $balance = $balances[(int) $item->item_id] ?? $this->batchBalances->emptyBalance();
            $expiringBatches = collect($balance['batches'])
                ->filter(fn (array $batch) => $batch['remaining_quantity'] > 0
                    && $batch['expiry_date'] !== null
                    && $batch['expiry_date'] <= $cutoff)
                ->sortBy(fn (array $batch) => [
                    $batch['expiry_date'],
                    $batch['transaction_id'],
                ])
                ->values();

            if ($expiringBatches->isEmpty()) {
                return null;
            }

            $earliest = $expiringBatches->first();
            $expiryDate = CarbonImmutable::parse($earliest['expiry_date']);

            return [
                ...$this->formatItem($item, $balance),
                'earliest_expiry' => $earliest['expiry_date'],
                'is_expired' => $earliest['is_expired'],
                'days_until_expiry' => (int) $today->diffInDays($expiryDate, false),
                'expiring_quantity' => round((float) $expiringBatches->sum('remaining_quantity'), 2),
                'expiring_batches' => $expiringBatches->all(),
            ];
        })->filter()->values();
    }

    private function formatTransaction(InventoryTransaction $t): array
    {
        return [
            'transaction_id'        => $t->transaction_id,
            'item_id'               => $t->item_id,
            'item_name'             => $t->item?->item_name,
            'category'              => $t->item?->category,
            'unit'                  => $t->item?->unit,
            'type'                  => $t->type,
            'quantity'              => $t->quantity,
            'unit_cost_at_time'     => $t->unit_cost_at_time,
            'selling_price_at_time' => $t->selling_price_at_time,
            'reason'                => $t->reason,
            'supplier_id'           => $t->supplier_id,
            'supplier_name'         => $t->supplier?->supplier_name,
            'batch_number'          => $t->batch_number,
            'expiry_date'           => $t->expiry_date?->format('Y-m-d'),
            'reference_type'        => $t->reference_type,
            'reference_id'          => $t->reference_id,
            'notes'                 => $t->notes,
            'performed_by'          => $t->performed_by,
            'performed_by_name'     => $t->performedBy?->name,
            'created_at'            => $t->created_at,
        ];
    }

    // ── Product CRUD ───────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $this->requireAuth();

        $q              = trim($request->query('q', ''));
        $category       = $request->query('category', '');
        $lowStock       = $request->boolean('low_stock');
        $includeInactive = $request->boolean('include_inactive');
        $page           = max(1, (int) $request->query('page', 1));

        $query = $includeInactive
            ? InventoryItem::query()
            : InventoryItem::where('is_active', 1);

        if ($q) {
            $query->where(function ($qb) use ($q) {
                $qb->where('item_name', 'like', "%{$q}%")
                   ->orWhere('barcode', 'like', "%{$q}%");
            });
        }

        if ($category) {
            $query->where('category', $category);
        }

        if ($lowStock) {
            $query->whereRaw('reorder_level > 0 AND quantity_on_hand <= reorder_level');
        }

        $paginator = $query->orderBy('item_name')->paginate(20, ['*'], 'page', $page);

        return response()->json([
            'data'      => $this->formatItems($paginator->items()),
            'total'     => $paginator->total(),
            'page'      => $page,
            'last_page' => $paginator->lastPage(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireAuth();

        $validated = $request->validate([
            'item_name'     => 'required|string|max:150',
            'barcode'       => 'nullable|string|max:100|unique:inventory_items,barcode',
            'category'      => 'required|in:medicine,vaccine,food,grooming_supply,pet_shop,miscellaneous',
            'unit'          => 'required|string|max:50',
            'description'   => 'nullable|string|max:255',
            'unit_cost'     => 'nullable|numeric|decimal:0,2|min:0|max:'.self::MAX_MONEY,
            'selling_price' => 'nullable|numeric|decimal:0,2|min:0|max:'.self::MAX_MONEY,
            'reorder_level' => 'nullable|numeric|decimal:0,2|min:0|max:'.self::MAX_QUANTITY,
        ]);

        $item = InventoryItem::create([
            ...$validated,
            'unit_cost'        => $validated['unit_cost'] ?? 0,
            'quantity_on_hand' => 0,
            'reorder_level'    => $validated['reorder_level'] ?? 0,
            'is_active'        => 1,
        ]);

        return response()->json(['data' => $this->formatItem($item)], 201);
    }

    public function show(int $itemId): JsonResponse
    {
        $this->requireAuth();

        $item = InventoryItem::findOrFail($itemId);

        return response()->json(['data' => $this->formatItem($item)]);
    }

    public function update(Request $request, int $itemId): JsonResponse
    {
        $this->requireAuth();

        $item = InventoryItem::findOrFail($itemId);

        $validated = $request->validate([
            'item_name'     => 'sometimes|string|max:150',
            'barcode'       => "nullable|string|max:100|unique:inventory_items,barcode,{$item->item_id},item_id",
            'category'      => 'sometimes|in:medicine,vaccine,food,grooming_supply,pet_shop,miscellaneous',
            'unit'          => 'sometimes|string|max:50',
            'description'   => 'nullable|string|max:255',
            'unit_cost'     => 'nullable|numeric|decimal:0,2|min:0|max:'.self::MAX_MONEY,
            'selling_price' => 'nullable|numeric|decimal:0,2|min:0|max:'.self::MAX_MONEY,
            'reorder_level' => 'nullable|numeric|decimal:0,2|min:0|max:'.self::MAX_QUANTITY,
        ]);

        // Prevent accidentally overwriting stock quantity via edit form
        unset($validated['quantity_on_hand']);

        $item->update($validated);

        return response()->json(['data' => $this->formatItem($item->fresh())]);
    }

    public function deactivate(int $itemId): JsonResponse
    {
        $this->requireAuth();

        $item = InventoryItem::findOrFail($itemId);

        if ((float) $item->quantity_on_hand > 0) {
            abort(422, "Cannot deactivate \"{$item->item_name}\" — it still has {$item->quantity_on_hand} {$item->unit} in stock. Record a stock-out first.");
        }

        $item->update(['is_active' => 0]);

        return response()->json(['message' => 'Item deactivated.']);
    }

    public function reactivate(int $itemId): JsonResponse
    {
        $this->requireAuth();

        $item = InventoryItem::findOrFail($itemId);
        $item->update(['is_active' => 1]);

        return response()->json(['data' => $this->formatItem($item->fresh())]);
    }

    // ── Barcode & Search ───────────────────────────────────────────────────────

    public function findByBarcode(string $barcode): JsonResponse
    {
        $this->requireAuth();

        $item = InventoryItem::where('barcode', $barcode)
                             ->where('is_active', 1)
                             ->first();

        if (! $item) {
            return response()->json(['message' => 'Item not found.'], 404);
        }

        return response()->json(['data' => $this->formatItem($item)]);
    }

    public function search(Request $request): JsonResponse
    {
        $this->requireAuth();

        $q = trim($request->query('q', ''));

        if (strlen($q) < 2) {
            return response()->json(['data' => []]);
        }

        $items = InventoryItem::where('is_active', 1)
            ->where(function ($query) use ($q) {
                $query->where('item_name', 'like', "%{$q}%")
                      ->orWhere('barcode', 'like', "%{$q}%");
            })
            ->orderBy('item_name')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => $this->formatItems($items),
        ]);
    }

    // ── Stock Movements ────────────────────────────────────────────────────────

    public function stockIn(Request $request): JsonResponse
    {
        $user = $this->requireAuth();

        $validated = $request->validate([
            'items'                => 'required|array|min:1',
            'items.*.item_id'      => 'required|integer|exists:inventory_items,item_id',
            'items.*.quantity'     => 'required|numeric|decimal:0,2|min:0.01|max:'.self::MAX_QUANTITY,
            'items.*.reason'       => 'required|in:purchase,return,adjustment',
            'items.*.unit_cost'    => 'nullable|numeric|decimal:0,2|min:0|max:'.self::MAX_MONEY,
            'items.*.batch_number' => 'nullable|string|max:100',
            'items.*.expiry_date'  => 'required|date|after_or_equal:today',
            'items.*.notes'        => 'nullable|string|max:500',
            'supplier_id'          => 'nullable|integer|exists:suppliers,supplier_id',
        ]);

        $results = [];

        DB::transaction(function () use ($validated, $user, &$results) {
            // A stable item order prevents opposing multi-item requests from
            // taking row locks in reverse order.
            $entries = collect($validated['items'])
                ->sortBy(fn (array $entry) => (int) $entry['item_id'])
                ->values();

            foreach ($entries as $entry) {
                $item = InventoryItem::where('item_id', $entry['item_id'])
                                     ->where('is_active', 1)
                                     ->lockForUpdate()
                                     ->firstOrFail();

                if ((float) $item->quantity_on_hand + (float) $entry['quantity'] > self::MAX_QUANTITY) {
                    abort(422, "Stock-in would exceed the maximum supported quantity for \"{$item->item_name}\".");
                }

                $item->increment('quantity_on_hand', $entry['quantity']);

                $tx = InventoryTransaction::create([
                    'item_id'           => $item->item_id,
                    'type'              => 'stock_in',
                    'quantity'          => $entry['quantity'],
                    'unit_cost_at_time' => $entry['unit_cost'] ?? $item->unit_cost,
                    'reason'            => $entry['reason'],
                    'supplier_id'       => $validated['supplier_id'] ?? null,
                    'batch_number'      => $entry['batch_number'] ?? null,
                    'expiry_date'       => $entry['expiry_date'] ?? null,
                    'notes'             => $entry['notes'] ?? null,
                    'reference_type'    => 'manual',
                    'performed_by'      => $user->user_id,
                ]);

                $results[] = [
                    'item_id'          => $item->item_id,
                    'item_name'        => $item->item_name,
                    'quantity_added'   => $entry['quantity'],
                    'quantity_on_hand' => $item->quantity_on_hand,
                    'unexpired_quantity' => $this->batchBalances
                        ->forItem((int) $item->item_id)['unexpired_quantity'],
                    'transaction_id'   => $tx->transaction_id,
                ];
            }
        });

        return response()->json(['data' => $results], 201);
    }

    public function stockOut(Request $request): JsonResponse
    {
        $user = $this->requireAuth();

        $validated = $request->validate([
            'items'                  => 'required|array|min:1',
            'items.*.item_id'        => 'required|integer|exists:inventory_items,item_id',
            'items.*.quantity'       => 'required|numeric|decimal:0,2|min:0.01|max:'.self::MAX_QUANTITY,
            'items.*.reason'         => 'required|in:used,sold,expired,damaged,adjustment',
            'items.*.selling_price'  => 'nullable|numeric|decimal:0,2|min:0|max:'.self::MAX_MONEY,
            'items.*.notes'          => 'nullable|string|max:500',
            'items.*.reference_type' => 'nullable|in:manual,appointment,pos',
            'items.*.reference_id'   => 'nullable|integer',
        ]);

        $results = [];

        DB::transaction(function () use ($validated, $user, &$results) {
            // Process constrained expiry pools before unrestricted write-offs,
            // and lock item rows in a deterministic order. This makes a valid
            // mixed request independent of its client-side row order.
            $reasonPriority = fn (string $reason): int => match ($reason) {
                'expired' => 0,
                'sold', 'used' => 1,
                default => 2,
            };
            $entries = collect($validated['items'])
                ->sort(function (array $left, array $right) use ($reasonPriority): int {
                    return [
                        (int) $left['item_id'],
                        $reasonPriority($left['reason']),
                    ] <=> [
                        (int) $right['item_id'],
                        $reasonPriority($right['reason']),
                    ];
                })
                ->values();

            foreach ($entries as $entry) {
                $item = InventoryItem::where('item_id', $entry['item_id'])
                                     ->where('is_active', 1)
                                     ->lockForUpdate()
                                     ->firstOrFail();

                if ((float) $item->quantity_on_hand < (float) $entry['quantity']) {
                    abort(422, "Insufficient stock for \"{$item->item_name}\". Available: {$item->quantity_on_hand} {$item->unit}.");
                }

                $balance = $this->batchBalances->forItem((int) $item->item_id);
                if (in_array($entry['reason'], ['sold', 'used'], true)
                    && (float) $balance['unexpired_quantity'] + 0.00001 < (float) $entry['quantity']) {
                    abort(422, "Insufficient unexpired stock for \"{$item->item_name}\". Unexpired available: {$balance['unexpired_quantity']} {$item->unit}; physical stock: {$item->quantity_on_hand} {$item->unit}.");
                }
                if ($entry['reason'] === 'expired'
                    && (float) $balance['expired_quantity'] + 0.00001 < (float) $entry['quantity']) {
                    abort(422, "Insufficient expired stock for \"{$item->item_name}\". Expired available: {$balance['expired_quantity']} {$item->unit}; physical stock: {$item->quantity_on_hand} {$item->unit}.");
                }

                $item->decrement('quantity_on_hand', $entry['quantity']);

                $tx = InventoryTransaction::create([
                    'item_id'               => $item->item_id,
                    'type'                  => 'stock_out',
                    'quantity'              => $entry['quantity'],
                    'selling_price_at_time' => $entry['selling_price'] ?? $item->selling_price,
                    'reason'                => $entry['reason'],
                    'notes'                 => $entry['notes'] ?? null,
                    'reference_type'        => $entry['reference_type'] ?? 'manual',
                    'reference_id'          => $entry['reference_id'] ?? null,
                    'performed_by'          => $user->user_id,
                ]);

                $results[] = [
                    'item_id'          => $item->item_id,
                    'item_name'        => $item->item_name,
                    'unit'             => $item->unit,
                    'quantity_removed' => $entry['quantity'],
                    'quantity_on_hand' => $item->quantity_on_hand,
                    'unexpired_quantity' => $this->batchBalances
                        ->forItem((int) $item->item_id)['unexpired_quantity'],
                    'transaction_id'   => $tx->transaction_id,
                ];
            }
        });

        return response()->json(['data' => $results], 201);
    }

    // ── Transaction History ────────────────────────────────────────────────────

    public function transactions(Request $request): JsonResponse
    {
        $this->requireAuth();

        $query = InventoryTransaction::with(['item', 'supplier', 'performedBy'])
                     ->orderBy('created_at', 'desc');

        if ($request->filled('item_id')) {
            $query->where('item_id', $request->integer('item_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('category')) {
            $query->whereHas('item', fn ($q) => $q->where('category', $request->query('category')));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        $page      = max(1, $request->integer('page', 1));
        $paginator = $query->paginate(20, ['*'], 'page', $page);

        return response()->json([
            'data'      => collect($paginator->items())->map(fn ($t) => $this->formatTransaction($t)),
            'total'     => $paginator->total(),
            'page'      => $page,
            'last_page' => $paginator->lastPage(),
        ]);
    }

    // ── Alerts ─────────────────────────────────────────────────────────────────

    public function lowStock(): JsonResponse
    {
        $this->requireAuth();

        $items = InventoryItem::where('is_active', 1)
            ->whereRaw('reorder_level > 0 AND quantity_on_hand <= reorder_level')
            ->orderByRaw('quantity_on_hand / reorder_level ASC')
            ->get();

        return response()->json([
            'data'  => $this->formatItems($items),
            'count' => $items->count(),
        ]);
    }

    public function expiryAlerts(): JsonResponse
    {
        $this->requireAuth();

        $data = $this->currentExpiryAlerts();

        return response()->json([
            'data'  => $data,
            'count' => $data->count(),
        ]);
    }

    public function alertBadge(): JsonResponse
    {
        $this->requireAuth();

        $lowStockCount = InventoryItem::where('is_active', 1)
            ->whereRaw('reorder_level > 0 AND quantity_on_hand <= reorder_level')
            ->count();

        $expiryCount = $this->currentExpiryAlerts()->count();

        return response()->json([
            'low_stock_count'    => $lowStockCount,
            'expiry_alert_count' => $expiryCount,
            'total'              => $lowStockCount + $expiryCount,
        ]);
    }

    // ── Dashboard Summary ──────────────────────────────────────────────────────

    public function summary(): JsonResponse
    {
        $this->requireAuth();

        $totalItems    = InventoryItem::where('is_active', 1)->count();
        $lowStockCount = InventoryItem::where('is_active', 1)
            ->whereRaw('reorder_level > 0 AND quantity_on_hand <= reorder_level')
            ->count();

        $expiryCount = $this->currentExpiryAlerts()->count();

        $recentTransactions = InventoryTransaction::with(['item', 'supplier', 'performedBy'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($t) => $this->formatTransaction($t));

        $topUsed = InventoryTransaction::where('inventory_transactions.type', 'stock_out')
            ->join('inventory_items', 'inventory_transactions.item_id', '=', 'inventory_items.item_id')
            ->select(
                'inventory_items.item_id',
                'inventory_items.item_name',
                'inventory_items.unit'
            )
            ->selectRaw('SUM(inventory_transactions.quantity) as total_used')
            ->where('inventory_transactions.created_at', '>=', now()->subDays(30))
            ->groupBy(
                'inventory_items.item_id',
                'inventory_items.item_name',
                'inventory_items.unit'
            )
            ->orderByDesc('total_used')
            ->limit(5)
            ->get();

        return response()->json([
            'total_items'         => $totalItems,
            'low_stock_count'     => $lowStockCount,
            'expiry_alert_count'  => $expiryCount,
            'recent_transactions' => $recentTransactions,
            'top_used_30_days'    => $topUsed,
        ]);
    }
}
