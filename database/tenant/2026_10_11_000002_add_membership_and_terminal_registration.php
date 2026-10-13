<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('membership_id')->nullable()->index();
        });

        Schema::table('terminals', function (Blueprint $table) {
            $table->boolean('registered')->default(false);
            $table->string('device_token_hash')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('terminals', function (Blueprint $table) {
            $table->dropColumn(['registered', 'device_token_hash']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('membership_id');
        });
    }
};
