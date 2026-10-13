<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('login_alias')->nullable();
            $table->string('password')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->string('status')->default('active');
            $table->string('locale')->nullable();
            $table->string('theme')->default('system');
            $table->boolean('must_change_password')->default(false);
            $table->uuid('preferred_tenant_id')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique('email');
            $table->unique('phone');
            $table->unique('login_alias');
        });

        Schema::create('workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->unique();
            $table->string('slug')->unique();
            $table->string('display_name');
            $table->string('logo_path')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('identity_id')->index();
            $table->uuid('tenant_id')->index();
            $table->uuid('tenant_user_id');
            $table->string('status')->default('active');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['identity_id', 'tenant_id']);
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('tenant_user_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->uuid('role_id')->nullable();
            $table->json('branch_ids')->nullable();
            $table->string('token_hash');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('device_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('token_hash')->unique();
            $table->uuid('identity_id')->index();
            $table->boolean('remember')->default(false);
            $table->boolean('two_factor_ok')->default(false);
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device')->nullable();
            $table->string('location')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('workspace_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('device_session_id')->index();
            $table->uuid('tenant_id')->index();
            $table->uuid('identity_id')->index();
            $table->uuid('membership_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->string('status')->default('active');
            $table->uuid('impersonated_by')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id']);
        });

        Schema::create('api_token_tenants', function (Blueprint $table) {
            $table->string('token_hash')->primary();
            $table->uuid('tenant_id')->index();
            $table->timestamps();
        });

        Schema::create('identity_password_resets', function (Blueprint $table) {
            $table->string('identifier')->primary();
            $table->string('token_hash');
            $table->timestamp('created_at')->nullable();
        });

        if (Schema::hasTable('tenants')) {
            $this->backfill();
        }

        Schema::dropIfExists('domains');
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_password_resets');
        Schema::dropIfExists('api_token_tenants');
        Schema::dropIfExists('workspace_sessions');
        Schema::dropIfExists('device_sessions');
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('workspaces');
        Schema::dropIfExists('identities');
    }

    private function backfill(): void
    {
        $tenants = DB::table('tenants')->get();
        foreach ($tenants as $tenant) {
            if (! preg_match('/^[a-z][a-z0-9_]+$/', (string) $tenant->schema_name)) {
                continue;
            }
            DB::table('workspaces')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'slug' => $tenant->slug,
                'display_name' => $tenant->name,
                'status' => $tenant->status === 'active' ? 'active' : 'suspended',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::statement('ALTER TABLE '.$tenant->schema_name.'.users ADD COLUMN IF NOT EXISTS membership_id uuid');
            DB::statement('ALTER TABLE '.$tenant->schema_name.'.terminals ADD COLUMN IF NOT EXISTS registered boolean NOT NULL DEFAULT false');
            DB::statement('ALTER TABLE '.$tenant->schema_name.'.terminals ADD COLUMN IF NOT EXISTS device_token_hash varchar(255)');
            $users = DB::select('SELECT id, name, email, phone, password, status, locale, theme, employee_code FROM '.$tenant->schema_name.'.users');
            foreach ($users as $user) {
                $identityId = $this->identityFor($tenant, $user);
                $membershipId = (string) Str::uuid();
                $status = match ($user->status) {
                    'suspended' => 'suspended',
                    'deactivated' => 'removed',
                    'invited' => 'invited',
                    default => 'active',
                };
                DB::table('memberships')->insert([
                    'id' => $membershipId,
                    'identity_id' => $identityId,
                    'tenant_id' => $tenant->id,
                    'tenant_user_id' => $user->id,
                    'status' => $status,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::update(
                    'UPDATE '.$tenant->schema_name.'.users SET membership_id = ? WHERE id = ?',
                    [$membershipId, $user->id],
                );
            }
        }
    }

    private function identityFor(object $tenant, object $user): string
    {
        $email = $user->email ? strtolower($user->email) : null;
        if ($email) {
            $existing = DB::table('identities')->where('email', $email)->value('id');
            if ($existing) {
                return $existing;
            }
        }
        $alias = null;
        if (! $email && ! $user->phone) {
            $alias = $tenant->slug.':'.strtolower($user->employee_code ?: substr($user->id, 0, 8));
        }
        $id = (string) Str::uuid();
        DB::table('identities')->insert([
            'id' => $id,
            'email' => $email,
            'phone' => $user->phone ?: null,
            'login_alias' => $alias,
            'password' => $user->password,
            'status' => 'active',
            'locale' => $user->locale,
            'theme' => $user->theme ?: 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
};
