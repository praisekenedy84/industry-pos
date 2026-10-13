<?php

namespace App\Support;

class Paths
{
    public static function base(): string
    {
        return app()->bound('makerly.base') ? (string) app('makerly.base') : '';
    }

    public static function to(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        return self::base().($path === '/' ? '' : $path);
    }

    public static function login(?string $slug = null): string
    {
        $slug ??= request()->route('workspace');
        if (is_string($slug) && $slug !== '') {
            return route('workspace.login', ['workspace' => $slug]);
        }

        return route('login');
    }

    public static function safeNext(?string $next, string $slug): ?string
    {
        if (! is_string($next) || $next === '') {
            return null;
        }
        $prefix = '/w/'.$slug.'/';
        if (! str_starts_with($next, $prefix) || str_contains($next, '://') || str_contains($next, '\\')) {
            return null;
        }

        return $next;
    }
}
