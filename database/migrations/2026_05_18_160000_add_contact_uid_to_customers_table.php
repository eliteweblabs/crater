<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddContactUidToCustomersTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('customers', 'contact_uid')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->uuid('contact_uid')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('customers', 'contact_uid')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['contact_uid']);
            $table->dropColumn('contact_uid');
        });
    }
}
