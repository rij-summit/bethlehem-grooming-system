<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->string('fur_type', 100)->nullable()->change();
        });

        $this->convertLegacyFurTypes();
    }

    public function down(): void
    {
        $legacyValues = ['short', 'medium', 'long', 'wire', 'curl'];

        DB::table('pets')
            ->whereNotNull('fur_type')
            ->whereNotIn('fur_type', $legacyValues)
            ->update(['fur_type' => null]);

        Schema::table('pets', function (Blueprint $table) {
            $table->enum('fur_type', ['short', 'medium', 'long', 'wire', 'curl'])
                ->nullable()
                ->change();
        });
    }

    private function convertLegacyFurTypes(): void
    {
        $path = base_path('scripts/data/breed-coat-options.json');
        $catalogue = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $legacyValues = ['short', 'medium', 'long', 'wire', 'curl'];

        DB::table('pets')
            ->select(['pet_id', 'species', 'breed', 'fur_type'])
            ->whereIn('fur_type', $legacyValues)
            ->orderBy('pet_id')
            ->chunkById(100, function ($pets) use ($catalogue) {
                foreach ($pets as $pet) {
                    DB::table('pets')
                        ->where('pet_id', $pet->pet_id)
                        ->update([
                            'fur_type' => $this->matchLegacyFurType(
                                $catalogue,
                                $pet->species,
                                $pet->breed,
                                $pet->fur_type,
                            ),
                        ]);
                }
            }, 'pet_id');
    }

    private function matchLegacyFurType(
        array $catalogue,
        ?string $species,
        ?string $breed,
        string $legacyValue,
    ): ?string {
        $speciesKey = match (strtolower(trim((string) $species))) {
            'dog' => 'Dog',
            'cat' => 'Cat',
            default => null,
        };

        if ($speciesKey === null || trim((string) $breed) === '') {
            return null;
        }

        $options = [];
        foreach ($catalogue[$speciesKey] ?? [] as $catalogueBreed => $breedOptions) {
            if (strcasecmp($catalogueBreed, trim((string) $breed)) === 0) {
                $options = $breedOptions;
                break;
            }
        }

        if ($options === []) {
            return null;
        }

        $keywords = match ($legacyValue) {
            'short' => ['short', 'smooth', 'horse'],
            'medium' => ['standard', 'double', 'semi'],
            'long' => ['long', 'wooly', 'fluffy', 'shaggy', 'cashmere'],
            'wire' => ['wire', 'rough', 'broken', 'brush', 'bear', 'double'],
            'curl' => ['curl', 'wavy', 'corded', 'rex'],
            default => [],
        };

        foreach ($keywords as $keyword) {
            foreach ($options as $option) {
                if (str_contains(strtolower($option), $keyword)) {
                    return $option;
                }
            }
        }

        return $options[0];
    }
};
