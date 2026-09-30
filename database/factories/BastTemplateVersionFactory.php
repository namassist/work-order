<?php

namespace Database\Factories;

use App\Models\BastTemplate;
use App\Models\BastTemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The next published version of the (single) BAST template, not active
 * unless ->active().
 *
 * @extends Factory<BastTemplateVersion>
 */
class BastTemplateVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bast_template_id' => fn (): int => BastTemplate::current()->id,
            'version' => fn (array $attributes): int => (int) BastTemplateVersion::query()->where('bast_template_id', $attributes['bast_template_id'])->max('version') + 1,
            'html' => '<h1 style="text-align: center">BERITA ACARA SERAH TERIMA</h1><p>Nomor: {{nomor_bast}}</p><p>Work order {{nomor_wo}}: {{judul}}</p>',
            'images' => [],
            'published_by' => null,
            'published_at' => now(),
            'is_active' => false,
        ];
    }

    /**
     * The active version; the caller deactivates any other first.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => true]);
    }
}
