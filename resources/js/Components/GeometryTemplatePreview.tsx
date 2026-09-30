import { useRef } from 'react';
import type { MouseEvent, PointerEvent as ReactPointerEvent } from 'react';

export type GeometryTemplate =
    | 'square' | 'rectangle' | 'parallelogram' | 'trapezoid' | 'trapezoid_right' | 'trapezoid_isosceles' | 'rhombus' | 'kite'
    | 'triangle' | 'triangle_right' | 'triangle_isosceles' | 'triangle_equilateral' | 'triangle_scalene' | 'triangle_acute' | 'triangle_obtuse'
    | 'circle' | 'circle_diameter' | 'semicircle' | 'quarter_circle' | 'circle_sector' | 'annulus'
    | 'pentagon' | 'hexagon' | 'heptagon' | 'octagon' | 'nonagon' | 'decagon' | 'dodecagon'
    | 'cube' | 'cuboid' | 'triangular_prism' | 'pentagonal_prism' | 'hexagonal_prism'
    | 'triangular_pyramid' | 'square_pyramid' | 'pentagonal_pyramid' | 'hexagonal_pyramid'
    | 'cylinder' | 'cone' | 'sphere' | 'hemisphere'
    | 'parallel_lines' | 'perpendicular_lines' | 'intersecting_lines'
    | 'angle_acute' | 'angle_right' | 'angle_obtuse' | 'angle_straight' | 'angle_reflex'
    | 'circle_chord' | 'circle_segment' | 'circle_tangent'
    | 'composite_l_shape' | 'composite_rectangle_semicircle' | 'shaded_square_circle'
    | 'composite_square_semicircle' | 'composite_square_quarter_circle' | 'composite_square_four_quarters' | 'shaded_square_diagonal'
    | 'composite_stadium' | 'composite_rectangle_two_quarters' | 'shaded_rectangle_circle'
    | 'composite_triangle_semicircle' | 'composite_triangle_rectangle' | 'shaded_triangle_midsegment'
    | 'shaded_circle_square' | 'shaded_circle_sector' | 'shaded_annulus'
    | 'fraction_circle' | 'fraction_bar' | 'fraction_equivalent_circles' | 'fraction_equivalent_bars'
    | 'cube_net' | 'cuboid_net' | 'triangular_prism_net' | 'cylinder_net' | 'cone_net'
    | 'cartesian_point' | 'cartesian_line' | 'translation' | 'reflection' | 'rotation' | 'dilation'
    | 'ruler' | 'clock' | 'protractor_90' | 'protractor' | 'protractor_270' | 'protractor_360' | 'number_line' | 'scale_bar';

const formatted = (value: string, unit: string) => `${value || '?'} ${unit || 'cm'}`;
const shape = { fill: '#dbeafe', stroke: '#1e40af', strokeWidth: 6 };
const top = { fill: '#bfdbfe', stroke: '#1e40af', strokeWidth: 5 };
const front = { fill: '#60a5fa', stroke: '#1e40af', strokeWidth: 5 };
const side = { fill: '#2563eb', stroke: '#1e40af', strokeWidth: 5 };
type CanvasOverlay = { id: string; type: 'text' | 'symbol'; content: string; x: number; y: number; font_size: number; color: string; rotation: number };
type ProtractorAngle = { id: string; label: string; degrees: string; asked: boolean };

