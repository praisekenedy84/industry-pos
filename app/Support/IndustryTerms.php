<?php

namespace App\Support;

use Illuminate\Support\Str;

final class IndustryTerms
{
    /**
     * Words the product uses for the same production flow.
     * A template supplies the industry label for each word.
     *
     * @var list<string>
     */
    public const KEYS = [
        'ingredient', 'ingredients',
        'recipe', 'recipes',
        'batch', 'batches',
        'production',
        'output', 'outputs',
        'produced',
        'line', 'lines',
        'waste',
        'raw_material', 'raw_materials',
        'branch', 'branches',
        'lot', 'lots',
        'yield',
        'labour',
    ];

    /**
     * @var array<string, string>
     */
    private const PLURAL_OF = [
        'ingredient' => 'ingredients',
        'recipe' => 'recipes',
        'batch' => 'batches',
        'output' => 'outputs',
        'line' => 'lines',
        'raw_material' => 'raw_materials',
        'branch' => 'branches',
        'lot' => 'lots',
    ];

    /**
     * Lowercase phrases in the current wording, mapped to a term key.
     * Longer phrases are matched before shorter ones.
     *
     * @var array<string, array<string, string>>
     */
    private const PHRASES = [
        'en' => [
            'bills of materials' => 'recipes',
            'bill of materials' => 'recipe',
            'production lines' => 'lines',
            'production line' => 'line',
            'raw materials' => 'raw_materials',
            'raw material' => 'raw_material',
            'ingredients' => 'ingredients',
            'ingredient' => 'ingredient',
            'recipes' => 'recipes',
            'recipe' => 'recipe',
            'batches' => 'batches',
            'batch' => 'batch',
            'production' => 'production',
            'outputs' => 'outputs',
            'output' => 'output',
            'produced' => 'produced',
            'branches' => 'branches',
            'branch' => 'branch',
            'lots' => 'lots',
            'lot' => 'lot',
            'waste' => 'waste',
            'yield' => 'yield',
            'labour' => 'labour',
            'labor' => 'labour',
        ],
        'sw' => [
            'orodha za malighafi na vipimo vya uzalishaji' => 'recipes',
            'orodha ya malighafi na vipimo vya uzalishaji' => 'recipe',
            'orodha ya uzalishaji wa bidhaa' => 'recipe',
            'orodha za uzalishaji' => 'recipes',
            'orodha ya uzalishaji' => 'recipe',
            'makundi ya bidhaa na mwisho wa matumizi' => 'lots',
            'mstari wa uzalishaji' => 'line',
            'mistari ya uzalishaji' => 'lines',
            'makundi ya uzalishaji' => 'batches',
            'makundi ya bidhaa' => 'lots',
            'kundi la uzalishaji' => 'batch',
            'kundi la bidhaa' => 'lot',
            'bidhaa inayozalishwa' => 'output',
            'kiasi kinachozalishwa' => 'yield',
            'imezalishwa' => 'produced',
            'malighafi' => 'raw_material',
            'uzalishaji' => 'production',
            'upotevu' => 'waste',
            'viungo' => 'ingredients',
            'kiungo' => 'ingredient',
            'matawi' => 'branches',
            'tawi' => 'branch',
        ],
    ];

    /**
     * @return list<array{singular: string, plural: string|null, label: string}>
     */
    public static function rows(): array
    {
        return [
            ['singular' => 'ingredient', 'plural' => 'ingredients', 'label' => 'Ingredient'],
            ['singular' => 'recipe', 'plural' => 'recipes', 'label' => 'Recipe'],
            ['singular' => 'batch', 'plural' => 'batches', 'label' => 'Batch'],
            ['singular' => 'production', 'plural' => null, 'label' => 'Production'],
            ['singular' => 'output', 'plural' => 'outputs', 'label' => 'Output'],
            ['singular' => 'produced', 'plural' => null, 'label' => 'Produced good'],
            ['singular' => 'line', 'plural' => 'lines', 'label' => 'Production line'],
            ['singular' => 'waste', 'plural' => null, 'label' => 'Waste'],
            ['singular' => 'raw_material', 'plural' => 'raw_materials', 'label' => 'Raw material'],
            ['singular' => 'branch', 'plural' => 'branches', 'label' => 'Branch'],
            ['singular' => 'lot', 'plural' => 'lots', 'label' => 'Lot'],
            ['singular' => 'yield', 'plural' => null, 'label' => 'Yield'],
            ['singular' => 'labour', 'plural' => null, 'label' => 'Labour'],
        ];
    }

