<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class WorkspaceSlug
{
    public const RESERVED = [
        'login', 'admin', 'api', 'w', 'invite', 'assets', 'app', 'support',
        'platform', 'signup', 'forgot-password', 'reset-password', 'workspaces',
        'two-factor', 'build', 'up', 'storage', 'vendor', 'offline',
    ];

    public static function assert(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{1,30}[a-z0-9])$/', $slug) || strlen($slug) > 32) {
            throw ValidationException::withMessages([
                'slug' => 'Use 3–32 lowercase letters, numbers or hyphens.',
            ]);
        }
        if (in_array($slug, self::RESERVED, true)) {
            throw ValidationException::withMessages([
                'slug' => 'That workspace address is reserved.',
            ]);
        }

        return $slug;
    }
}