export default function GeometryTemplatePreview({ template, dimensionA, dimensionB, dimensionC = '', unit, fractionModels = [], protractorAngles = [], onToggleFractionPart, overlays = [], selectedOverlayId = null, onSelectOverlay, onMoveOverlay, zoom = 1, offsetX = 0, offsetY = 0, className = '' }: { template: GeometryTemplate; dimensionA: string; dimensionB: string; dimensionC?: string; unit: string; fractionModels?: { numerator: string; denominator: string; shaded_parts?: number[] }[]; protractorAngles?: ProtractorAngle[]; onToggleFractionPart?: (modelIndex: number, partIndex: number) => void; overlays?: CanvasOverlay[]; selectedOverlayId?: string | null; onSelectOverlay?: (id: string) => void; onMoveOverlay?: (id: string, x: number, y: number) => void; zoom?: number; offsetX?: number; offsetY?: number; className?: string }) {
    const a = formatted(dimensionA, unit);
    const b = formatted(dimensionB, unit);
    const c = formatted(dimensionC, unit);
    const overlayDrag = useRef<{ id: string; clientX: number; clientY: number; x: number; y: number } | null>(null);
    const startOverlayDrag = (event: ReactPointerEvent<SVGTextElement>, overlay: CanvasOverlay) => {
        event.stopPropagation();
        event.currentTarget.setPointerCapture(event.pointerId);
        overlayDrag.current = { id: overlay.id, clientX: event.clientX, clientY: event.clientY, x: overlay.x, y: overlay.y };
        onSelectOverlay?.(overlay.id);
    };
    const moveOverlay = (event: ReactPointerEvent<SVGTextElement>) => {
        if (!overlayDrag.current || !onMoveOverlay) return;
        event.stopPropagation();
        const bounds = event.currentTarget.ownerSVGElement?.getBoundingClientRect();
        if (!bounds) return;
        const x = Math.max(0, Math.min(1000, Math.round(overlayDrag.current.x + ((event.clientX - overlayDrag.current.clientX) * 1000 / bounds.width))));
        const y = Math.max(0, Math.min(600, Math.round(overlayDrag.current.y + ((event.clientY - overlayDrag.current.clientY) * 600 / bounds.height))));
        onMoveOverlay(overlayDrag.current.id, x, y);
    };
    const stopOverlayDrag = (event: ReactPointerEvent<SVGTextElement>) => {
        event.stopPropagation();
        overlayDrag.current = null;
    };

    return (
        <svg viewBox="0 0 1000 600" role="img" aria-label="Preview template geometri" className={className}>
            <defs>
                <marker id="geometry-arrow-start" markerWidth="10" markerHeight="10" refX="2" refY="5" orient="auto"><path d="M10 0L0 5l10 5" fill="#334155" /></marker>
                <marker id="geometry-arrow-end" markerWidth="10" markerHeight="10" refX="8" refY="5" orient="auto"><path d="M0 0l10 5-10 5" fill="#334155" /></marker>
            </defs>
            <rect width="1000" height="600" fill="#fff" />
            <g transform={`translate(500 300) translate(${offsetX} ${offsetY}) scale(${zoom}) translate(-500 -300)`}>
            {template === 'square' && <><rect x="310" y="105" width="380" height="380" rx="4" {...shape} /><DimensionLine x1={310} y1={525} x2={690} y2={525} /><Label x={500} y={565} text={`sisi = ${a}`} /></>}
            {template === 'rectangle' && <><rect x="220" y="145" width="560" height="300" rx="4" {...shape} /><DimensionLine x1={220} y1={490} x2={780} y2={490} /><Label x={500} y={535} text={`panjang = ${a}`} /><DimensionLine x1={830} y1={145} x2={830} y2={445} /><RotatedLabel x={870} y={295} text={`lebar = ${b}`} /></>}
            {template === 'parallelogram' && <><polygon points="300,125 780,125 690,455 210,455" {...shape} /><DimensionLine x1={210} y1={505} x2={690} y2={505} /><Label x={450} y={550} text={`alas = ${a}`} /><Guide x1={760} y1={125} x2={760} y2={455} /><RotatedLabel x={800} y={300} text={`tinggi = ${b}`} /></>}
            {template === 'trapezoid' && <><polygon points="330,125 670,125 790,455 210,455" {...shape} /><Label x={500} y={105} text={`sisi atas = ${a}`} /><DimensionLine x1={210} y1={505} x2={790} y2={505} /><Label x={500} y={550} text={`sisi bawah = ${b}`} /><Guide x1={670} y1={125} x2={670} y2={455} /><RotatedLabel x={710} y={300} text={`tinggi = ${c}`} /></>}
            {template === 'trapezoid_right' && <><polygon points="260,125 640,125 790,455 260,455" {...shape} /><RightAngle x={260} y={415} /><Label x={450} y={105} text={`sisi atas = ${a}`} /><Label x={525} y={510} text={`sisi bawah = ${b}`} /><RotatedLabel x={215} y={290} text={`tinggi = ${c}`} /></>}
            {template === 'trapezoid_isosceles' && <><polygon points="330,125 670,125 790,455 210,455" {...shape} /><Guide x1={330} y1={125} x2={330} y2={455} /><Guide x1={670} y1={125} x2={670} y2={455} /><Label x={500} y={105} text={`sisi atas = ${a}`} /><Label x={500} y={510} text={`sisi bawah = ${b}`} /><RotatedLabel x={710} y={290} text={`tinggi = ${c}`} /></>}
            {(template === 'rhombus' || template === 'kite') && <><polygon points={template === 'rhombus' ? '500,85 760,300 500,515 240,300' : '500,75 735,280 500,525 265,280'} {...shape} /><Guide x1={250} y1={template === 'kite' ? 280 : 300} x2={750} y2={template === 'kite' ? 280 : 300} /><Guide x1={500} y1={80} x2={500} y2={520} /><Label x={500} y={270} text={`d₁ = ${a}`} /><Label x={600} y={410} text={`d₂ = ${b}`} /></>}
            {(template === 'triangle' || template === 'triangle_isosceles') && <><polygon points="220,465 780,465 500,115" {...shape} /><DimensionLine x1={220} y1={510} x2={780} y2={510} /><Label x={500} y={555} text={`alas = ${a}`} /><Guide x1={500} y1={115} x2={500} y2={465} /><RightAngle x={500} y={435} /><Label x={630} y={300} text={`tinggi = ${b}`} /></>}
            {template === 'triangle_right' && <><polygon points="260,465 780,465 260,115" {...shape} /><RightAngle x={260} y={425} /><DimensionLine x1={260} y1={510} x2={780} y2={510} /><Label x={520} y={555} text={`alas = ${a}`} /><RotatedLabel x={215} y={300} text={`tinggi = ${b}`} /></>}
            {template === 'triangle_equilateral' && <><polygon points="230,465 770,465 500,95" {...shape} /><DimensionLine x1={230} y1={510} x2={770} y2={510} /><Label x={500} y={555} text={`setiap sisi = ${a}`} /></>}
            {template === 'triangle_scalene' && <><polygon points="210,465 790,465 620,105" {...shape} /><DimensionLine x1={210} y1={510} x2={790} y2={510} /><Label x={500} y={555} text={`alas = ${a}`} /><Guide x1={620} y1={105} x2={620} y2={465} /><Label x={720} y={300} text={`tinggi = ${b}`} /></>}
            {template === 'triangle_acute' && <><polygon points="220,465 780,465 560,115" {...shape} /><Label x={500} y={520} text={`alas = ${a}`} /><Label x={660} y={300} text={`tinggi = ${b}`} /></>}
            {template === 'triangle_obtuse' && <><polygon points="220,465 800,465 330,130" {...shape} /><Guide x1={330} y1={130} x2={330} y2={465} /><Label x={510} y={520} text={`alas = ${a}`} /><Label x={430} y={300} text={`tinggi = ${b}`} /></>}
            {template === 'circle' && <><circle cx="500" cy="290" r="190" {...shape} /><DimensionLine x1={500} y1={290} x2={690} y2={290} /><circle cx="500" cy="290" r="7" fill="#0f172a" /><Label x={595} y={270} text={`r = ${a}`} /></>}
            {template === 'circle_diameter' && <><circle cx="500" cy="290" r="190" {...shape} /><DimensionLine x1={310} y1={290} x2={690} y2={290} /><circle cx="500" cy="290" r="7" fill="#0f172a" /><Label x={500} y={270} text={`diameter = ${a}`} /></>}
            {template === 'semicircle' && <><path d="M260 420a240 240 0 0 1 480 0Z" {...shape} /><DimensionLine x1={260} y1={465} x2={740} y2={465} /><Label x={500} y={515} text={`diameter = ${a}`} /></>}
            {template === 'quarter_circle' && <><path d="M300 455V115a340 340 0 0 1 340 340Z" {...shape} /><DimensionLine x1={300} y1={455} x2={640} y2={455} /><Label x={470} y={510} text={`r = ${a}`} /></>}
            {template === 'circle_sector' && <CircleSector radiusLabel={a} angle={dimensionB} />}
            {template === 'annulus' && <Annulus outerLabel={a} innerLabel={b} outerValue={dimensionA} innerValue={dimensionB} />}
            {template === 'pentagon' && <><polygon points="500,80 790,290 680,505 320,505 210,290" {...shape} /><Label x={500} y={555} text={`sisi = ${a}`} /></>}
            {template === 'hexagon' && <><polygon points="320,100 680,100 840,300 680,500 320,500 160,300" {...shape} /><Label x={500} y={555} text={`sisi = ${a}`} /></>}
            {template === 'heptagon' && <RegularPolygon sides={7} sideLength={a} />}
            {template === 'octagon' && <><polygon points="350,80 650,80 820,220 820,380 650,520 350,520 180,380 180,220" {...shape} /><Label x={500} y={565} text={`sisi = ${a}`} /></>}
            {template === 'nonagon' && <RegularPolygon sides={9} sideLength={a} />}
            {template === 'decagon' && <RegularPolygon sides={10} sideLength={a} />}
            {template === 'dodecagon' && <RegularPolygon sides={12} sideLength={a} />}
            {template === 'cube' && <><polygon points="350,180 570,180 690,105 470,105" {...top} /><polygon points="350,180 570,180 570,430 350,430" {...front} /><polygon points="570,180 690,105 690,355 570,430" {...side} /><DimensionLine x1={350} y1={480} x2={570} y2={480} /><Label x={460} y={530} text={`rusuk = ${a}`} /></>}
            {template === 'cuboid' && <><polygon points="260,190 650,190 770,115 380,115" {...top} /><polygon points="260,190 650,190 650,440 260,440" {...front} /><polygon points="650,190 770,115 770,365 650,440" {...side} /><Label x={455} y={490} text={`p = ${a}`} /><Label x={770} y={420} text={`l = ${b}`} /><RotatedLabel x={205} y={315} text={`t = ${c}`} /></>}
            {template === 'triangular_prism' && <Prism sides={3} baseSize={a} baseHeight={b} length={c} />}
            {template === 'pentagonal_prism' && <Prism sides={5} baseSize={a} baseHeight={b} length={c} />}
            {template === 'hexagonal_prism' && <Prism sides={6} baseSize={a} baseHeight={b} length={c} />}
            {template === 'cylinder' && <><ellipse cx="500" cy="140" rx="210" ry="70" {...top} /><path d="M290 140v300c0 39 94 70 210 70s210-31 210-70V140" {...front} /><ellipse cx="500" cy="440" rx="210" ry="70" {...side} /><Label x={500} y={120} text={`r = ${a}`} /><RotatedLabel x={755} y={300} text={`tinggi = ${b}`} /></>}
            {template === 'square_pyramid' && <><polygon points="500,70 250,420 500,520 750,420" {...front} /><polygon points="500,70 750,420 500,520" {...side} /><Guide x1={500} y1={70} x2={500} y2={470} /><Label x={500} y={565} text={`sisi alas = ${a}`} /><Label x={630} y={260} text={`tinggi = ${b}`} /></>}
            {template === 'triangular_pyramid' && <Pyramid sides={3} baseSize={a} height={b} />}
            {template === 'pentagonal_pyramid' && <Pyramid sides={5} baseSize={a} height={b} />}
            {template === 'hexagonal_pyramid' && <Pyramid sides={6} baseSize={a} height={b} />}
            {template === 'cone' && <><path d="M500 70 270 440a230 70 0 0 0 460 0Z" {...front} /><ellipse cx="500" cy="440" rx="230" ry="70" {...side} /><Guide x1={500} y1={70} x2={500} y2={440} /><DimensionLine x1={500} y1={440} x2={730} y2={440} /><Label x={615} y={420} text={`r = ${a}`} /><Label x={630} y={260} text={`tinggi = ${b}`} /></>}
            {template === 'sphere' && <><circle cx="500" cy="290" r="210" {...shape} /><ellipse cx="500" cy="290" rx="210" ry="75" fill="none" stroke="#1e40af" strokeWidth="5" strokeDasharray="12 10" /><DimensionLine x1={500} y1={290} x2={710} y2={290} /><Label x={605} y={270} text={`r = ${a}`} /></>}
            {template === 'hemisphere' && <><path d="M270 300a230 230 0 0 0 460 0Z" {...shape} /><ellipse cx="500" cy="300" rx="230" ry="75" {...top} /><DimensionLine x1={500} y1={300} x2={730} y2={300} /><Label x={615} y={280} text={`r = ${a}`} /></>}
            <ExtendedTemplate template={template} dimensionA={dimensionA} dimensionB={dimensionB} dimensionC={dimensionC} a={a} b={b} c={c} unit={unit} fractionModels={fractionModels} protractorAngles={protractorAngles} onToggleFractionPart={onToggleFractionPart} />
            </g>
            {overlays.map((overlay) => <g key={overlay.id} data-canvas-overlay="true">
                {selectedOverlayId === overlay.id && <rect x={overlay.x - Math.max(30, overlay.content.length * overlay.font_size * 0.3)} y={overlay.y - overlay.font_size} width={Math.max(60, overlay.content.length * overlay.font_size * 0.6)} height={overlay.font_size * 1.35} rx="8" fill="none" stroke="#7c3aed" strokeWidth="3" strokeDasharray="8 6" transform={`rotate(${overlay.rotation} ${overlay.x} ${overlay.y})`} />}
                <text x={overlay.x} y={overlay.y} textAnchor="middle" dominantBaseline="middle" fontSize={overlay.font_size} fontWeight={overlay.type === 'symbol' ? 700 : 600} fill={overlay.color} transform={`rotate(${overlay.rotation} ${overlay.x} ${overlay.y})`} className={onMoveOverlay ? 'cursor-move select-none' : undefined} onPointerDown={(event) => startOverlayDrag(event, overlay)} onPointerMove={moveOverlay} onPointerUp={stopOverlayDrag} onPointerCancel={stopOverlayDrag}>{overlay.content}</text>
            </g>)}
        </svg>
    );
}

