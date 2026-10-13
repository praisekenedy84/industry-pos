<?php

namespace App\Support;

use App\Models\IndustryTemplate;
use App\Tenancy\TenancyManager;

final class IndustryVocabulary
{
    /** @var array<string, array{en: array<string, string>, sw: array<string, string>}> */
    private array $resolved = [];

    /**
     * @return array{en: array<string, string>, sw: array<string, string>}
     */
    public function forCurrentTenant(): array
    {
        return $this->forIndustry(app(TenancyManager::class)->tenant()?->industry_key);
    }

    /**
     * @return array{en: array<string, string>, sw: array<string, string>}
     */
    public function forIndustry(?string $key): array
    {
        $key = is_string($key) && isset(IndustryCatalog::all()[$key]) ? $key : 'general';
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $payload = IndustryTemplate::query()->where('key', $key)->value('payload');
        $custom = is_array($payload) && ($payload['vocabulary_customized'] ?? false) === true
            ? ($payload['vocabulary'] ?? null)
            : null;
        $vocabulary = is_array($custom) ? $custom : (IndustryCatalog::find($key)['vocabulary'] ?? []);

        return $this->resolved[$key] = IndustryTerms::normalize($vocabulary);
    }

    public function forget(string $key): void
    {
        unset($this->resolved[$key]);
    }

    /**
     * @param  array<string, mixed>  $vocabulary
     * @return list<array{from: string, to: string}>
     */
    public function pairs(array $vocabulary, string $lang): array
    {
        $normalized = IndustryTerms::normalize($vocabulary);
        $labels = $normalized[$lang] ?? $normalized['en'];
        $pairs = [];
        foreach (IndustryTerms::phrasesFor($lang) as $from => $key) {
            $label = $labels[$key] ?? '';
            if ($label === '' || mb_strtolower($label) === $from) {
                continue;
            }
            $pairs[] = ['from' => $from, 'to' => $label];
        }
        usort($pairs, function (array $left, array $right): int {
            $length = mb_strlen($right['from']) <=> mb_strlen($left['from']);
            if ($length !== 0) {
                return $length;
            }

            return $left['from'] <=> $right['from'];
        });

        return $pairs;
    }

    /**
     * @param  array<string, mixed>  $vocabulary
     */
    public function apply(string $text, array $vocabulary, string $lang): string
    {
        $pairs = $this->pairs($vocabulary, $lang);
        if ($pairs === [] || $text === '') {
            return $text;
        }

        $lower = mb_strtolower($text);
        $length = mb_strlen($text);
        $result = '';
        $index = 0;
        while ($index < $length) {
            $matched = false;
            foreach ($pairs as $pair) {
                $fromLength = mb_strlen($pair['from']);
                if ($fromLength === 0 || $index + $fromLength > $length) {
                    continue;
                }
                if (mb_substr($lower, $index, $fromLength) !== $pair['from']) {
                    continue;
                }
                if (! $this->hasBoundary($lower, $index, $fromLength, $length)) {
                    continue;
                }
                $result .= $this->matchCase(mb_substr($text, $index, $fromLength), $pair['to']);
                $index += $fromLength;
                $matched = true;
                break;
            }
            if (! $matched) {
                $result .= mb_substr($text, $index, 1);
                $index++;
            }
        }

        return $result;
    }

    private function hasBoundary(string $lower, int $index, int $fromLength, int $length): bool
    {
        if ($index > 0 && $this->isWordChar(mb_substr($lower, $index - 1, 1))) {
            return false;
        }
        $after = $index + $fromLength;
        if ($after < $length && $this->isWordChar(mb_substr($lower, $after, 1))) {
            return false;
        }

        return true;
    }

    private function isWordChar(string $char): bool
    {
        return $char !== '' && preg_match('/^[\p{L}\p{N}]$/u', $char) === 1;
    }

    private function matchCase(string $original, string $label): string
    {
        if (mb_strtoupper($original) === $original && mb_strtolower($original) !== $original) {
            return mb_strtoupper($label);
        }
        if (mb_strtolower($original) === $original) {
            return mb_strtolower($label);
        }

        return $label;
    }
}
