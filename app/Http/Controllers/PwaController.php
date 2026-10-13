<?php

namespace App\Http\Controllers;

use App\Auth\WorkspaceSessionManager;
use App\Models\Branch;
use App\Models\Setting;
use App\Models\Terminal;
use App\Support\Paths;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PwaController extends Controller
{
    public function manifest(Request $request): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        $tenant = $request->attributes->get('tenant');
        $slug = $workspace->slug;
        $base = '/w/'.$slug;
        $name = $workspace->display_name;
        $policy = app(WorkspaceSessionManager::class)->policy($tenant->id);
        $display = ($policy['pwa_display'] ?? 'standalone') === 'fullscreen' ? 'fullscreen' : 'standalone';

        return response()->json([
            'id' => $base.'/',
            'start_url' => $base.'/?source=pwa',
            'scope' => $base.'/',
            'name' => $name,
            'short_name' => Str::limit($name, 12, ''),
            'display' => $display,
            'orientation' => 'any',
            'theme_color' => '#f6f5f4',
            'background_color' => '#f6f5f4',
            'categories' => ['business'],
            'lang' => $tenant->default_locale ?: 'en',
            'icons' => [
                ['src' => $base.'/icon/192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $base.'/icon/512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $base.'/icon/512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                ['name' => 'New sale', 'url' => $base.'/pos'],
                ['name' => 'New production order', 'url' => $base.'/production/orders'],
                ['name' => 'Stock', 'url' => $base.'/inventory'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }

    public function offline(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');
        $name = e($workspace->display_name);
        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$name} is offline</title>
            <style>
                body { font-family: sans-serif; background: #f6f5f4; color: #37352f; display: grid; min-height: 100vh; place-items: center; margin: 0; }
                main { max-width: 28rem; padding: 1.5rem; }
            </style>
        </head>
        <body>
            <main>
                <p>Makerly</p>
                <h1>{$name} is offline</h1>
                <p>You can keep selling on a registered terminal. Other pages need a connection. Sign-in from a new device waits until you are back online.</p>
            </main>
        </body>
        </html>
        HTML;

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function icon(Request $request): Response
    {
        $size = (int) $request->route('size');
        $size = in_array($size, [192, 512], true) ? $size : 192;
        $png = function_exists('imagecreatetruecolor') ? $this->drawnIcon($size) : $this->solidPng($size);

        return response($png, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=86400']);
    }

    private function drawnIcon(int $size): string
    {
        $image = imagecreatetruecolor($size, $size);
        $background = imagecolorallocate($image, 0, 117, 222);
        $ink = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, $size, $size, $background);
        $margin = (int) ($size * 0.2);
        imagefilledellipse($image, (int) ($size / 2), (int) ($size / 2), $size - $margin, $size - $margin, $ink);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        return $png === false ? $this->solidPng($size) : $png;
    }

    private function solidPng(int $size): string
    {
        $row = "\x00".str_repeat("\x00\x75\xde", $size);
        $compressed = gzcompress(str_repeat($row, $size), 9);
        $ihdr = pack('NNCCCCC', $size, $size, 8, 2, 0, 0, 0);
        $png = "\x89PNG\r\n\x1a\n";
        $png .= $this->pngChunk('IHDR', $ihdr);
        $png .= $this->pngChunk('IDAT', $compressed);
        $png .= $this->pngChunk('IEND', '');

        return $png;
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.hash('crc32b', $type.$data, true);
    }

    public function terminalSetup(Request $request): InertiaResponse
    {
        $this->authorize('settings.manage');

        return Inertia::render('Pos/TerminalSetup', [
            'branches' => Branch::query()->where('status', 'active')->get(['id', 'name']),
            'manifest' => Paths::to('/manifest.webmanifest'),
        ]);
    }

    public function terminalStore(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'branch_id' => ['required', 'uuid'],
            'paper_width' => ['required', 'in:80,57'],
            'fullscreen' => ['nullable', 'boolean'],
        ]);
        $token = Str::random(40);
        $terminal = Terminal::query()->create([
            'branch_id' => $data['branch_id'],
            'name' => $data['name'],
            'paper_width' => (int) $data['paper_width'],
            'registered' => true,
            'device_token_hash' => hash('sha256', $token),
        ]);
        cookie()->queue(cookie('pos_terminal', $terminal->id, 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'lax'));
        if ($request->boolean('fullscreen')) {
            Setting::putValue('pwa_display', 'fullscreen');
        }

        return back()->with('success', 'Terminal registered. Install the app, then sign in with a PIN on this device.');
    }
}