function ExtendedTemplate({ template, dimensionA, dimensionB, dimensionC, a, b, c, unit, fractionModels, protractorAngles, onToggleFractionPart }: { template: GeometryTemplate; dimensionA: string; dimensionB: string; dimensionC: string; a: string; b: string; c: string; unit: string; fractionModels: { numerator: string; denominator: string; shaded_parts?: number[] }[]; protractorAngles: ProtractorAngle[]; onToggleFractionPart?: (modelIndex: number, partIndex: number) => void }) {
    const rawA = dimensionA || '?';
    const rawB = dimensionB || '?';
    const numericA = Number(dimensionA);
    const numericB = Number(dimensionB);
    const squareCircleRadius = numericA > 0 && numericB > 0 ? Math.min(230, 460 * numericB / numericA) : 230;
    const squareQuarterRadius = numericA > 0 && numericB > 0 ? Math.min(460, 460 * numericB / numericA) : 230;
    const sectorAngle = numericB > 0 ? Math.min(360, numericB) : 90;
    const sectorRadians = (sectorAngle * Math.PI) / 180;
    const sectorEndX = 500 + (220 * Math.sin(sectorRadians));
    const sectorEndY = 290 - (220 * Math.cos(sectorRadians));
    const referenceAngle = numericA > 0 ? Math.min(180, numericA) : 60;
    const referenceRadians = (referenceAngle * Math.PI) / 180;
    const referenceDx = 220 * Math.cos(referenceRadians);
    const referenceDy = 220 * Math.sin(referenceRadians);
    const chordHalf = numericA > 0 && numericB > 0 ? Math.min(210, 210 * numericB / (2 * numericA)) : 165;
    const chordY = 285 - Math.sqrt(Math.max(0, (210 ** 2) - (chordHalf ** 2)));

    if (template === 'parallel_lines') return <><DimensionLine x1={180} y1={190} x2={820} y2={190} /><DimensionLine x1={180} y1={410} x2={820} y2={410} /><Guide x1={500} y1={205} x2={500} y2={395} /><Label x={650} y={310} text={`jarak = ${a}`} /></>;
    if (template === 'perpendicular_lines') return <><DimensionLine x1={170} y1={340} x2={830} y2={340} /><DimensionLine x1={500} y1={80} x2={500} y2={520} /><RightAngle x={500} y={300} /><Label x={570} y={295} text="90°" /><Label x={500} y={570} text={`panjang acuan = ${a}`} /></>;
    if (template === 'intersecting_lines') return <><line x1={170} y1={300} x2={830} y2={300} stroke="#334155" strokeWidth="4" /><line x1={500 - referenceDx} y1={300 + referenceDy} x2={500 + referenceDx} y2={300 - referenceDy} stroke="#334155" strokeWidth="4" /><path d={`M590 300A90 90 0 0 0 ${500 + (90 * Math.cos(referenceRadians))} ${300 - (90 * Math.sin(referenceRadians))}`} fill="none" stroke="#f59e0b" strokeWidth="4" /><Label x={600} y={240} text={`${rawA}°`} /></>;
    if (template.startsWith('angle_')) return <AngleTemplate template={template} degrees={rawA} />;
    if (template === 'circle_chord') return <><circle cx="500" cy="285" r="210" {...shape} /><line x1={500 - chordHalf} y1={chordY} x2={500 + chordHalf} y2={chordY} stroke="#334155" strokeWidth="5" /><Label x={500} y={chordY - 20} text={`tali busur = ${b}`} /><Guide x1={500} y1={285} x2={710} y2={285} /><Label x={610} y={270} text={`r = ${a}`} /></>;
    if (template === 'circle_segment') return <><circle cx="500" cy="285" r="210" {...shape} /><path d="M320 390Q500 530 680 390Z" fill="#fbbf24" fillOpacity="0.65" stroke="#1e40af" strokeWidth="5" /><Label x={500} y={450} text="tembereng" /><Label x={620} y={270} text={`r = ${a}`} /></>;
    if (template === 'circle_tangent') return <><circle cx="440" cy="300" r="180" {...shape} /><DimensionLine x1={620} y1={90} x2={620} y2={510} /><Guide x1={440} y1={300} x2={620} y2={300} /><RightAngle x={580} y={300} /><Label x={515} y={280} text={`r = ${a}`} /><RotatedLabel x={670} y={300} text={`garis singgung = ${b}`} /></>;
    if (template === 'composite_l_shape') return <><path d="M220 100H500V300H780V500H220Z" {...shape} /><Label x={360} y={550} text={`panjang = ${a}`} /><RotatedLabel x={825} y={390} text={`tinggi = ${b}`} /><Label x={650} y={280} text={`lekukan = ${c}`} /></>;
    if (template === 'composite_rectangle_semicircle') return <><rect x="300" y="250" width="400" height="250" {...shape} /><path d="M300 250a200 200 0 0 1 400 0Z" {...shape} /><Label x={500} y={545} text={`panjang = ${a}`} /><RotatedLabel x={750} y={375} text={`tinggi = ${b}`} /></>;
    if (template === 'shaded_square_circle') return <><rect x="270" y="70" width="460" height="460" fill="#fbbf24" stroke="#1e40af" strokeWidth="6" /><circle cx="500" cy="300" r={squareCircleRadius} fill="#fff" stroke="#1e40af" strokeWidth="6" /><Label x={500} y={570} text={`sisi = ${a}; r = ${b}`} /></>;
    if (template === 'composite_square_semicircle') return <><rect x="340" y="220" width="320" height="320" {...shape} /><path d="M340 220a160 160 0 0 1 320 0Z" {...top} /><Label x={500} y={580} text={`sisi/diameter = ${a}`} /></>;
    if (template === 'composite_square_quarter_circle') return <><rect x="270" y="70" width="460" height="460" fill="#fbbf24" stroke="#1e40af" strokeWidth="6" /><path d={`M270 530V${530 - squareQuarterRadius}A${squareQuarterRadius} ${squareQuarterRadius} 0 0 1 ${270 + squareQuarterRadius} 530Z`} fill="#fff" stroke="#1e40af" strokeWidth="6" /><Label x={500} y={570} text={`sisi = ${a}; r = ${b}`} /></>;
    if (template === 'composite_square_four_quarters') return <><rect x="270" y="70" width="460" height="460" fill="#fbbf24" stroke="#1e40af" strokeWidth="6" /><path d="M270 300V70H500A230 230 0 0 0 270 300ZM500 70H730V300A230 230 0 0 0 500 70ZM730 300V530H500A230 230 0 0 0 730 300ZM500 530H270V300A230 230 0 0 0 500 530Z" fill="#fff" stroke="#1e40af" strokeWidth="5" /><Label x={500} y={570} text={`sisi = ${a}`} /></>;
    if (template === 'shaded_square_diagonal') return <><rect x="270" y="70" width="460" height="460" {...shape} /><polygon points="270,70 730,530 270,530" fill="#fbbf24" stroke="#1e40af" strokeWidth="5" /><Label x={500} y={570} text={`sisi = ${a}`} /></>;
    if (template === 'composite_stadium') return <><path d="M300 170H700A130 130 0 0 1 700 430H300A130 130 0 0 1 300 170Z" {...shape} /><Label x={500} y={500} text={`panjang = ${a}; diameter = ${b}`} /></>;
    if (template === 'composite_rectangle_two_quarters') return <><rect x="240" y="100" width="520" height="400" fill="#fbbf24" stroke="#1e40af" strokeWidth="6" /><path d="M240 300V100h200A200 200 0 0 1 240 300M760 300V500H560A200 200 0 0 1 760 300" fill="#fff" stroke="#1e40af" strokeWidth="5" /><Label x={500} y={550} text={`p = ${a}; l = ${b}`} /></>;
    if (template === 'shaded_rectangle_circle') return <><rect x="220" y="100" width="560" height="400" fill="#fbbf24" stroke="#1e40af" strokeWidth="6" /><circle cx="500" cy="300" r="150" fill="#fff" stroke="#1e40af" strokeWidth="6" /><Label x={500} y={550} text={`p = ${a}; l = ${b}; r = ${c}`} /></>;
    if (template === 'composite_triangle_semicircle') return <><polygon points="270,310 730,310 500,60" {...shape} /><path d="M270 310a230 230 0 0 0 460 0Z" {...top} /><Label x={500} y={580} text={`alas/diameter = ${a}; tinggi = ${b}`} /></>;
    if (template === 'composite_triangle_rectangle') return <><rect x="260" y="300" width="480" height="220" {...shape} /><polygon points="260,300 740,300 500,70" {...top} /><Label x={500} y={570} text={`lebar = ${a}; t▭ = ${b}; t△ = ${c}`} /></>;
    if (template === 'shaded_triangle_midsegment') return <><polygon points="230,500 770,500 500,70" {...shape} /><polygon points="365,285 635,285 500,70" fill="#fbbf24" stroke="#1e40af" strokeWidth="5" /><Label x={500} y={550} text={`alas = ${a}; tinggi = ${b}`} /></>;
    if (template === 'shaded_circle_square') return <><circle cx="500" cy="290" r="230" fill="#fbbf24" stroke="#1e40af" strokeWidth="6" /><rect x="337" y="127" width="326" height="326" fill="#fff" stroke="#1e40af" strokeWidth="6" /><Label x={500} y={570} text={`r = ${a}`} /></>;
    if (template === 'shaded_circle_sector') return <><circle cx="500" cy="290" r="220" {...shape} />{sectorAngle >= 360 ? <circle cx="500" cy="290" r="220" fill="#fbbf24" stroke="#1e40af" strokeWidth="5" /> : <path d={`M500 290L500 70A220 220 0 ${sectorAngle > 180 ? 1 : 0} 1 ${sectorEndX} ${sectorEndY}Z`} fill="#fbbf24" stroke="#1e40af" strokeWidth="5" />}<Label x={590} y={220} text={`${rawB}°`} /><Label x={500} y={560} text={`r = ${a}`} /></>;
    if (template === 'shaded_annulus') return <><path d="M500 70a220 220 0 1 1 0 440 220 220 0 1 1 0-440m0 90a130 130 0 1 0 0 260 130 130 0 1 0 0-260" fill="#fbbf24" fillRule="evenodd" stroke="#1e40af" strokeWidth="6" /><Label x={500} y={570} text={`R = ${a}; r = ${b}`} /></>;
    if (template === 'fraction_circle') return <><Label x={500} y={70} text="Bagian yang diarsir" /><FractionCircle cx={500} cy={320} radius={205} numerator={numericA} denominator={numericB} /></>;
    if (template === 'fraction_bar') return <><Label x={500} y={120} text="Bagian yang diarsir" /><FractionBar x={170} y={220} width={660} height={210} numerator={numericA} denominator={numericB} /></>;
    if (template === 'fraction_equivalent_circles') {
        const models = (fractionModels.length >= 2 ? fractionModels : [{ numerator: '1', denominator: '2' }, { numerator: '2', denominator: '4' }]).slice(0, 4);
        const radius = models.length === 2 ? 150 : models.length === 3 ? 120 : 95;
        const centers = models.length === 2 ? [330, 670] : models.length === 3 ? [230, 500, 770] : [150, 383, 617, 850];
        return <><Label x={500} y={65} text="Perhatikan bagian yang diarsir" />{models.map((model, index) => <g key={index}><FractionCircle cx={centers[index]} cy={285} radius={radius} numerator={Number(model.numerator)} denominator={Number(model.denominator)} shadedParts={model.shaded_parts} onTogglePart={onToggleFractionPart ? (partIndex) => onToggleFractionPart(index, partIndex) : undefined} /><Label x={centers[index]} y={285 + radius + 50} text={`Model ${index + 1}`} /></g>)}</>;
    }
    if (template === 'fraction_equivalent_bars') {
        const factor = Math.max(2, Math.min(4, Math.round(Number(dimensionC) || 4)));
        return <><Label x={500} y={65} text="Perhatikan bagian yang diarsir" /><FractionBar x={180} y={120} width={640} height={100} numerator={numericA} denominator={numericB} /><FractionBar x={180} y={255} width={640} height={100} numerator={numericA * 2} denominator={numericB * 2} /><FractionBar x={180} y={390} width={640} height={100} numerator={numericA * factor} denominator={numericB * factor} /></>;
    }
    if (template === 'cube_net') return <BoxNet cube a={a} b={b} c={c} />;
    if (template === 'cuboid_net') return <BoxNet a={a} b={b} c={c} />;
    if (template === 'triangular_prism_net') return <><polygon points="180,300 310,100 440,300" {...top} /><rect x="180" y="300" width="260" height="180" {...shape} /><rect x="440" y="300" width="220" height="180" {...shape} /><rect x="660" y="300" width="160" height="180" {...shape} /><polygon points="440,300 570,100 700,300" {...top} /><Label x={500} y={540} text={`alas = ${a}; t△ = ${b}; panjang = ${c}`} /></>;
    if (template === 'cylinder_net') return <><rect x="250" y="170" width="500" height="260" {...shape} /><circle cx="500" cy="80" r="90" {...top} /><circle cx="500" cy="520" r="90" {...top} /><Label x={610} y={470} text={`tinggi = ${b}`} /><Label x={500} y={85} text={`r = ${a}`} /></>;
    if (template === 'cone_net') return <><path d="M480 320L250 470A275 275 0 0 1 710 470Z" {...shape} /><circle cx="760" cy="395" r="90" {...top} /><Label x={760} y={400} text={`r = ${a}`} /><Label x={600} y={420} text={`s = ${b}`} /></>;
    if (['cartesian_point', 'cartesian_line', 'translation', 'reflection', 'rotation', 'dilation'].includes(template)) return <CoordinateTemplate template={template} a={rawA} b={rawB} />;
    if (template === 'ruler') return <Ruler length={rawA} unit={unit} />;
    if (template === 'clock') return <Clock hour={Number(dimensionA)} minute={Number(dimensionB)} />;
    if (template.startsWith('protractor')) return <Protractor span={template === 'protractor_90' ? 90 : template === 'protractor_270' ? 270 : template === 'protractor_360' ? 360 : 180} degrees={Number(dimensionA)} angles={protractorAngles} />;
    if (template === 'number_line') return <NumberLine minimum={Number(dimensionA)} maximum={Number(dimensionB)} />;
    if (template === 'scale_bar') return <ScaleBar length={rawA} intervals={Number(dimensionB)} unit={unit} />;
    return null;
}

