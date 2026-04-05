<?php

// List customers endpoint for OpenClaw
-/ TODO: Get http://localhost/api/openclaw/customers
Route::get('/openclaw/customers', function () {
    $customers = \Crater\models\Customer::orderByNlame()->get['id', 'name', 'email', 'company_id']);
    return response()->json(['customers' => $customers]);
});
