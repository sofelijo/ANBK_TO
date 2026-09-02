<?php

namespace App\Services;

use InvalidArgumentException;

class EducationalGeometryTemplateSvgRenderer
{
    public const TEMPLATES = [
        'square', 'rectangle', 'parallelogram', 'trapezoid', 'trapezoid_right', 'trapezoid_isosceles', 'rhombus', 'kite',
        'triangle', 'triangle_right', 'triangle_isosceles', 'triangle_equilateral', 'triangle_scalene', 'triangle_acute', 'triangle_obtuse',
        'circle', 'circle_diameter', 'semicircle', 'quarter_circle', 'circle_sector', 'annulus',
        'pentagon', 'hexagon', 'heptagon', 'octagon', 'nonagon', 'decagon', 'dodecagon',
        'cube', 'cuboid', 'triangular_prism', 'pentagonal_prism', 'hexagonal_prism',
        'triangular_pyramid', 'square_pyramid', 'pentagonal_pyramid', 'hexagonal_pyramid',
        'cylinder', 'cone', 'sphere', 'hemisphere',
        'parallel_lines', 'perpendicular_lines', 'intersecting_lines',
        'angle_acute', 'angle_right', 'angle_obtuse', 'angle_straight', 'angle_reflex',
        'circle_chord', 'circle_segment', 'circle_tangent',
        'composite_l_shape', 'composite_rectangle_semicircle', 'shaded_square_circle',
        'composite_square_semicircle', 'composite_square_quarter_circle', 'composite_square_four_quarters', 'shaded_square_diagonal',
        'composite_stadium', 'composite_rectangle_two_quarters', 'shaded_rectangle_circle',
        'composite_triangle_semicircle', 'composite_triangle_rectangle', 'shaded_triangle_midsegment',
        'shaded_circle_square', 'shaded_circle_sector', 'shaded_annulus',
        'fraction_circle', 'fraction_bar', 'fraction_equivalent_circles', 'fraction_equivalent_bars',
        'cube_net', 'cuboid_net', 'triangular_prism_net', 'cylinder_net', 'cone_net',
        'cartesian_point', 'cartesian_line', 'translation', 'reflection', 'rotation', 'dilation',
        'ruler', 'clock', 'protractor', 'number_line', 'scale_bar',
    ];

