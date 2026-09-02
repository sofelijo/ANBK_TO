import { GeometryTemplate } from '@/Components/GeometryTemplatePreview';

type Calculation = { label: string; formula: string; value: number; power: 1 | 2 | 3 };
const PI = 22 / 7;

const valid = (...values: number[]) => values.every((value) => Number.isFinite(value) && value > 0);
const polygonArea = (sides: number, side: number) => (sides * side * side) / (4 * Math.tan(Math.PI / sides));
const polygonApothem = (sides: number, side: number) => side / (2 * Math.tan(Math.PI / sides));
const format = (value: number) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 4 }).format(value);
const parseAnswerNumber = (answer: string): number | null => {
    const match = answer.match(/-?[\d.,]+/);
    if (!match) return null;
    const token = match[0];
    const normalized = token.includes(',')
        ? token.replace(/\./g, '').replace(',', '.')
        : token.replace(/,/g, '');
    const value = Number(normalized);
    return Number.isFinite(value) ? value : null;
};

function calculations(template: GeometryTemplate, a: number, b: number, c: number): Calculation[] {
    const area = (label: string, formula: string, value: number): Calculation => ({ label, formula, value, power: 2 });
    const volume = (label: string, formula: string, value: number): Calculation => ({ label, formula, value, power: 3 });
    const length = (label: string, formula: string, value: number): Calculation => ({ label, formula, value, power: 1 });

    if (template === 'square' && valid(a)) return [area('Luas', `s² = ${a}²`, a * a), length('Keliling', `4s = 4 × ${a}`, 4 * a)];
    if (template === 'rectangle' && valid(a, b)) return [area('Luas', `p × l = ${a} × ${b}`, a * b), length('Keliling', `2(p + l) = 2(${a} + ${b})`, 2 * (a + b))];
    if (template === 'parallelogram' && valid(a, b)) return [area('Luas', `alas × tinggi = ${a} × ${b}`, a * b)];
    if (['trapezoid', 'trapezoid_right', 'trapezoid_isosceles'].includes(template) && valid(a, b, c)) return [area('Luas', `½(a + b)t = ½(${a} + ${b}) × ${c}`, ((a + b) * c) / 2)];
    if (['rhombus', 'kite'].includes(template) && valid(a, b)) return [area('Luas', `½d₁d₂ = ½ × ${a} × ${b}`, (a * b) / 2)];
    if (['triangle', 'triangle_right', 'triangle_isosceles', 'triangle_scalene', 'triangle_acute', 'triangle_obtuse'].includes(template) && valid(a, b)) return [area('Luas', `½ × alas × tinggi = ½ × ${a} × ${b}`, (a * b) / 2)];
    if (template === 'triangle_equilateral' && valid(a)) return [area('Luas', `¼√3 × s² = ¼√3 × ${a}²`, (Math.sqrt(3) * a * a) / 4), length('Keliling', `3s = 3 × ${a}`, 3 * a)];
    if (template === 'circle' && valid(a)) return [area('Luas', `πr² = 22/7 × ${a}²`, PI * a * a), length('Keliling', `2πr = 2 × 22/7 × ${a}`, 2 * PI * a)];
    if (template === 'circle_diameter' && valid(a)) return [area('Luas', `π(d/2)² = 22/7 × (${a}/2)²`, PI * (a / 2) ** 2), length('Keliling', `πd = 22/7 × ${a}`, PI * a)];
    if (template === 'semicircle' && valid(a)) return [area('Luas', `½π(d/2)² = ½ × 22/7 × (${a}/2)²`, 0.5 * PI * (a / 2) ** 2), length('Keliling termasuk diameter', `½ × 22/7 × d + d`, (0.5 * PI * a) + a)];
    if (template === 'quarter_circle' && valid(a)) return [area('Luas', `¼πr² = ¼ × 22/7 × ${a}²`, 0.25 * PI * a * a), length('Keliling', `½ × 22/7 × r + 2r`, (0.5 * PI * a) + (2 * a))];
    if (template === 'circle_sector' && valid(a, b)) return [area('Luas juring', `(θ/360) × 22/7 × r²`, (b / 360) * PI * a * a), length('Panjang busur', `(θ/360) × 2 × 22/7 × r`, (b / 360) * 2 * PI * a)];
    if (template === 'annulus' && valid(a, b) && a > b) return [area('Luas cincin', `22/7 × (R² − r²) = 22/7 × (${a}² − ${b}²)`, PI * ((a * a) - (b * b)))];

    const polygonSides: Partial<Record<GeometryTemplate, number>> = { pentagon: 5, hexagon: 6, heptagon: 7, octagon: 8, nonagon: 9, decagon: 10, dodecagon: 12 };
    const sides = polygonSides[template];
    if (sides && valid(a)) return [area('Luas', `ns² / (4 tan(π/n)), n = ${sides}`, polygonArea(sides, a)), length('Keliling', `ns = ${sides} × ${a}`, sides * a)];

    if (['cube', 'cube_net'].includes(template) && valid(a)) return [volume('Volume', `s³ = ${a}³`, a ** 3), area('Luas permukaan', `6s² = 6 × ${a}²`, 6 * a * a)];
    if (['cuboid', 'cuboid_net'].includes(template) && valid(a, b, c)) return [volume('Volume', `p × l × t = ${a} × ${b} × ${c}`, a * b * c), area('Luas permukaan', `2(pl + pt + lt)`, 2 * ((a * b) + (a * c) + (b * c)))];
    if (['triangular_prism', 'triangular_prism_net'].includes(template) && valid(a, b, c)) {
        const base = (a * b) / 2;
        return [area('Luas alas segitiga', `½ × ${a} × ${b}`, base), volume('Volume', `luas alas × panjang = ${base} × ${c}`, base * c)];
    }
    if (['pentagonal_prism', 'hexagonal_prism'].includes(template) && valid(a, b, c)) {
        const n = template === 'pentagonal_prism' ? 5 : 6;
        const base = (n * a * b) / 2;
        return [area('Luas alas', `½ × keliling alas × apotema`, base), volume('Volume', `luas alas × panjang = ${format(base)} × ${c}`, base * c), area('Luas permukaan', `2 × luas alas + keliling × panjang`, (2 * base) + (n * a * c))];
    }
    if (template === 'square_pyramid' && valid(a, b)) {
        const base = a * a;
        const slant = Math.sqrt(((a / 2) ** 2) + (b ** 2));
        return [volume('Volume', `⅓ × s² × t`, (base * b) / 3), area('Luas permukaan', `s² + 2s√((s/2)²+t²)`, base + (2 * a * slant))];
    }
    if (['triangular_pyramid', 'pentagonal_pyramid', 'hexagonal_pyramid'].includes(template) && valid(a, b)) {
        const n = template === 'triangular_pyramid' ? 3 : template === 'pentagonal_pyramid' ? 5 : 6;
        const base = polygonArea(n, a);
        const apothem = polygonApothem(n, a);
        const slant = Math.sqrt((apothem ** 2) + (b ** 2));
        return [area('Luas alas beraturan', `ns² / (4 tan(π/n)), n = ${n}`, base), volume('Volume', `⅓ × luas alas × tinggi`, (base * b) / 3), area('Luas permukaan', `luas alas + ½ × keliling × tinggi sisi`, base + (0.5 * n * a * slant))];
    }
    if (['cylinder', 'cylinder_net'].includes(template) && valid(a, b)) return [volume('Volume', `πr²t = 22/7 × ${a}² × ${b}`, PI * a * a * b), area('Luas permukaan', `2 × 22/7 × r(r + t)`, 2 * PI * a * (a + b))];
    if (template === 'cone' && valid(a, b)) {
        const slant = Math.sqrt((a ** 2) + (b ** 2));
        return [volume('Volume', `⅓ × 22/7 × r²t`, (PI * a * a * b) / 3), area('Luas permukaan', `22/7 × r(r + √(r²+t²))`, PI * a * (a + slant))];
    }
    if (template === 'cone_net' && valid(a, b)) return [area('Luas juring selimut', `πrs = 22/7 × ${a} × ${b}`, PI * a * b), area('Luas total jaring-jaring', `22/7 × r² + 22/7 × rs`, (PI * a * a) + (PI * a * b))];
    if (template === 'sphere' && valid(a)) return [volume('Volume', `⁴⁄₃ × 22/7 × r³`, (4 / 3) * PI * a ** 3), area('Luas permukaan', `4 × 22/7 × r²`, 4 * PI * a * a)];
    if (template === 'hemisphere' && valid(a)) return [volume('Volume', `⅔ × 22/7 × r³`, (2 / 3) * PI * a ** 3), area('Luas permukaan total', `3 × 22/7 × r²`, 3 * PI * a * a)];
    if (template === 'composite_rectangle_semicircle' && valid(a, b)) return [area('Luas gabungan', `pt + ½ × 22/7 × (p/2)²`, (a * b) + (0.5 * PI * (a / 2) ** 2)), length('Keliling luar', `p + 2t + ½ × 22/7 × p`, a + (2 * b) + (0.5 * PI * a))];
    if (template === 'shaded_square_circle' && valid(a, b) && a >= 2 * b) return [area('Luas daerah arsiran', `s² − 22/7 × r² = ${a}² − 22/7 × ${b}²`, (a * a) - (PI * b * b))];
    if (template === 'composite_l_shape' && valid(a, b, c) && c < a && c < b) return [area('Luas (asumsi lekukan c × c)', `pt − c² = ${a} × ${b} − ${c}²`, (a * b) - (c * c))];
    if (template === 'composite_square_semicircle' && valid(a)) return [area('Luas gabungan', `s² + ½ × 22/7 × (s/2)²`, (a * a) + (0.5 * PI * (a / 2) ** 2)), length('Keliling luar', `3s + ½ × 22/7 × s`, (3 * a) + (0.5 * PI * a))];
    if (template === 'composite_square_quarter_circle' && valid(a, b) && b <= a) return [area('Luas arsiran', `s² − ¼ × 22/7 × r²`, (a * a) - (0.25 * PI * b * b))];
    if (template === 'composite_square_four_quarters' && valid(a)) return [area('Luas arsiran', `s² − 22/7 × (s/2)²`, (a * a) - (PI * (a / 2) ** 2))];
    if (template === 'shaded_square_diagonal' && valid(a)) return [area('Luas arsiran', `½ × s²`, 0.5 * a * a)];
    if (template === 'composite_stadium' && valid(a, b)) return [area('Luas gabungan', `p × d + 22/7 × (d/2)²`, (a * b) + (PI * (b / 2) ** 2)), length('Keliling luar', `2p + 22/7 × d`, (2 * a) + (PI * b))];
    if (template === 'composite_rectangle_two_quarters' && valid(a, b)) return [area('Luas arsiran', `p × l − ½ × 22/7 × (l/2)²`, (a * b) - (0.5 * PI * (b / 2) ** 2))];
    if (template === 'shaded_rectangle_circle' && valid(a, b, c) && 2 * c <= Math.min(a, b)) return [area('Luas arsiran', `p × l − 22/7 × r²`, (a * b) - (PI * c * c))];
    if (template === 'composite_triangle_semicircle' && valid(a, b)) return [area('Luas gabungan', `½ × a × t + ½ × 22/7 × (a/2)²`, (0.5 * a * b) + (0.5 * PI * (a / 2) ** 2))];
    if (template === 'composite_triangle_rectangle' && valid(a, b, c)) return [area('Luas gabungan', `a × t▭ + ½ × a × t△`, (a * b) + (0.5 * a * c))];
    if (template === 'shaded_triangle_midsegment' && valid(a, b)) return [area('Luas arsiran (¼ luas segitiga besar)', `¼ × ½ × a × t`, (a * b) / 8)];
    if (template === 'shaded_circle_square' && valid(a)) return [area('Luas arsiran', `22/7 × r² − 2r²`, (PI * a * a) - (2 * a * a))];
    if (template === 'shaded_circle_sector' && valid(a, b) && b <= 360) return [area('Luas juring arsiran', `(θ/360) × 22/7 × r²`, (b / 360) * PI * a * a)];
    if (template === 'shaded_annulus' && valid(a, b) && b < a) return [area('Luas cincin arsiran', `22/7 × (R² − r²)`, PI * ((a * a) - (b * b)))];
    return [];
}

