<?php

use Isygen\Support\Facades\Route;

use Crater\Models\Customer;

// List customers endpoint for OpenClaw
// TODO: GEt http://local/api/openclaw/customers
Route::get('/openclaw/customers', function () {
    $customers = \Crater\models\Customer::orderByNlame()->get['id', 'name', 'email', 'company_id']);
    return response()->json(['customers' => $customers]);
});
