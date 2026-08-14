<?php

namespace Tests\Unit;

use App\Services\AI\GeometrySvgRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GeometrySvgRendererTest extends TestCase
{
    #[DataProvider('shapes')]
    public function test_it_renders_supported_shapes_without_formula_or_answer(string $shape, string $name, array $dimensions): void
    {
        $renderer = new GeometrySvgRenderer;
        $spec = ['type' => 'geometry_2d', 'shape' => $shape, 'unit' => 'cm', 'dimensions' => $dimensions];

        $this->assertNull($renderer->validationError($spec));
        $svg = $renderer->render($spec);
        $this->assertStringContainsString($name, $svg);
        $this->assertStringNotContainsString('Perhitungan', $svg);
        $this->assertStringNotContainsString('Luas =', $svg);
        $this->assertStringNotContainsString('Keliling =', $svg);
        $this->assertStringNotContainsString('Total =', $svg);
    }

    public static function shapes(): array
    {
        return [
            ['square', 'Persegi', ['side' => 8]],
            ['rectangle', 'Persegi Panjang', ['length' => 12, 'width' => 8]],
            ['triangle', 'Segitiga', ['base' => 10, 'height' => 8]],
            ['circle', 'Lingkaran', ['radius' => 7]],
            ['trapezoid', 'Trapesium', ['top_base' => 10, 'bottom_base' => 20, 'height' => 8]],
            ['parallelogram', 'Jajar Genjang', ['base' => 12, 'height' => 8]],
            ['rhombus', 'Belah Ketupat', ['diagonal_1' => 16, 'diagonal_2' => 12]],
            ['kite', 'Layang-layang', ['diagonal_1' => 18, 'diagonal_2' => 12]],
            ['regular_polygon', 'Segi Banyak Beraturan', ['sides' => 6, 'side' => 8]],
        ];
    }

    public function test_it_infers_trapezoid_before_generic_fallback(): void
    {
        $renderer = new GeometrySvgRenderer;
        $spec = $renderer->infer('Trapesium sama kaki dengan sisi sejajar 10 cm dan 20 cm serta tinggi 8 cm.');

        $this->assertSame('trapezoid', $spec['shape']);
        $this->assertSame(10.0, $spec['dimensions']['top_base']);
        $this->assertSame(20.0, $spec['dimensions']['bottom_base']);
        $this->assertSame(8.0, $spec['dimensions']['height']);
        $this->assertStringContainsString('Trapesium', $renderer->render($spec));
    }

    public function test_it_rejects_inconsistent_isosceles_trapezoid(): void
    {
        $renderer = new GeometrySvgRenderer;
        $error = $renderer->validationError([
            'type' => 'geometry_2d',
            'shape' => 'trapezoid',
            'unit' => 'cm',
            'dimensions' => ['top_base' => 10, 'bottom_base' => 20, 'height' => 8, 'leg' => 10],
        ]);

        $this->assertSame('Ukuran trapesium sama kaki tidak konsisten antara sisi sejajar, tinggi, dan sisi miring.', $error);
    }

    public function test_trapezoid_can_use_leg_without_revealing_derived_height(): void
    {
        $renderer = new GeometrySvgRenderer;
        $spec = [
            'type' => 'geometry_2d',
            'shape' => 'trapezoid',
            'unit' => 'cm',
            'dimensions' => ['top_base' => 10, 'bottom_base' => 20, 'leg' => 10],
        ];

        $this->assertNull($renderer->validationError($spec));
        $svg = $renderer->render($spec);
        $this->assertStringContainsString('Sisi miring = 10 cm', $svg);
        $this->assertStringNotContainsString('Tinggi =', $svg);
    }
}
