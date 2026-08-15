<?php

/**
 * REΛVE custom API routes — loaded by app/Providers/RouteServiceProvider.php
 * alongside the main routes/api.php file.
 *
 * All routes here are prefixed with /api (api middleware group).
 * Auth: every route checks the X-Crater-Api-Token header against the CRATER_API_TOKEN env var.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// Update a single line item on an existing invoice.
// PUT /api/custom/invoice/{invoiceId}/items/{itemId}
//
// Body (JSON, all fields optional):
//   name        string   — new item name / label
//   description string   — new description
//   quantity    numeric  — new quantity
//   price       numeric  — new unit price in whole dollars (stored as cents internally)
// ---------------------------------------------------------------------------
Route::put('/custom/invoice/{invoiceId}/items/{itemId}', function (Request $request, $invoiceId, $itemId) {
    if ($request->header('X-Crater-Api-Token') !== env('CRATER_API_TOKEN')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $invoice = \Crater\Models\Invoice::findOrFail($invoiceId);

    // Verify the item belongs to this invoice.
    $item = $invoice->items()->where('id', $itemId)->firstOrFail();

    $validated = $request->validate([
        'name'        => 'nullable|string|max:255',
        'description' => 'nullable|string',
        'quantity'    => 'nullable|numeric|min:0',
        'price'       => 'nullable|numeric|min:0', // whole dollars → stored as cents
    ]);

    if (!empty($validated['name'])) {
        $item->name = $validated['name'];
    }
    if (array_key_exists('description', $validated)) {
        $item->description = $validated['description'];
    }
    if (isset($validated['quantity'])) {
        $item->quantity = $validated['quantity'];
    }
    if (isset($validated['price'])) {
        // Crater stores price in cents; the agent sends whole dollars.
        $item->price = (int) round($validated['price'] * 100);
        $item->total = (int) round($validated['price'] * 100 * $item->quantity);
    }

    $item->save();

    // Recalculate invoice totals.
    $invoice->updateTotals();

    return response()->json([
        'success'     => true,
        'item_id'     => $item->id,
        'invoice_id'  => $invoice->id,
        'name'        => $item->name,
        'description' => $item->description,
        'quantity'    => $item->quantity,
        'price'       => $item->price / 100,
        'total'       => $item->total / 100,
    ]);
});
