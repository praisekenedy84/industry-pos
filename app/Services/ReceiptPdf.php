<?php

namespace App\Services;

use App\Models\Sale;
use App\Support\IndustryVocabulary;
use App\Tenancy\TenancyManager;
use Illuminate\Support\Facades\App;

class ReceiptPdf
{
    private const WIDTH = 226.77;

    private const MARGIN = 12.0;

    /** @var array<string, string>|null */
    private ?array $translations = null;

    /** @var array{en: array<string, string>, sw: array<string, string>}|null */
    private ?array $vocabulary = null;

    /**
     * @param  array<string, string>  $itemNames
     */
    public function render(Sale $sale, string $shopName, string $footer, array $itemNames): string
    {
        $rows = $this->rows($sale, $shopName, $footer, $itemNames);
        $height = $this->height($rows);
        $stream = $this->stream($rows, $height);

        return $this->document($stream, $height);
    }

    /**
     * @param  array<string, string>  $itemNames
     * @return list<array{kind: string, text: string, right: string, bold: bool, size: float}>
     */
    private function rows(Sale $sale, string $shopName, string $footer, array $itemNames): array
    {
        $rows = [];
        foreach ($this->wrapped($shopName !== '' ? $shopName : ' ', 12) as $line) {
            $rows[] = $this->center($line, true, 12);
        }
        foreach ([$sale->receipt?->number, $sale->number] as $line) {
            if ($line) {
                $rows[] = $this->center($this->encode((string) $line), false, 9);
            }
        }
        $seller = $sale->seller?->name ?: $this->label('Unavailable', 'Unavailable');
        $rows[] = $this->center($this->encode($this->label('Seller:', 'Seller:').' '.$seller), false, 9);
        $rows[] = ['kind' => 'gap', 'text' => '', 'right' => '', 'bold' => false, 'size' => 6];

        foreach ($sale->lines as $line) {
            $label = ($itemNames[$line->item_id] ?? '').' x '.$this->quantity($line->qty);
            $rows = array_merge($rows, $this->pair($label, $this->money($line->gross), false));
        }

        $rows[] = ['kind' => 'rule', 'text' => '', 'right' => '', 'bold' => false, 'size' => 8];
        $rows = array_merge($rows, $this->pair($this->label('Total', 'Total'), $this->money($sale->total), true));
        foreach ($sale->payments as $payment) {
            $rows = array_merge($rows, $this->pair($this->label('payment.'.$payment->method, (string) $payment->method), $this->money($payment->amount), false));
        }

        $footer = trim($footer);
        if ($footer !== '') {
            $rows[] = ['kind' => 'gap', 'text' => '', 'right' => '', 'bold' => false, 'size' => 8];
            foreach (preg_split("/\r\n|\n|\r/", $footer) ?: [] as $line) {
                foreach ($this->wrapped($line, 9) as $wrapped) {
                    $rows[] = $this->center($wrapped, false, 9);
                }
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{kind: string, text: string, right: string, bold: bool, size: float}>  $rows
     */
    private function height(array $rows): float
    {
        $height = self::MARGIN * 2;
        foreach ($rows as $row) {
            $height += $row['size'] + ($row['kind'] === 'rule' ? 4 : 3);
        }

        return max($height, 140);
    }

    /**
     * @param  list<array{kind: string, text: string, right: string, bold: bool, size: float}>  $rows
     */
    private function stream(array $rows, float $height): string
    {
        $commands = ['0.4 w'];
        $y = $height - self::MARGIN;
        foreach ($rows as $row) {
            if ($row['kind'] === 'gap') {
                $y -= $row['size'];

                continue;
            }
            if ($row['kind'] === 'rule') {
                $y -= $row['size'];
                $commands[] = sprintf('%.2F %.2F m %.2F %.2F l S', self::MARGIN, $y, self::WIDTH - self::MARGIN, $y);

                continue;
            }
            $y -= $row['size'];
            $font = $row['bold'] ? 'F2' : 'F1';
            $size = $row['size'];
            if ($row['kind'] === 'pair') {
                $commands[] = sprintf('BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm %s Tj ET', $font, $size, self::MARGIN, $y, $this->literal($row['text']));
                $rightX = self::WIDTH - self::MARGIN - (strlen($row['right']) * $this->charWidth($size));
                $commands[] = sprintf('BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm %s Tj ET', $font, $size, $rightX, $y, $this->literal($row['right']));
            } else {
                $x = (self::WIDTH - (strlen($row['text']) * $this->charWidth($size))) / 2;
                $commands[] = sprintf('BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm %s Tj ET', $font, $size, $x, $y, $this->literal($row['text']));
            }
            $y -= 3;
        }

        return implode("\n", $commands);
    }

    /**
     * @return array{kind: string, text: string, right: string, bold: bool, size: float}
     */
    private function center(string $encoded, bool $bold, float $size): array
    {
        return ['kind' => 'center', 'text' => $encoded, 'right' => '', 'bold' => $bold, 'size' => $size];
    }

    /**
     * @return list<array{kind: string, text: string, right: string, bold: bool, size: float}>
     */
    private function pair(string $left, string $right, bool $bold): array
    {
        $size = 9.0;
        $encodedRight = $this->encode($right);
        $gap = $this->charWidth($size);
        $leftWidth = self::WIDTH - (self::MARGIN * 2) - (strlen($encodedRight) * $this->charWidth($size)) - $gap;
        $lines = $this->wrapEncoded($this->encode($left), max(8, (int) floor($leftWidth / $this->charWidth($size))));
        $rows = [];
        foreach ($lines as $index => $line) {
            $rows[] = [
                'kind' => 'pair',
                'text' => $line,
                'right' => $index === 0 ? $encodedRight : '',
                'bold' => $bold,
                'size' => $size,
            ];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function wrapped(string $text, float $size): array
    {
        $maxChars = max(8, (int) floor((self::WIDTH - (self::MARGIN * 2)) / $this->charWidth($size)));

        return $this->wrapEncoded($this->encode($text), $maxChars);
    }

    /**
     * @return list<string>
     */
    private function wrapEncoded(string $encoded, int $maxChars): array
    {
        if ($encoded === '') {
            return [''];
        }
        $lines = [];
        $current = '';
        foreach (explode(' ', $encoded) as $word) {
            if (strlen($word) > $maxChars) {
                if ($current !== '') {
                    $lines[] = $current;
                    $current = '';
                }
                foreach (str_split($word, $maxChars) as $piece) {
                    $lines[] = $piece;
                }

                continue;
            }
            $candidate = $current === '' ? $word : $current.' '.$word;
            if (strlen($candidate) <= $maxChars) {
                $current = $candidate;
            } else {
                $lines[] = $current;
                $current = $word;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }

    private function document(string $stream, float $height): string
    {
        $width = sprintf('%.2F', self::WIDTH);
        $pageHeight = sprintf('%.2F', $height);
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Count 1 /Kids [3 0 R] >>',
            3 => "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$width} {$pageHeight}] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >>",
            4 => '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>',
            6 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= count($objects); $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }

    private function literal(string $encoded): string
    {
        $out = '';
        $length = strlen($encoded);
        for ($i = 0; $i < $length; $i++) {
            $byte = $encoded[$i];
            $ord = ord($byte);
            if ($byte === '\\' || $byte === '(' || $byte === ')') {
                $out .= '\\'.$byte;
            } elseif ($ord < 32 || $ord > 126) {
                $out .= sprintf('\\%03o', $ord);
            } else {
                $out .= $byte;
            }
        }

        return '('.$out.')';
    }

    private function encode(string $text): string
    {
        $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($converted === false) {
            return preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
        }

        return $converted;
    }

    private function charWidth(float $size): float
    {
        return $size * 0.6;
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', ',').' TZS';
    }

    private function quantity(mixed $value): string
    {
        $number = round((float) $value, 2);
        if (abs($number - round($number)) < 0.001) {
            return number_format($number, 0, '.', ',');
        }

        return rtrim(rtrim(number_format($number, 2, '.', ','), '0'), '.');
    }

    private function label(string $key, string $fallback): string
    {
        $this->translations ??= $this->loadTranslations();
        $value = $this->translations[$key] ?? null;
        $text = is_string($value) && $value !== '' ? $value : $fallback;
        if (! app(TenancyManager::class)->initialized()) {
            return $text;
        }
        $this->vocabulary ??= app(IndustryVocabulary::class)->forCurrentTenant();

        return app(IndustryVocabulary::class)->apply($text, $this->vocabulary, App::getLocale() === 'sw' ? 'sw' : 'en');
    }

    /**
     * @return array<string, string>
     */
    private function loadTranslations(): array
    {
        $locale = App::getLocale() === 'sw' ? 'sw' : 'en';
        $path = lang_path($locale.'.json');
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
