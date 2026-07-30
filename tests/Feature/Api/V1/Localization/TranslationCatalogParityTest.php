<?php

namespace Tests\Feature\Api\V1\Localization;

use Illuminate\Support\Arr;
use Tests\TestCase;

class TranslationCatalogParityTest extends TestCase
{
    public function test_english_and_arabic_catalogs_have_identical_complete_contracts(): void
    {
        $englishFiles = collect(glob(lang_path('en/*.php')) ?: [])
            ->mapWithKeys(fn (string $path): array => [basename($path) => $path])
            ->sortKeys();
        $arabicFiles = collect(glob(lang_path('ar/*.php')) ?: [])
            ->mapWithKeys(fn (string $path): array => [basename($path) => $path])
            ->sortKeys();

        $this->assertSame($englishFiles->keys()->all(), $arabicFiles->keys()->all());

        foreach ($englishFiles as $filename => $englishPath) {
            $english = Arr::dot(require $englishPath);
            $arabic = Arr::dot(require $arabicFiles[$filename]);

            $englishKeys = array_keys($english);
            $arabicKeys = array_keys($arabic);
            sort($englishKeys);
            sort($arabicKeys);
            $this->assertSame($englishKeys, $arabicKeys, $filename);

            foreach ($english as $key => $englishValue) {
                $arabicValue = $arabic[$key];
                $qualifiedKey = $filename.':'.$key;

                $this->assertIsString($englishValue, $qualifiedKey);
                $this->assertIsString($arabicValue, $qualifiedKey);
                $this->assertNotSame('', trim($englishValue), $qualifiedKey);
                $this->assertNotSame('', trim($arabicValue), $qualifiedKey);
                $this->assertSame(
                    $this->placeholders($englishValue),
                    $this->placeholders($arabicValue),
                    $qualifiedKey,
                );

                if (! in_array($qualifiedKey, $this->documentedTechnicalIdentities(), true)) {
                    $this->assertNotSame($englishValue, $arabicValue, $qualifiedKey);
                }
            }
        }
    }

    /**
     * Values that are deliberately language-neutral protocol or product names.
     *
     * @return list<string>
     */
    private function documentedTechnicalIdentities(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $value): array
    {
        preg_match_all(
            '/:[A-Za-z_][A-Za-z0-9_]*|%(?:\d+\$)?[-+0-9.]*[bcdeEfFgGosuxX]/',
            $value,
            $matches,
        );
        $placeholders = array_values(array_unique($matches[0]));
        sort($placeholders);

        return $placeholders;
    }
}
