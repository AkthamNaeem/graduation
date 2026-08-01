<?php

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CitySeeder extends Seeder
{
    /** @var list<array{code: string, name_ar: string, name_en: string}> */
    public const CITIES = [
        ['code' => 'damascus', 'name_ar' => 'دمشق', 'name_en' => 'Damascus'],
        ['code' => 'aleppo', 'name_ar' => 'حلب', 'name_en' => 'Aleppo'],
        ['code' => 'homs', 'name_ar' => 'حمص', 'name_en' => 'Homs'],
        ['code' => 'hama', 'name_ar' => 'حماة', 'name_en' => 'Hama'],
        ['code' => 'latakia', 'name_ar' => 'اللاذقية', 'name_en' => 'Latakia'],
        ['code' => 'tartus', 'name_ar' => 'طرطوس', 'name_en' => 'Tartus'],
        ['code' => 'idlib', 'name_ar' => 'إدلب', 'name_en' => 'Idlib'],
        ['code' => 'daraa', 'name_ar' => 'درعا', 'name_en' => 'Daraa'],
        ['code' => 'as-suwayda', 'name_ar' => 'السويداء', 'name_en' => 'As-Suwayda'],
        ['code' => 'quneitra', 'name_ar' => 'القنيطرة', 'name_en' => 'Quneitra'],
        ['code' => 'deir-ez-zor', 'name_ar' => 'دير الزور', 'name_en' => 'Deir ez-Zor'],
        ['code' => 'raqqa', 'name_ar' => 'الرقة', 'name_en' => 'Raqqa'],
        ['code' => 'al-hasakah', 'name_ar' => 'الحسكة', 'name_en' => 'Al-Hasakah'],
        ['code' => 'qamishli', 'name_ar' => 'القامشلي', 'name_en' => 'Qamishli'],
    ];

    public function run(): void
    {
        foreach (self::CITIES as $data) {
            City::query()->updateOrCreate(
                ['code' => $data['code']],
                [...$data, 'country_code' => 'SY', 'is_active' => true],
            );
        }

        $this->backfillExactLegacyLocations();
    }

    private function backfillExactLegacyLocations(): void
    {
        foreach (City::query()->where('country_code', 'SY')->get() as $city) {
            $exactValues = [
                $city->name_en,
                $city->name_en.', Syria',
                $city->name_ar,
                $city->name_ar.'، سوريا',
            ];

            DB::table('job_seeker_profiles')
                ->whereNull('city_id')
                ->whereIn('location', $exactValues)
                ->update(['city_id' => $city->id]);

            DB::table('job_postings')
                ->whereNull('city_id')
                ->whereIn('location', $exactValues)
                ->update(['city_id' => $city->id]);
        }
    }
}
