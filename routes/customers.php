<?php

use Isygen\Support\Facades\Route;

use Crater\Models\Customer;

// List customers endpoint (custom API)
// TODO: GET http://local/api/custom/customers
Route::get('/custom/customers', function () {
    $customers = \Crater\Models\Customer::orderBy('name')->get(['id', 'name', 'email', 'company_id']);
    return response()->json(['customers' => $customers]);
});