    /**
     * @param  array<string, string>  $en
     * @param  array<string, string>  $sw
     * @return array{en: array<string, string>, sw: array<string, string>}
     */
    public static function vocabulary(array $en = [], array $sw = []): array
    {
        return self::normalize(['en' => $en, 'sw' => $sw]);
    }

    /**
     * @param  array<string, mixed>  $vocabulary
     * @return array{en: array<string, string>, sw: array<string, string>}
     */
    public static function normalize(array $vocabulary): array
    {
        $normalized = [];
        foreach (['en', 'sw'] as $lang) {
            $given = is_array($vocabulary[$lang] ?? null) ? $vocabulary[$lang] : [];
            $labels = self::defaults($lang);
            foreach (self::KEYS as $key) {
                if (self::filled($given[$key] ?? null)) {
                    $labels[$key] = trim((string) $given[$key]);
                }
            }
            if ($lang === 'en') {
                foreach (self::PLURAL_OF as $singular => $plural) {
                    $singularGiven = self::filled($given[$singular] ?? null);
                    $pluralGiven = self::filled($given[$plural] ?? null);
                    if ($singularGiven && ! $pluralGiven && ! str_contains($labels[$singular], ' ')) {
                        $labels[$plural] = Str::plural($labels[$singular]);
                    }
                }
            }
            $normalized[$lang] = $labels;
        }

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    public static function phrasesFor(string $lang): array
    {
        $phrases = self::PHRASES[$lang] ?? [];
        if ($lang !== 'en') {
            foreach (self::PHRASES['en'] as $phrase => $key) {
                $phrases[$phrase] ??= $key;
            }
        }

        return $phrases;
    }

    /**
     * @return array<string, string>
     */
    public static function defaults(string $lang): array
    {
        if ($lang === 'sw') {
            return [
                'ingredient' => 'Kiungo',
                'ingredients' => 'Viungo',
                'recipe' => 'Orodha ya uzalishaji',
                'recipes' => 'Orodha za uzalishaji',
                'batch' => 'Kundi la uzalishaji',
                'batches' => 'Makundi ya uzalishaji',
                'production' => 'Uzalishaji',
                'output' => 'Bidhaa inayozalishwa',
                'outputs' => 'Bidhaa zinazozalishwa',
                'produced' => 'Imezalishwa',
                'line' => 'Mstari wa uzalishaji',
                'lines' => 'Mistari ya uzalishaji',
                'waste' => 'Upotevu',
                'raw_material' => 'Malighafi',
                'raw_materials' => 'Malighafi',
                'branch' => 'Tawi',
                'branches' => 'Matawi',
                'lot' => 'Kundi la bidhaa',
                'lots' => 'Makundi ya bidhaa',
                'yield' => 'Kiasi kinachozalishwa',
                'labour' => 'Kazi',
            ];
        }

        return [
            'ingredient' => 'Ingredient',
            'ingredients' => 'Ingredients',
            'recipe' => 'Bill of materials',
            'recipes' => 'Bills of materials',
            'batch' => 'Batch',
            'batches' => 'Batches',
            'production' => 'Production',
            'output' => 'Output',
            'outputs' => 'Outputs',
            'produced' => 'Produced',
            'line' => 'Production line',
            'lines' => 'Production lines',
            'waste' => 'Waste',
            'raw_material' => 'Raw material',
            'raw_materials' => 'Raw materials',
            'branch' => 'Branch',
            'branches' => 'Branches',
            'lot' => 'Lot',
            'lots' => 'Lots',
            'yield' => 'Yield',
            'labour' => 'Labor',
        ];
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