function AngleTemplate({ template, degrees }: { template: GeometryTemplate; degrees: string }) {
    const angle = Number.isFinite(Number(degrees)) ? Number(degrees) : template === 'angle_right' ? 90 : template === 'angle_straight' ? 180 : 45;
    const radians = (angle * Math.PI) / 180;
    const endX = 500 + (220 * Math.cos(radians));
    const endY = 360 - (220 * Math.sin(radians));
    const arcX = 500 + (110 * Math.cos(radians));
    const arcY = 360 - (110 * Math.sin(radians));
    return <><DimensionLine x1={500} y1={360} x2={780} y2={360} /><DimensionLine x1={500} y1={360} x2={endX} y2={endY} />{template === 'angle_right' ? <RightAngle x={500} y={320} /> : <path d={`M610 360A110 110 0 ${angle > 180 ? 1 : 0} 0 ${arcX} ${arcY}`} fill="none" stroke="#f59e0b" strokeWidth="4" />}<circle cx="500" cy="360" r="7" fill="#0f172a" /><Label x={650} y={300} text={`${degrees}°`} /></>;
}

function Protractor({ span, degrees, angles }: { span: 90 | 180 | 270 | 360; degrees: number; angles: ProtractorAngle[] }) {
    const angle = Number.isFinite(degrees) ? Math.max(0, Math.min(span, degrees)) : Math.min(45, span);
    const radians = (angle * Math.PI) / 180;
    const rayX = 500 + (210 * Math.cos(radians));
    const rayY = 300 - (210 * Math.sin(radians));
    const arcX = 500 + (85 * Math.cos(radians));
    const arcY = 300 - (85 * Math.sin(radians));
    const body = span === 360
        ? <circle cx={500} cy={300} r={230} fill="#dbeafe" stroke="#1e40af" strokeWidth="7" />
        : span === 270
            ? <><circle cx={500} cy={300} r={230} fill="#dbeafe" stroke="#1e40af" strokeWidth="7" /><path d="M500 300L730 300A230 230 0 0 1 500 530Z" fill="#fff" stroke="#1e40af" strokeWidth="7" /></>
        : <path d={span === 90
            ? 'M500 300L730 300A230 230 0 0 0 500 70Z'
            : 'M270 300A230 230 0 0 1 730 300Z'} fill="#dbeafe" stroke="#1e40af" strokeWidth="7" />;

    if (angles.length === 0) return <>{body}<line x1={500} y1={300} x2={710} y2={300} stroke="#334155" strokeWidth="4" /><line x1={500} y1={300} x2={rayX} y2={rayY} stroke="#334155" strokeWidth="4" />{angle >= 360 ? <circle cx={500} cy={300} r={85} fill="none" stroke="#f59e0b" strokeWidth="4" /> : <path d={`M585 300A85 85 0 ${angle > 180 ? 1 : 0} 0 ${arcX} ${arcY}`} fill="none" stroke="#f59e0b" strokeWidth="4" />}<circle cx={500} cy={300} r={7} fill="#0f172a" /><Label x={650} y={255} text={`${angle}°`} /></>;

    let start = 0;
    const colors = ['#f59e0b', '#7c3aed', '#0891b2', '#dc2626', '#16a34a', '#db2777', '#4f46e5', '#ea580c'];
    return <>{body}<line x1={500} y1={300} x2={710} y2={300} stroke="#334155" strokeWidth="4" />{angles.map((item, index) => {
        const size = Math.max(0, Number(item.degrees) || 0);
        const end = Math.min(span, start + size);
        const startRadians = start * Math.PI / 180;
        const endRadians = end * Math.PI / 180;
        const middleRadians = ((start + end) / 2) * Math.PI / 180;
        const radius = 82 + ((index % 3) * 18);
        const startX = 500 + radius * Math.cos(startRadians);
        const startY = 300 - radius * Math.sin(startRadians);
        const endX = 500 + radius * Math.cos(endRadians);
        const endY = 300 - radius * Math.sin(endRadians);
        const labelX = 500 + 150 * Math.cos(middleRadians);
        const labelY = 300 - 150 * Math.sin(middleRadians);
        const rayX = 500 + 210 * Math.cos(endRadians);
        const rayY = 300 - 210 * Math.sin(endRadians);
        const segmentStart = start;
        start = end;
        return <g key={item.id}><line x1={500} y1={300} x2={rayX} y2={rayY} stroke="#334155" strokeWidth="4" />{size >= 360 ? <circle cx={500} cy={300} r={radius} fill="none" stroke={colors[index % colors.length]} strokeWidth="6" /> : <path d={`M${startX} ${startY}A${radius} ${radius} 0 ${end - segmentStart > 180 ? 1 : 0} 0 ${endX} ${endY}`} fill="none" stroke={colors[index % colors.length]} strokeWidth="6" />}<Label x={labelX} y={labelY} text={`${item.label || `s${index + 1}`} = ${item.asked ? '?' : `${size}°`}`} /></g>;
    })}<circle cx={500} cy={300} r={7} fill="#0f172a" /></>;
}