    public function render(string $template, float $dimensionA, ?float $dimensionB, string $unit, ?float $dimensionC = null, float $zoom = 1, float $offsetX = 0, float $offsetY = 0, array $options = []): string
    {
        $signedTemplates = ['cartesian_point', 'cartesian_line', 'translation', 'reflection', 'number_line', 'clock', 'fraction_equivalent_circles'];
        if (! in_array($template, self::TEMPLATES, true) || ($dimensionA <= 0 && ! in_array($template, $signedTemplates, true))) {
            throw new InvalidArgumentException('Template atau ukuran geometri tidak valid.');
        }

        $unit = $this->escape(trim($unit) ?: 'cm');
        $a = $this->measurement($dimensionA, $unit);
        $b = $this->measurement((float) $dimensionB, $unit);
        $c = $this->measurement((float) $dimensionC, $unit);
        $aNumber = $this->number($dimensionA);
        $bNumber = $this->number((float) $dimensionB);
        $cNumber = $this->number((float) $dimensionC);
        $squareCircleRadius = $dimensionB > 0 ? min(230, 460 * $dimensionB / $dimensionA) : 230;
        $squareQuarterRadius = $dimensionB > 0 ? min(460, 460 * $dimensionB / $dimensionA) : 230;
        $sectorAngle = $dimensionB > 0 ? min(360, $dimensionB) : 90;
        $sectorRadians = deg2rad($sectorAngle);
        $sectorEndX = 500 + (220 * sin($sectorRadians));
        $sectorEndY = 290 - (220 * cos($sectorRadians));
        $sectorLargeArc = $sectorAngle > 180 ? 1 : 0;
        $shadedSector = $sectorAngle >= 360
            ? '<circle cx="500" cy="290" r="220" fill="#fbbf24" stroke="#1e40af" stroke-width="5"/>'
            : '<path d="M500 290L500 70A220 220 0 '.$sectorLargeArc.' 1 '.$this->number($sectorEndX).' '.$this->number($sectorEndY).'Z" fill="#fbbf24" stroke="#1e40af" stroke-width="5"/>';
        $circleSectorEndX = 500 + (220 * sin($sectorRadians));
        $circleSectorEndY = 300 - (220 * cos($sectorRadians));
        $circleSector = $sectorAngle >= 360
            ? '<circle cx="500" cy="300" r="220" class="shape"/>'
            : '<path d="M500 300L500 80A220 220 0 '.$sectorLargeArc.' 1 '.$this->number($circleSectorEndX).' '.$this->number($circleSectorEndY).'Z" class="shape"/>';
        $annulusInnerRadius = $dimensionB > 0 ? min(215, 220 * $dimensionB / $dimensionA) : 130;
        $chordHalf = $dimensionB > 0 ? min(210, 210 * $dimensionB / (2 * $dimensionA)) : 165;
        $chordY = 285 - sqrt(max(0, (210 ** 2) - ($chordHalf ** 2)));

        $content = match ($template) {
            'square' => '<rect x="310" y="105" width="380" height="380" rx="4" class="shape"/><line x1="310" y1="525" x2="690" y2="525" class="dimension"/><text x="500" y="565" class="label" text-anchor="middle">sisi = '.$a.'</text>',
            'rectangle' => '<rect x="220" y="145" width="560" height="300" rx="4" class="shape"/><line x1="220" y1="490" x2="780" y2="490" class="dimension"/><text x="500" y="535" class="label" text-anchor="middle">panjang = '.$a.'</text><line x1="830" y1="145" x2="830" y2="445" class="dimension"/><text x="870" y="295" class="label" text-anchor="middle" transform="rotate(-90 870 295)">lebar = '.$b.'</text>',
            'parallelogram' => '<polygon points="300,125 780,125 690,455 210,455" class="shape"/><line x1="210" y1="505" x2="690" y2="505" class="dimension"/><text x="450" y="550" class="label" text-anchor="middle">alas = '.$a.'</text><line x1="760" y1="125" x2="760" y2="455" class="guide"/><text x="800" y="300" class="label" transform="rotate(-90 800 300)" text-anchor="middle">tinggi = '.$b.'</text>',
            'trapezoid' => '<polygon points="330,125 670,125 790,455 210,455" class="shape"/><text x="500" y="105" class="label" text-anchor="middle">sisi atas = '.$a.'</text><line x1="210" y1="505" x2="790" y2="505" class="dimension"/><text x="500" y="550" class="label" text-anchor="middle">sisi bawah = '.$b.'</text><line x1="670" y1="125" x2="670" y2="455" class="guide"/><text x="710" y="300" class="label" transform="rotate(-90 710 300)" text-anchor="middle">tinggi = '.$c.'</text>',
            'trapezoid_right' => '<polygon points="260,125 640,125 790,455 260,455" class="shape"/><path d="M260 415h40v40" class="angle"/><text x="450" y="105" class="label" text-anchor="middle">sisi atas = '.$a.'</text><text x="525" y="510" class="label" text-anchor="middle">sisi bawah = '.$b.'</text><text x="215" y="290" class="label" transform="rotate(-90 215 290)" text-anchor="middle">tinggi = '.$c.'</text>',
            'trapezoid_isosceles' => '<polygon points="330,125 670,125 790,455 210,455" class="shape"/><line x1="330" y1="125" x2="330" y2="455" class="guide"/><line x1="670" y1="125" x2="670" y2="455" class="guide"/><text x="500" y="105" class="label" text-anchor="middle">sisi atas = '.$a.'</text><text x="500" y="510" class="label" text-anchor="middle">sisi bawah = '.$b.'</text><text x="710" y="290" class="label" transform="rotate(-90 710 290)" text-anchor="middle">tinggi = '.$c.'</text>',
            'rhombus' => '<polygon points="500,85 760,300 500,515 240,300" class="shape"/><line x1="240" y1="300" x2="760" y2="300" class="guide"/><line x1="500" y1="85" x2="500" y2="515" class="guide"/><text x="500" y="280" class="label" text-anchor="middle">d₁ = '.$a.'</text><text x="535" y="410" class="label">d₂ = '.$b.'</text>',
            'kite' => '<polygon points="500,75 735,280 500,525 265,280" class="shape"/><line x1="265" y1="280" x2="735" y2="280" class="guide"/><line x1="500" y1="75" x2="500" y2="525" class="guide"/><text x="500" y="260" class="label" text-anchor="middle">d₁ = '.$a.'</text><text x="535" y="430" class="label">d₂ = '.$b.'</text>',
            'triangle', 'triangle_isosceles' => '<polygon points="220,465 780,465 500,115" class="shape"/><line x1="220" y1="510" x2="780" y2="510" class="dimension"/><text x="500" y="555" class="label" text-anchor="middle">alas = '.$a.'</text><line x1="500" y1="115" x2="500" y2="465" class="guide"/><path d="M500 435h30v30" class="angle"/><text x="550" y="300" class="label">tinggi = '.$b.'</text>',
            'triangle_right' => '<polygon points="260,465 780,465 260,115" class="shape"/><path d="M260 425h40v40" class="angle"/><line x1="260" y1="510" x2="780" y2="510" class="dimension"/><text x="520" y="555" class="label" text-anchor="middle">alas = '.$a.'</text><text x="215" y="300" class="label" transform="rotate(-90 215 300)" text-anchor="middle">tinggi = '.$b.'</text>',
            'triangle_equilateral' => '<polygon points="230,465 770,465 500,95" class="shape"/><line x1="230" y1="510" x2="770" y2="510" class="dimension"/><text x="500" y="555" class="label" text-anchor="middle">setiap sisi = '.$a.'</text>',
            'triangle_scalene' => '<polygon points="210,465 790,465 620,105" class="shape"/><line x1="210" y1="510" x2="790" y2="510" class="dimension"/><text x="500" y="555" class="label" text-anchor="middle">alas = '.$a.'</text><line x1="620" y1="105" x2="620" y2="465" class="guide"/><text x="660" y="300" class="label">tinggi = '.$b.'</text>',
            'triangle_acute' => '<polygon points="220,465 780,465 560,115" class="shape"/><text x="500" y="520" class="label" text-anchor="middle">alas = '.$a.'</text><text x="600" y="300" class="label">tinggi = '.$b.'</text>',
            'triangle_obtuse' => '<polygon points="220,465 800,465 330,130" class="shape"/><line x1="330" y1="130" x2="330" y2="465" class="guide"/><text x="510" y="520" class="label" text-anchor="middle">alas = '.$a.'</text><text x="370" y="300" class="label">tinggi = '.$b.'</text>',
            'circle' => '<circle cx="500" cy="290" r="190" class="shape"/><line x1="500" y1="290" x2="690" y2="290" class="dimension"/><circle cx="500" cy="290" r="7" fill="#0f172a"/><text x="595" y="270" class="label" text-anchor="middle">r = '.$a.'</text>',
            'circle_diameter' => '<circle cx="500" cy="290" r="190" class="shape"/><line x1="310" y1="290" x2="690" y2="290" class="dimension"/><circle cx="500" cy="290" r="7" fill="#0f172a"/><text x="500" y="270" class="label" text-anchor="middle">diameter = '.$a.'</text>',
            'semicircle' => '<path d="M260 420a240 240 0 0 1 480 0Z" class="shape"/><line x1="260" y1="465" x2="740" y2="465" class="dimension"/><text x="500" y="515" class="label" text-anchor="middle">diameter = '.$a.'</text>',
            'quarter_circle' => '<path d="M300 455V115a340 340 0 0 1 340 340Z" class="shape"/><line x1="300" y1="455" x2="640" y2="455" class="dimension"/><text x="470" y="510" class="label" text-anchor="middle">r = '.$a.'</text>',
            'circle_sector' => $circleSector.'<text x="585" y="220" class="label">'.$bNumber.'°</text><text x="650" y="350" class="label">r = '.$a.'</text>',
            'annulus' => '<circle cx="500" cy="290" r="220" fill="#dbeafe" stroke="#1e40af" stroke-width="6"/><circle cx="500" cy="290" r="'.$this->number($annulusInnerRadius).'" fill="#fff" stroke="#1e40af" stroke-width="6"/><line x1="500" y1="290" x2="720" y2="290" class="dimension"/><line x1="500" y1="290" x2="'.$this->number(500 + $annulusInnerRadius).'" y2="290" class="dimension"/><text x="670" y="270" class="label">R = '.$a.'</text><text x="555" y="340" class="label">r = '.$b.'</text>',
            'pentagon' => '<polygon points="500,80 790,290 680,505 320,505 210,290" class="shape"/><text x="500" y="555" class="label" text-anchor="middle">sisi = '.$a.'</text>',
            'hexagon' => '<polygon points="320,100 680,100 840,300 680,500 320,500 160,300" class="shape"/><text x="500" y="555" class="label" text-anchor="middle">sisi = '.$a.'</text>',
            'heptagon' => $this->regularPolygon(7, $a),
            'octagon' => '<polygon points="350,80 650,80 820,220 820,380 650,520 350,520 180,380 180,220" class="shape"/><text x="500" y="565" class="label" text-anchor="middle">sisi = '.$a.'</text>',
            'nonagon' => $this->regularPolygon(9, $a),
            'decagon' => $this->regularPolygon(10, $a),
            'dodecagon' => $this->regularPolygon(12, $a),
            'cube' => '<polygon points="350,180 570,180 690,105 470,105" class="top"/><polygon points="350,180 570,180 570,430 350,430" class="front"/><polygon points="570,180 690,105 690,355 570,430" class="side"/><line x1="350" y1="480" x2="570" y2="480" class="dimension"/><text x="460" y="530" class="label" text-anchor="middle">rusuk = '.$a.'</text>',
            'cuboid' => '<polygon points="260,190 650,190 770,115 380,115" class="top"/><polygon points="260,190 650,190 650,440 260,440" class="front"/><polygon points="650,190 770,115 770,365 650,440" class="side"/><text x="455" y="490" class="label" text-anchor="middle">p = '.$a.'</text><text x="735" y="420" class="label">l = '.$b.'</text><text x="205" y="315" class="label" transform="rotate(-90 205 315)" text-anchor="middle">t = '.$c.'</text>',
            'triangular_prism' => $this->prism(3, $a, $b, $c),
            'pentagonal_prism' => $this->prism(5, $a, $b, $c),
            'hexagonal_prism' => $this->prism(6, $a, $b, $c),
            'cylinder' => '<ellipse cx="500" cy="140" rx="210" ry="70" class="top"/><path d="M290 140v300c0 39 94 70 210 70s210-31 210-70V140" class="front"/><ellipse cx="500" cy="440" rx="210" ry="70" class="side"/><text x="500" y="120" class="label" text-anchor="middle">r = '.$a.'</text><text x="755" y="300" class="label" transform="rotate(-90 755 300)" text-anchor="middle">tinggi = '.$b.'</text>',
            'square_pyramid' => '<polygon points="500,70 250,420 500,520 750,420" class="front"/><polygon points="500,70 750,420 500,520" class="side"/><line x1="500" y1="70" x2="500" y2="470" class="guide"/><text x="500" y="565" class="label" text-anchor="middle">sisi alas = '.$a.'</text><text x="535" y="260" class="label">tinggi = '.$b.'</text>',
            'triangular_pyramid' => $this->pyramid(3, $a, $b),
            'pentagonal_pyramid' => $this->pyramid(5, $a, $b),
            'hexagonal_pyramid' => $this->pyramid(6, $a, $b),
            'cone' => '<path d="M500 70 270 440a230 70 0 0 0 460 0Z" class="front"/><ellipse cx="500" cy="440" rx="230" ry="70" class="side"/><line x1="500" y1="70" x2="500" y2="440" class="guide"/><line x1="500" y1="440" x2="730" y2="440" class="dimension"/><text x="615" y="420" class="label" text-anchor="middle">r = '.$a.'</text><text x="535" y="260" class="label">tinggi = '.$b.'</text>',
            'sphere' => '<circle cx="500" cy="290" r="210" class="shape"/><ellipse cx="500" cy="290" rx="210" ry="75" fill="none" stroke="#1e40af" stroke-width="5" stroke-dasharray="12 10"/><line x1="500" y1="290" x2="710" y2="290" class="dimension"/><text x="605" y="270" class="label" text-anchor="middle">r = '.$a.'</text>',
            'hemisphere' => '<path d="M270 300a230 230 0 0 0 460 0Z" class="shape"/><ellipse cx="500" cy="300" rx="230" ry="75" class="top"/><line x1="500" y1="300" x2="730" y2="300" class="dimension"/><text x="615" y="280" class="label" text-anchor="middle">r = '.$a.'</text>',
            'parallel_lines' => '<line x1="180" y1="190" x2="820" y2="190" class="dimension"/><line x1="180" y1="410" x2="820" y2="410" class="dimension"/><line x1="500" y1="205" x2="500" y2="395" class="guide"/><text x="540" y="310" class="label">jarak = '.$a.'</text>',
            'perpendicular_lines' => '<line x1="170" y1="340" x2="830" y2="340" class="dimension"/><line x1="500" y1="80" x2="500" y2="520" class="dimension"/><path d="M500 300h40v40" class="angle"/><text x="570" y="295" class="label">90°</text><text x="500" y="570" class="label" text-anchor="middle">panjang acuan = '.$a.'</text>',
            'intersecting_lines' => $this->intersectingLines((float) $dimensionA),
            'angle_acute', 'angle_right', 'angle_obtuse', 'angle_straight', 'angle_reflex' => $this->angle($template, (float) $dimensionA),
            'circle_chord' => '<circle cx="500" cy="285" r="210" class="shape"/><line x1="'.$this->number(500 - $chordHalf).'" y1="'.$this->number($chordY).'" x2="'.$this->number(500 + $chordHalf).'" y2="'.$this->number($chordY).'" stroke="#334155" stroke-width="5"/><text x="500" y="'.$this->number($chordY - 20).'" class="label" text-anchor="middle">tali busur = '.$b.'</text><line x1="500" y1="285" x2="710" y2="285" class="guide"/><text x="610" y="270" class="label">r = '.$a.'</text>',
            'circle_segment' => '<circle cx="500" cy="285" r="210" class="shape"/><path d="M320 390Q500 530 680 390Z" fill="#fbbf24" fill-opacity="0.65" stroke="#1e40af" stroke-width="5"/><text x="500" y="450" class="label" text-anchor="middle">tembereng</text><text x="600" y="270" class="label">r = '.$a.'</text>',
            'circle_tangent' => '<circle cx="440" cy="300" r="180" class="shape"/><line x1="620" y1="90" x2="620" y2="510" class="dimension"/><line x1="440" y1="300" x2="620" y2="300" class="guide"/><path d="M580 300v40h40" class="angle"/><text x="515" y="280" class="label">r = '.$a.'</text><text x="660" y="300" class="label" transform="rotate(-90 660 300)" text-anchor="middle">garis singgung = '.$b.'</text>',
            'composite_l_shape' => '<path d="M220 100H500V300H780V500H220Z" class="shape"/><text x="360" y="550" class="label" text-anchor="middle">panjang = '.$a.'</text><text x="825" y="390" class="label" transform="rotate(-90 825 390)" text-anchor="middle">tinggi = '.$b.'</text><text x="620" y="280" class="label" text-anchor="middle">lekukan = '.$c.'</text>',
            'composite_rectangle_semicircle' => '<rect x="300" y="250" width="400" height="250" class="shape"/><path d="M300 250a200 200 0 0 1 400 0Z" class="shape"/><text x="500" y="545" class="label" text-anchor="middle">panjang = '.$a.'</text><text x="750" y="375" class="label" transform="rotate(-90 750 375)" text-anchor="middle">tinggi = '.$b.'</text>',
            'shaded_square_circle' => '<rect x="270" y="70" width="460" height="460" fill="#fbbf24" stroke="#1e40af" stroke-width="6"/><circle cx="500" cy="300" r="'.$this->number($squareCircleRadius).'" fill="#fff" stroke="#1e40af" stroke-width="6"/><text x="500" y="570" class="label" text-anchor="middle">sisi persegi = '.$a.'; r = '.$b.'</text>',
            'composite_square_semicircle' => '<rect x="340" y="220" width="320" height="320" class="shape"/><path d="M340 220a160 160 0 0 1 320 0Z" class="top"/><text x="500" y="580" class="label" text-anchor="middle">sisi/diameter = '.$a.'</text>',
            'composite_square_quarter_circle' => '<rect x="270" y="70" width="460" height="460" fill="#fbbf24" stroke="#1e40af" stroke-width="6"/><path d="M270 530V'.$this->number(530 - $squareQuarterRadius).'A'.$this->number($squareQuarterRadius).' '.$this->number($squareQuarterRadius).' 0 0 1 '.$this->number(270 + $squareQuarterRadius).' 530Z" fill="#fff" stroke="#1e40af" stroke-width="6"/><text x="500" y="570" class="label" text-anchor="middle">sisi = '.$a.'; r = '.$b.'</text>',
            'composite_square_four_quarters' => '<rect x="270" y="70" width="460" height="460" fill="#fbbf24" stroke="#1e40af" stroke-width="6"/><path d="M270 300V70H500A230 230 0 0 0 270 300ZM500 70H730V300A230 230 0 0 0 500 70ZM730 300V530H500A230 230 0 0 0 730 300ZM500 530H270V300A230 230 0 0 0 500 530Z" fill="#fff" stroke="#1e40af" stroke-width="5"/><text x="500" y="570" class="label" text-anchor="middle">sisi = '.$a.'</text>',
            'shaded_square_diagonal' => '<rect x="270" y="70" width="460" height="460" class="shape"/><polygon points="270,70 730,530 270,530" fill="#fbbf24" stroke="#1e40af" stroke-width="5"/><text x="500" y="570" class="label" text-anchor="middle">sisi = '.$a.'</text>',
            'composite_stadium' => '<path d="M300 170H700A130 130 0 0 1 700 430H300A130 130 0 0 1 300 170Z" class="shape"/><text x="500" y="500" class="label" text-anchor="middle">panjang = '.$a.'; diameter = '.$b.'</text>',
            'composite_rectangle_two_quarters' => '<rect x="240" y="100" width="520" height="400" fill="#fbbf24" stroke="#1e40af" stroke-width="6"/><path d="M240 300V100h200A200 200 0 0 1 240 300M760 300V500H560A200 200 0 0 1 760 300" fill="#fff" stroke="#1e40af" stroke-width="5"/><text x="500" y="550" class="label" text-anchor="middle">p = '.$a.'; l = '.$b.'</text>',
            'shaded_rectangle_circle' => '<rect x="220" y="100" width="560" height="400" fill="#fbbf24" stroke="#1e40af" stroke-width="6"/><circle cx="500" cy="300" r="150" fill="#fff" stroke="#1e40af" stroke-width="6"/><text x="500" y="550" class="label" text-anchor="middle">p = '.$a.'; l = '.$b.'; r = '.$c.'</text>',
            'composite_triangle_semicircle' => '<polygon points="270,310 730,310 500,60" class="shape"/><path d="M270 310a230 230 0 0 0 460 0Z" class="top"/><text x="500" y="580" class="label" text-anchor="middle">alas/diameter = '.$a.'; tinggi = '.$b.'</text>',
            'composite_triangle_rectangle' => '<rect x="260" y="300" width="480" height="220" class="shape"/><polygon points="260,300 740,300 500,70" class="top"/><text x="500" y="570" class="label" text-anchor="middle">lebar = '.$a.'; t▭ = '.$b.'; t△ = '.$c.'</text>',
            'shaded_triangle_midsegment' => '<polygon points="230,500 770,500 500,70" class="shape"/><polygon points="365,285 635,285 500,70" fill="#fbbf24" stroke="#1e40af" stroke-width="5"/><text x="500" y="550" class="label" text-anchor="middle">alas = '.$a.'; tinggi = '.$b.'</text>',
            'shaded_circle_square' => '<circle cx="500" cy="290" r="230" fill="#fbbf24" stroke="#1e40af" stroke-width="6"/><rect x="337" y="127" width="326" height="326" fill="#fff" stroke="#1e40af" stroke-width="6"/><text x="500" y="570" class="label" text-anchor="middle">r = '.$a.'</text>',
            'shaded_circle_sector' => '<circle cx="500" cy="290" r="220" class="shape"/>'.$shadedSector.'<text x="590" y="220" class="label">'.$bNumber.'°</text><text x="500" y="560" class="label" text-anchor="middle">r = '.$a.'</text>',
            'shaded_annulus' => '<path d="M500 70a220 220 0 1 1 0 440 220 220 0 1 1 0-440m0 90a130 130 0 1 0 0 260 130 130 0 1 0 0-260" fill="#fbbf24" fill-rule="evenodd" stroke="#1e40af" stroke-width="6"/><text x="500" y="570" class="label" text-anchor="middle">R = '.$a.'; r = '.$b.'</text>',
            'fraction_circle' => '<text x="500" y="70" class="label" text-anchor="middle">Bagian yang diarsir</text>'.$this->fractionCircle(500, 320, 205, (int) $dimensionA, (int) $dimensionB),
            'fraction_bar' => '<text x="500" y="120" class="label" text-anchor="middle">Bagian yang diarsir</text>'.$this->fractionBar(170, 220, 660, 210, (int) $dimensionA, (int) $dimensionB),
            'fraction_equivalent_circles' => $this->equivalentFractionCircles((int) $dimensionA, (int) $dimensionB, $options['fraction_models'] ?? []),
            'fraction_equivalent_bars' => $this->equivalentFractionBars((int) $dimensionA, (int) $dimensionB, (int) $dimensionC),
            'cube_net' => $this->boxNet(true, $a),
            'cuboid_net' => $this->boxNet(false, $a, $b, $c),
            'triangular_prism_net' => $this->prismNet($a, $b, $c),
            'cylinder_net' => '<rect x="250" y="170" width="500" height="260" class="shape"/><circle cx="500" cy="80" r="90" class="top"/><circle cx="500" cy="520" r="90" class="top"/><text x="610" y="470" class="label">tinggi = '.$b.'</text><text x="500" y="85" class="label" text-anchor="middle">r = '.$a.'</text>',
            'cone_net' => '<path d="M480 320L250 470A275 275 0 0 1 710 470Z" class="shape"/><circle cx="760" cy="395" r="90" class="top"/><text x="760" y="400" class="label" text-anchor="middle">r = '.$a.'</text><text x="600" y="420" class="label">s = '.$b.'</text>',
            'cartesian_point' => $this->coordinatePlane('Titik P('.$aNumber.', '.$bNumber.')', $aNumber, $bNumber),
            'cartesian_line' => $this->coordinatePlane('y = '.$aNumber.'x + '.$bNumber, (float) $dimensionA, (float) $dimensionB, true),
            'translation' => $this->coordinatePlane('Translasi ('.$aNumber.', '.$bNumber.')', $aNumber, $bNumber, false, true),
            'reflection' => $this->coordinatePlane('Refleksi pada x = '.$aNumber, $aNumber, 0, false, false, true),
            'rotation' => $this->coordinatePlane('Rotasi '.$aNumber.'°', 3, 2, false, false, false, (float) $dimensionA),
            'dilation' => $this->coordinatePlane('Dilatasi k = '.$aNumber, 3, 2, false, false, false, null, (float) $dimensionA),
            'ruler' => $this->ruler($aNumber, $unit),
            'clock' => $this->clock((float) $dimensionA, (float) $dimensionB),
            'protractor' => $this->protractor((float) $dimensionA),
            'number_line' => $this->numberLine((float) $dimensionA, (float) $dimensionB),
            'scale_bar' => $this->scaleBar($aNumber, max(1, (int) $dimensionB), $unit),
        };

        $zoom = max(0.25, min(3, $zoom));
        $offsetX = max(-500, min(500, $offsetX));
        $offsetY = max(-300, min(300, $offsetY));
        $transform = 'translate(500 300) translate('.$this->number($offsetX).' '.$this->number($offsetY).') scale('.$this->number($zoom).') translate(-500 -300)';

        return '<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="600" viewBox="0 0 1000 600" role="img" aria-label="Template geometri"><defs><marker id="arrow-start" markerWidth="10" markerHeight="10" refX="2" refY="5" orient="auto"><path d="M10 0L0 5l10 5" fill="#334155"/></marker><marker id="arrow-end" markerWidth="10" markerHeight="10" refX="8" refY="5" orient="auto"><path d="M0 0l10 5-10 5" fill="#334155"/></marker></defs><style>.shape{fill:#dbeafe;stroke:#1e40af;stroke-width:6}.dimension{stroke:#334155;stroke-width:4;marker-start:url(#arrow-start);marker-end:url(#arrow-end)}.guide{stroke:#f59e0b;stroke-width:4;stroke-dasharray:12 10}.angle{fill:none;stroke:#f59e0b;stroke-width:4}.label{font:700 26px sans-serif;fill:#0f172a}.top{fill:#bfdbfe;stroke:#1e40af;stroke-width:5}.front{fill:#60a5fa;stroke:#1e40af;stroke-width:5}.side{fill:#2563eb;stroke:#1e40af;stroke-width:5}</style><rect width="1000" height="600" fill="#fff"/><g transform="'.$transform.'">'.$content.'</g></svg>';
    }

