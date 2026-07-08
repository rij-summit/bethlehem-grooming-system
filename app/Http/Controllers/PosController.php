<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    private function requireAdmin(Request $request): void
    {
        if ($request->user()?->role !== 'admin') {
            abort(response()->json([
                'success' => false,
                'message' => 'Unauthorized. Admin access required.',
            ], 403));
        }
    }

    // POST /api/pos/transactions
    public function processSale(Request $request)
    {
        $this->requireAdmin($request);

        $request->validate([
            'items'                   => 'required|array|min:1',
            'items.*.item_id'         => 'required|integer|exists:inventory_items,item_id',
            'items.*.quantity'        => 'required|numeric|min:0.01',
            'items.*.price_at_sale'   => 'required|numeric|min:0',
            'total_amount'            => 'required|numeric|min:0',
            'amount_tendered'         => 'required|numeric|min:0',
            'change_amount'           => 'required|numeric|min:0',
            'notes'                   => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();
        try {
            $pos = PosTransaction::create([
                'cashier_id'      => $request->user()->user_id,
                'total_amount'    => $request->total_amount,
                'amount_tendered' => $request->amount_tendered,
                'change_amount'   => $request->change_amount,
                'notes'           => $request->notes,
            ]);

            foreach ($request->items as $line) {
                $item = InventoryItem::where('item_id', $line['item_id'])
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if (!$item) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Item ID ' . $line['item_id'] . ' is inactive or not found.',
                    ], 422);
                }

                if ((float) $item->quantity_on_hand < (float) $line['quantity']) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Insufficient stock for "' . $item->item_name . '". Available: ' . $item->quantity_on_hand . ' ' . $item->unit . '.',
                    ], 422);
                }

                $subtotal = round((float) $line['quantity'] * (float) $line['price_at_sale'], 2);

                PosTransactionItem::create([
                    'pos_id'        => $pos->pos_id,
                    'item_id'       => $item->item_id,
                    'quantity'      => $line['quantity'],
                    'price_at_sale' => $line['price_at_sale'],
                    'subtotal'      => $subtotal,
                ]);

                $item->decrement('quantity_on_hand', $line['quantity']);

                InventoryTransaction::create([
                    'item_id'               => $item->item_id,
                    'type'                  => 'stock_out',
                    'quantity'              => $line['quantity'],
                    'unit_cost_at_time'     => $item->unit_cost,
                    'selling_price_at_time' => $line['price_at_sale'],
                    'reason'                => 'sold',
                    'reference_id'          => $pos->pos_id,
                    'performed_by'          => $request->user()->user_id,
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Sale failed: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Sale processed successfully.',
            'data'    => $this->formatTransaction($pos->load(['items.item', 'cashier'])),
        ], 201);
    }

    // GET /api/pos/transactions/{posId}
    public function getReceipt(Request $request, int $posId)
    {
        $this->requireAdmin($request);

        $pos = PosTransaction::with(['items.item', 'cashier'])->find($posId);
        if (!$pos) {
            return response()->json(['success' => false, 'message' => 'Transaction not found.'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->formatTransaction($pos)]);
    }

    // GET /api/pos/transactions
    public function getTransactions(Request $request)
    {
        $this->requireAdmin($request);

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
