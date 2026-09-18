<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use App\Services\InventoryStockMovementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    private const MAX_MONEY = 999999.99;

    public function __construct(private readonly InventoryStockMovementService $stockMovements) {}

    // POST /api/pos/transactions
    public function processSale(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer|exists:inventory_items,item_id',
            'items.*.quantity' => 'required|numeric|decimal:0,2|min:0.01|max:99999999.99',
            'items.*.price_at_sale' => 'sometimes|numeric|decimal:0,2|min:0|max:'.self::MAX_MONEY,
            'total_amount' => 'sometimes|numeric|decimal:0,2|min:0|max:'.self::MAX_MONEY,
            'amount_tendered' => 'required|numeric|decimal:0,2|min:0|max:'.self::MAX_MONEY,
            'change_amount' => 'sometimes|numeric|decimal:0,2|min:0|max:'.self::MAX_MONEY,
            'notes' => 'nullable|string|max:500',
        ]);

        $pos = DB::transaction(function () use ($request, $validated) {
            $requestedLines = collect($validated['items'])
                ->groupBy(fn (array $line) => (int) $line['item_id'])
                ->map(fn ($lines, int $itemId) => [
                    'item_id' => $itemId,
                    'quantity' => round((float) $lines->sum('quantity'), 2),
                ])
                ->sortBy('item_id')
                ->values();
            $preparedLines = [];
            $computedTotal = 0.0;

            foreach ($requestedLines as $line) {
                $item = InventoryItem::where('item_id', $line['item_id'])
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if (! $item) {
                    abort(response()->json([
                        'success' => false,
                        'message' => 'Item ID '.$line['item_id'].' is inactive or not found.',
                    ], 422));
                }

                if ($item->selling_price === null) {
                    abort(response()->json([
                        'success' => false,
                        'message' => 'A selling price must be set for "'.$item->item_name.'" before it can be sold.',
                    ], 422));
                }

                $priceAtSale = round((float) $item->selling_price, 2);
                $subtotal = round((float) $line['quantity'] * $priceAtSale, 2);
                if ($priceAtSale > self::MAX_MONEY || $subtotal > self::MAX_MONEY) {
                    abort(response()->json([
                        'success' => false,
                        'message' => 'The sale exceeds the maximum supported transaction amount of ₱'.number_format(self::MAX_MONEY, 2).'.',
                    ], 422));
                }
                $computedTotal = round($computedTotal + $subtotal, 2);
                if ($computedTotal > self::MAX_MONEY) {
                    abort(response()->json([
                        'success' => false,
                        'message' => 'The sale exceeds the maximum supported transaction amount of ₱'.number_format(self::MAX_MONEY, 2).'.',
                    ], 422));
                }
                $preparedLines[] = [
                    'item' => $item,
                    'quantity' => $line['quantity'],
                    'price_at_sale' => $priceAtSale,
                    'subtotal' => $subtotal,
                ];
            }

            $amountTendered = round((float) $validated['amount_tendered'], 2);
            if ($amountTendered + 0.00001 < $computedTotal) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'Amount tendered is less than the server-calculated total of ₱'.number_format($computedTotal, 2).'.',
                ], 422));
            }

            $pos = PosTransaction::create([
                'cashier_id' => $request->user()->user_id,
                'total_amount' => $computedTotal,
                'amount_tendered' => $amountTendered,
                'change_amount' => round($amountTendered - $computedTotal, 2),
                'notes' => $validated['notes'] ?? null,
            ]);

            $stockOutEntries = [];

            foreach ($preparedLines as $line) {
                /** @var InventoryItem $item */
                $item = $line['item'];
                PosTransactionItem::create([
                    'pos_id' => $pos->pos_id,
                    'item_id' => $item->item_id,
                    'quantity' => $line['quantity'],
                    'price_at_sale' => $line['price_at_sale'],
                    'subtotal' => $line['subtotal'],
                ]);

                $stockOutEntries[] = [
                    'item_id' => $item->item_id,
                    'quantity' => $line['quantity'],
                    'unit_cost_at_time' => $item->unit_cost,
                    'selling_price' => $line['price_at_sale'],
                    'reason' => 'sold',
                    'reference_type' => 'pos',
                    'reference_id' => $pos->pos_id,
                ];
            }

            $this->stockMovements->remove($request->user(), $stockOutEntries);

            return $pos;
        });

        return response()->json([
            'success' => true,
            'message' => 'Sale processed successfully.',
            'data' => $this->formatTransaction($pos->load(['items.item', 'cashier'])),
        ], 201);
    }

    // GET /api/pos/transactions/{posId}
    public function getReceipt(Request $request, int $posId)
    {
        $pos = PosTransaction::with(['items.item', 'cashier'])->find($posId);
        if (!$pos) {
            return response()->json(['success' => false, 'message' => 'Transaction not found.'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->formatTransaction($pos)]);
    }

    // GET /api/pos/transactions
    public function getTransactions(Request $request)
    {
        $query = PosTransaction::with(['items.item', 'cashier']);

        if ($request->filled('cashier_id')) {
            $query->where('cashier_id', (int) $request->cashier_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $paginator = $query->orderByDesc('created_at')->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $paginator->getCollection()->map(fn ($pos) => $this->formatTransaction($pos)),
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    private function formatTransaction(PosTransaction $pos): array
    {
        return [
            'pos_id'          => $pos->pos_id,
            'cashier_id'      => $pos->cashier_id,
            'cashier_name'    => $pos->cashier
                ? trim($pos->cashier->first_name . ' ' . $pos->cashier->last_name)
                : null,
            'total_amount'    => $pos->total_amount,
            'amount_tendered' => $pos->amount_tendered,
            'change_amount'   => $pos->change_amount,
            'notes'           => $pos->notes,
            'created_at'      => $pos->created_at,
            'items'           => $pos->items->map(fn ($line) => [
                'id'            => $line->id,
                'item_id'       => $line->item_id,
                'item_name'     => $line->item?->item_name,
                'unit'          => $line->item?->unit,
                'quantity'      => $line->quantity,
                'price_at_sale' => $line->price_at_sale,
                'subtotal'      => $line->subtotal,
            ])->values(),
        ];
    }
}