    private function angle(string $template, float $degrees): string
    {
        $radians = deg2rad($degrees);
        $endX = 500 + (220 * cos($radians));
        $endY = 360 - (220 * sin($radians));
        $arcX = 500 + (110 * cos($radians));
        $arcY = 360 - (110 * sin($radians));
        $largeArc = $degrees > 180 ? 1 : 0;
        $extra = $template === 'angle_right'
            ? '<path d="M500 320h40v40" class="angle"/>'
            : '<path d="M610 360A110 110 0 '.$largeArc.' 0 '.$this->number($arcX).' '.$this->number($arcY).'" class="angle"/>';

        return '<line x1="500" y1="360" x2="780" y2="360" class="dimension"/><line x1="500" y1="360" x2="'.$this->number($endX).'" y2="'.$this->number($endY).'" class="dimension"/>'.$extra.'<circle cx="500" cy="360" r="7" fill="#0f172a"/><text x="625" y="300" class="label">'.$this->number($degrees).'°</text>';
    }

    private function intersectingLines(float $degrees): string
    {
        $radians = deg2rad(min(180, $degrees));
        $dx = 220 * cos($radians);
        $dy = 220 * sin($radians);
        $arcX = 500 + (90 * cos($radians));
        $arcY = 300 - (90 * sin($radians));

        return '<line x1="170" y1="300" x2="830" y2="300" stroke="#334155" stroke-width="4"/><line x1="'.$this->number(500 - $dx).'" y1="'.$this->number(300 + $dy).'" x2="'.$this->number(500 + $dx).'" y2="'.$this->number(300 - $dy).'" stroke="#334155" stroke-width="4"/><path d="M590 300A90 90 0 0 0 '.$this->number($arcX).' '.$this->number($arcY).'" class="angle"/><text x="600" y="240" class="label">'.$this->number($degrees).'°</text>';
    }

