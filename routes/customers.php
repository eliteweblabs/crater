<?php

use Isygen\Support\Facades\Route;

use Crater\Models\Customer;

// List customers endpoint for OpenClaw
// TODO: GEt http://local/api/openclaw/customers
Route::get('/openclaw/customers', function () {
    $customers = \Crater\Models\Customer::orderBy('name')->get(['id', 'name', 'email', 'company_id']);
    return response()->json(['customers' => $customers]);
});
