<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Services\InventoryBatchBalanceService;
use App\Services\InventoryStockMovementService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    private const MAX_MONEY = 999999.99;

    private const MAX_QUANTITY = 99999999.99;

    public function __construct(
        private readonly InventoryBatchBalanceService $batchBalances,
        private readonly InventoryStockMovementService $stockMovements,
    )
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

        $paginator = $query->orderBy('item_name')->paginate(15, ['*'], 'page', $page);

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
            'barcode'       => 'nullable|string|regex:/^[0-9]{1,13}$/|unique:inventory_items,barcode',
            'category'      => 'required|in:medicine,vaccine,food,grooming_supply,pet_shop,miscellaneous',
            'unit'          => 'required|string|max:50',
            'description'   => 'nullable|string|max:255',
            'unit_cost'     => 'required|numeric|decimal:0,2|min:0.01|max:'.self::MAX_MONEY,
            'selling_price' => 'required|numeric|decimal:0,2|min:0.01|max:'.self::MAX_MONEY,
            'reorder_level' => 'required|integer|min:0|max:99999999',
        ]);

        $item = InventoryItem::create([
            ...$validated,
            'unit_cost'        => $validated['unit_cost'],
            'quantity_on_hand' => 0,
            'reorder_level'    => $validated['reorder_level'],
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
            'barcode'       => "nullable|string|regex:/^[0-9]{1,13}$/|unique:inventory_items,barcode,{$item->item_id},item_id",
            'category'      => 'sometimes|in:medicine,vaccine,food,grooming_supply,pet_shop,miscellaneous',
            'unit'          => 'sometimes|string|max:50',
            'description'   => 'nullable|string|max:255',
            'unit_cost'     => 'required|numeric|decimal:0,2|min:0.01|max:'.self::MAX_MONEY,
            'selling_price' => 'required|numeric|decimal:0,2|min:0.01|max:'.self::MAX_MONEY,
            'reorder_level' => 'required|integer|min:0|max:99999999',
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
        $includeInactive = $request->boolean('include_inactive');

        if (strlen($q) < 2) {
            return response()->json(['data' => []]);
        }

        $items = InventoryItem::query()
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', 1))
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
            'items.*.quantity'     => 'required|integer|min:1|max:99999999',
            'items.*.reason'       => 'required|in:purchase,return,adjustment',
            'items.*.unit_cost'    => 'nullable|numeric|decimal:0,2|min:0|max:'.self::MAX_MONEY,
            'items.*.batch_number' => 'nullable|string|max:100',
            'items.*.expiry_date'  => 'required|date|after_or_equal:today',
            'items.*.notes'        => 'nullable|string|max:500',
        ]);

        $results = DB::transaction(fn () => $this->stockMovements->receive(
            $user,
            $validated['items'],
        ));

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

        $results = DB::transaction(fn () => $this->stockMovements->remove(
            $user,
            $validated['items'],
            enforceBatchAvailability: false,
        ));

        return response()->json(['data' => $results], 201);
    }

    // ── Transaction History ────────────────────────────────────────────────────

    public function transactions(Request $request): JsonResponse
    {
        $this->requireAuth();

        $query = InventoryTransaction::with(['item', 'performedBy'])
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

        $page = max(1, $request->integer('page', 1));
        $perPage = $request->integer('per_page', 20) === 10 ? 10 : 20;
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'      => collect($paginator->items())->map(fn ($t) => $this->formatTransaction($t)),
            'total'     => $paginator->total(),
            'page'      => $page,
            'last_page' => $paginator->lastPage(),
        ]);
    }

    // ── Alerts ─────────────────────────────────────────────────────────────────

    public function lowStock(Request $request): JsonResponse
    {
        $this->requireAuth();

        $page = max(1, $request->integer('page', 1));
        $paginator = InventoryItem::where('is_active', 1)
            ->whereRaw('reorder_level > 0 AND quantity_on_hand <= reorder_level')
            ->orderByRaw('quantity_on_hand / reorder_level ASC')
            ->orderBy('item_id')
            ->paginate(10, ['*'], 'page', $page);

        return response()->json([
            'data' => $this->formatItems($paginator->items()),
            'count' => $paginator->total(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    public function expiryAlerts(Request $request): JsonResponse
    {
        $this->requireAuth();

        $data = $this->currentExpiryAlerts();
        $page = max(1, $request->integer('page', 1));
        $total = $data->count();

        return response()->json([
            'data' => $data->forPage($page, 10)->values(),
            'count' => $total,
            'total' => $total,
            'page' => $page,
            'last_page' => max(1, (int) ceil($total / 10)),
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

    public function summary(Request $request): JsonResponse
    {
        $this->requireAuth();

        $totalItems    = InventoryItem::where('is_active', 1)->count();
        $lowStockCount = InventoryItem::where('is_active', 1)
            ->whereRaw('reorder_level > 0 AND quantity_on_hand <= reorder_level')
            ->count();

        $expiryCount = $this->currentExpiryAlerts()->count();

        $recentTransactionsPage = max(1, $request->integer('page', 1));
        $recentTransactions = InventoryTransaction::with(['item', 'performedBy'])
            ->orderBy('created_at', 'desc')
            ->paginate(10, ['*'], 'page', $recentTransactionsPage);

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
            'recent_transactions' => collect($recentTransactions->items())
                ->map(fn ($t) => $this->formatTransaction($t)),
            'recent_transactions_page' => $recentTransactions->currentPage(),
            'recent_transactions_last_page' => $recentTransactions->lastPage(),
            'recent_transactions_total' => $recentTransactions->total(),
            'top_used_30_days'    => $topUsed,
        ]);
    }
}