    private function boxNet(bool $cube, string $a, string $b = '', string $c = ''): string
    {
        if ($cube) {
            return '<g class="shape"><rect x="360" y="60" width="140" height="140"/><rect x="220" y="200" width="140" height="140"/><rect x="360" y="200" width="140" height="140"/><rect x="500" y="200" width="140" height="140"/><rect x="640" y="200" width="140" height="140"/><rect x="360" y="340" width="140" height="140"/></g><text x="500" y="535" class="label" text-anchor="middle">rusuk = '.$a.'</text>';
        }

        return '<g class="shape"><rect x="320" y="80" width="180" height="120"/><rect x="200" y="200" width="120" height="180"/><rect x="320" y="200" width="180" height="180"/><rect x="500" y="200" width="120" height="180"/><rect x="620" y="200" width="180" height="180"/><rect x="320" y="380" width="180" height="120"/></g><text x="500" y="555" class="label" text-anchor="middle">p = '.$a.'; l = '.$b.'; t = '.$c.'</text>';
    }

    private function prismNet(string $a, string $b, string $c): string
    {
        return '<polygon points="180,300 310,100 440,300" class="top"/><rect x="180" y="300" width="260" height="180" class="shape"/><rect x="440" y="300" width="220" height="180" class="shape"/><rect x="660" y="300" width="160" height="180" class="shape"/><polygon points="440,300 570,100 700,300" class="top"/><text x="500" y="540" class="label" text-anchor="middle">alas = '.$a.'; t△ = '.$b.'; panjang = '.$c.'</text>';
    }

