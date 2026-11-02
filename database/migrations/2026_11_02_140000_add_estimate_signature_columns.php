<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->timestamp('signed_at')->nullable()->after('status');
            $table->string('signer_name')->nullable()->after('signed_at');
            $table->string('signature_doc_id', 64)->nullable()->after('signer_name');
            $table->string('signature_view_url', 512)->nullable()->after('signature_doc_id');
        });
    }

    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropColumn([
                'signed_at',
                'signer_name',
                'signature_doc_id',
                'signature_view_url',
            ]);
        });
    }
};
