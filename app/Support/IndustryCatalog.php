<?php

namespace App\Support;

final class IndustryCatalog
{
    public static function all(): array
    {
        static $cached;
        if ($cached !== null) {
            return $cached;
        }

        $catalog = [
            'bakery' => self::bakery(),
            'soap' => self::soap(),
            'tailoring' => self::tailoring(),
            'juice' => self::juice(),
            'furniture' => self::furniture(),
        ];

        foreach (self::industries() as $key => $industry) {
            $catalog[$key] = self::industry($industry);
        }

        return $cached = $catalog;
    }

    public static function find(string $key): array
    {
        return self::all()[$key] ?? self::all()['general'];
    }

    /**
     * @return list<array{key: string, name: string}>
     */
    public static function options(): array
    {
        return collect(self::all())
            ->map(fn (array $template, string $key): array => ['key' => $key, 'name' => $template['name']])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    public static function name(string $key): string
    {
        return self::all()[$key]['name'] ?? $key;
    }

    /**
     * @param  array<string, mixed>  $industry
     * @return array<string, mixed>
     */
    private static function industry(array $industry): array
    {
        $code = $industry['code'];
        $unit = $industry['unit'] ?? 'kg';
        $outputUnit = $industry['output_unit'] ?? 'pc';

        return self::template($industry['name'], IndustryTerms::vocabulary($industry['en'], $industry['sw']), [
            ['sku' => $code.'-IN', 'name' => $industry['input'], 'name_alt' => $industry['input_alt'], 'type' => 'raw_material', 'unit' => $unit, 'category' => 'Materials', 'cost' => 1000, 'qty' => 20, 'price' => 0],
            ['sku' => $code.'-OUT', 'name' => $industry['output'], 'name_alt' => $industry['output_alt'], 'type' => 'finished_good', 'unit' => $outputUnit, 'category' => 'Finished', 'cost' => 0, 'qty' => 0, 'price' => 5000],
        ], [
            'name' => $industry['output'],
            'output' => $code.'-OUT',
            'yield' => 10,
            'lines' => [['sku' => $code.'-IN', 'qty' => 2]],
            'labor' => 2000,
            'overhead' => 500,
            'stages' => $industry['stages'] ?? ['Prepare', 'Make', 'Finish'],
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function industries(): array
    {
        return [
            'general' => self::spec('General manufacturing', 'GEN', [], [], 'Raw material', 'Malighafi', 'Finished good', 'Bidhaa tayari'),
            'construction' => self::spec('Construction', 'CON', [
                'ingredient' => 'Raw material', 'ingredients' => 'Raw materials',
                'recipe' => 'Bill of quantities', 'recipes' => 'Bills of quantities',
                'batch' => 'Job', 'batches' => 'Jobs',
                'production' => 'Construction', 'output' => 'Element', 'outputs' => 'Elements',
                'produced' => 'Built', 'line' => 'Work crew', 'lines' => 'Work crews',
                'waste' => 'Spoil', 'branch' => 'Site', 'branches' => 'Sites',
                'lot' => 'Delivery lot', 'lots' => 'Delivery lots', 'yield' => 'Placed quantity',
            ], [
                'ingredient' => 'Malighafi', 'ingredients' => 'Malighafi',
                'recipe' => 'Jedwali la kazi', 'recipes' => 'Majedwali ya kazi',
                'batch' => 'Kazi', 'batches' => 'Kazi',
                'production' => 'Ujenzi', 'output' => 'Kipengele', 'outputs' => 'Vipengele',
                'produced' => 'Imejengwa', 'line' => 'Timu ya kazi', 'lines' => 'Timu za kazi',
                'waste' => 'Taka za ujenzi', 'branch' => 'Eneo', 'branches' => 'Maeneo',
            ], 'Cement', 'Saruji', 'Concrete block', 'Tofali la zege', 'kg', 'pc', ['Mix', 'Cast', 'Cure']),
            'metal' => self::spec('Metal fabrication', 'MET', [
                'ingredient' => 'Material', 'ingredients' => 'Materials',
                'recipe' => 'Cut list', 'recipes' => 'Cut lists',
                'batch' => 'Job', 'batches' => 'Jobs',
                'production' => 'Fabrication', 'output' => 'Part', 'outputs' => 'Parts',
                'produced' => 'Fabricated', 'line' => 'Fabrication bay', 'lines' => 'Fabrication bays',
                'waste' => 'Scrap', 'branch' => 'Shop', 'branches' => 'Shops',
                'lot' => 'Heat', 'lots' => 'Heats',
            ], [
                'ingredient' => 'Malighafi', 'recipe' => 'Orodha ya kukata', 'recipes' => 'Orodha za kukata',
                'batch' => 'Kazi', 'batches' => 'Kazi', 'production' => 'Utengenezaji chuma',
                'output' => 'Sehemu', 'produced' => 'Imetengenezwa', 'line' => 'Ghala la kazi',
                'waste' => 'Chakavu', 'branch' => 'Karakani', 'branches' => 'Karakana',
            ], 'Steel sheet', 'Bati la chuma', 'Bracket', 'Kibano'),
            'plastics' => self::spec('Plastics moulding', 'PLA', [
                'ingredient' => 'Resin', 'ingredients' => 'Resins',
                'recipe' => 'Mould recipe', 'recipes' => 'Mould recipes',
                'batch' => 'Shot run', 'batches' => 'Shot runs',
                'production' => 'Moulding', 'output' => 'Part', 'outputs' => 'Parts',
                'produced' => 'Moulded', 'line' => 'Moulding press', 'lines' => 'Moulding presses',
                'waste' => 'Regrind', 'branch' => 'Plant', 'branches' => 'Plants',
            ], [
                'ingredient' => 'Resini', 'ingredients' => 'Resini',
                'recipe' => 'Mapishi ya ukungu', 'batch' => 'Zao la ukungu', 'batches' => 'Mazoa ya ukungu',
                'production' => 'Ukingo', 'output' => 'Sehemu', 'waste' => 'Kusaga tena', 'branch' => 'Kiwanda',
            ], 'Plastic resin', 'Resini ya plastiki', 'Moulded cap', 'Kifuniko'),
            'printing' => self::spec('Printing and packaging', 'PRT', [
                'ingredient' => 'Substrate', 'ingredients' => 'Substrates',
                'recipe' => 'Job spec', 'recipes' => 'Job specs',
                'batch' => 'Press run', 'batches' => 'Press runs',
                'production' => 'Pressroom', 'output' => 'Copy', 'outputs' => 'Copies',
                'produced' => 'Printed', 'line' => 'Printing press', 'lines' => 'Printing presses',
                'waste' => 'Spoilage', 'branch' => 'Plant', 'branches' => 'Plants',
            ], [
                'ingredient' => 'Karatasi', 'recipe' => 'Maelezo ya kazi', 'batch' => 'Chapisho',
                'batches' => 'Machapisho', 'production' => 'Uchapishaji', 'output' => 'Nakala',
                'waste' => 'Hasara', 'branch' => 'Kiwanda',
            ], 'Paper stock', 'Karatasi', 'Printed carton', 'Katoni iliyochapishwa'),
            'dairy' => self::spec('Dairy processing', 'DAI', [
                'ingredient' => 'Ingredient', 'ingredients' => 'Ingredients',
                'recipe' => 'Formula', 'recipes' => 'Formulas',
                'batch' => 'Batch', 'batches' => 'Batches',
                'production' => 'Dairy', 'output' => 'Pack', 'outputs' => 'Packs',
                'produced' => 'Processed', 'line' => 'Process line', 'lines' => 'Process lines',
                'waste' => 'Shrink', 'branch' => 'Plant', 'branches' => 'Plants',
                'lot' => 'Vat lot', 'lots' => 'Vat lots',
            ], [
                'recipe' => 'Fomula', 'recipes' => 'Fomula', 'production' => 'Kiwanda cha maziwa',
                'output' => 'Kifurushi', 'waste' => 'Upungufu', 'branch' => 'Kiwanda', 'line' => 'Mstari wa usindikaji',
            ], 'Fresh milk', 'Maziwa mabichi', 'Yoghurt cup', 'Mtindi'),
            'milling' => self::spec('Grain milling', 'MIL', [
                'ingredient' => 'Grain', 'ingredients' => 'Grains',
                'recipe' => 'Mill mix', 'recipes' => 'Mill mixes',
                'batch' => 'Mill run', 'batches' => 'Mill runs',
                'production' => 'Milling', 'output' => 'Bag', 'outputs' => 'Bags',
                'produced' => 'Milled', 'line' => 'Mill line', 'lines' => 'Mill lines',
                'waste' => 'Screenings', 'branch' => 'Mill', 'branches' => 'Mills',
            ], [
                'ingredient' => 'Nafaka', 'ingredients' => 'Nafaka', 'recipe' => 'Mchanganyiko wa kusaga',
                'batch' => 'Usagaji', 'batches' => 'Misagaji', 'production' => 'Usagaji',
                'output' => 'Gunia', 'waste' => 'Mabaki ya ungo', 'branch' => 'Kiwanda cha kusaga',
            ], 'Maize', 'Mahindi', 'Maize flour', 'Unga wa mahindi'),
            'brewery' => self::spec('Brewery', 'BRW', [
                'ingredient' => 'Ingredient', 'ingredients' => 'Ingredients',
                'recipe' => 'Recipe', 'recipes' => 'Recipes',
                'batch' => 'Brew', 'batches' => 'Brews',
                'production' => 'Brewhouse', 'output' => 'Keg', 'outputs' => 'Kegs',
                'produced' => 'Brewed', 'line' => 'Brewhouse line', 'lines' => 'Brewhouse lines',
                'waste' => 'Loss', 'branch' => 'Brewery', 'branches' => 'Breweries',
                'lot' => 'Brew lot', 'lots' => 'Brew lots',
            ], [
                'recipe' => 'Mapishi', 'recipes' => 'Mapishi', 'batch' => 'Zao la bia', 'batches' => 'Mazoa ya bia',
                'production' => 'Kiwanda cha bia', 'output' => 'Pipa', 'waste' => 'Hasara', 'branch' => 'Kiwanda cha bia',
            ], 'Malted barley', 'Shayiri', 'Lager', 'Bia', 'kg', 'L'),
            'pharmaceutical' => self::spec('Pharmaceutical compounding', 'PHA', [
                'ingredient' => 'Component', 'ingredients' => 'Components',
                'recipe' => 'Master formula', 'recipes' => 'Master formulas',
                'batch' => 'Batch', 'batches' => 'Batches',
                'production' => 'Compounding', 'output' => 'Pack', 'outputs' => 'Packs',
                'produced' => 'Compounded', 'line' => 'Compounding suite', 'lines' => 'Compounding suites',
                'waste' => 'Reject', 'branch' => 'Site', 'branches' => 'Sites',
                'lot' => 'Batch lot', 'lots' => 'Batch lots',
            ], [
                'ingredient' => 'Kijenzi', 'ingredients' => 'Vijenzi', 'recipe' => 'Fomula kuu',
                'production' => 'Utengenezaji dawa', 'output' => 'Kifurushi', 'waste' => 'Kataliwa', 'branch' => 'Eneo',
            ], 'Active ingredient', 'Kiambato', 'Tablet pack', 'Pakiti ya vidonge'),
            'paint' => self::spec('Paint and coatings', 'PNT', [
                'ingredient' => 'Ingredient', 'ingredients' => 'Ingredients',
                'recipe' => 'Formulation', 'recipes' => 'Formulations',
                'batch' => 'Batch', 'batches' => 'Batches',
                'production' => 'Paint plant', 'output' => 'Can', 'outputs' => 'Cans',
                'produced' => 'Mixed', 'line' => 'Mix tank', 'lines' => 'Mix tanks',
                'waste' => 'Off-spec', 'branch' => 'Plant', 'branches' => 'Plants',
            ], [
                'recipe' => 'Muundo', 'recipes' => 'Miundo', 'production' => 'Kiwanda cha rangi',
                'output' => 'Mkebe', 'waste' => 'Nje ya viwango', 'branch' => 'Kiwanda',
            ], 'Pigment', 'Rangi ghafi', 'Emulsion paint', 'Rangi ya emulsion'),
            'concrete' => self::spec('Concrete products', 'CRC', [
                'ingredient' => 'Aggregate', 'ingredients' => 'Aggregates',
                'recipe' => 'Mix design', 'recipes' => 'Mix designs',
                'batch' => 'Pour', 'batches' => 'Pours',
                'production' => 'Batching', 'output' => 'Unit', 'outputs' => 'Units',
                'produced' => 'Cast', 'line' => 'Batching plant', 'lines' => 'Batching plants',
                'waste' => 'Waste', 'branch' => 'Yard', 'branches' => 'Yards',
            ], [
                'ingredient' => 'Changarawe', 'ingredients' => 'Changarawe', 'recipe' => 'Muundo wa mchanganyiko',
                'batch' => 'Umiminaji', 'batches' => 'Umiminaji', 'production' => 'Uchanganyaji',
                'output' => 'Kipimo', 'produced' => 'Imetupwa', 'branch' => 'Yadi', 'branches' => 'Yadi',
            ], 'Cement', 'Saruji', 'Paving block', 'Tofali la lami'),
            'textile' => self::spec('Textile mill', 'TEX', [
                'ingredient' => 'Fibre', 'ingredients' => 'Fibres',
                'recipe' => 'Cloth spec', 'recipes' => 'Cloth specs',
                'batch' => 'Lot run', 'batches' => 'Lot runs',
                'production' => 'Mill', 'output' => 'Roll', 'outputs' => 'Rolls',
                'produced' => 'Woven', 'line' => 'Weaving line', 'lines' => 'Weaving lines',
                'waste' => 'Waste', 'branch' => 'Mill', 'branches' => 'Mills',
            ], [
                'ingredient' => 'Nyuzi', 'ingredients' => 'Nyuzi', 'recipe' => 'Maelezo ya nguo',
                'batch' => 'Zao', 'batches' => 'Mazoa', 'production' => 'Kiwanda cha nguo',
                'output' => 'Rol', 'branch' => 'Kiwanda', 'line' => 'Mstari wa kusuka',
            ], 'Cotton yarn', 'Uzi wa pamba', 'Grey cloth', 'Nguo ghafi', 'kg', 'm'),
            'electronics' => self::spec('Electronics assembly', 'ELC', [
                'ingredient' => 'Component', 'ingredients' => 'Components',
                'recipe' => 'Assembly spec', 'recipes' => 'Assembly specs',
                'batch' => 'Build', 'batches' => 'Builds',
                'production' => 'Assembly', 'output' => 'Unit', 'outputs' => 'Units',
                'produced' => 'Assembled', 'line' => 'Assembly line', 'lines' => 'Assembly lines',
                'waste' => 'Scrap', 'branch' => 'Plant', 'branches' => 'Plants',
            ], [
                'ingredient' => 'Kijenzi', 'ingredients' => 'Vijenzi', 'recipe' => 'Maelezo ya kuunganisha',
                'batch' => 'Ujenzi', 'batches' => 'Ujenzi', 'production' => 'Uunganishaji',
                'output' => 'Kifaa', 'waste' => 'Chakavu', 'branch' => 'Kiwanda', 'line' => 'Mstari wa kuunganisha',
            ], 'Circuit board', 'Bodi ya mzunguko', 'Control unit', 'Kifaa cha udhibiti', 'pc'),
            'automotive' => self::spec('Automotive parts', 'AUT', [
                'ingredient' => 'Component', 'ingredients' => 'Components',
                'recipe' => 'Process sheet', 'recipes' => 'Process sheets',
                'batch' => 'Batch', 'batches' => 'Batches',
                'production' => 'Assembly', 'output' => 'Part', 'outputs' => 'Parts',
                'produced' => 'Assembled', 'line' => 'Assembly line', 'lines' => 'Assembly lines',
                'waste' => 'Scrap', 'branch' => 'Plant', 'branches' => 'Plants',
            ], [
                'ingredient' => 'Kijenzi', 'ingredients' => 'Vijenzi', 'recipe' => 'Karatasi ya mchakato',
                'production' => 'Uunganishaji', 'output' => 'Sehemu', 'waste' => 'Chakavu', 'branch' => 'Kiwanda',
            ], 'Steel blank', 'Chuma tupu', 'Brake pad', 'Pedi ya breki', 'pc'),
            'confectionery' => self::spec('Confectionery', 'CNF', [
                'ingredient' => 'Ingredient', 'ingredients' => 'Ingredients',
                'recipe' => 'Recipe', 'recipes' => 'Recipes',
                'batch' => 'Batch', 'batches' => 'Batches',
                'production' => 'Confectionery', 'output' => 'Piece', 'outputs' => 'Pieces',
                'produced' => 'Made', 'line' => 'Making line', 'lines' => 'Making lines',
                'waste' => 'Scrap', 'branch' => 'Factory', 'branches' => 'Factories',
            ], [
                'recipe' => 'Mapishi', 'recipes' => 'Mapishi', 'production' => 'Utengenezaji peremende',
                'output' => 'Kipande', 'branch' => 'Kiwanda', 'line' => 'Mstari wa kutengeneza',
            ], 'Sugar', 'Sukari', 'Candy', 'Peremende'),
            'water' => self::spec('Water bottling', 'WAT', [
                'ingredient' => 'Input', 'ingredients' => 'Inputs',
                'recipe' => 'Process spec', 'recipes' => 'Process specs',
                'batch' => 'Bottling run', 'batches' => 'Bottling runs',
                'production' => 'Bottling', 'output' => 'Bottle', 'outputs' => 'Bottles',
                'produced' => 'Bottled', 'line' => 'Bottling line', 'lines' => 'Bottling lines',
                'waste' => 'Shrink', 'branch' => 'Plant', 'branches' => 'Plants',
            ], [
                'ingredient' => 'Pembejeo', 'recipe' => 'Maelezo ya mchakato', 'batch' => 'Uchujaji',
                'batches' => 'Uchujaji', 'production' => 'Ufungashaji chupa', 'output' => 'Chupa',
                'waste' => 'Upungufu', 'branch' => 'Kiwanda', 'line' => 'Mstari wa chupa',
            ], 'Treated water', 'Maji yaliyosafishwa', 'Bottled water', 'Maji ya chupa', 'L'),
            'chemical' => self::spec('Chemical blending', 'CHM', [
                'ingredient' => 'Raw material', 'ingredients' => 'Raw materials',
                'recipe' => 'Formulation', 'recipes' => 'Formulations',
                'batch' => 'Batch', 'batches' => 'Batches',
                'production' => 'Blending', 'output' => 'Drum', 'outputs' => 'Drums',
                'produced' => 'Blended', 'line' => 'Reactor', 'lines' => 'Reactors',
                'waste' => 'Off-spec', 'branch' => 'Plant', 'branches' => 'Plants',
            ], [
                'ingredient' => 'Malighafi', 'ingredients' => 'Malighafi', 'recipe' => 'Muundo',
                'production' => 'Uchanganyaji', 'output' => 'Pipa', 'waste' => 'Nje ya viwango',
                'branch' => 'Kiwanda', 'line' => 'Kiyeyusha',
            ], 'Solvent', 'Kiyeyushi', 'Cleaning blend', 'Mchanganyiko wa kusafisha'),
            'ceramics' => self::spec('Ceramics and pottery', 'CER', [
                'ingredient' => 'Material', 'ingredients' => 'Materials',
                'recipe' => 'Body recipe', 'recipes' => 'Body recipes',
                'batch' => 'Firing', 'batches' => 'Firings',
                'production' => 'Kiln', 'output' => 'Piece', 'outputs' => 'Pieces',
                'produced' => 'Fired', 'line' => 'Kiln line', 'lines' => 'Kiln lines',
                'waste' => 'Breakage', 'branch' => 'Workshop', 'branches' => 'Workshops',
            ], [
                'ingredient' => 'Udongo', 'recipe' => 'Mapishi ya udongo', 'batch' => 'Uchomaji',
                'batches' => 'Michomo', 'production' => 'Tanuri', 'output' => 'Kipande',
                'waste' => 'Mavunjiko', 'branch' => 'Karakani', 'line' => 'Mstari wa tanuri',
            ], 'Clay', 'Udongo', 'Bowl', 'Bakuli'),
            'paper' => self::spec('Paper mill', 'PAP', [
                'ingredient' => 'Fibre', 'ingredients' => 'Fibres',
                'recipe' => 'Furnish', 'recipes' => 'Furnishes',
                'batch' => 'Machine run', 'batches' => 'Machine runs',
                'production' => 'Paper mill', 'output' => 'Reel', 'outputs' => 'Reels',
                'produced' => 'Made', 'line' => 'Paper machine', 'lines' => 'Paper machines',
                'waste' => 'Broke', 'branch' => 'Mill', 'branches' => 'Mills',
            ], [
                'ingredient' => 'Nyuzi', 'recipe' => 'Mchanganyiko wa massa', 'batch' => 'Zao la mashine',
                'production' => 'Kiwanda cha karatasi', 'output' => 'Rol', 'waste' => 'Karatasi iliyoharibika',
                'branch' => 'Kiwanda', 'line' => 'Mashine ya karatasi',
            ], 'Pulp', 'Massa', 'Paper reel', 'Rol ya karatasi'),
            'leather' => self::spec('Leather goods', 'LEA', [
                'ingredient' => 'Hide', 'ingredients' => 'Hides',
                'recipe' => 'Pattern', 'recipes' => 'Patterns',
                'batch' => 'Run', 'batches' => 'Runs',
                'production' => 'Tannery', 'output' => 'Piece', 'outputs' => 'Pieces',
                'produced' => 'Finished', 'line' => 'Making line', 'lines' => 'Making lines',
                'waste' => 'Offcut', 'branch' => 'Workshop', 'branches' => 'Workshops',
            ], [
                'ingredient' => 'Ngozi', 'ingredients' => 'Ngozi', 'recipe' => 'Muundo',
                'batch' => 'Kazi', 'batches' => 'Kazi', 'production' => 'Kiwanda cha ngozi',
                'output' => 'Kipande', 'waste' => 'Mabaki', 'branch' => 'Karakani',
            ], 'Hide', 'Ngozi', 'Belt', 'Mshipi'),
            'feed' => self::spec('Animal feed', 'FED', [
                'ingredient' => 'Ingredient', 'ingredients' => 'Ingredients',
                'recipe' => 'Formula', 'recipes' => 'Formulas',
                'batch' => 'Batch', 'batches' => 'Batches',
                'production' => 'Feed mill', 'output' => 'Bag', 'outputs' => 'Bags',
                'produced' => 'Milled', 'line' => 'Mill line', 'lines' => 'Mill lines',
                'waste' => 'Fines', 'branch' => 'Mill', 'branches' => 'Mills',
            ], [
                'recipe' => 'Fomula', 'recipes' => 'Fomula', 'production' => 'Kiwanda cha malisho',
                'output' => 'Gunia', 'waste' => 'Vumbi', 'branch' => 'Kiwanda',
            ], 'Maize bran', 'Pumba', 'Layer mash', 'Malisho ya kuku'),
            'coffee' => self::spec('Coffee roasting', 'COF', [
                'ingredient' => 'Bean', 'ingredients' => 'Beans',
                'recipe' => 'Roast profile', 'recipes' => 'Roast profiles',
                'batch' => 'Roast', 'batches' => 'Roasts',
                'production' => 'Roasting', 'output' => 'Bag', 'outputs' => 'Bags',
                'produced' => 'Roasted', 'line' => 'Roaster', 'lines' => 'Roasters',
                'waste' => 'Shrink', 'branch' => 'Roastery', 'branches' => 'Roasteries',
            ], [
                'ingredient' => 'Mbegu', 'ingredients' => 'Mbegu', 'recipe' => 'Wasifu wa kuchoma',
                'batch' => 'Uchomaji', 'batches' => 'Michomo', 'production' => 'Uchomaji kahawa',
                'output' => 'Mfuko', 'waste' => 'Upungufu', 'branch' => 'Kiwanja cha kuchoma', 'line' => 'Mashine ya kuchoma',
            ], 'Green coffee', 'Kahawa mbichi', 'Roasted coffee', 'Kahawa iliyochomwa'),
            'tea' => self::spec('Tea processing', 'TEA', [
                'ingredient' => 'Leaf', 'ingredients' => 'Leaves',
                'recipe' => 'Blend', 'recipes' => 'Blends',
                'batch' => 'Batch', 'batches' => 'Batches',
                'production' => 'Tea factory', 'output' => 'Pack', 'outputs' => 'Packs',
                'produced' => 'Processed', 'line' => 'Processing line', 'lines' => 'Processing lines',
                'waste' => 'Waste', 'branch' => 'Factory', 'branches' => 'Factories',
            ], [
                'ingredient' => 'Jani', 'ingredients' => 'Majani', 'recipe' => 'Mchanganyiko',
                'production' => 'Kiwanda cha chai', 'output' => 'Kifurushi', 'branch' => 'Kiwanda',
            ], 'Green leaf', 'Majani mabichi', 'Black tea', 'Chai nyeusi'),
            'meat' => self::spec('Meat processing', 'MEA', [
                'ingredient' => 'Carcass', 'ingredients' => 'Carcasses',
                'recipe' => 'Cut spec', 'recipes' => 'Cut specs',
                'batch' => 'Run', 'batches' => 'Runs',
                'production' => 'Processing', 'output' => 'Pack', 'outputs' => 'Packs',
                'produced' => 'Cut', 'line' => 'Cutting line', 'lines' => 'Cutting lines',
                'waste' => 'Trim', 'branch' => 'Plant', 'branches' => 'Plants',
            ], [
                'ingredient' => 'Mzoga', 'ingredients' => 'Mizoga', 'recipe' => 'Maelezo ya kukata',
                'batch' => 'Kazi', 'production' => 'Usindikaji nyama', 'output' => 'Kifurushi',
                'waste' => 'Mabaki', 'branch' => 'Kiwanda', 'line' => 'Mstari wa kukata',
            ], 'Beef carcass', 'Mzoga wa ng\'ombe', 'Packed beef', 'Nyama iliyofungwa'),
            'fish' => self::spec('Fish processing', 'FSH', [
                'ingredient' => 'Catch', 'ingredients' => 'Catches',
                'recipe' => 'Process spec', 'recipes' => 'Process specs',
                'batch' => 'Run', 'batches' => 'Runs',
                'production' => 'Processing', 'output' => 'Pack', 'outputs' => 'Packs',
                'produced' => 'Processed', 'line' => 'Processing line', 'lines' => 'Processing lines',
                'waste' => 'Waste', 'branch' => 'Plant', 'branches' => 'Plants',
            ], [
                'ingredient' => 'Samaki', 'recipe' => 'Maelezo ya usindikaji', 'batch' => 'Kazi',
                'production' => 'Usindikaji samaki', 'output' => 'Kifurushi', 'branch' => 'Kiwanda',
            ], 'Fresh fish', 'Samaki mbichi', 'Frozen fillet', 'Filleti iliyogandishwa'),
            'candle' => self::spec('Candle making', 'CND', [
                'ingredient' => 'Wax', 'ingredients' => 'Waxes',
                'recipe' => 'Formula', 'recipes' => 'Formulas',
                'batch' => 'Pour', 'batches' => 'Pours',
                'production' => 'Candle making', 'output' => 'Candle', 'outputs' => 'Candles',
                'produced' => 'Poured', 'line' => 'Pouring line', 'lines' => 'Pouring lines',
                'waste' => 'Scrap', 'branch' => 'Workshop', 'branches' => 'Workshops',
            ], [
                'ingredient' => 'Nta', 'ingredients' => 'Nta', 'recipe' => 'Fomula',
                'batch' => 'Umiminaji', 'batches' => 'Umiminaji', 'production' => 'Utengenezaji mishumaa',
                'output' => 'Mshumaa', 'branch' => 'Karakani', 'line' => 'Mstari wa kumwaga',
            ], 'Paraffin wax', 'Nta', 'Pillar candle', 'Mshumaa'),
            'ice_cream' => self::spec('Frozen desserts', 'ICE', [
                'ingredient' => 'Ingredient', 'ingredients' => 'Ingredients',
                'recipe' => 'Mix', 'recipes' => 'Mixes',
                'batch' => 'Batch', 'batches' => 'Batches',
                'production' => 'Freezing', 'output' => 'Tub', 'outputs' => 'Tubs',
                'produced' => 'Frozen', 'line' => 'Freezing line', 'lines' => 'Freezing lines',
                'waste' => 'Melt', 'branch' => 'Plant', 'branches' => 'Plants',
            ], [
                'recipe' => 'Mchanganyiko', 'recipes' => 'Mchanganyiko', 'production' => 'Ugandishaji',
                'output' => 'Bakuli', 'waste' => 'Iliyoyeyuka', 'branch' => 'Kiwanda', 'line' => 'Mstari wa kugandisha',
            ], 'Milk', 'Maziwa', 'Vanilla tub', 'Aiskrimu ya vanila', 'L'),
            'poultry' => self::spec('Poultry processing', 'PLT', [
                'ingredient' => 'Bird', 'ingredients' => 'Birds',
                'recipe' => 'Cut spec', 'recipes' => 'Cut specs',
                'batch' => 'Run', 'batches' => 'Runs',
                'production' => 'Processing', 'output' => 'Pack', 'outputs' => 'Packs',
                'produced' => 'Cut', 'line' => 'Processing line', 'lines' => 'Processing lines',
                'waste' => 'Trim', 'branch' => 'Plant', 'branches' => 'Plants',
            ], [
                'ingredient' => 'Kuku', 'ingredients' => 'Kuku', 'recipe' => 'Maelezo ya kukata',
                'production' => 'Usindikaji kuku', 'output' => 'Kifurushi', 'waste' => 'Mabaki', 'branch' => 'Kiwanda',
            ], 'Whole bird', 'Kuku mzima', 'Chicken pack', 'Kuku aliyefungwa', 'pc'),
            'glass' => self::spec('Glass works', 'GLS', [
                'ingredient' => 'Batch material', 'ingredients' => 'Batch materials',
                'recipe' => 'Glass recipe', 'recipes' => 'Glass recipes',
                'batch' => 'Melt', 'batches' => 'Melts',
                'production' => 'Glassworks', 'output' => 'Piece', 'outputs' => 'Pieces',
                'produced' => 'Formed', 'line' => 'Forming line', 'lines' => 'Forming lines',
                'waste' => 'Cullet', 'branch' => 'Plant', 'branches' => 'Plants',
            ], [
                'ingredient' => 'Malighafi ya kioo', 'recipe' => 'Mapishi ya kioo', 'batch' => 'Kuyeyuka',
                'batches' => 'Kuyeyuka', 'production' => 'Kiwanda cha kioo', 'output' => 'Kipande',
                'waste' => 'Kioo kilichovunjika', 'branch' => 'Kiwanda',
            ], 'Silica sand', 'Mchanga wa silika', 'Glass bottle', 'Chupa ya kioo'),
            'rubber' => self::spec('Rubber goods', 'RUB', [
                'ingredient' => 'Compound', 'ingredients' => 'Compounds',
                'recipe' => 'Formula', 'recipes' => 'Formulas',
                'batch' => 'Batch', 'batches' => 'Batches',
                'production' => 'Compounding', 'output' => 'Piece', 'outputs' => 'Pieces',
                'produced' => 'Cured', 'line' => 'Curing line', 'lines' => 'Curing lines',
                'waste' => 'Scrap', 'branch' => 'Plant', 'branches' => 'Plants',
            ], [
                'ingredient' => 'Mchanganyiko', 'recipe' => 'Fomula', 'production' => 'Utengenezaji mpira',
                'output' => 'Kipande', 'waste' => 'Chakavu', 'branch' => 'Kiwanda', 'line' => 'Mstari wa kuiva',
            ], 'Rubber compound', 'Mpira', 'Rubber seal', 'Muhuri wa mpira'),
            'cable' => self::spec('Cable and wire', 'CBL', [
                'ingredient' => 'Conductor', 'ingredients' => 'Conductors',
                'recipe' => 'Spec', 'recipes' => 'Specs',
                'batch' => 'Draw run', 'batches' => 'Draw runs',
                'production' => 'Drawing', 'output' => 'Reel', 'outputs' => 'Reels',
                'produced' => 'Drawn', 'line' => 'Drawing line', 'lines' => 'Drawing lines',
                'waste' => 'Scrap', 'branch' => 'Plant', 'branches' => 'Plants',
            ], [
                'ingredient' => 'Kipitishi', 'recipe' => 'Vipimo', 'batch' => 'Uchimbaji',
                'batches' => 'Uchimbaji', 'production' => 'Uchimbaji waya', 'output' => 'Rol',
                'waste' => 'Chakavu', 'branch' => 'Kiwanda', 'line' => 'Mstari wa kuchimba',
            ], 'Copper rod', 'Fimbo ya shaba', 'Cable reel', 'Rol ya kebo', 'kg', 'm'),
            'brick' => self::spec('Brick making', 'BRK', [
                'ingredient' => 'Clay', 'ingredients' => 'Clays',
                'recipe' => 'Mix', 'recipes' => 'Mixes',
                'batch' => 'Firing', 'batches' => 'Firings',
                'production' => 'Brickworks', 'output' => 'Brick', 'outputs' => 'Bricks',
                'produced' => 'Fired', 'line' => 'Kiln', 'lines' => 'Kilns',
                'waste' => 'Breakage', 'branch' => 'Yard', 'branches' => 'Yards',
            ], [
                'ingredient' => 'Udongo', 'recipe' => 'Mchanganyiko', 'batch' => 'Uchomaji',
                'batches' => 'Michomo', 'production' => 'Kiwanda cha matofali', 'output' => 'Tofali',
                'waste' => 'Mavunjiko', 'branch' => 'Yadi', 'line' => 'Tanuri',
            ], 'Clay', 'Udongo', 'Building brick', 'Tofali la ujenzi'),
            'steel' => self::spec('Steel fabrication', 'STL', [
                'ingredient' => 'Billet', 'ingredients' => 'Billets',
                'recipe' => 'Process route', 'recipes' => 'Process routes',
                'batch' => 'Heat', 'batches' => 'Heats',
                'production' => 'Steelworks', 'output' => 'Piece', 'outputs' => 'Pieces',
                'produced' => 'Rolled', 'line' => 'Rolling bay', 'lines' => 'Rolling bays',
                'waste' => 'Scrap', 'branch' => 'Works', 'branches' => 'Works',
                'lot' => 'Heat', 'lots' => 'Heats',
            ], [
                'ingredient' => 'Chuma tupu', 'recipe' => 'Njia ya mchakato', 'batch' => 'Joto',
                'batches' => 'Joto', 'production' => 'Kiwanda cha chuma', 'output' => 'Kipande',
                'waste' => 'Chakavu', 'branch' => 'Kiwanda', 'line' => 'Ghala la kuviringisha',
            ], 'Steel billet', 'Chuma tupu', 'Steel section', 'Sehemu ya chuma'),
            'food' => self::spec('Food processing', 'FOD', [
                'ingredient' => 'Ingredient', 'ingredients' => 'Ingredients',
                'recipe' => 'Recipe', 'recipes' => 'Recipes',
                'batch' => 'Batch', 'batches' => 'Batches',
                'production' => 'Processing', 'output' => 'Pack', 'outputs' => 'Packs',
                'produced' => 'Processed', 'line' => 'Processing line', 'lines' => 'Processing lines',
                'waste' => 'Waste', 'branch' => 'Plant', 'branches' => 'Plants',
            ], [
                'recipe' => 'Mapishi', 'recipes' => 'Mapishi', 'production' => 'Usindikaji chakula',
                'output' => 'Kifurushi', 'branch' => 'Kiwanda', 'line' => 'Mstari wa usindikaji',
            ], 'Raw ingredient', 'Kiungo', 'Packed meal', 'Mlo uliofungwa'),
        ];
    }

    /**
     * @param  array<string, string>  $en
     * @param  array<string, string>  $sw
     * @param  list<string>  $stages
     * @return array<string, mixed>
     */
    private static function spec(string $name, string $code, array $en, array $sw, string $input, string $inputAlt, string $output, string $outputAlt, string $unit = 'kg', string $outputUnit = 'pc', array $stages = ['Prepare', 'Make', 'Finish']): array
    {
        return [
            'name' => $name,
            'code' => $code,
            'en' => $en,
            'sw' => $sw,
            'input' => $input,
            'input_alt' => $inputAlt,
            'output' => $output,
            'output_alt' => $outputAlt,
            'unit' => $unit,
            'output_unit' => $outputUnit,
            'stages' => $stages,
        ];
    }

    private static function bakery(): array
    {
        return self::template('Bakery', IndustryTerms::vocabulary([
            'recipe' => 'Formula', 'recipes' => 'Formulas',
            'batch' => 'Bake', 'batches' => 'Bakes',
            'production' => 'Baking', 'output' => 'Loaf', 'outputs' => 'Loaves',
            'produced' => 'Baked', 'line' => 'Baking line', 'lines' => 'Baking lines',
            'waste' => 'Scrap', 'branch' => 'Shop', 'branches' => 'Shops',
        ], [
            'recipe' => 'Mapishi', 'recipes' => 'Mapishi',
            'batch' => 'Uokaji', 'batches' => 'Miokaji',
            'production' => 'Uokaji', 'output' => 'Mkate', 'outputs' => 'Mikate',
            'produced' => 'Imeokwa', 'line' => 'Mstari wa kuokea', 'lines' => 'Mistari ya kuokea',
            'waste' => 'Mabaki', 'branch' => 'Duka', 'branches' => 'Maduka',
        ]), self::bakeryItems(), self::bakeryRecipe());
    }

    private static function soap(): array
    {
        return self::template('Soap making', IndustryTerms::vocabulary([
            'recipe' => 'Formula', 'recipes' => 'Formulas',
            'batch' => 'Batch', 'batches' => 'Batches',
            'production' => 'Soap making', 'output' => 'Bar', 'outputs' => 'Bars',
            'produced' => 'Made', 'line' => 'Kettle line', 'lines' => 'Kettle lines',
            'waste' => 'Scrap', 'branch' => 'Workshop', 'branches' => 'Workshops',
        ], [
            'recipe' => 'Fomula', 'recipes' => 'Fomula',
            'batch' => 'Kundi', 'batches' => 'Makundi',
            'production' => 'Utengenezaji sabuni', 'output' => 'Sabuni', 'outputs' => 'Sabuni',
            'line' => 'Mstari wa sufuria', 'waste' => 'Mabaki', 'branch' => 'Karakani',
        ]), [
            ['sku' => 'OIL-001', 'name' => 'Coconut oil', 'name_alt' => 'Mafuta ya nazi', 'type' => 'raw_material', 'unit' => 'kg', 'category' => 'Ingredients', 'cost' => 6000, 'qty' => 20, 'price' => 0],
            ['sku' => 'LYE-001', 'name' => 'Lye', 'name_alt' => 'Soda', 'type' => 'raw_material', 'unit' => 'kg', 'category' => 'Ingredients', 'cost' => 2500, 'qty' => 5, 'price' => 0],
            ['sku' => 'SOAP-001', 'name' => 'Plain soap bar', 'name_alt' => 'Sabuni tupu', 'type' => 'finished_good', 'unit' => 'pc', 'category' => 'Finished', 'cost' => 0, 'qty' => 0, 'price' => 2000],
        ], ['name' => 'Plain soap', 'output' => 'SOAP-001', 'yield' => 20, 'lines' => [
            ['sku' => 'OIL-001', 'qty' => 4],
            ['sku' => 'LYE-001', 'qty' => 0.5],
        ], 'labor' => 3000, 'overhead' => 1000]);
    }

    private static function tailoring(): array
    {
        return self::template('Garment making', IndustryTerms::vocabulary([
            'ingredient' => 'Material', 'ingredients' => 'Materials',
            'recipe' => 'Pattern', 'recipes' => 'Patterns',
            'batch' => 'Run', 'batches' => 'Runs',
            'production' => 'Workshop', 'output' => 'Garment', 'outputs' => 'Garments',
            'produced' => 'Sewn', 'line' => 'Sewing line', 'lines' => 'Sewing lines',
            'waste' => 'Offcut', 'branch' => 'Shop', 'branches' => 'Shops',
        ], [
            'ingredient' => 'Nguo', 'ingredients' => 'Nguo',
            'recipe' => 'Muundo', 'recipes' => 'Miundo',
            'batch' => 'Kazi', 'batches' => 'Kazi',
            'production' => 'Karakani', 'output' => 'Nguo', 'outputs' => 'Nguo',
            'line' => 'Mstari wa kushona', 'waste' => 'Mabaki ya nguo', 'branch' => 'Duka',
        ]), [
            ['sku' => 'FAB-001', 'name' => 'Cotton fabric', 'name_alt' => 'Nguo ya pamba', 'type' => 'raw_material', 'unit' => 'm', 'category' => 'Materials', 'cost' => 8000, 'qty' => 40, 'price' => 0],
            ['sku' => 'SHT-001', 'name' => 'Shirt', 'name_alt' => 'Shati', 'type' => 'finished_good', 'unit' => 'pc', 'category' => 'Finished', 'cost' => 0, 'qty' => 0, 'price' => 25000],
        ], ['name' => 'Shirt', 'output' => 'SHT-001', 'yield' => 1, 'lines' => [
            ['sku' => 'FAB-001', 'qty' => 2],
        ], 'labor' => 8000, 'overhead' => 1000]);
    }

    private static function juice(): array
    {
        return self::template('Juice and beverages', IndustryTerms::vocabulary([
            'recipe' => 'Blend', 'recipes' => 'Blends',
            'batch' => 'Press', 'batches' => 'Presses',
            'production' => 'Processing', 'output' => 'Bottle', 'outputs' => 'Bottles',
            'produced' => 'Bottled', 'line' => 'Press line', 'lines' => 'Press lines',
            'waste' => 'Spoilage', 'branch' => 'Plant', 'branches' => 'Plants',
        ], [
            'recipe' => 'Mchanganyiko', 'recipes' => 'Mchanganyiko',
            'batch' => 'Kubonyeza', 'batches' => 'Kubonyeza',
            'production' => 'Usindikaji', 'output' => 'Chupa', 'outputs' => 'Chupa',
            'line' => 'Mstari wa kubonyeza', 'waste' => 'Hasara', 'branch' => 'Kiwanda',
        ]), [
            ['sku' => 'MNG-001', 'name' => 'Mangoes', 'name_alt' => 'Maembe', 'type' => 'raw_material', 'unit' => 'kg', 'category' => 'Fruit', 'cost' => 1500, 'qty' => 30, 'price' => 0],
            ['sku' => 'JCE-001', 'name' => 'Mango juice 500ml', 'name_alt' => 'Juisi ya embe', 'type' => 'finished_good', 'unit' => 'pc', 'category' => 'Finished', 'cost' => 0, 'qty' => 0, 'price' => 2000],
        ], ['name' => 'Mango juice', 'output' => 'JCE-001', 'yield' => 10, 'lines' => [
            ['sku' => 'MNG-001', 'qty' => 5],
        ], 'labor' => 2000, 'overhead' => 1500]);
    }

    private static function furniture(): array
    {
        return self::template('Furniture and woodwork', IndustryTerms::vocabulary([
            'ingredient' => 'Material', 'ingredients' => 'Materials',
            'recipe' => 'Cut list', 'recipes' => 'Cut lists',
            'batch' => 'Job', 'batches' => 'Jobs',
            'production' => 'Workshop', 'output' => 'Piece', 'outputs' => 'Pieces',
            'produced' => 'Built', 'line' => 'Work bench', 'lines' => 'Work benches',
            'waste' => 'Offcut', 'branch' => 'Workshop', 'branches' => 'Workshops',
        ], [
            'ingredient' => 'Mbao', 'ingredients' => 'Mbao',
            'recipe' => 'Orodha ya kukata', 'recipes' => 'Orodha za kukata',
            'batch' => 'Kazi', 'batches' => 'Kazi',
            'production' => 'Karakani', 'output' => 'Samani', 'outputs' => 'Samani',
            'line' => 'Benchi', 'waste' => 'Mabaki ya mbao', 'branch' => 'Karakani',
        ]), [
            ['sku' => 'WOD-001', 'name' => 'Timber', 'name_alt' => 'Mbao', 'type' => 'raw_material', 'unit' => 'pc', 'category' => 'Materials', 'cost' => 15000, 'qty' => 20, 'price' => 0],
            ['sku' => 'CHR-001', 'name' => 'Chair', 'name_alt' => 'Kiti', 'type' => 'finished_good', 'unit' => 'pc', 'category' => 'Finished', 'cost' => 0, 'qty' => 0, 'price' => 45000],
        ], ['name' => 'Chair', 'output' => 'CHR-001', 'yield' => 1, 'lines' => [
            ['sku' => 'WOD-001', 'qty' => 2],
        ], 'labor' => 10000, 'overhead' => 2000]);
    }

    /**
     * @param  array{en: array<string, string>, sw: array<string, string>}  $vocabulary
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $recipe
     * @return array<string, mixed>
     */
    private static function template(string $name, array $vocabulary, array $items, array $recipe): array
    {
        return [
            'name' => $name,
            'vocabulary' => $vocabulary,
            'units' => [
                ['name' => 'Kilogram', 'symbol' => 'kg'],
                ['name' => 'Gram', 'symbol' => 'g'],
                ['name' => 'Piece', 'symbol' => 'pc'],
                ['name' => 'Litre', 'symbol' => 'L'],
                ['name' => 'Metre', 'symbol' => 'm'],
            ],
            'conversions' => [
                ['from' => 'kg', 'to' => 'g', 'factor' => 1000],
            ],
            'categories' => [
                ['name' => 'Ingredients', 'name_alt' => 'Viungo', 'color' => 'brown'],
                ['name' => 'Materials', 'name_alt' => 'Malighafi', 'color' => 'orange'],
                ['name' => 'Fruit', 'name_alt' => 'Matunda', 'color' => 'green'],
                ['name' => 'Finished', 'name_alt' => 'Bidhaa tayari', 'color' => 'teal'],
                ['name' => 'Trading', 'name_alt' => 'Bidhaa za rejareja', 'color' => 'sky'],
            ],
            'items' => $items,
            'recipe' => $recipe,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function bakeryItems(): array
    {
        return [
            ['sku' => 'FLR-001', 'name' => 'Wheat flour', 'name_alt' => 'Unga wa ngano', 'type' => 'raw_material', 'unit' => 'kg', 'category' => 'Ingredients', 'cost' => 1000, 'qty' => 50, 'price' => 0],
            ['sku' => 'YST-001', 'name' => 'Yeast', 'name_alt' => 'Hamira', 'type' => 'raw_material', 'unit' => 'kg', 'category' => 'Ingredients', 'cost' => 2000, 'qty' => 5, 'price' => 0],
            ['sku' => 'SGR-001', 'name' => 'Sugar', 'name_alt' => 'Sukari', 'type' => 'raw_material', 'unit' => 'kg', 'category' => 'Ingredients', 'cost' => 500, 'qty' => 20, 'price' => 0],
            ['sku' => 'BRD-001', 'name' => 'White bread', 'name_alt' => 'Mkate mweupe', 'type' => 'finished_good', 'unit' => 'pc', 'category' => 'Finished', 'cost' => 0, 'qty' => 24, 'price' => 2500, 'opening_cost' => 300],
            ['sku' => 'SOD-001', 'name' => 'Soda 300ml', 'name_alt' => 'Soda', 'type' => 'trading_good', 'unit' => 'pc', 'category' => 'Trading', 'cost' => 800, 'qty' => 48, 'price' => 1500],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function bakeryRecipe(): array
    {
        return [
            'name' => 'White bread',
            'output' => 'BRD-001',
            'yield' => 20,
            'lines' => [
                ['sku' => 'FLR-001', 'qty' => 5],
                ['sku' => 'YST-001', 'qty' => 0.1],
                ['sku' => 'SGR-001', 'qty' => 0.5],
            ],
            'labor' => 2000,
            'overhead' => 500,
            'stages' => ['Mix', 'Proof', 'Bake', 'Pack'],
        ];
    }
}