    private function coordinatePlane(string $caption, float $x, float $y, bool $line = false, bool $translation = false, bool $reflection = false, ?float $rotation = null, ?float $scale = null): string
    {
        $content = '<g stroke="#cbd5e1" stroke-width="2">';
        foreach (range(100, 900, 80) as $position) {
            $content .= '<line x1="'.$position.'" y1="80" x2="'.$position.'" y2="520"/>';
        }
        foreach (range(100, 500, 80) as $position) {
            $content .= '<line x1="100" y1="'.$position.'" x2="900" y2="'.$position.'"/>';
        }
        $content .= '</g><line x1="100" y1="300" x2="900" y2="300" class="dimension"/><line x1="500" y1="520" x2="500" y2="80" class="dimension"/><text x="880" y="335" class="label">x</text><text x="530" y="105" class="label">y</text>';
        if ($line) {
            $startY = max(100, min(500, 300 - (((-$x * 5) + $y) * 45)));
            $endY = max(100, min(500, 300 - ((($x * 5) + $y) * 45)));
            $content .= '<line x1="225" y1="'.$this->number($startY).'" x2="775" y2="'.$this->number($endY).'" stroke="#2563eb" stroke-width="8"/>';
        } elseif ($reflection) {
            $axisX = max(260, min(740, 500 + ($x * 55)));
            $content .= '<line x1="'.$this->number($axisX).'" y1="90" x2="'.$this->number($axisX).'" y2="510" class="guide"/><polygon points="'.($axisX - 220).',400 '.($axisX - 100).',400 '.($axisX - 160).',220" class="front"/><polygon points="'.($axisX + 220).',400 '.($axisX + 100).',400 '.($axisX + 160).',220" class="top"/>';
        } elseif ($rotation !== null) {
            $content .= '<polygon points="540,390 670,390 590,210" class="front"/><polygon points="540,390 670,390 590,210" class="top" opacity="0.7" transform="rotate('.$this->number(-$rotation).' 500 300)"/><path d="M650 390A170 170 0 0 0 430 260" class="guide"/>';
        } elseif ($scale !== null) {
            $visualScale = max(0.25, min(2, $scale));
            $content .= '<polygon points="520,340 600,340 540,230" class="front"/><polygon points="520,340 600,340 540,230" class="top" opacity="0.55" transform="translate(500 300) scale('.$this->number($visualScale).') translate(-500 -300)"/>';
        } elseif ($translation) {
            $dx = max(-250, min(250, $x * 55));
            $dy = max(-160, min(160, -$y * 45));
            $content .= '<polygon points="380,400 500,400 440,230" class="front"/><polygon points="380,400 500,400 440,230" class="top" transform="translate('.$this->number($dx).' '.$this->number($dy).')"/><line x1="440" y1="315" x2="'.$this->number(440 + $dx).'" y2="'.$this->number(315 + $dy).'" class="dimension"/>';
        } else {
            $plotX = max(130, min(870, 500 + ($x * 55)));
            $plotY = max(100, min(500, 300 - ($y * 45)));
            $content .= '<circle cx="'.$plotX.'" cy="'.$plotY.'" r="13" fill="#ef4444"/><line x1="'.$plotX.'" y1="'.$plotY.'" x2="'.$plotX.'" y2="300" class="guide"/><line x1="'.$plotX.'" y1="'.$plotY.'" x2="500" y2="'.$plotY.'" class="guide"/>';
        }

        return $content.'<text x="500" y="570" class="label" text-anchor="middle">'.$caption.'</text>';
    }

