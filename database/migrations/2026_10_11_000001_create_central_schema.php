<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_admins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->string('theme')->default('system');
            $table->string('locale')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('schema_name')->unique();
            $table->string('status')->default('active');
            $table->string('industry_key')->nullable();
            $table->string('default_locale')->default('en');
            $table->string('receipt_language')->default('bilingual');
            $table->string('accent_color')->default('#0075de');
            $table->string('timezone')->default('Africa/Dar_es_Salaam');
            $table->string('owner_email')->nullable();
            $table->text('suspended_reason')->nullable();
            $table->timestamp('retention_until')->nullable();
            $table->timestamps();
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('domain')->unique();
            $table->boolean('is_primary')->default(true);
            $table->timestamps();
        });

        Schema::create('features', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('type')->default('boolean');
            $table->json('default_value');
            $table->string('module');
            $table->text('description');
            $table->boolean('globally_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('tenant_features', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('feature_key');
            $table->json('value');
            $table->uuid('set_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'feature_key']);
        });

        Schema::create('tenant_limits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('limit_key');
            $table->unsignedInteger('value');
            $table->timestamps();
            $table->unique(['tenant_id', 'limit_key']);
        });

        Schema::create('impersonation_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('platform_admin_id')->index();
            $table->uuid('user_id');
            $table->text('reason');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('industry_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('name');
            $table->json('payload');
            $table->timestamps();
        });

        Schema::create('central_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_id')->nullable();
            $table->string('action');
            $table->string('auditable_type')->nullable();
            $table->uuid('auditable_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('login_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('token_hash');
            $table->uuid('tenant_id')->index();
            $table->uuid('user_id');
            $table->string('purpose');
            $table->uuid('impersonation_log_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('login_links');
        Schema::dropIfExists('central_audit_logs');
        Schema::dropIfExists('industry_templates');
        Schema::dropIfExists('impersonation_logs');
        Schema::dropIfExists('tenant_limits');
        Schema::dropIfExists('tenant_features');
        Schema::dropIfExists('features');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('platform_admins');
    }
};
