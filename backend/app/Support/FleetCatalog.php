<?php

namespace App\Support;

/**
 * Coherent brand → model pairs for demo seeding and showroom data hygiene.
 */
final class FleetCatalog
{
    /**
     * @return array<string, list<string>>
     */
    public static function brandModels(): array
    {
        return [
            'هيونداي' => ['إلنترا', 'أكسنت', 'توسان', 'كريتا', 'اي30'],
            'تويوتا' => ['كورولا', 'يارس', 'راش', 'فورتشنر', 'أفانزا'],
            'كيا' => ['سبورتاج', 'سيراتو', 'ريو', 'بيكانتو', 'سيلتوس'],
            'نيسان' => ['صني', 'سنترا', 'قشقاي', 'جوك', 'إكس تريل'],
            'شيري' => ['تيجو 7', 'تيجو 8', 'أريزو 5', 'أريزو 6'],
            'MG' => ['ZS', 'RX5', 'HS', '5', 'GT'],
            'بي واي دي' => ['أتو 3', 'سي جولدن', 'هان', 'دولفين'],
            'سكودا' => ['أوكافيا', 'رابيد', 'كاروك', 'كودياك'],
            'شيفروليه' => ['لانوس', 'أوبترا', 'كابتيفا', 'تاهو'],
            'رينو' => ['لوجان', 'سانديرو', 'داستر', 'كوليوس'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function brands(): array
    {
        return array_keys(self::brandModels());
    }

    /**
     * @return list<string>
     */
    public static function modelsFor(string $brand): array
    {
        return self::brandModels()[$brand] ?? [];
    }

    public static function modelBelongsToBrand(string $brand, string $model): bool
    {
        $models = self::modelsFor($brand);
        if ($models === []) {
            return true;
        }

        return in_array($model, $models, true);
    }

    /**
     * Deterministic coherent pair for seed index n.
     *
     * @return array{brand:string,model:string}
     */
    public static function pairForIndex(int $n): array
    {
        $brands = self::brands();
        $brand = $brands[$n % count($brands)];
        $models = self::modelsFor($brand);
        $model = $models[$n % count($models)];

        return ['brand' => $brand, 'model' => $model];
    }

    public static function categoryForModel(string $model): string
    {
        $suv = ['توسان', 'كريتا', 'سبورتاج', 'سيلتوس', 'قشقاي', 'جوك', 'إكس تريل', 'تيجو 7', 'تيجو 8', 'ZS', 'RX5', 'HS', 'أتو 3', 'كاروك', 'كودياك', 'كابتيفا', 'تاهو', 'داستر', 'كوليوس', 'فورتشنر', 'راش'];
        if (in_array($model, $suv, true)) {
            return 'SUV';
        }

        return 'سيدان';
    }
}