    private function ruler(string $length, string $unit): string
    {
        $content = '<rect x="100" y="210" width="800" height="180" rx="12" fill="#fef3c7" stroke="#92400e" stroke-width="6"/>';
        foreach (range(0, 20) as $index) {
            $x = 120 + ($index * 38);
            $height = $index % 5 === 0 ? 85 : ($index % 2 === 0 ? 55 : 35);
            $content .= '<line x1="'.$x.'" y1="210" x2="'.$x.'" y2="'.(210 + $height).'" stroke="#92400e" stroke-width="4"/>';
        }

        return $content.'<text x="500" y="445" class="label" text-anchor="middle">panjang ukur = '.$length.' '.$unit.'</text>';
    }

    private function clock(float $hour, float $minute): string
    {
        $hourAngle = deg2rad((($hour % 12) * 30) + ($minute * 0.5) - 90);
        $minuteAngle = deg2rad(($minute * 6) - 90);
        $hourX = 500 + (125 * cos($hourAngle));
        $hourY = 290 + (125 * sin($hourAngle));
        $minuteX = 500 + (190 * cos($minuteAngle));
        $minuteY = 290 + (190 * sin($minuteAngle));
        $content = '<circle cx="500" cy="290" r="235" fill="#fff" stroke="#1e40af" stroke-width="8"/>';
        foreach (range(1, 12) as $number) {
            $angle = deg2rad(($number * 30) - 90);
            $content .= '<text x="'.(500 + (195 * cos($angle))).'" y="'.(300 + (195 * sin($angle))).'" class="label" text-anchor="middle">'.$number.'</text>';
        }

        return $content.'<line x1="500" y1="290" x2="'.$hourX.'" y2="'.$hourY.'" stroke="#0f172a" stroke-width="12"/><line x1="500" y1="290" x2="'.$minuteX.'" y2="'.$minuteY.'" stroke="#ef4444" stroke-width="7"/><circle cx="500" cy="290" r="12" fill="#0f172a"/><text x="500" y="570" class="label" text-anchor="middle">'.sprintf('%02d:%02d', (int) $hour, (int) $minute).'</text>';
    }

