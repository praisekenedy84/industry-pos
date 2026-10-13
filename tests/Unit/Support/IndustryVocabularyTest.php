<?php

namespace Tests\Unit\Support;

use App\Support\IndustryCatalog;
use App\Support\IndustryVocabulary;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IndustryVocabularyTest extends TestCase
{
    public function test_construction_renames_ingredient_to_raw_material(): void
    {
        $text = $this->vocabulary()->apply('Choose ingredient', IndustryCatalog::find('construction')['vocabulary'], 'en');

        $this->assertSame('Choose raw material', $text);
    }

    public function test_bakery_renames_recipe_batch_and_production_line(): void
    {
        $vocabulary = IndustryCatalog::find('bakery')['vocabulary'];
        $apply = $this->vocabulary();

        $this->assertSame('Formula', $apply->apply('Bill of materials', $vocabulary, 'en'));
        $this->assertSame('Choose formula', $apply->apply('Choose recipe', $vocabulary, 'en'));
        $this->assertSame('Bakes', $apply->apply('Batches', $vocabulary, 'en'));
        $this->assertSame('bakes', $apply->apply('batches', $vocabulary, 'en'));
        $this->assertSame('Baking line', $apply->apply('Production line', $vocabulary, 'en'));
        $this->assertSame('batching', $apply->apply('batching', $vocabulary, 'en'));
    }

    public function test_replacement_keeps_the_original_letter_case(): void
    {
        $text = $this->vocabulary()->apply('INGREDIENT', IndustryCatalog::find('construction')['vocabulary'], 'en');

        $this->assertSame('RAW MATERIAL', $text);
    }

    public function test_general_manufacturing_keeps_bill_of_materials_and_renames_recipe(): void
    {
        $vocabulary = IndustryCatalog::find('general')['vocabulary'];
        $apply = $this->vocabulary();

        $this->assertSame('Bill of materials', $apply->apply('Bill of materials', $vocabulary, 'en'));
        $this->assertSame('Choose bill of materials', $apply->apply('Choose recipe', $vocabulary, 'en'));
        $this->assertSame('Batch', $apply->apply('Batch', $vocabulary, 'en'));
    }

    public function test_catalog_ships_a_template_for_each_production_industry(): void
    {
        $keys = array_keys(IndustryCatalog::all());

        $this->assertGreaterThanOrEqual(35, count($keys));
        foreach (['general', 'bakery', 'construction', 'metal', 'brewery', 'pharmaceutical', 'textile', 'food'] as $key) {
            $this->assertArrayHasKey($key, IndustryCatalog::all());
        }
        $this->assertSame('JCE-001', IndustryCatalog::find('juice')['recipe']['output']);
        $this->assertSame('MNG-001', IndustryCatalog::find('juice')['items'][0]['sku']);
        $this->assertSame('CHR-001', IndustryCatalog::find('furniture')['recipe']['output']);
    }

    #[DataProvider('industries')]
    public function test_each_template_has_a_sample_bill_and_both_languages(string $key): void
    {
        $template = IndustryCatalog::find($key);

        $this->assertNotSame('', $template['name']);
        $this->assertArrayHasKey('ingredient', $template['vocabulary']['en']);
        $this->assertArrayHasKey('ingredient', $template['vocabulary']['sw']);
        $this->assertNotSame('', $template['recipe']['output']);
        $this->assertNotEmpty($template['items']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function industries(): array
    {
        $cases = [];
        foreach (array_keys(IndustryCatalog::all()) as $key) {
            $cases[$key] = [$key];
        }

        return $cases;
    }

    private function vocabulary(): IndustryVocabulary
    {
        return new IndustryVocabulary;
    }
}