function CircleSector({ radiusLabel, angle }: { radiusLabel: string; angle: string }) {
    const degrees = Number(angle) > 0 ? Math.min(360, Number(angle)) : 90;
    const radians = (degrees * Math.PI) / 180;
    const endX = 500 + (220 * Math.sin(radians));
    const endY = 300 - (220 * Math.cos(radians));
    return <>{degrees >= 360 ? <circle cx="500" cy="300" r="220" {...shape} /> : <path d={`M500 300L500 80A220 220 0 ${degrees > 180 ? 1 : 0} 1 ${endX} ${endY}Z`} {...shape} />}<Label x={585} y={220} text={`${angle || '?'}°`} /><Label x={650} y={350} text={`r = ${radiusLabel}`} /></>;
}

function Annulus({ outerLabel, innerLabel, outerValue, innerValue }: { outerLabel: string; innerLabel: string; outerValue: string; innerValue: string }) {
    const outer = Number(outerValue);
    const inner = Number(innerValue);
    const innerRadius = outer > 0 && inner > 0 ? Math.min(215, 220 * inner / outer) : 130;
    return <><circle cx="500" cy="290" r="220" fill="#dbeafe" stroke="#1e40af" strokeWidth="6" /><circle cx="500" cy="290" r={innerRadius} fill="#fff" stroke="#1e40af" strokeWidth="6" /><DimensionLine x1={500} y1={290} x2={720} y2={290} /><DimensionLine x1={500} y1={290} x2={500 + innerRadius} y2={290} /><Label x={690} y={270} text={`R = ${outerLabel}`} /><Label x={570} y={340} text={`r = ${innerLabel}`} /></>;
}