    private function protractor(float $degrees): string
    {
        $radians = deg2rad(min(180, $degrees));
        $rayX = 500 + (300 * cos($radians));
        $rayY = 430 - (300 * sin($radians));
        $arcX = 500 + (110 * cos($radians));
        $arcY = 430 - (110 * sin($radians));

        return '<path d="M170 430a330 330 0 0 1 660 0Z" fill="#dbeafe" stroke="#1e40af" stroke-width="7"/><line x1="500" y1="430" x2="820" y2="430" stroke="#334155" stroke-width="4"/><line x1="500" y1="430" x2="'.$this->number($rayX).'" y2="'.$this->number($rayY).'" stroke="#334155" stroke-width="4"/><path d="M610 430A110 110 0 0 0 '.$this->number($arcX).' '.$this->number($arcY).'" class="angle"/><text x="620" y="335" class="label">'.$this->number($degrees).'°</text>';
    }

    private function numberLine(float $minimum, float $maximum): string
    {
        $content = '<line x1="120" y1="300" x2="880" y2="300" class="dimension"/>';
        $steps = 10;
        foreach (range(0, $steps) as $index) {
            $x = 140 + ($index * 72);
            $value = $minimum + (($maximum - $minimum) * $index / $steps);
            $content .= '<line x1="'.$x.'" y1="275" x2="'.$x.'" y2="325" stroke="#0f172a" stroke-width="4"/><text x="'.$x.'" y="365" class="label" text-anchor="middle">'.$this->number($value).'</text>';
        }

        return $content;
    }

    private function scaleBar(string $length, int $intervals, string $unit): string
    {
        $intervals = min(12, $intervals);
        $width = 700 / $intervals;
        $content = '';
        foreach (range(0, $intervals - 1) as $index) {
            $content .= '<rect x="'.(150 + ($index * $width)).'" y="260" width="'.$width.'" height="90" fill="'.($index % 2 === 0 ? '#0f172a' : '#fff').'" stroke="#0f172a" stroke-width="3"/>';
        }

        return $content.'<text x="500" y="410" class="label" text-anchor="middle">'.$intervals.' bagian = '.$length.' '.$unit.'</text>';
    }

    private function fractionCircle(float $centerX, float $centerY, float $radius, int $numerator, int $denominator, ?array $shadedParts = null): string
    {
        $parts = max(1, min(24, $denominator));
        $shaded = max(0, min($parts, $numerator));
        $selected = $shadedParts ?? range(0, max(0, $shaded - 1));
        if ($shaded === 0 && $shadedParts === null) {
            $selected = [];
        }
        if ($parts === 1) {
            return '<circle cx="'.$centerX.'" cy="'.$centerY.'" r="'.$radius.'" fill="'.(in_array(0, $selected, true) ? '#38bdf8' : '#fff').'" stroke="#0f172a" stroke-width="4"/>';
        }
        $content = '';
        foreach (range(0, $parts - 1) as $index) {
            $start = (-M_PI / 2) + (($index * 2 * M_PI) / $parts);
            $end = (-M_PI / 2) + ((($index + 1) * 2 * M_PI) / $parts);
            $startX = $centerX + ($radius * cos($start));
            $startY = $centerY + ($radius * sin($start));
            $endX = $centerX + ($radius * cos($end));
            $endY = $centerY + ($radius * sin($end));
            $content .= '<path d="M'.$centerX.' '.$centerY.'L'.$this->number($startX).' '.$this->number($startY).'A'.$radius.' '.$radius.' 0 0 1 '.$this->number($endX).' '.$this->number($endY).'Z" fill="'.(in_array($index, $selected, true) ? '#38bdf8' : '#fff').'" stroke="#0f172a" stroke-width="4"/>';
        }

        return $content;
    }

