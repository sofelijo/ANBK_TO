<?php

namespace Tests\Unit;

use App\Services\EducationalGeometryTemplateSvgRenderer;
use DOMDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EducationalGeometryTemplateSvgRendererTest extends TestCase
{
    public function test_every_registered_template_produces_valid_svg(): void
    {
        $renderer = new EducationalGeometryTemplateSvgRenderer;

        foreach (EducationalGeometryTemplateSvgRenderer::TEMPLATES as $template) {
            $svg = $renderer->render($template, 12, 8, 'cm', 5);
            $document = new DOMDocument;

            $this->assertTrue($document->loadXML($svg), "SVG {$template} tidak valid.");
        }
    }

    #[DataProvider('templates')]
    public function test_it_renders_customizable_geometry_templates(string $template, float $a, ?float $b, ?float $c, string $expected): void
    {
        $svg = (new EducationalGeometryTemplateSvgRenderer)->render($template, $a, $b, 'cm', $c);

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString($expected, $svg);
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($svg));
    }

    public static function templates(): array
    {
        return [
            'square' => ['square', 8, null, null, 'sisi = 8 cm'],
            'rectangle' => ['rectangle', 12, 5, null, 'lebar = 5 cm'],
            'parallelogram' => ['parallelogram', 12, 5, null, 'tinggi = 5 cm'],
            'trapezoid' => ['trapezoid', 8, 14, 6, 'tinggi = 6 cm'],
            'rhombus' => ['rhombus', 12, 8, null, 'd₂ = 8 cm'],
            'kite' => ['kite', 12, 8, null, 'd₂ = 8 cm'],
            'triangle' => ['triangle', 10, 6, null, 'tinggi = 6 cm'],
            'triangle right' => ['triangle_right', 10, 6, null, 'tinggi = 6 cm'],
            'triangle isosceles' => ['triangle_isosceles', 10, 6, null, 'tinggi = 6 cm'],
            'triangle equilateral' => ['triangle_equilateral', 10, null, null, 'setiap sisi = 10 cm'],
            'circle' => ['circle', 7, null, null, 'r = 7 cm'],
            'circle diameter' => ['circle_diameter', 14, null, null, 'diameter = 14 cm'],
            'semicircle' => ['semicircle', 14, null, null, 'diameter = 14 cm'],
            'quarter circle' => ['quarter_circle', 7, null, null, 'r = 7 cm'],
            'pentagon' => ['pentagon', 5, null, null, 'sisi = 5 cm'],
            'hexagon' => ['hexagon', 5, null, null, 'sisi = 5 cm'],
            'octagon' => ['octagon', 5, null, null, 'sisi = 5 cm'],
            'cube' => ['cube', 4, null, null, 'rusuk = 4 cm'],
            'cuboid' => ['cuboid', 12, 8, 5, 't = 5 cm'],
            'triangular prism' => ['triangular_prism', 8, 6, 15, 'panjang = 15 cm'],
            'cylinder' => ['cylinder', 7, 12, null, 'tinggi = 12 cm'],
            'square pyramid' => ['square_pyramid', 10, 12, null, 'tinggi = 12 cm'],
            'cone' => ['cone', 7, 12, null, 'tinggi = 12 cm'],
            'sphere' => ['sphere', 7, null, null, 'r = 7 cm'],
        ];
    }

    public function test_numeric_geometry_changes_the_rendered_proportions(): void
    {
        $renderer = new EducationalGeometryTemplateSvgRenderer;

        $squareCircle = $renderer->render('shaded_square_circle', 20, 5, 'cm');
        $this->assertStringContainsString('<rect x="270" y="70" width="460" height="460"', $squareCircle);
        $this->assertStringContainsString('<circle cx="500" cy="300" r="115"', $squareCircle);

        $annulus = $renderer->render('annulus', 10, 5, 'cm');
        $this->assertStringContainsString('<circle cx="500" cy="290" r="110" fill="#fff"', $annulus);

        $sector = $renderer->render('circle_sector', 7, 90, 'cm');
        $this->assertStringContainsString('A220 220 0 0 1 720 300Z', $sector);

        $angle = $renderer->render('angle_acute', 30, null, 'cm');
        $this->assertStringContainsString('x2="690.53" y2="250"', $angle);

        $line = $renderer->render('cartesian_line', 1, 0, 'cm');
        $this->assertStringContainsString('x1="225" y1="500" x2="775" y2="100"', $line);

        $fractions = $renderer->render('fraction_equivalent_circles', 1, 2, '', 4, 1, 0, 0, ['fraction_models' => [
            ['numerator' => 1, 'denominator' => 2],
            ['numerator' => 2, 'denominator' => 3],
            ['numerator' => 3, 'denominator' => 5],
            ['numerator' => 4, 'denominator' => 7],
        ]]);
        $this->assertStringContainsString('Model 1', $fractions);
        $this->assertStringContainsString('Model 2', $fractions);
        $this->assertStringContainsString('Model 3', $fractions);
        $this->assertStringContainsString('Model 4', $fractions);
        $this->assertStringNotContainsString('2/4', $fractions);
        $this->assertStringNotContainsString('4/8', $fractions);
        $this->assertStringNotContainsString('1/2', $fractions);
    }
}