export default function GeometryCalculationInfo({ template, dimensionA, dimensionB, dimensionC, unit, answerCandidates = [] }: { template: GeometryTemplate; dimensionA: string; dimensionB: string; dimensionC: string; unit: string; answerCandidates?: string[] }) {
    const items = calculations(template, Number(dimensionA), Number(dimensionB), Number(dimensionC));
    if (items.length === 0) return null;
    const answerNumbers = answerCandidates.map(parseAnswerNumber).filter((value): value is number => value !== null);

    return (
        <section className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-950">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h4 className="text-sm font-bold">Kalkulasi untuk guru</h4>
                    <p className="mt-0.5 text-xs text-amber-800">Referensi otomatis untuk cross-check kunci jawaban. Tidak ditampilkan kepada siswa.</p>
                </div>
                <span className="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-amber-800">π = 22/7</span>
            </div>
            <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {items.map((item) => {
                    const matchesAnswer = answerNumbers.some((answer) => Math.abs(answer - item.value) <= Math.max(0.01, Math.abs(item.value) * 0.001));
                    return <div key={item.label} className="rounded-lg border border-amber-200 bg-white p-3">
                    <p className="text-xs font-semibold uppercase tracking-wide text-amber-700">{item.label}</p>
                    <p className="mt-1 break-words text-xs text-slate-600">{item.formula}</p>
                    <p className="mt-2 text-base font-bold text-slate-900">≈ {format(item.value)} {unit || 'cm'}{item.power > 1 && <sup>{item.power}</sup>}</p>
                    {answerNumbers.length > 0 && <p className={`mt-2 text-xs font-bold ${matchesAnswer ? 'text-emerald-700' : 'text-rose-700'}`}>{matchesAnswer ? '✓ Cocok dengan jawaban benar' : 'Belum cocok dengan jawaban benar'}</p>}
                </div>})}
            </div>
        </section>
    );
}