function FractionCircle({ cx, cy, radius, numerator, denominator, shadedParts, onTogglePart }: { cx: number; cy: number; radius: number; numerator: number; denominator: number; shadedParts?: number[]; onTogglePart?: (partIndex: number) => void }) {
    const parts = Math.max(1, Math.min(24, Math.round(denominator) || 1));
    const shaded = Math.max(0, Math.min(parts, Math.round(numerator) || 0));
    const selected = new Set(shadedParts || Array.from({ length: shaded }, (_, index) => index));
    return <g>{Array.from({ length: parts }, (_, index) => {
        const start = (-Math.PI / 2) + ((index * 2 * Math.PI) / parts);
        const end = (-Math.PI / 2) + (((index + 1) * 2 * Math.PI) / parts);
        const path = parts === 1 ? undefined : `M${cx} ${cy}L${cx + (radius * Math.cos(start))} ${cy + (radius * Math.sin(start))}A${radius} ${radius} 0 ${parts === 1 ? 1 : 0} 1 ${cx + (radius * Math.cos(end))} ${cy + (radius * Math.sin(end))}Z`;
        const interaction = onTogglePart ? { onClick: (event: MouseEvent<SVGElement>) => { event.preventDefault(); event.stopPropagation(); onTogglePart(index); }, className: 'cursor-pointer', 'data-fraction-part': 'true' } : {};
        return parts === 1 ? <circle key={index} cx={cx} cy={cy} r={radius} fill={selected.has(index) ? '#38bdf8' : '#fff'} stroke="#0f172a" strokeWidth="4" {...interaction} /> : <path key={index} d={path} fill={selected.has(index) ? '#38bdf8' : '#fff'} stroke="#0f172a" strokeWidth="4" {...interaction} />;
    })}</g>;
}