    private function fractionBar(float $x, float $y, float $width, float $height, int $numerator, int $denominator): string
    {
        $parts = max(1, min(24, $denominator));
        $shaded = max(0, min($parts, $numerator));
        $partWidth = $width / $parts;
        $content = '';
        foreach (range(0, $parts - 1) as $index) {
            $content .= '<rect x="'.$this->number($x + ($partWidth * $index)).'" y="'.$y.'" width="'.$this->number($partWidth).'" height="'.$height.'" fill="'.($index < $shaded ? '#38bdf8' : '#fff').'" stroke="#0f172a" stroke-width="4"/>';
        }

        return $content;
    }

    /** @param array<int, array{numerator: int|string, denominator: int|string}> $models */
    private function equivalentFractionCircles(int $numerator, int $denominator, array $models): string
    {
        if (count($models) < 2) {
            $models = [
                ['numerator' => $numerator, 'denominator' => $denominator],
                ['numerator' => $numerator * 2, 'denominator' => $denominator * 2],
            ];
        }
        $models = array_slice($models, 0, 4);
        $count = count($models);
        $radius = $count === 2 ? 150 : ($count === 3 ? 120 : 95);
        $centers = match ($count) {
            2 => [330, 670],
            3 => [230, 500, 770],
            default => [150, 383, 617, 850],
        };
        $content = '<text x="500" y="65" class="label" text-anchor="middle">Perhatikan bagian yang diarsir</text>';
        foreach ($models as $index => $model) {
            $selectedParts = array_key_exists('shaded_parts', $model) ? array_map('intval', $model['shaded_parts']) : null;
            $content .= $this->fractionCircle($centers[$index], 285, $radius, (int) $model['numerator'], (int) $model['denominator'], $selectedParts);
            $content .= '<text x="'.$centers[$index].'" y="'.(285 + $radius + 50).'" class="label" text-anchor="middle">Model '.($index + 1).'</text>';
        }

        return $content;
    }

    private function equivalentFractionBars(int $numerator, int $denominator, int $factor): string
    {
        $factor = max(2, min(4, $factor));

        return '<text x="500" y="65" class="label" text-anchor="middle">Perhatikan bagian yang diarsir</text>'
            .$this->fractionBar(180, 120, 640, 100, $numerator, $denominator)
            .$this->fractionBar(180, 255, 640, 100, $numerator * 2, $denominator * 2)
            .$this->fractionBar(180, 390, 640, 100, $numerator * $factor, $denominator * $factor);
    }

    private function regularPolygon(int $sides, string $sideLength): string
    {
        $points = $this->polygonPoints($sides, 500, 285, 215, 215);

        return '<polygon points="'.$points.'" class="shape"/><text x="500" y="555" class="label" text-anchor="middle">sisi = '.$sideLength.'</text>';
    }

    private function prism(int $sides, string $baseSize, string $baseHeight, string $length): string
    {
        $front = $this->polygonPointArray($sides, 360, 315, 165, 150);
        $back = array_map(fn (array $point): array => [$point[0] + 260, $point[1] - 75], $front);
        $content = '<polygon points="'.$this->points($back).'" class="top"/>';
        foreach (range(0, $sides - 1) as $index) {
            $next = ($index + 1) % $sides;
            $content .= '<polygon points="'.$this->points([$front[$index], $front[$next], $back[$next], $back[$index]]).'" class="side" opacity="'.(0.55 + (($index % 3) * 0.15)).'"/>';
        }
        $content .= '<polygon points="'.$this->points($front).'" class="front"/>';
        $content .= '<text x="360" y="535" class="label" text-anchor="middle">ukuran alas = '.$baseSize.'</text>';
        $content .= '<text x="220" y="305" class="label" text-anchor="middle">tinggi alas = '.$baseHeight.'</text>';
        $content .= '<line x1="'.$front[0][0].'" y1="'.$front[0][1].'" x2="'.$back[0][0].'" y2="'.$back[0][1].'" class="dimension"/>';
        $content .= '<text x="'.(($front[0][0] + $back[0][0]) / 2).'" y="'.((($front[0][1] + $back[0][1]) / 2) - 25).'" class="label" text-anchor="middle">panjang = '.$length.'</text>';

        return $content;
    }

    private function pyramid(int $sides, string $baseSize, string $height): string
    {
        $base = $this->polygonPointArray($sides, 500, 420, 270, 95);
        $apex = [500, 75];
        $content = '';
        foreach (range(0, $sides - 1) as $index) {
            $next = ($index + 1) % $sides;
            $content .= '<polygon points="'.$this->points([$apex, $base[$index], $base[$next]]).'" class="'.($index % 2 === 0 ? 'front' : 'side').'" opacity="'.(0.65 + (($index % 2) * 0.2)).'"/>';
        }
        $content .= '<polygon points="'.$this->points($base).'" class="top" opacity="0.65"/>';
        $content .= '<line x1="500" y1="75" x2="500" y2="420" class="guide"/><text x="545" y="250" class="label">tinggi = '.$height.'</text>';
        $content .= '<text x="500" y="555" class="label" text-anchor="middle">sisi alas = '.$baseSize.'</text>';

        return $content;
    }

    /** @return array<int, array{0: float, 1: float}> */
    private function polygonPointArray(int $sides, float $centerX, float $centerY, float $radiusX, float $radiusY): array
    {
        return array_map(function (int $index) use ($sides, $centerX, $centerY, $radiusX, $radiusY): array {
            $angle = (-M_PI / 2) + (($index * 2 * M_PI) / $sides);

            return [round($centerX + ($radiusX * cos($angle)), 2), round($centerY + ($radiusY * sin($angle)), 2)];
        }, range(0, $sides - 1));
    }

    private function polygonPoints(int $sides, float $centerX, float $centerY, float $radiusX, float $radiusY): string
    {
        return $this->points($this->polygonPointArray($sides, $centerX, $centerY, $radiusX, $radiusY));
    }

    /** @param array<int, array{0: float, 1: float}> $points */
    private function points(array $points): string
    {
        return implode(' ', array_map(fn (array $point): string => $point[0].','.$point[1], $points));
    }

    private function measurement(float $number, string $unit): string
    {
        return $this->number($number).' '.$unit;
    }

    private function number(float $number): string
    {
        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