function FractionBar({ x, y, width, height, numerator, denominator }: { x: number; y: number; width: number; height: number; numerator: number; denominator: number }) {
    const parts = Math.max(1, Math.min(24, Math.round(denominator) || 1));
    const shaded = Math.max(0, Math.min(parts, Math.round(numerator) || 0));
    return <g>{Array.from({ length: parts }, (_, index) => <rect key={index} x={x + ((width / parts) * index)} y={y} width={width / parts} height={height} fill={index < shaded ? '#38bdf8' : '#fff'} stroke="#0f172a" strokeWidth="4" />)}</g>;
}

function BoxNet({ cube = false, a, b, c }: { cube?: boolean; a: string; b: string; c: string }) {
    return cube ? <><g {...shape}><rect x="360" y="60" width="140" height="140" /><rect x="220" y="200" width="140" height="140" /><rect x="360" y="200" width="140" height="140" /><rect x="500" y="200" width="140" height="140" /><rect x="640" y="200" width="140" height="140" /><rect x="360" y="340" width="140" height="140" /></g><Label x={500} y={535} text={`rusuk = ${a}`} /></>
        : <><g {...shape}><rect x="320" y="80" width="180" height="120" /><rect x="200" y="200" width="120" height="180" /><rect x="320" y="200" width="180" height="180" /><rect x="500" y="200" width="120" height="180" /><rect x="620" y="200" width="180" height="180" /><rect x="320" y="380" width="180" height="120" /></g><Label x={500} y={555} text={`p = ${a}; l = ${b}; t = ${c}`} /></>;
}

function CoordinateTemplate({ template, a, b }: { template: GeometryTemplate; a: string; b: string }) {
    const numericA = Number(a);
    const numericB = Number(b);
    const x = Math.max(130, Math.min(870, 500 + (numericA * 55)));
    const y = Math.max(100, Math.min(500, 300 - (numericB * 45)));
    const lineStartY = Math.max(100, Math.min(500, 300 - (((-numericA * 5) + numericB) * 45)));
    const lineEndY = Math.max(100, Math.min(500, 300 - (((numericA * 5) + numericB) * 45)));
    const translateX = Math.max(-250, Math.min(250, numericA * 55));
    const translateY = Math.max(-160, Math.min(160, -numericB * 45));
    const reflectionX = Math.max(260, Math.min(740, 500 + (numericA * 55)));
    const dilationScale = Math.max(0.25, Math.min(2, numericA));
    const caption = template === 'cartesian_point' ? `Titik P(${a}, ${b})` : template === 'cartesian_line' ? `y = ${a}x + ${b}` : template === 'translation' ? `Translasi (${a}, ${b})` : template === 'reflection' ? `Refleksi pada x = ${a}` : template === 'rotation' ? `Rotasi ${a}°` : `Dilatasi k = ${a}`;
    return <>
        <g stroke="#cbd5e1" strokeWidth="2">{Array.from({ length: 11 }, (_, index) => <line key={`v${index}`} x1={100 + (index * 80)} y1={80} x2={100 + (index * 80)} y2={520} />)}{Array.from({ length: 6 }, (_, index) => <line key={`h${index}`} x1={100} y1={100 + (index * 80)} x2={900} y2={100 + (index * 80)} />)}</g>
        <DimensionLine x1={100} y1={300} x2={900} y2={300} /><DimensionLine x1={500} y1={520} x2={500} y2={80} /><Label x={880} y={335} text="x" /><Label x={530} y={105} text="y" />
        {template === 'cartesian_point' && <><circle cx={Number.isFinite(x) ? x : 650} cy={Number.isFinite(y) ? y : 210} r="13" fill="#ef4444" /><Guide x1={Number.isFinite(x) ? x : 650} y1={Number.isFinite(y) ? y : 210} x2={Number.isFinite(x) ? x : 650} y2={300} /><Guide x1={Number.isFinite(x) ? x : 650} y1={Number.isFinite(y) ? y : 210} x2={500} y2={Number.isFinite(y) ? y : 210} /></>}
        {template === 'cartesian_line' && <line x1="225" y1={lineStartY} x2="775" y2={lineEndY} stroke="#2563eb" strokeWidth="8" />}
        {template === 'translation' && <><polygon points="380,400 500,400 440,230" {...front} /><polygon points="380,400 500,400 440,230" {...top} transform={`translate(${translateX} ${translateY})`} /><DimensionLine x1={440} y1={315} x2={440 + translateX} y2={315 + translateY} /></>}
        {template === 'reflection' && <><Guide x1={reflectionX} y1={90} x2={reflectionX} y2={510} /><polygon points={`${reflectionX - 220},400 ${reflectionX - 100},400 ${reflectionX - 160},220`} {...front} /><polygon points={`${reflectionX + 220},400 ${reflectionX + 100},400 ${reflectionX + 160},220`} {...top} /></>}
        {template === 'rotation' && <><polygon points="540,390 670,390 590,210" {...front} /><polygon points="540,390 670,390 590,210" {...top} opacity="0.7" transform={`rotate(${-numericA} 500 300)`} /><path d="M650 390A170 170 0 0 0 430 260" fill="none" stroke="#f59e0b" strokeWidth="4" strokeDasharray="12 10" /></>}
        {template === 'dilation' && <><polygon points="520,340 600,340 540,230" {...front} /><polygon points="520,340 600,340 540,230" {...top} opacity="0.55" transform={`translate(500 300) scale(${dilationScale}) translate(-500 -300)`} /></>}
        <Label x={500} y={570} text={caption} />
    </>;
}

function Ruler({ length, unit }: { length: string; unit: string }) {
    return <><rect x="100" y="210" width="800" height="180" rx="12" fill="#fef3c7" stroke="#92400e" strokeWidth="6" />{Array.from({ length: 21 }, (_, index) => { const x = 120 + (index * 38); const height = index % 5 === 0 ? 85 : index % 2 === 0 ? 55 : 35; return <line key={index} x1={x} y1={210} x2={x} y2={210 + height} stroke="#92400e" strokeWidth="4" />; })}<Label x={500} y={445} text={`panjang ukur = ${length} ${unit || 'cm'}`} /></>;
}

function Clock({ hour, minute }: { hour: number; minute: number }) {
    const safeHour = Number.isFinite(hour) ? hour : 10;
    const safeMinute = Number.isFinite(minute) ? minute : 10;
    const hourAngle = ((((safeHour % 12) * 30) + (safeMinute * 0.5) - 90) * Math.PI) / 180;
    const minuteAngle = (((safeMinute * 6) - 90) * Math.PI) / 180;
    return <><circle cx="500" cy="290" r="235" fill="#fff" stroke="#1e40af" strokeWidth="8" />{Array.from({ length: 12 }, (_, index) => { const number = index + 1; const angle = (((number * 30) - 90) * Math.PI) / 180; return <Label key={number} x={500 + (195 * Math.cos(angle))} y={300 + (195 * Math.sin(angle))} text={String(number)} />; })}<line x1="500" y1="290" x2={500 + (125 * Math.cos(hourAngle))} y2={290 + (125 * Math.sin(hourAngle))} stroke="#0f172a" strokeWidth="12" /><line x1="500" y1="290" x2={500 + (190 * Math.cos(minuteAngle))} y2={290 + (190 * Math.sin(minuteAngle))} stroke="#ef4444" strokeWidth="7" /><circle cx="500" cy="290" r="12" fill="#0f172a" /><Label x={500} y={570} text={`${String(safeHour).padStart(2, '0')}:${String(safeMinute).padStart(2, '0')}`} /></>;
}

function NumberLine({ minimum, maximum }: { minimum: number; maximum: number }) {
    const min = Number.isFinite(minimum) ? minimum : -5;
    const max = Number.isFinite(maximum) ? maximum : 5;
    return <><DimensionLine x1={120} y1={300} x2={880} y2={300} />{Array.from({ length: 11 }, (_, index) => { const x = 140 + (index * 72); const value = min + (((max - min) * index) / 10); return <g key={index}><line x1={x} y1={275} x2={x} y2={325} stroke="#0f172a" strokeWidth="4" /><Label x={x} y={365} text={Number(value.toFixed(2)).toString()} /></g>; })}</>;
}

function ScaleBar({ length, intervals, unit }: { length: string; intervals: number; unit: string }) {
    const count = Number.isFinite(intervals) ? Math.max(1, Math.min(12, Math.round(intervals))) : 5;
    return <>{Array.from({ length: count }, (_, index) => <rect key={index} x={150 + ((700 / count) * index)} y="260" width={700 / count} height="90" fill={index % 2 === 0 ? '#0f172a' : '#fff'} stroke="#0f172a" strokeWidth="3" />)}<Label x={500} y={410} text={`${count} bagian = ${length} ${unit || 'cm'}`} /></>;
}

const polygonPoints = (sides: number, centerX: number, centerY: number, radiusX: number, radiusY: number) => Array.from({ length: sides }, (_, index) => {
    const angle = (-Math.PI / 2) + ((index * 2 * Math.PI) / sides);
    return [centerX + (radiusX * Math.cos(angle)), centerY + (radiusY * Math.sin(angle))] as const;
});

const pointsAttribute = (points: readonly (readonly [number, number])[]) => points.map(([x, y]) => `${x.toFixed(2)},${y.toFixed(2)}`).join(' ');

function RegularPolygon({ sides, sideLength }: { sides: number; sideLength: string }) {
    return <><polygon points={pointsAttribute(polygonPoints(sides, 500, 285, 215, 215))} {...shape} /><Label x={500} y={555} text={`sisi = ${sideLength}`} /></>;
}

function Prism({ sides, baseSize, baseHeight, length }: { sides: number; baseSize: string; baseHeight: string; length: string }) {
    const frontPoints = polygonPoints(sides, 360, 315, 165, 150);
    const backPoints = frontPoints.map(([x, y]) => [x + 260, y - 75] as const);

    return <>
        <polygon points={pointsAttribute(backPoints)} {...top} />
        {frontPoints.map((point, index) => {
            const next = (index + 1) % sides;
            return <polygon key={index} points={pointsAttribute([point, frontPoints[next], backPoints[next], backPoints[index]])} {...side} opacity={0.55 + ((index % 3) * 0.15)} />;
        })}
        <polygon points={pointsAttribute(frontPoints)} {...front} />
        <Label x={360} y={535} text={`ukuran alas = ${baseSize}`} />
        <Label x={205} y={305} text={`tinggi alas = ${baseHeight}`} />
        <DimensionLine x1={frontPoints[0][0]} y1={frontPoints[0][1]} x2={backPoints[0][0]} y2={backPoints[0][1]} />
        <Label x={(frontPoints[0][0] + backPoints[0][0]) / 2} y={((frontPoints[0][1] + backPoints[0][1]) / 2) - 25} text={`panjang = ${length}`} />
    </>;
}

function Pyramid({ sides, baseSize, height }: { sides: number; baseSize: string; height: string }) {
    const basePoints = polygonPoints(sides, 500, 420, 270, 95);
    const apex = [500, 75] as const;

    return <>
        {basePoints.map((point, index) => {
            const next = (index + 1) % sides;
            return <polygon key={index} points={pointsAttribute([apex, point, basePoints[next]])} {...(index % 2 === 0 ? front : side)} opacity={0.65 + ((index % 2) * 0.2)} />;
        })}
        <polygon points={pointsAttribute(basePoints)} {...top} opacity="0.65" />
        <Guide x1={500} y1={75} x2={500} y2={420} />
        <Label x={625} y={250} text={`tinggi = ${height}`} />
        <Label x={500} y={555} text={`sisi alas = ${baseSize}`} />
    </>;
}

function DimensionLine({ x1, y1, x2, y2 }: { x1: number; y1: number; x2: number; y2: number }) {
    return <line x1={x1} y1={y1} x2={x2} y2={y2} stroke="#334155" strokeWidth="4" markerStart="url(#geometry-arrow-start)" markerEnd="url(#geometry-arrow-end)" />;
}

function Guide({ x1, y1, x2, y2 }: { x1: number; y1: number; x2: number; y2: number }) {
    return <line x1={x1} y1={y1} x2={x2} y2={y2} stroke="#f59e0b" strokeWidth="4" strokeDasharray="12 10" />;
}

function RightAngle({ x, y }: { x: number; y: number }) {
    return <path d={`M${x} ${y}h40v40`} fill="none" stroke="#f59e0b" strokeWidth="4" />;
}

function Label({ x, y, text }: { x: number; y: number; text: string }) {
    return <text x={x} y={y} textAnchor="middle" fontSize="26" fontWeight="700" fill="#0f172a">{text}</text>;
}

function RotatedLabel({ x, y, text }: { x: number; y: number; text: string }) {
    return <text x={x} y={y} textAnchor="middle" transform={`rotate(-90 ${x} ${y})`} fontSize="26" fontWeight="700" fill="#0f172a">{text}</text>;
}
