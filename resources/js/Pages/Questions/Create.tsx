import InputError from '@/Components/InputError';
import GeometryTemplatePreview, { GeometryTemplate } from '@/Components/GeometryTemplatePreview';
import GeometryCalculationInfo from '@/Components/GeometryCalculationInfo';
import Modal from '@/Components/Modal';
import PositionedImage from '@/Components/PositionedImage';
import StimulusVisual, { StimulusVisualData } from '@/Components/StimulusVisual';
import FormattedText from '@/Components/FormattedText';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent, PointerEvent as ReactPointerEvent, useEffect, useMemo, useRef, useState } from 'react';

type Competency = {
    id: number;
    subject_id: number;
    parent_id?: number;
    code: string;
    domain: string;
    name: string;
    grade_level: number;
};

type MatchingPair = { left_id?: string; left: string; right_id?: string; right: string };
type MatchingDistractor = { id?: string; content: string };
type MatrixColumn = { id?: string; label: string };
type MatrixRow = { id?: string; statement: string; correct_column_index: number };
type StimulusVisualType = 'none' | 'table' | 'bar_chart' | 'pictogram' | 'pie_chart';
type StimulusChartItem = { label: string; value: string };
type PieTotalMode = 'percentage' | 'degrees' | 'custom';
type StimulusPieItem = StimulusChartItem & { asked: boolean; auto_calculate: boolean };
type StimulusGroupedChartCategory = { label: string; values: string[] };
type FractionModel = { numerator: string; denominator: string; shaded_parts?: number[] };
type CanvasOverlay = { id: string; type: 'text' | 'symbol'; content: string; x: number; y: number; font_size: number; color: string; rotation: number };
type ProtractorAngle = { id: string; label: string; degrees: string; asked: boolean };
type StimulusSvgTemplateOption = {
    value: GeometryTemplate;
    category: string;
    category_label: string;
    family: string;
    family_label: string;
    subfamily?: string;
    subfamily_label?: string;
    label: string;
    dimension_a_label: string;
    dimension_b_label?: string;
    dimension_c_label?: string;
    uses_unit?: boolean;
    allow_signed_dimensions?: boolean;
    integer_dimensions?: boolean;
    custom_fraction_models?: boolean;
};

const svgTemplateDefaults: Partial<Record<GeometryTemplate, [string, string?, string?]>> = {
    parallel_lines: ['5'], perpendicular_lines: ['8'], intersecting_lines: ['60'],
    angle_acute: ['45'], angle_right: ['90'], angle_obtuse: ['120'], angle_straight: ['180'], angle_reflex: ['270'],
    circle_sector: ['7', '60'], annulus: ['10', '6'],
    composite_square_semicircle: ['14'], composite_square_quarter_circle: ['14', '7'], composite_square_four_quarters: ['14'], shaded_square_diagonal: ['14'], shaded_square_circle: ['14', '7'],
    composite_rectangle_semicircle: ['14', '8'], composite_stadium: ['14', '7'], composite_rectangle_two_quarters: ['16', '8'], composite_l_shape: ['16', '12', '4'], shaded_rectangle_circle: ['16', '12', '5'],
    composite_triangle_semicircle: ['14', '10'], composite_triangle_rectangle: ['14', '8', '10'], shaded_triangle_midsegment: ['14', '10'],
    shaded_circle_square: ['7'], shaded_circle_sector: ['7', '90'], shaded_annulus: ['10', '6'],
    fraction_circle: ['1', '2'], fraction_bar: ['3', '4'], fraction_equivalent_circles: ['1', '2', '2'], fraction_equivalent_bars: ['1', '2', '4'],
    cartesian_point: ['2', '3'], cartesian_line: ['1', '0'], translation: ['3', '2'], reflection: ['0'], rotation: ['90'], dilation: ['2'],
    ruler: ['10'], clock: ['10', '10'], protractor_90: ['45'], protractor: ['45'], protractor_270: ['135'], protractor_360: ['225'], number_line: ['-5', '5'], scale_bar: ['100', '5'],
};

const protractorSpanFor = (template: GeometryTemplate): number => template === 'protractor_90'
    ? 90
    : template === 'protractor_270'
        ? 270
        : template === 'protractor_360'
            ? 360
            : 180;

const formatAngleDegrees = (degrees: number): string => String(Number(Math.max(0, degrees).toFixed(6)));

const recalculateAskedAngle = (angles: ProtractorAngle[], span: number): ProtractorAngle[] => {
    const askedIndex = angles.findIndex((angle) => angle.asked);
    if (askedIndex < 0) return angles;

    const knownTotal = angles.reduce((total, angle, index) => index === askedIndex ? total : total + (Number(angle.degrees) || 0), 0);
    return angles.map((angle, index) => index === askedIndex
        ? { ...angle, degrees: formatAngleDegrees(span - knownTotal) }
        : angle);
};

const defaultProtractorAngles = (span: number, knownDegrees: number): ProtractorAngle[] => recalculateAskedAngle([
    { id: crypto.randomUUID(), label: 'x', degrees: formatAngleDegrees(knownDegrees), asked: false },
    { id: crypto.randomUUID(), label: 'y', degrees: '', asked: true },
], span);

type QuestionBlueprint = { id: number; subject_id: number; code: string; name: string; competency_ids: number[] };
type Assessment = {
    id: number;
    title: string;
    grade_level: number;
    subject_id: number | null;
    competency_coverage: Record<number, number>; // sub-competency_id => count
};

type QuestionForm = {
    return_generation_id: number | null;
    subject_id: string;
    root_competency_id: string;
    competency_id: string;
    question_blueprint_id: string;
    type: 'single_choice' | 'multiple_choice' | 'short_answer' | 'matching' | 'category_matrix';
    title: string;
    stimulus: string;
    stimulus_visual_type: StimulusVisualType;
    stimulus_visual_title: string;
    stimulus_table_headers: string[];
    stimulus_table_rows: string[][];
    stimulus_chart_items: StimulusChartItem[];
    stimulus_chart_mode: 'single' | 'grouped';
    stimulus_chart_series_labels: string[];
    stimulus_chart_grouped_categories: StimulusGroupedChartCategory[];
    stimulus_chart_x_axis_label: string;
    stimulus_chart_y_axis_label: string;
    stimulus_chart_maximum: string;
    stimulus_pictogram_symbol: string;
    stimulus_pictogram_legend_value: string;
    stimulus_pictogram_unit: string;
    stimulus_pictogram_items: StimulusChartItem[];
    stimulus_pie_unit: string;
    stimulus_pie_total_mode: PieTotalMode;
    stimulus_pie_total: string;
    stimulus_pie_total_asked: boolean;
    stimulus_pie_items: StimulusPieItem[];
    stimulus_image: File | null;
    stimulus_image_source: 'upload' | 'template';
    stimulus_svg_template: GeometryTemplate;
    stimulus_svg_dimension_a: string;
    stimulus_svg_dimension_b: string;
    stimulus_svg_dimension_c: string;
    stimulus_svg_unit: string;
    stimulus_svg_zoom: number;
    stimulus_svg_offset_x: number;
    stimulus_svg_offset_y: number;
    stimulus_fraction_models: FractionModel[];
    stimulus_svg_overlays: CanvasOverlay[];
    stimulus_protractor_angles: ProtractorAngle[];
    stimulus_image_width: number;
    stimulus_image_height: number;
    stimulus_upload_zoom: number;
    stimulus_upload_offset_x: number;
    stimulus_upload_offset_y: number;
    stimulus_image_alt: string;
    remove_stimulus_image: boolean;
    prompt: string;
    explanation: string;
    explanation_image: File | null;
    explanation_image_alt: string;
    remove_explanation_image: boolean;
    difficulty: number;
    grade_level: number;
    cognitive_level: string;
    options: { content: string; is_correct: boolean }[];
    accepted_answers: string[];
    matching_pairs: MatchingPair[];
    matching_distractors: MatchingDistractor[];
    matrix_columns: MatrixColumn[];
    matrix_rows: MatrixRow[];
    target_assessment_id: number | '';
};

type QuestionTypeOption = {
    value: QuestionForm['type'];
    label: string;
    active: boolean;
};

const defaultQuestionTypes: QuestionTypeOption[] = [
    { value: 'single_choice', label: 'Pilihan tunggal', active: true },
    { value: 'multiple_choice', label: 'Pilihan kompleks (MCMA)', active: true },
    { value: 'short_answer', label: 'Isian singkat', active: true },
    { value: 'matching', label: 'Menjodohkan', active: true },
    { value: 'category_matrix', label: 'Pilihan kategori (tabel)', active: true },
];

const stimulusVisualFromForm = (data: QuestionForm): StimulusVisualData | null => {
    if (data.stimulus_visual_type === 'table') {
        return {
            type: 'table',
            title: data.stimulus_visual_title.trim(),
            headers: data.stimulus_table_headers,
            rows: data.stimulus_table_rows,
        };
    }

    if (data.stimulus_visual_type === 'bar_chart') {
        const maximum = Number(data.stimulus_chart_maximum);
        const common = {
            type: 'bar_chart',
            title: data.stimulus_visual_title.trim(),
            x_axis_label: data.stimulus_chart_x_axis_label.trim(),
            y_axis_label: data.stimulus_chart_y_axis_label.trim(),
            maximum: data.stimulus_chart_maximum.trim() !== '' && Number.isFinite(maximum) ? maximum : undefined,
        } as const;

        if (data.stimulus_chart_mode === 'grouped') {
            return {
                ...common,
                categories: data.stimulus_chart_grouped_categories.map((category) => category.label),
                series: data.stimulus_chart_series_labels.map((label, seriesIndex) => ({
                    label,
                    values: data.stimulus_chart_grouped_categories.map((category) => Number(category.values[seriesIndex])),
                })),
            };
        }

        return {
            ...common,
            items: data.stimulus_chart_items.map((item) => ({
                label: item.label,
                value: Number(item.value),
            })),
        };
    }

    if (data.stimulus_visual_type === 'pictogram') {
        return {
            type: 'pictogram',
            title: data.stimulus_visual_title.trim(),
            symbol: data.stimulus_pictogram_symbol.trim() || '●',
            legend_value: Number(data.stimulus_pictogram_legend_value) || 1,
            unit: data.stimulus_pictogram_unit.trim(),
            items: data.stimulus_pictogram_items.map((item) => ({
                label: item.label,
                value: Number(item.value),
            })),
        };
    }

    if (data.stimulus_visual_type === 'pie_chart') {
        return {
            type: 'pie_chart',
            title: data.stimulus_visual_title.trim(),
            unit: data.stimulus_pie_unit.trim(),
            total_mode: data.stimulus_pie_total_mode,
            total: Number(data.stimulus_pie_total),
            total_asked: data.stimulus_pie_total_asked,
            items: data.stimulus_pie_items.map((item) => ({
                label: item.label,
                value: Number(item.value),
                asked: item.asked,
                auto_calculate: item.auto_calculate,
            })),
        };
    }

    return null;
};

type ExistingQuestion = {
    id: number;
    status: string;
    version: number;
    competency_id: number;
    question_blueprint_id?: number;
    type: QuestionForm['type'];
    title?: string;
    stimulus?: string;
    prompt: string;
    explanation?: string;
    difficulty: number;
    grade_level: number;
    cognitive_level?: string;
    illustration_url?: string;
    explanation_image_url?: string;
    options: { content: string; is_correct: boolean }[];
    metadata?: {
        accepted_answers?: string[];
        illustration?: { alt?: string; path?: string; source?: string; display_width?: number; display_height?: number; display_zoom?: number; display_offset_x?: number; display_offset_y?: number; template?: GeometryTemplate; dimension_a?: number; dimension_b?: number; dimension_c?: number; unit?: string; zoom?: number; offset_x?: number; offset_y?: number; fraction_models?: { numerator: number; denominator: number; shaded_parts?: number[] }[]; overlays?: CanvasOverlay[]; protractor_angles?: { id: string; label: string; degrees: number; asked: boolean }[] };
        explanation_illustration?: { alt?: string };
        stimulus_visual?: StimulusVisualData;
        matching_pairs?: MatchingPair[];
        matching_distractors?: MatchingDistractor[];
        matrix_columns?: MatrixColumn[];
        matrix_rows?: { id: string; statement: string; correct_column_id: string }[];
    };
};

export default function Create({ subjects, competencies, questionBlueprints, assessments, questionTypes = defaultQuestionTypes, question, selectedSubjectId, returnGeneration, stimulusSvgTemplates = [], requiredVerifications = 3 }: { subjects: { id: number; code: string; name: string }[]; competencies: Competency[]; questionBlueprints: QuestionBlueprint[]; assessments: Assessment[]; questionTypes?: QuestionTypeOption[]; question?: ExistingQuestion; selectedSubjectId?: number | null; returnGeneration?: { id: number; format: 'direct' | 'story' } | null; stimulusSvgTemplates?: StimulusSvgTemplateOption[]; requiredVerifications?: number }) {
    const [previewOpen, setPreviewOpen] = useState(false);
    const [submitIntent, setSubmitIntent] = useState<'draft' | 'review'>('draft');
    const [saveError, setSaveError] = useState<string | null>(null);
    const [activeStimulusTab, setActiveStimulusTab] = useState<'text' | 'visual' | 'image' | null>(null);
    const defaultOptions = [
            { content: '', is_correct: true },
            { content: '', is_correct: false },
            { content: '', is_correct: false },
            { content: '', is_correct: false },
        ];
    const initialMatrixColumns = question?.metadata?.matrix_columns?.length ? question.metadata.matrix_columns : [
        { label: 'Ya' },
        { label: 'Tidak' },
    ];
    const initialMatrixRows = question?.metadata?.matrix_rows?.length ? question.metadata.matrix_rows.map((row) => ({
        id: row.id,
        statement: row.statement,
        correct_column_index: Math.max(0, initialMatrixColumns.findIndex((column) => column.id === row.correct_column_id)),
    })) : [
        { statement: '', correct_column_index: 0 },
        { statement: '', correct_column_index: 1 },
    ];
    const questionCompetency = question ? competencies.find((competency) => competency.id === question.competency_id) : undefined;
    const initialRootCompetencyId = questionCompetency?.parent_id || questionCompetency?.id;
    const existingStimulusVisual = question?.metadata?.stimulus_visual;
    const initialPieItems: StimulusPieItem[] = existingStimulusVisual?.type === 'pie_chart'
        ? (() => {
            const items = existingStimulusVisual.items.map((item) => ({
                label: item.label,
                value: String(item.value),
                asked: Boolean(item.asked),
                auto_calculate: Boolean(item.auto_calculate),
            }));
            if (items.some((item) => item.auto_calculate)) return items;
            return items.map((item, index) => ({ ...item, auto_calculate: index === items.length - 1 }));
        })()
        : [
            { label: '', value: '40', asked: false, auto_calculate: false },
            { label: '', value: '35', asked: false, auto_calculate: false },
            { label: '', value: '25', asked: true, auto_calculate: true },
        ];
    const existingProtractorTemplate = question?.metadata?.illustration?.template;
    const existingProtractorAngles = question?.metadata?.illustration?.protractor_angles?.map((angle) => ({ ...angle, degrees: String(angle.degrees) }));
    const initialProtractorAngles = existingProtractorTemplate?.startsWith('protractor')
        ? existingProtractorAngles && existingProtractorAngles.length >= 2
            ? recalculateAskedAngle(existingProtractorAngles, protractorSpanFor(existingProtractorTemplate))
            : defaultProtractorAngles(protractorSpanFor(existingProtractorTemplate), Number(question?.metadata?.illustration?.dimension_a) || 45)
        : [];
    const { data, setData, post, transform, processing, errors, clearErrors } = useForm<QuestionForm>({
        return_generation_id: returnGeneration?.id || null,
        subject_id: questionCompetency ? String(questionCompetency.subject_id) : selectedSubjectId ? String(selectedSubjectId) : '',
        root_competency_id: initialRootCompetencyId ? String(initialRootCompetencyId) : '',
        competency_id: question ? String(question.competency_id) : '',
        question_blueprint_id: question?.question_blueprint_id ? String(question.question_blueprint_id) : '',
        type: question?.type || questionTypes[0]?.value || 'single_choice',
        title: question?.title || '',
        stimulus: question?.stimulus || '',
        stimulus_visual_type: existingStimulusVisual?.type || 'none',
        stimulus_visual_title: existingStimulusVisual?.title || '',
        stimulus_table_headers: existingStimulusVisual?.type === 'table' ? existingStimulusVisual.headers : ['Kategori', 'Nilai'],
        stimulus_table_rows: existingStimulusVisual?.type === 'table' ? existingStimulusVisual.rows : [['', ''], ['', ''], ['', '']],
        stimulus_chart_items: existingStimulusVisual?.type === 'bar_chart'
            ? (existingStimulusVisual.items || []).map((item) => ({ label: item.label, value: String(item.value) }))
            : [{ label: '', value: '' }, { label: '', value: '' }, { label: '', value: '' }],
        stimulus_chart_mode: existingStimulusVisual?.type === 'bar_chart' && (existingStimulusVisual.series?.length || 0) >= 2 ? 'grouped' : 'single',
        stimulus_chart_series_labels: existingStimulusVisual?.type === 'bar_chart' && existingStimulusVisual.series?.length
            ? existingStimulusVisual.series.map((series) => series.label)
            : ['Seri 1', 'Seri 2'],
        stimulus_chart_grouped_categories: existingStimulusVisual?.type === 'bar_chart' && existingStimulusVisual.categories?.length && existingStimulusVisual.series?.length
            ? existingStimulusVisual.categories.map((label, categoryIndex) => ({
                label,
                values: existingStimulusVisual.series!.map((series) => String(series.values[categoryIndex] ?? '')),
            }))
            : [{ label: '', values: ['', ''] }, { label: '', values: ['', ''] }, { label: '', values: ['', ''] }],
        stimulus_chart_x_axis_label: existingStimulusVisual?.type === 'bar_chart' ? existingStimulusVisual.x_axis_label || '' : '',
        stimulus_chart_y_axis_label: existingStimulusVisual?.type === 'bar_chart' ? existingStimulusVisual.y_axis_label || '' : '',
        stimulus_chart_maximum: existingStimulusVisual?.type === 'bar_chart' && existingStimulusVisual.maximum != null ? String(existingStimulusVisual.maximum) : '',
        stimulus_pictogram_symbol: existingStimulusVisual?.type === 'pictogram' ? existingStimulusVisual.symbol : '📘',
        stimulus_pictogram_legend_value: existingStimulusVisual?.type === 'pictogram' ? String(existingStimulusVisual.legend_value) : '5',
        stimulus_pictogram_unit: existingStimulusVisual?.type === 'pictogram' ? existingStimulusVisual.unit || '' : 'buku',
        stimulus_pictogram_items: existingStimulusVisual?.type === 'pictogram'
            ? existingStimulusVisual.items.map((item) => ({ label: item.label, value: String(item.value) }))
            : [{ label: '', value: '' }, { label: '', value: '' }, { label: '', value: '' }],
        stimulus_pie_unit: existingStimulusVisual?.type === 'pie_chart' ? existingStimulusVisual.unit || '' : '',
        stimulus_pie_total_mode: existingStimulusVisual?.type === 'pie_chart' ? existingStimulusVisual.total_mode || 'custom' : 'percentage',
        stimulus_pie_total: existingStimulusVisual?.type === 'pie_chart'
            ? String(existingStimulusVisual.total || existingStimulusVisual.items.reduce((sum, item) => sum + Number(item.value), 0))
            : '100',
        stimulus_pie_total_asked: existingStimulusVisual?.type === 'pie_chart' ? Boolean(existingStimulusVisual.total_asked) : false,
        stimulus_pie_items: initialPieItems,
        stimulus_image: null,
        stimulus_image_source: question?.metadata?.illustration?.source === 'template-svg' ? 'template' : 'upload',
        stimulus_svg_template: question?.metadata?.illustration?.template || 'square',
        stimulus_svg_dimension_a: question?.metadata?.illustration?.dimension_a != null ? String(question.metadata.illustration.dimension_a) : '',
        stimulus_svg_dimension_b: question?.metadata?.illustration?.dimension_b != null ? String(question.metadata.illustration.dimension_b) : '',
        stimulus_svg_dimension_c: question?.metadata?.illustration?.dimension_c != null ? String(question.metadata.illustration.dimension_c) : '',
        stimulus_svg_unit: question?.metadata?.illustration?.unit || 'cm',
        stimulus_svg_zoom: question?.metadata?.illustration?.zoom || 1,
        stimulus_svg_offset_x: question?.metadata?.illustration?.offset_x || 0,
        stimulus_svg_offset_y: question?.metadata?.illustration?.offset_y || 0,
        stimulus_fraction_models: question?.metadata?.illustration?.fraction_models?.map((model) => ({ numerator: String(model.numerator), denominator: String(model.denominator), shaded_parts: model.shaded_parts || Array.from({ length: model.numerator }, (_, index) => index) })) || [{ numerator: '1', denominator: '2', shaded_parts: [0] }, { numerator: '2', denominator: '4', shaded_parts: [0, 1] }],
        stimulus_svg_overlays: question?.metadata?.illustration?.overlays || [],
        stimulus_protractor_angles: initialProtractorAngles,
        stimulus_image_width: question?.metadata?.illustration?.display_width || 800,
        stimulus_image_height: question?.metadata?.illustration?.display_height || 450,
        stimulus_upload_zoom: question?.metadata?.illustration?.display_zoom || 1,
        stimulus_upload_offset_x: question?.metadata?.illustration?.display_offset_x || 0,
        stimulus_upload_offset_y: question?.metadata?.illustration?.display_offset_y || 0,
        stimulus_image_alt: question?.metadata?.illustration?.alt || '',
        remove_stimulus_image: false,
        prompt: question?.prompt || '',
        explanation: question?.explanation || '',
        explanation_image: null,
        explanation_image_alt: question?.metadata?.explanation_illustration?.alt || '',
        remove_explanation_image: false,
        difficulty: question?.difficulty || 1,
        grade_level: question?.grade_level || 6,
        cognitive_level: question?.cognitive_level || '',
        options: question?.options.length ? question.options.map((option) => ({ content: option.content, is_correct: option.is_correct })) : defaultOptions,
        accepted_answers: question?.metadata?.accepted_answers?.length ? question.metadata.accepted_answers : [''],
        matching_pairs: question?.metadata?.matching_pairs?.length ? question.metadata.matching_pairs : [
            { left: '', right: '' },
            { left: '', right: '' },
            { left: '', right: '' },
        ],
        matching_distractors: question?.metadata?.matching_distractors || [],
        matrix_columns: initialMatrixColumns,
        matrix_rows: initialMatrixRows,
        target_assessment_id: '',
    });
    const formErrors = errors as Record<string, string>;
    const [selectedIllustrationUrl, setSelectedIllustrationUrl] = useState<string>();
    const [selectedExplanationImageUrl, setSelectedExplanationImageUrl] = useState<string>();
    const [draggingSvgPreview, setDraggingSvgPreview] = useState(false);
    const [selectedOverlayId, setSelectedOverlayId] = useState<string | null>(null);
    const [canvasFullscreen, setCanvasFullscreen] = useState(false);
    const svgDragStart = useRef({ clientX: 0, clientY: 0, offsetX: 0, offsetY: 0 });
    const selectedSvgTemplate = stimulusSvgTemplates.find((template) => template.value === data.stimulus_svg_template);
    const editableUploadIllustrationUrl = data.stimulus_image_source === 'upload'
        ? selectedIllustrationUrl || (!data.remove_stimulus_image ? question?.illustration_url : undefined)
        : undefined;
    const selectedSvgCategory = selectedSvgTemplate?.category || '2d';
    const svgFamilies = Array.from(new Map(stimulusSvgTemplates
        .filter((template) => template.category === selectedSvgCategory)
        .map((template) => [template.family, template.family_label])).entries());
    const selectedSvgFamily = selectedSvgTemplate?.family || svgFamilies[0]?.[0];
    const svgSubfamilies = Array.from(new Map(stimulusSvgTemplates
        .filter((template) => template.category === selectedSvgCategory && template.family === selectedSvgFamily && template.subfamily)
        .map((template) => [template.subfamily!, template.subfamily_label!])).entries());
    const hasSvgSubfamilies = svgSubfamilies.length > 0;
    const selectedSvgSubfamily = selectedSvgTemplate?.subfamily || svgSubfamilies[0]?.[0];
    const svgVariations = stimulusSvgTemplates.filter((template) => template.category === selectedSvgCategory
        && template.family === selectedSvgFamily
        && (!hasSvgSubfamilies || template.subfamily === selectedSvgSubfamily));
    const usesCustomFractionModels = selectedSvgTemplate?.custom_fraction_models === true;
    const isProtractorTemplate = data.stimulus_svg_template.startsWith('protractor');
    const protractorSpan = protractorSpanFor(data.stimulus_svg_template);
    const protractorTotal = data.stimulus_protractor_angles.reduce((total, angle) => total + (Number(angle.degrees) || 0), 0);
    const selectedOverlay = data.stimulus_svg_overlays.find((overlay) => overlay.id === selectedOverlayId);
    const addOverlay = (type: CanvasOverlay['type'], content: string) => {
        const overlay: CanvasOverlay = { id: crypto.randomUUID(), type, content, x: 500, y: 150, font_size: type === 'symbol' ? 52 : 32, color: '#0f172a', rotation: 0 };
        setData('stimulus_svg_overlays', [...data.stimulus_svg_overlays, overlay]);
        setSelectedOverlayId(overlay.id);
    };
    const updateOverlay = (id: string, changes: Partial<CanvasOverlay>) => setData('stimulus_svg_overlays', data.stimulus_svg_overlays.map((overlay) => overlay.id === id ? { ...overlay, ...changes } : overlay));
    const removeOverlay = (id: string) => {
        setData('stimulus_svg_overlays', data.stimulus_svg_overlays.filter((overlay) => overlay.id !== id));
        setSelectedOverlayId((current) => current === id ? null : current);
    };
    const duplicateOverlay = (overlay: CanvasOverlay) => {
        const copy = { ...overlay, id: crypto.randomUUID(), x: Math.min(1000, overlay.x + 30), y: Math.min(600, overlay.y + 30) };
        setData('stimulus_svg_overlays', [...data.stimulus_svg_overlays, copy]);
        setSelectedOverlayId(copy.id);
    };
    const moveOverlayLayer = (id: string, direction: -1 | 1) => {
        const overlays = [...data.stimulus_svg_overlays];
        const index = overlays.findIndex((overlay) => overlay.id === id);
        const target = Math.max(0, Math.min(overlays.length - 1, index + direction));
        if (index < 0 || index === target) return;
        [overlays[index], overlays[target]] = [overlays[target], overlays[index]];
        setData('stimulus_svg_overlays', overlays);
    };
    const changeCanvasZoom = (delta: number) => setData('stimulus_svg_zoom', Math.max(0.25, Math.min(3, Number((data.stimulus_svg_zoom + delta).toFixed(2)))));
    const selectSvgTemplate = (template?: StimulusSvgTemplateOption) => {
        if (!template) return;
        const defaults = svgTemplateDefaults[template.value] || ['', '', ''];
        const targetProtractorSpan = protractorSpanFor(template.value);
        setData((current) => ({
            ...current,
            stimulus_svg_template: template.value,
            stimulus_svg_dimension_a: current.stimulus_svg_template === template.value ? current.stimulus_svg_dimension_a : defaults[0],
            stimulus_svg_dimension_b: template.dimension_b_label ? (current.stimulus_svg_template === template.value ? current.stimulus_svg_dimension_b : defaults[1] || '') : '',
            stimulus_svg_dimension_c: template.dimension_c_label ? (current.stimulus_svg_template === template.value ? current.stimulus_svg_dimension_c : defaults[2] || '') : '',
            stimulus_svg_zoom: current.stimulus_svg_template === template.value ? current.stimulus_svg_zoom : 1,
            stimulus_svg_offset_x: current.stimulus_svg_template === template.value ? current.stimulus_svg_offset_x : 0,
            stimulus_svg_offset_y: current.stimulus_svg_template === template.value ? current.stimulus_svg_offset_y : 0,
            stimulus_fraction_models: current.stimulus_svg_template === template.value
                ? current.stimulus_fraction_models
                : template.custom_fraction_models ? [{ numerator: '1', denominator: '2', shaded_parts: [0] }, { numerator: '2', denominator: '4', shaded_parts: [0, 1] }] : current.stimulus_fraction_models,
            stimulus_protractor_angles: template.value.startsWith('protractor')
                ? (current.stimulus_svg_template.startsWith('protractor') && current.stimulus_protractor_angles.length > 0
                    ? recalculateAskedAngle(current.stimulus_protractor_angles, targetProtractorSpan)
                    : defaultProtractorAngles(targetProtractorSpan, Number(defaults[0]) || 45))
                : current.stimulus_protractor_angles,
        }));
    };
    const updateProtractorAngles = (angles: ProtractorAngle[]) => setData((current) => {
        const normalizedAngles = recalculateAskedAngle(angles, protractorSpanFor(current.stimulus_svg_template));
        const firstKnownAngle = normalizedAngles.find((angle) => !angle.asked);

        return {
            ...current,
            stimulus_protractor_angles: normalizedAngles,
            stimulus_svg_dimension_a: firstKnownAngle?.degrees || current.stimulus_svg_dimension_a,
        };
    });
    const pieTargetTotal = data.stimulus_pie_total_mode === 'percentage'
        ? 100
        : data.stimulus_pie_total_mode === 'degrees'
            ? 360
            : Number(data.stimulus_pie_total) || 0;
    const recalculatePieItems = (items: StimulusPieItem[], total: number): StimulusPieItem[] => {
        const automaticIndex = items.findIndex((item) => item.auto_calculate);
        if (automaticIndex < 0) return items;
        const knownTotal = items.reduce((sum, item, index) => index === automaticIndex ? sum : sum + (Number(item.value) || 0), 0);
        return items.map((item, index) => index === automaticIndex
            ? { ...item, value: formatAngleDegrees(total - knownTotal) }
            : item);
    };
    const updatePieItems = (items: StimulusPieItem[]) => setData('stimulus_pie_items', recalculatePieItems(items, pieTargetTotal));
    const changePieTotalMode = (mode: PieTotalMode) => setData((current) => {
        const total = mode === 'percentage' ? 100 : mode === 'degrees' ? 360 : Number(current.stimulus_pie_total) || 1000;
        return {
            ...current,
            stimulus_pie_total_mode: mode,
            stimulus_pie_total: String(total),
            stimulus_pie_total_asked: mode === 'custom' ? current.stimulus_pie_total_asked : false,
            stimulus_pie_items: recalculatePieItems(current.stimulus_pie_items, total),
        };
    });
    const changePieCustomTotal = (value: string) => setData((current) => ({
        ...current,
        stimulus_pie_total: value,
        stimulus_pie_items: recalculatePieItems(current.stimulus_pie_items, Number(value) || 0),
    }));
    const updateFractionModels = (models: FractionModel[]) => setData((current) => ({
        ...current,
        stimulus_fraction_models: models,
        stimulus_svg_dimension_a: models[0]?.numerator || '1',
        stimulus_svg_dimension_b: models[0]?.denominator || '2',
        stimulus_svg_dimension_c: String(models.length),
    }));
    const toggleFractionPart = (modelIndex: number, partIndex: number) => updateFractionModels(data.stimulus_fraction_models.map((model, index) => {
        if (index !== modelIndex) return model;
        const selected = new Set(model.shaded_parts || Array.from({ length: Number(model.numerator) || 0 }, (_, selectedIndex) => selectedIndex));
        selected.has(partIndex) ? selected.delete(partIndex) : selected.add(partIndex);
        const shadedParts = Array.from(selected).sort((left, right) => left - right);
        return { ...model, numerator: String(shadedParts.length), shaded_parts: shadedParts };
    }));
    const startSvgDrag = (event: ReactPointerEvent<HTMLDivElement>) => {
        if (event.button !== 0) return;
        if ((event.target as Element).closest('[data-fraction-part="true"], [data-canvas-overlay="true"]')) return;
        event.currentTarget.setPointerCapture(event.pointerId);
        svgDragStart.current = {
            clientX: event.clientX,
            clientY: event.clientY,
            offsetX: data.stimulus_svg_offset_x,
            offsetY: data.stimulus_svg_offset_y,
        };
        setDraggingSvgPreview(true);
    };
    const moveSvgDrag = (event: ReactPointerEvent<HTMLDivElement>) => {
        if (!draggingSvgPreview) return;
        const bounds = event.currentTarget.getBoundingClientRect();
        const deltaX = ((event.clientX - svgDragStart.current.clientX) * 1000) / bounds.width;
        const deltaY = ((event.clientY - svgDragStart.current.clientY) * 600) / bounds.height;
        setData((current) => ({
            ...current,
            stimulus_svg_offset_x: Math.max(-500, Math.min(500, Math.round(svgDragStart.current.offsetX + deltaX))),
            stimulus_svg_offset_y: Math.max(-300, Math.min(300, Math.round(svgDragStart.current.offsetY + deltaY))),
        }));
    };
    const stopSvgDrag = (event: ReactPointerEvent<HTMLDivElement>) => {
        if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId);
        setDraggingSvgPreview(false);
    };

    useEffect(() => {
        if (!data.stimulus_image) {
            setSelectedIllustrationUrl(undefined);
            return;
        }

        const objectUrl = URL.createObjectURL(data.stimulus_image);
        setSelectedIllustrationUrl(objectUrl);

        return () => URL.revokeObjectURL(objectUrl);
    }, [data.stimulus_image]);

    useEffect(() => {
        if (!data.explanation_image) {
            setSelectedExplanationImageUrl(undefined);
            return;
        }

        const objectUrl = URL.createObjectURL(data.explanation_image);
        setSelectedExplanationImageUrl(objectUrl);

        return () => URL.revokeObjectURL(objectUrl);
    }, [data.explanation_image]);

    const availableCompetencies = competencies.filter(
        (competency) => competency.subject_id === Number(data.subject_id) && !competency.parent_id,
    );
    const availableSubcompetencies = competencies.filter(
        (competency) => competency.parent_id === Number(data.root_competency_id),
    );
    const selectedSubject = subjects.find((subject) => subject.id === Number(data.subject_id));
    const usesQuestionBlueprints = selectedSubject?.code === 'BIND';
    const availableQuestionBlueprints = questionBlueprints.filter((item) => item.subject_id === Number(data.subject_id));
    const defaultQuestionBlueprints = availableQuestionBlueprints.filter((item) => item.competency_ids.includes(Number(data.competency_id)));

    // Determine the active sub-competency id (the final competency_id if it has a parent)
    const activeSubCompetencyId = useMemo(() => {
        const cid = Number(data.competency_id);
        if (!cid) return null;
        const comp = competencies.find((c) => c.id === cid);
        return comp?.parent_id ? cid : null; // only sub-competencies (those with a parent)
    }, [data.competency_id, competencies]);

    // Pre-select assessment: oldest that doesn't have this sub-competency, or fewest questions if all have it
    const suggestedAssessmentId = useMemo((): number | '' => {
        if (!activeSubCompetencyId || !data.grade_level) return '';
        const gradeMatched = assessments.filter((a) => a.grade_level === Number(data.grade_level));
        if (gradeMatched.length === 0) return '';
        // First: find oldest (first in list, already sorted oldest first) that has 0 coverage for this sub-competency
        const withoutCoverage = gradeMatched.filter((a) => !(a.competency_coverage[activeSubCompetencyId] > 0));
        if (withoutCoverage.length > 0) return withoutCoverage[0].id;
        // Fall back: pick the one with fewest questions for this sub-competency
        return gradeMatched.sort((a, b) =>
            (a.competency_coverage[activeSubCompetencyId] ?? 0) - (b.competency_coverage[activeSubCompetencyId] ?? 0),
        )[0].id;
    }, [activeSubCompetencyId, assessments, data.grade_level]);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const submitter = (event.nativeEvent as SubmitEvent).submitter as HTMLButtonElement | null;
        const intent = submitter?.value === 'review' ? 'review' : 'draft';
        const actionLabel = intent === 'review' ? 'mengajukan soal' : 'menyimpan draft';
        setSubmitIntent(intent);
        setSaveError(null);
        clearErrors();
        transform((formData) => ({ ...formData, ...(question ? { _method: 'put' } : {}), intent }));
        const options = {
            forceFormData: true,
            onError: (validationErrors: Record<string, string>) => {
                const entries = Object.entries(validationErrors);
                const fieldGroups = new Set(entries.map(([field]) => field.split('.')[0]));
                setSaveError(entries.length > 0
                    ? `Gagal ${actionLabel}: ${entries[0][1]}${fieldGroups.size > 1 ? ` (+${fieldGroups.size - 1} bagian lain perlu diperiksa)` : ''}`
                    : `Gagal ${actionLabel}. Periksa kembali data soal.`);
            },
        };
        if (question) {
            post(route('questions.update', question.id), options);
        } else {
            post(route('questions.store'), options);
        }
    };

    const updateOption = (index: number, field: 'content' | 'is_correct', value: string | boolean) => {
        const options = [...data.options];
        options[index] = { ...options[index], [field]: value };
        if (field === 'is_correct' && value && data.type === 'single_choice') {
            options.forEach((option, optionIndex) => {
                option.is_correct = optionIndex === index;
            });
        }
        setData('options', options);
    };

    const renderCanvasEditor = (fullscreen = false) => (
        <figure className={`relative overflow-hidden border border-slate-300 bg-slate-100 shadow-inner ${fullscreen ? 'h-[calc(100vh-7rem)] min-h-[620px] rounded-b-xl' : 'min-h-[420px] rounded-xl'}`}>
            <div className="absolute inset-x-3 top-3 z-20 flex min-w-0 items-center gap-1 overflow-x-auto rounded-xl border border-slate-200 bg-white/95 p-1.5 shadow-lg backdrop-blur">
                <button type="button" title="Tambah teks" onClick={() => addOverlay('text', 'Teks baru')} className="shrink-0 rounded-lg px-3 py-2 text-xs font-bold text-slate-700 hover:bg-violet-50 hover:text-violet-700"><span className="mr-1 text-base">T</span> Teks</button>
                <select aria-label="Tambahkan simbol" title="Tambah simbol" defaultValue="" onChange={(event) => { if (event.target.value) addOverlay('symbol', event.target.value); event.target.value = ''; }} className="h-9 w-24 shrink-0 rounded-lg border-0 bg-slate-50 py-1 pl-2 pr-7 text-xs font-bold text-slate-700 focus:ring-violet-500">
                    <option value="">Simbol</option>
                    {['°', '∠', 'π', '×', '÷', '+', '−', '=', '≠', '≤', '≥', '√', '★', '●', '▲', '■', '→', '↔'].map((symbol) => <option key={symbol} value={symbol}>{symbol}</option>)}
                </select>
                <span className="mx-1 h-6 w-px shrink-0 bg-slate-200" />
                {selectedOverlay ? <>
                    <input aria-label="Isi elemen" title="Isi teks atau simbol" value={selectedOverlay.content} maxLength={100} onChange={(event) => updateOverlay(selectedOverlay.id, { content: event.target.value })} className="h-9 min-w-28 max-w-52 rounded-lg border-slate-200 px-2 text-xs font-semibold" />
                    <input aria-label="Ukuran elemen" title="Ukuran" type="number" min="12" max="160" value={selectedOverlay.font_size} onChange={(event) => updateOverlay(selectedOverlay.id, { font_size: Number(event.target.value) })} className="h-9 w-16 rounded-lg border-slate-200 px-2 text-xs" />
                    <input aria-label="Warna elemen" title="Warna" type="color" value={selectedOverlay.color} onChange={(event) => updateOverlay(selectedOverlay.id, { color: event.target.value })} className="h-9 w-10 shrink-0 rounded-lg border border-slate-200 bg-white p-1" />
                    <input aria-label="Rotasi elemen" title="Rotasi derajat" type="number" min="-180" max="180" value={selectedOverlay.rotation} onChange={(event) => updateOverlay(selectedOverlay.id, { rotation: Number(event.target.value) })} className="h-9 w-16 rounded-lg border-slate-200 px-2 text-xs" />
                    <button type="button" title="Duplikat" onClick={() => duplicateOverlay(selectedOverlay)} className="h-9 w-9 shrink-0 rounded-lg text-sm text-slate-600 hover:bg-slate-100">⧉</button>
                    <button type="button" title="Turunkan satu lapisan" onClick={() => moveOverlayLayer(selectedOverlay.id, -1)} className="h-9 w-9 shrink-0 rounded-lg text-sm text-slate-600 hover:bg-slate-100">↓</button>
                    <button type="button" title="Naikkan satu lapisan" onClick={() => moveOverlayLayer(selectedOverlay.id, 1)} className="h-9 w-9 shrink-0 rounded-lg text-sm text-slate-600 hover:bg-slate-100">↑</button>
                    <button type="button" title="Hapus elemen" onClick={() => removeOverlay(selectedOverlay.id)} className="h-9 w-9 shrink-0 rounded-lg text-base text-rose-600 hover:bg-rose-50">×</button>
                </> : <span className="whitespace-nowrap px-2 text-xs text-slate-500">Pilih elemen untuk mengedit</span>}
                <span className="flex-1" />
                {fullscreen && <button type="button" title="Tutup editor" onClick={() => setCanvasFullscreen(false)} className="h-9 w-9 shrink-0 rounded-lg text-lg text-slate-600 hover:bg-slate-100">×</button>}
            </div>
            <div
                role="application"
                aria-label="Editor kanvas gambar"
                tabIndex={0}
                onPointerDown={(event) => { if (!(event.target as Element).closest('[data-canvas-overlay="true"]')) setSelectedOverlayId(null); startSvgDrag(event); }}
                onPointerMove={moveSvgDrag}
                onPointerUp={stopSvgDrag}
                onPointerCancel={stopSvgDrag}
                onWheel={(event) => { if (!event.ctrlKey && !event.metaKey) return; event.preventDefault(); changeCanvasZoom(event.deltaY < 0 ? 0.1 : -0.1); }}
                onKeyDown={(event) => {
                    if (!selectedOverlay || (event.target as HTMLElement).tagName === 'INPUT') return;
                    if (event.key === 'Delete' || event.key === 'Backspace') { event.preventDefault(); removeOverlay(selectedOverlay.id); return; }
                    const movement = event.shiftKey ? 10 : 2;
                    const changes = event.key === 'ArrowLeft' ? { x: Math.max(0, selectedOverlay.x - movement) } : event.key === 'ArrowRight' ? { x: Math.min(1000, selectedOverlay.x + movement) } : event.key === 'ArrowUp' ? { y: Math.max(0, selectedOverlay.y - movement) } : event.key === 'ArrowDown' ? { y: Math.min(600, selectedOverlay.y + movement) } : null;
                    if (changes) { event.preventDefault(); updateOverlay(selectedOverlay.id, changes); }
                }}
                className={`flex h-full min-h-[420px] touch-none select-none items-center justify-center px-3 pb-16 pt-16 outline-none focus:ring-2 focus:ring-inset focus:ring-violet-400 ${draggingSvgPreview ? 'cursor-grabbing' : 'cursor-grab'}`}
            >
                <GeometryTemplatePreview template={data.stimulus_svg_template} dimensionA={data.stimulus_svg_dimension_a} dimensionB={data.stimulus_svg_dimension_b} dimensionC={data.stimulus_svg_dimension_c} unit={data.stimulus_svg_unit} fractionModels={data.stimulus_fraction_models} protractorAngles={data.stimulus_protractor_angles} onToggleFractionPart={usesCustomFractionModels ? toggleFractionPart : undefined} overlays={data.stimulus_svg_overlays} selectedOverlayId={selectedOverlayId} onSelectOverlay={setSelectedOverlayId} onMoveOverlay={(id, x, y) => updateOverlay(id, { x, y })} zoom={data.stimulus_svg_zoom} offsetX={data.stimulus_svg_offset_x} offsetY={data.stimulus_svg_offset_y} className={`mx-auto h-auto w-full ${fullscreen ? 'max-h-[calc(100vh-11rem)] max-w-6xl' : ''}`} />
            </div>
            <div className="absolute bottom-3 left-1/2 z-20 flex -translate-x-1/2 items-center gap-1 rounded-xl border border-slate-200 bg-white/95 p-1 shadow-lg backdrop-blur">
                <button type="button" aria-label="Perkecil gambar" onClick={() => changeCanvasZoom(-0.1)} className="h-8 w-9 rounded-lg text-sm font-bold text-slate-700 hover:bg-slate-100">−</button>
                <span className="min-w-12 text-center text-xs font-bold text-slate-600">{Math.round(data.stimulus_svg_zoom * 100)}%</span>
                <button type="button" aria-label="Perbesar gambar" onClick={() => changeCanvasZoom(0.1)} className="h-8 w-9 rounded-lg text-sm font-bold text-slate-700 hover:bg-slate-100">+</button>
                <button type="button" title="Reset tampilan" onClick={() => setData((current) => ({ ...current, stimulus_svg_zoom: 1, stimulus_svg_offset_x: 0, stimulus_svg_offset_y: 0 }))} className="border-l border-slate-200 px-2 py-1.5 text-xs font-semibold text-violet-700">Reset</button>
            </div>
            {!fullscreen && <button type="button" aria-label="Buka editor layar penuh" title="Buka editor besar" onClick={() => setCanvasFullscreen(true)} className="absolute bottom-3 right-3 z-20 flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white/95 text-lg text-slate-700 shadow-lg hover:bg-violet-50 hover:text-violet-700">⛶</button>}
            <figcaption className="sr-only">Editor {selectedSvgTemplate?.label || 'template gambar'}; klik elemen untuk memilih, tarik untuk memindahkan, dan Ctrl+scroll untuk zoom.</figcaption>
        </figure>
    );

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <p className="text-sm font-medium text-emerald-600">Bank Soal</p>
                    <h1 className="mt-1 text-2xl font-bold text-slate-900">{question ? 'Edit Soal' : 'Buat Soal'}</h1>
                </div>
            }
        >
            <Head title={question ? 'Edit Soal' : 'Buat Soal'} />
            <form onSubmit={submit} className="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6">
                {question?.status === 'published' && (
                    <div className="rounded-xl border border-indigo-200 bg-indigo-50 p-4 text-sm leading-6 text-indigo-900">
                        Anda sedang mengedit soal terbit versi {question.version}. Saat disimpan, sistem membuat revisi draft baru dan tidak mengubah soal pada paket yang sudah terbit.
                    </div>
                )}
                {question && returnGeneration && (
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm leading-6 text-emerald-900">
                        Edit pertanyaan, kunci jawaban, dan pembahasan. Setelah disimpan, Anda akan kembali ke paket soal AI ini.
                    </div>
                )}
                {subjects.length === 0 && <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Belum ada mata pelajaran. <Link href={route('subjects.create')} className="font-bold underline">Tambahkan mata pelajaran</Link> sebelum membuat soal.</div>}
                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="font-semibold text-slate-900">Klasifikasi</h2>
                    <div className="mt-4 grid gap-4 sm:grid-cols-2">
                        <label className="text-sm font-medium text-slate-700">
                            Mata pelajaran
                            <select value={data.subject_id} onChange={(event) => setData((current) => ({ ...current, subject_id: event.target.value, root_competency_id: '', competency_id: '', question_blueprint_id: '' }))} className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500">
                                <option value="">Pilih mata pelajaran terlebih dahulu</option>
                                {subjects.map((subject) => <option key={subject.id} value={subject.id}>{subject.code} · {subject.name}</option>)}
                            </select>
                            <InputError message={errors.subject_id} className="mt-1" />
                        </label>
                        <label className="text-sm font-medium text-slate-700">
                            Kompetensi
                            <select
                                value={data.root_competency_id}
                                onChange={(event) => {
                                    const competency = competencies.find((item) => item.id === Number(event.target.value));
                                    const defaults = questionBlueprints.filter((item) => item.competency_ids.includes(Number(event.target.value)));
                                    setData((current) => ({ ...current, root_competency_id: event.target.value, competency_id: event.target.value, question_blueprint_id: defaults[0] ? String(defaults[0].id) : '', grade_level: competency?.grade_level || current.grade_level }));
                                }}
                                className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                            >
                                <option value="">Pilih kompetensi</option>
                                {availableCompetencies.map((competency) => {
                                    const displayName = competency.name.length > 70 ? competency.name.substring(0, 70) + '…' : competency.name;
                                    return (
                                        <option key={competency.id} value={competency.id}>
                                            Kelas {competency.grade_level} · {displayName}
                                        </option>
                                    );
                                })}
                            </select>
                            {data.subject_id && availableCompetencies.length === 0 && <p className="mt-1 text-xs text-amber-700">Mapel ini belum memiliki kompetensi. <Link href={route('competencies.create')} className="font-bold underline">Tambahkan kompetensi</Link>.</p>}
                            <InputError message={errors.competency_id} className="mt-1" />
                        </label>
                        {!usesQuestionBlueprints && <label className="text-sm font-medium text-slate-700">
                            Subkompetensi <span className="font-normal text-slate-500">(opsional)</span>
                            <select
                                value={availableSubcompetencies.some((item) => item.id === Number(data.competency_id)) ? data.competency_id : ''}
                                onChange={(event) => {
                                    const newCompId = event.target.value || data.root_competency_id;
                                    // When sub-competency changes, auto-suggest a new assessment
                                    const cid = Number(newCompId);
                                    const comp = competencies.find((c) => c.id === cid);
                                    const isSubComp = !!comp?.parent_id;
                                    let newTargetId: number | '' = '';
                                    if (isSubComp) {
                                        const gradeMatched = assessments.filter((a) => a.grade_level === Number(data.grade_level));
                                        const without = gradeMatched.filter((a) => !(a.competency_coverage[cid] > 0));
                                        if (without.length > 0) newTargetId = without[0].id;
                                        else if (gradeMatched.length > 0) {
                                            newTargetId = [...gradeMatched].sort((a, b) =>
                                                (a.competency_coverage[cid] ?? 0) - (b.competency_coverage[cid] ?? 0)
                                            )[0].id;
                                        }
                                    }
                                    setData((current) => ({ ...current, competency_id: newCompId, target_assessment_id: newTargetId }));
                                }}
                                disabled={!data.root_competency_id || availableSubcompetencies.length === 0}
                                className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-slate-100"
                            >
                                <option value="">{availableSubcompetencies.length === 0 ? 'Belum ada subkompetensi' : 'Gunakan kompetensi utama'}</option>
                                {availableSubcompetencies.map((competency) => {
                                    const displayName = competency.name.length > 70 ? competency.name.substring(0, 70) + '…' : competency.name;
                                    return (
                                        <option key={competency.id} value={competency.id}>
                                            {displayName}
                                        </option>
                                    );
                                })}
                            </select>
                            {data.root_competency_id && availableSubcompetencies.length === 0 && <p className="mt-1 text-xs text-slate-500">Soal akan diklasifikasikan langsung ke kompetensi utama.</p>}
                        </label>}

                        {/* ── Dropdown: Masukkan ke paket ── */}
                        {activeSubCompetencyId && assessments.length > 0 && (
                            <label className="text-sm font-medium text-slate-700 sm:col-span-2">
                                Masukkan ke paket{' '}
                                <span className="font-normal text-slate-500">(opsional)</span>
                                <select
                                    value={data.target_assessment_id === '' ? (suggestedAssessmentId ?? '') : data.target_assessment_id}
                                    onChange={(e) =>
                                        setData('target_assessment_id', e.target.value === '' ? '' : Number(e.target.value))
                                    }
                                    className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                                >
                                    <option value="">— Tidak dimasukkan ke paket —</option>
                                    {assessments
                                        .filter((a) => a.grade_level === Number(data.grade_level))
                                        .map((a) => {
                                            const count = a.competency_coverage[activeSubCompetencyId] ?? 0;
                                            const isSuggested = a.id === suggestedAssessmentId;
                                            return (
                                                <option key={a.id} value={a.id}>
                                                    {isSuggested ? '★ ' : ''}{a.title}
                                                    {count === 0
                                                        ? ' — belum ada soal sub-kompetensi ini'
                                                        : ` — sudah ${count} soal`}
                                                </option>
                                            );
                                        })}
                                </select>
                                <p className="mt-1 text-xs text-slate-400">
                                    ★ = disarankan (paket terlama yang belum punya soal sub-kompetensi ini)
                                </p>
                            </label>
                        )}

                        {usesQuestionBlueprints && <label className="text-sm font-medium text-slate-700">
                            <div className="flex items-center justify-between">
                                <span>Tipe soal <span className="font-normal text-slate-500">(dapat disesuaikan)</span></span>
                                {data.question_blueprint_id && (
                                    <a
                                        href={route('question-types.edit', data.question_blueprint_id)}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="inline-flex items-center gap-1 text-xs font-semibold text-emerald-700 hover:text-emerald-900"
                                        title="Buka customize tipe soal di tab baru"
                                    >
                                        <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                                            <path d="m13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" />
                                        </svg>
                                        <span>Customize</span>
                                    </a>
                                )}
                            </div>
                            <div className="mt-1 flex items-center gap-2">
                                <select value={data.question_blueprint_id} onChange={(event) => setData('question_blueprint_id', event.target.value)} disabled={!data.competency_id} className="block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-slate-100">
                                    <option value="">Tanpa tipe khusus</option>
                                    {availableQuestionBlueprints.map((blueprint) => <option key={blueprint.id} value={blueprint.id}>{defaultQuestionBlueprints.some((item) => item.id === blueprint.id) ? 'Default · ' : ''}{blueprint.name}</option>)}
                                </select>
                                {data.question_blueprint_id && (
                                    <a
                                        href={route('question-types.edit', data.question_blueprint_id)}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="inline-flex shrink-0 items-center justify-center rounded-lg border border-slate-300 bg-white p-2.5 text-slate-700 shadow-sm hover:bg-slate-50 hover:text-emerald-700"
                                        title="Customize tipe soal ini di tab baru"
                                    >
                                        <svg className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                            <path d="m13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" />
                                        </svg>
                                    </a>
                                )}
                            </div>
                            <InputError message={errors.question_blueprint_id} className="mt-1" />
                        </label>}

                        <label className="text-sm font-medium text-slate-700">
                            Bentuk soal
                            <select
                                value={data.type}
                                onChange={(event) => setData('type', event.target.value as QuestionForm['type'])}
                                className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                            >
                                {questionTypes.map((type) => <option key={type.value} value={type.value}>{type.label}{type.active ? '' : ' · nonaktif (soal lama)'}</option>)}
                            </select>
                        </label>
                        <label className="text-sm font-medium text-slate-700">
                            Level soal
                            <select
                                value={data.difficulty}
                                onChange={(event) => setData('difficulty', Number(event.target.value))}
                                className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                            >
                                <option value={1}>Level 1 · Mudah</option>
                                <option value={2}>Level 2 · Sedang</option>
                                <option value={3}>Level 3 · Sulit</option>
                            </select>
                        </label>
                        <label className="text-sm font-medium text-slate-700">
                            Level kognitif
                            <input
                                value={data.cognitive_level}
                                onChange={(event) => setData('cognitive_level', event.target.value)}
                                placeholder="Contoh: interpretasi dan integrasi"
                                className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                            />
                        </label>
                    </div>
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="font-semibold text-slate-900">Isi soal</h2>
                    <div className="mt-4 space-y-4">
                        <label className="block text-sm font-medium text-slate-700">
                            Judul internal <span className="font-normal text-slate-500">(opsional)</span>
                            <input value={data.title} onChange={(event) => setData('title', event.target.value)} placeholder="Contoh: Diagram kandungan makanan" className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500" />
                            <span className="mt-1 block text-xs font-normal text-slate-500">Hanya untuk membantu guru mengenali soal di bank soal. Tidak ditampilkan kepada siswa.</span>
                        </label>
                        <div className="space-y-3">
                            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Stimulus opsional</p>
                            <div role="tablist" aria-label="Jenis stimulus" className="grid grid-cols-3 overflow-hidden rounded-xl border border-slate-200 bg-slate-50 p-1">
                                {([
                                    { id: 'text' as const, icon: '¶', label: 'Tulisan', filled: Boolean(data.stimulus.trim()) },
                                    { id: 'visual' as const, icon: '▥', label: 'Tabel/diagram', filled: data.stimulus_visual_type !== 'none' },
                                    { id: 'image' as const, icon: '▧', label: 'Gambar', filled: Boolean((data.stimulus_image_source === 'template' && data.stimulus_svg_dimension_a) || selectedIllustrationUrl || (question?.illustration_url && !data.remove_stimulus_image)) },
                                ]).map((tab) => (
                                    <button key={tab.id} type="button" role="tab" aria-selected={activeStimulusTab === tab.id} onClick={() => setActiveStimulusTab((current) => current === tab.id ? null : tab.id)} className={`relative flex min-h-12 items-center justify-center gap-1.5 rounded-lg px-2 py-2 text-xs font-semibold transition sm:text-sm ${activeStimulusTab === tab.id ? 'bg-white text-indigo-700 shadow-sm ring-1 ring-slate-200' : 'text-slate-600 hover:bg-white/70 hover:text-slate-900'}`}>
                                        <span aria-hidden="true">{tab.icon}</span><span className="truncate">{tab.label}</span>{tab.filled && <span className="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-emerald-500" title="Terisi" />}
                                    </button>
                                ))}
                            </div>
                            {activeStimulusTab === 'text' && (
                                <div role="tabpanel" className="overflow-hidden rounded-xl border border-slate-200 bg-white">
                                    <label className="block p-4 text-sm font-medium text-slate-700">
                                        Teks stimulus
                                        <textarea value={data.stimulus} onChange={(event) => setData('stimulus', event.target.value)} rows={5} className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500" />
                                        <span className="mt-1 block text-xs font-normal text-slate-500">Gunakan untuk bacaan, cerita, atau informasi tertulis pendamping soal.</span>
                                    </label>
                                </div>
                            )}
                        </div>
                        {activeStimulusTab === 'visual' && <div role="tabpanel" className="rounded-xl border border-indigo-200 bg-indigo-50/40 p-4">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                                <label className="block flex-1 text-sm font-medium text-slate-700">
                                    Bentuk stimulus terstruktur <span className="font-normal text-slate-500">(opsional)</span>
                                    <select value={data.stimulus_visual_type} onChange={(event) => setData('stimulus_visual_type', event.target.value as StimulusVisualType)} className="mt-1 block w-full rounded-lg border-slate-300 bg-white focus:border-indigo-500 focus:ring-indigo-500">
                                        <option value="none">Tanpa tabel atau diagram</option>
                                        <option value="table">Tabel data</option>
                                        <option value="bar_chart">Diagram batang</option>
                                        <option value="pictogram">Piktogram</option>
                                        <option value="pie_chart">Diagram lingkaran</option>
                                    </select>
                                </label>
                                {data.stimulus_visual_type !== 'none' && (
                                    <label className="block flex-1 text-sm font-medium text-slate-700">
                                        Judul tabel/diagram <span className="font-normal text-slate-500">(opsional)</span>
                                        <input value={data.stimulus_visual_title} onChange={(event) => setData('stimulus_visual_title', event.target.value)} placeholder="Contoh: Penjualan Buku Harian" className="mt-1 block w-full rounded-lg border-slate-300 bg-white focus:border-indigo-500 focus:ring-indigo-500" />
                                    </label>
                                )}
                            </div>

                            {data.stimulus_visual_type === 'table' && (
                                <div className="mt-4 space-y-4">
                                    <div>
                                        <div className="flex items-center justify-between gap-3">
                                            <p className="text-sm font-semibold text-slate-800">Judul kolom</p>
                                            <button
                                                type="button"
                                                disabled={data.stimulus_table_headers.length >= 6}
                                                onClick={() => setData((current) => ({
                                                    ...current,
                                                    stimulus_table_headers: [...current.stimulus_table_headers, `Kolom ${current.stimulus_table_headers.length + 1}`],
                                                    stimulus_table_rows: current.stimulus_table_rows.map((row) => [...row, '']),
                                                }))}
                                                className="text-xs font-bold text-indigo-700 disabled:opacity-40"
                                            >+ Tambah kolom</button>
                                        </div>
                                        <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                            {data.stimulus_table_headers.map((header, columnIndex) => (
                                                <div key={columnIndex} className="flex gap-2">
                                                    <input
                                                        value={header}
                                                        aria-label={`Judul kolom ${columnIndex + 1}`}
                                                        onChange={(event) => {
                                                            const headers = [...data.stimulus_table_headers];
                                                            headers[columnIndex] = event.target.value;
                                                            setData('stimulus_table_headers', headers);
                                                        }}
                                                        className="min-w-0 flex-1 rounded-lg border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                    />
                                                    <button
                                                        type="button"
                                                        aria-label={`Hapus kolom ${columnIndex + 1}`}
                                                        disabled={data.stimulus_table_headers.length <= 2}
                                                        onClick={() => setData((current) => ({
                                                            ...current,
                                                            stimulus_table_headers: current.stimulus_table_headers.filter((_, index) => index !== columnIndex),
                                                            stimulus_table_rows: current.stimulus_table_rows.map((row) => row.filter((_, index) => index !== columnIndex)),
                                                        }))}
                                                        className="rounded-lg px-2 text-rose-600 disabled:opacity-30"
                                                    >×</button>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                    <div className="overflow-x-auto">
                                        <table className="w-full min-w-[520px] border-separate border-spacing-2">
                                            <thead><tr>{data.stimulus_table_headers.map((header, index) => <th key={index} className="text-left text-xs font-semibold text-slate-500">{header || `Kolom ${index + 1}`}</th>)}<th className="w-8" /></tr></thead>
                                            <tbody>
                                                {data.stimulus_table_rows.map((row, rowIndex) => (
                                                    <tr key={rowIndex}>
                                                        {data.stimulus_table_headers.map((_, columnIndex) => (
                                                            <td key={columnIndex}>
                                                                <input
                                                                    value={row[columnIndex] || ''}
                                                                    aria-label={`Baris ${rowIndex + 1}, kolom ${columnIndex + 1}`}
                                                                    onChange={(event) => {
                                                                        const rows = data.stimulus_table_rows.map((item) => [...item]);
                                                                        rows[rowIndex][columnIndex] = event.target.value;
                                                                        setData('stimulus_table_rows', rows);
                                                                    }}
                                                                    className="w-full rounded-lg border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                                />
                                                            </td>
                                                        ))}
                                                        <td><button type="button" aria-label={`Hapus baris ${rowIndex + 1}`} disabled={data.stimulus_table_rows.length <= 1} onClick={() => setData('stimulus_table_rows', data.stimulus_table_rows.filter((_, index) => index !== rowIndex))} className="px-2 text-rose-600 disabled:opacity-30">×</button></td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                    <button type="button" disabled={data.stimulus_table_rows.length >= 15} onClick={() => setData('stimulus_table_rows', [...data.stimulus_table_rows, data.stimulus_table_headers.map(() => '')])} className="text-sm font-semibold text-indigo-700 disabled:opacity-40">+ Tambah baris</button>
                                    <InputError message={errors.stimulus_table_headers || errors.stimulus_table_rows} />
                                </div>
                            )}

                            {data.stimulus_visual_type === 'bar_chart' && (
                                <div className="mt-4 space-y-4">
                                    <div className="grid gap-3 sm:grid-cols-4">
                                        <label className="text-sm font-medium text-slate-700">Jenis diagram batang<select value={data.stimulus_chart_mode} onChange={(event) => setData('stimulus_chart_mode', event.target.value as 'single' | 'grouped')} className="mt-1 block w-full rounded-lg border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500"><option value="single">Tunggal · satu seri</option><option value="grouped">Berkelompok · beberapa seri</option></select></label>
                                        <label className="text-sm font-medium text-slate-700">Label sumbu X <span className="font-normal text-slate-500">(opsional)</span><input value={data.stimulus_chart_x_axis_label} onChange={(event) => setData('stimulus_chart_x_axis_label', event.target.value)} placeholder="Contoh: Hari" className="mt-1 block w-full rounded-lg border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500" /></label>
                                        <label className="text-sm font-medium text-slate-700">Label sumbu Y <span className="font-normal text-slate-500">(opsional)</span><input value={data.stimulus_chart_y_axis_label} onChange={(event) => setData('stimulus_chart_y_axis_label', event.target.value)} placeholder="Contoh: Jumlah buku" className="mt-1 block w-full rounded-lg border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500" /></label>
                                        <label className="text-sm font-medium text-slate-700">Batas maksimum Y <span className="font-normal text-slate-500">(otomatis)</span><input type="number" min="0.01" step="any" value={data.stimulus_chart_maximum} onChange={(event) => setData('stimulus_chart_maximum', event.target.value)} placeholder="Otomatis" className="mt-1 block w-full rounded-lg border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500" /></label>
                                    </div>
                                    {data.stimulus_chart_mode === 'single' ? <div>
                                        <p className="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">Untuk membuat label legenda seperti <strong>Lemak</strong> dan <strong>Protein</strong>, ubah jenis diagram menjadi <strong>Berkelompok · beberapa seri</strong>.</p>
                                        <div className="grid grid-cols-[minmax(0,1fr)_minmax(100px,0.45fr)_32px] gap-2 text-xs font-semibold text-slate-500"><span>Label/kategori</span><span>Nilai</span><span /></div>
                                        <div className="mt-2 space-y-2">
                                            {data.stimulus_chart_items.map((item, index) => (
                                                <div key={index} className="grid grid-cols-[minmax(0,1fr)_minmax(100px,0.45fr)_32px] gap-2">
                                                    <input value={item.label} aria-label={`Label data ${index + 1}`} onChange={(event) => {
                                                        const items = [...data.stimulus_chart_items];
                                                        items[index] = { ...items[index], label: event.target.value };
                                                        setData('stimulus_chart_items', items);
                                                    }} placeholder={`Kategori ${index + 1}`} className="min-w-0 rounded-lg border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                                    <input type="number" min="0" step="any" value={item.value} aria-label={`Nilai data ${index + 1}`} onChange={(event) => {
                                                        const items = [...data.stimulus_chart_items];
                                                        items[index] = { ...items[index], value: event.target.value };
                                                        setData('stimulus_chart_items', items);
                                                    }} className="min-w-0 rounded-lg border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                                    <button type="button" aria-label={`Hapus data ${index + 1}`} disabled={data.stimulus_chart_items.length <= 2} onClick={() => setData('stimulus_chart_items', data.stimulus_chart_items.filter((_, itemIndex) => itemIndex !== index))} className="text-rose-600 disabled:opacity-30">×</button>
                                                </div>
                                            ))}
                                        </div>
                                        <button type="button" disabled={data.stimulus_chart_items.length >= 12} onClick={() => setData('stimulus_chart_items', [...data.stimulus_chart_items, { label: '', value: '' }])} className="mt-3 text-sm font-semibold text-indigo-700 disabled:opacity-40">+ Tambah data</button>
                                    </div> : <div className="space-y-4">
                                        <div>
                                            <div className="flex items-center justify-between gap-3">
                                                <div>
                                                    <p className="text-sm font-semibold text-slate-800">Label seri dan legenda</p>
                                                    <p className="mt-0.5 text-xs text-slate-500">Isi nama pembanding yang akan tampil pada legenda, misalnya Lemak dan Protein.</p>
                                                </div>
                                                <button type="button" disabled={data.stimulus_chart_series_labels.length >= 4} onClick={() => setData((current) => ({
                                                    ...current,
                                                    stimulus_chart_series_labels: [...current.stimulus_chart_series_labels, `Seri ${current.stimulus_chart_series_labels.length + 1}`],
                                                    stimulus_chart_grouped_categories: current.stimulus_chart_grouped_categories.map((category) => ({ ...category, values: [...category.values, ''] })),
                                                }))} className="text-xs font-bold text-indigo-700 disabled:opacity-40">+ Tambah seri</button>
                                            </div>
                                            <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                                {data.stimulus_chart_series_labels.map((label, seriesIndex) => (
                                                    <label key={seriesIndex} className="block text-xs font-semibold text-slate-600">
                                                        Label seri/legenda {seriesIndex + 1}
                                                        <div className="mt-1 flex gap-2">
                                                            <span className="mt-2 h-5 w-5 shrink-0 rounded" style={{ backgroundColor: ['#38bdf8', '#f59e0b', '#34d399', '#a78bfa'][seriesIndex] }} />
                                                            <input value={label} aria-label={`Label seri/legenda ${seriesIndex + 1}`} onChange={(event) => {
                                                                const labels = [...data.stimulus_chart_series_labels];
                                                                labels[seriesIndex] = event.target.value;
                                                                setData('stimulus_chart_series_labels', labels);
                                                            }} placeholder={seriesIndex === 0 ? 'Contoh: Lemak' : seriesIndex === 1 ? 'Contoh: Protein' : `Seri ${seriesIndex + 1}`} className="min-w-0 flex-1 rounded-lg border-slate-300 bg-white text-sm font-normal focus:border-indigo-500 focus:ring-indigo-500" />
                                                            <button type="button" aria-label={`Hapus seri ${seriesIndex + 1}`} disabled={data.stimulus_chart_series_labels.length <= 2} onClick={() => setData((current) => ({
                                                                ...current,
                                                                stimulus_chart_series_labels: current.stimulus_chart_series_labels.filter((_, index) => index !== seriesIndex),
                                                                stimulus_chart_grouped_categories: current.stimulus_chart_grouped_categories.map((category) => ({ ...category, values: category.values.filter((_, index) => index !== seriesIndex) })),
                                                            }))} className="px-2 text-rose-600 disabled:opacity-30">×</button>
                                                        </div>
                                                    </label>
                                                ))}
                                            </div>
                                        </div>
                                        <div className="overflow-x-auto">
                                            <table className="w-full min-w-[620px] border-separate border-spacing-2">
                                                <thead><tr><th className="text-left text-xs font-semibold text-slate-500">Kategori</th>{data.stimulus_chart_series_labels.map((label, index) => <th key={index} className="text-left text-xs font-semibold text-slate-500">{label || `Seri ${index + 1}`}</th>)}<th className="w-8" /></tr></thead>
                                                <tbody>{data.stimulus_chart_grouped_categories.map((category, categoryIndex) => (
                                                    <tr key={categoryIndex}>
                                                        <td><input value={category.label} aria-label={`Kategori kelompok ${categoryIndex + 1}`} onChange={(event) => {
                                                            const categories = data.stimulus_chart_grouped_categories.map((item) => ({ ...item, values: [...item.values] }));
                                                            categories[categoryIndex].label = event.target.value;
                                                            setData('stimulus_chart_grouped_categories', categories);
                                                        }} placeholder={`Kategori ${categoryIndex + 1}`} className="w-full rounded-lg border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500" /></td>
                                                        {data.stimulus_chart_series_labels.map((_, seriesIndex) => <td key={seriesIndex}><input type="number" min="0" step="any" value={category.values[seriesIndex] || ''} aria-label={`Nilai kategori ${categoryIndex + 1}, seri ${seriesIndex + 1}`} onChange={(event) => {
                                                            const categories = data.stimulus_chart_grouped_categories.map((item) => ({ ...item, values: [...item.values] }));
                                                            categories[categoryIndex].values[seriesIndex] = event.target.value;
                                                            setData('stimulus_chart_grouped_categories', categories);
                                                        }} className="w-full rounded-lg border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500" /></td>)}
                                                        <td><button type="button" aria-label={`Hapus kategori ${categoryIndex + 1}`} disabled={data.stimulus_chart_grouped_categories.length <= 2} onClick={() => setData('stimulus_chart_grouped_categories', data.stimulus_chart_grouped_categories.filter((_, index) => index !== categoryIndex))} className="px-2 text-rose-600 disabled:opacity-30">×</button></td>
                                                    </tr>
                                                ))}</tbody>
                                            </table>
                                        </div>
                                        <button type="button" disabled={data.stimulus_chart_grouped_categories.length >= 8} onClick={() => setData('stimulus_chart_grouped_categories', [...data.stimulus_chart_grouped_categories, { label: '', values: data.stimulus_chart_series_labels.map(() => '') }])} className="text-sm font-semibold text-indigo-700 disabled:opacity-40">+ Tambah kategori</button>
                                    </div>}
                                    <InputError message={errors.stimulus_chart_items || errors.stimulus_chart_series_labels || errors.stimulus_chart_grouped_categories || errors.stimulus_chart_maximum} />
                                </div>
                            )}

                            {data.stimulus_visual_type === 'pictogram' && (
                                <div className="mt-4 space-y-4">
                                    <div className="grid gap-3 sm:grid-cols-3">
                                        <label className="text-sm font-medium text-slate-700">
                                            Simbol
                                            <input value={data.stimulus_pictogram_symbol} onChange={(event) => setData('stimulus_pictogram_symbol', event.target.value)} placeholder="Contoh: 📘 atau ●" className="mt-1 block w-full rounded-lg border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                        </label>
                                        <label className="text-sm font-medium text-slate-700">
                                            Nilai tiap simbol
                                            <input type="number" min="0.01" step="any" value={data.stimulus_pictogram_legend_value} onChange={(event) => setData('stimulus_pictogram_legend_value', event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                        </label>
                                        <label className="text-sm font-medium text-slate-700">
                                            Satuan <span className="font-normal text-slate-500">(opsional)</span>
                                            <input value={data.stimulus_pictogram_unit} onChange={(event) => setData('stimulus_pictogram_unit', event.target.value)} placeholder="Contoh: buku" className="mt-1 block w-full rounded-lg border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                        </label>
                                    </div>
                                    <div>
                                        <div className="grid grid-cols-[minmax(0,1fr)_minmax(100px,0.45fr)_32px] gap-2 text-xs font-semibold text-slate-500"><span>Label/kategori</span><span>Jumlah sebenarnya</span><span /></div>
                                        <div className="mt-2 space-y-2">
                                            {data.stimulus_pictogram_items.map((item, index) => (
                                                <div key={index} className="grid grid-cols-[minmax(0,1fr)_minmax(100px,0.45fr)_32px] gap-2">
                                                    <input value={item.label} aria-label={`Label piktogram ${index + 1}`} onChange={(event) => {
                                                        const items = [...data.stimulus_pictogram_items];
                                                        items[index] = { ...items[index], label: event.target.value };
                                                        setData('stimulus_pictogram_items', items);
                                                    }} placeholder={`Kategori ${index + 1}`} className="min-w-0 rounded-lg border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                                    <input type="number" min="0" step="any" value={item.value} aria-label={`Nilai piktogram ${index + 1}`} onChange={(event) => {
                                                        const items = [...data.stimulus_pictogram_items];
                                                        items[index] = { ...items[index], value: event.target.value };
                                                        setData('stimulus_pictogram_items', items);
                                                    }} className="min-w-0 rounded-lg border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                                    <button type="button" aria-label={`Hapus data piktogram ${index + 1}`} disabled={data.stimulus_pictogram_items.length <= 2} onClick={() => setData('stimulus_pictogram_items', data.stimulus_pictogram_items.filter((_, itemIndex) => itemIndex !== index))} className="text-rose-600 disabled:opacity-30">×</button>
                                                </div>
                                            ))}
                                        </div>
                                        <button type="button" disabled={data.stimulus_pictogram_items.length >= 12} onClick={() => setData('stimulus_pictogram_items', [...data.stimulus_pictogram_items, { label: '', value: '' }])} className="mt-3 text-sm font-semibold text-indigo-700 disabled:opacity-40">+ Tambah data</button>
                                        <p className="mt-2 text-xs text-slate-500">Nilai yang tidak genap terhadap legenda akan ditampilkan sebagai bagian dari simbol.</p>
                                    </div>
                                    <InputError message={errors.stimulus_pictogram_symbol || errors.stimulus_pictogram_legend_value || errors.stimulus_pictogram_items} />
                                </div>
                            )}

                            {data.stimulus_visual_type === 'pie_chart' && (
                                <div className="mt-3 space-y-3">
                                    <div className="rounded-xl border border-indigo-200 bg-indigo-50/60 p-2.5">
                                        <div className="grid gap-2.5 sm:grid-cols-[minmax(190px,1.1fr)_minmax(170px,0.7fr)_minmax(130px,0.6fr)]">
                                            <label className="text-sm font-medium text-slate-700">Dasar total
                                                <select value={data.stimulus_pie_total_mode} onChange={(event) => changePieTotalMode(event.target.value as PieTotalMode)} className="mt-1 block h-10 w-full rounded-lg border-slate-300 bg-white py-1 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                    <option value="percentage">Persentase (100%)</option>
                                                    <option value="degrees">Sudut (360°)</option>
                                                    <option value="custom">Jumlah khusus</option>
                                                </select>
                                            </label>
                                            <div className="text-sm font-medium text-slate-700"><span>Total</span>
                                                <div className="mt-1 flex h-10 items-center overflow-hidden rounded-lg border border-slate-300 bg-white focus-within:border-indigo-500 focus-within:ring-1 focus-within:ring-indigo-500">
                                                    <input type="number" min="0.01" max="1000000000" step="any" value={data.stimulus_pie_total} disabled={data.stimulus_pie_total_mode !== 'custom'} onChange={(event) => changePieCustomTotal(event.target.value)} className="h-full min-w-0 flex-1 rounded-none border-0 bg-transparent py-1 text-sm focus:ring-0 disabled:bg-slate-100" />
                                                    <span className="px-2 text-xs font-bold text-slate-500">{data.stimulus_pie_total_mode === 'percentage' ? '%' : data.stimulus_pie_total_mode === 'degrees' ? '°' : data.stimulus_pie_unit || 'item'}</span>
                                                    {data.stimulus_pie_total_mode === 'custom' && <label className="flex h-full items-center gap-1.5 border-l border-slate-200 px-2 text-xs font-semibold text-slate-700"><input type="checkbox" checked={data.stimulus_pie_total_asked} onChange={(event) => setData('stimulus_pie_total_asked', event.target.checked)} className="rounded border-slate-300 text-violet-600 focus:ring-violet-500" />Ditanya</label>}
                                                </div>
                                            </div>
                                            <label className="text-sm font-medium text-slate-700">Satuan <span className="font-normal text-slate-500">(opsional)</span>
                                                <input value={data.stimulus_pie_unit} onChange={(event) => setData('stimulus_pie_unit', event.target.value)} placeholder="siswa" className="mt-1 block h-10 w-full rounded-lg border-slate-300 bg-white py-1 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                            </label>
                                        </div>
                                        <p className="mt-1.5 text-[11px] text-indigo-700">Wajib satu Auto untuk sisa total · Ditanya boleh lebih dari satu.</p>
                                    </div>
                                    <div>
                                        <div className="grid grid-cols-[minmax(0,1fr)_minmax(90px,0.42fr)_64px_64px_32px] gap-2 text-xs font-semibold text-slate-500"><span>Label/kategori</span><span>Nilai</span><span className="text-center">Auto</span><span className="text-center">Ditanya</span><span /></div>
                                        <div className="mt-1.5 space-y-1.5">
                                            {data.stimulus_pie_items.map((item, index) => (
                                                <div key={index} className="grid grid-cols-[minmax(0,1fr)_minmax(90px,0.42fr)_64px_64px_32px] items-center gap-2">
                                                    <div className="min-w-0 self-start">
                                                        <input value={item.label} aria-invalid={Boolean(formErrors[`stimulus_pie_items.${index}.label`])} aria-label={`Label diagram lingkaran ${index + 1}`} onChange={(event) => {
                                                            const items = [...data.stimulus_pie_items];
                                                            items[index] = { ...items[index], label: event.target.value };
                                                            updatePieItems(items);
                                                        }} placeholder={`Kategori ${index + 1}`} className={`h-10 w-full min-w-0 rounded-lg bg-white py-1 text-sm focus:ring-indigo-500 ${formErrors[`stimulus_pie_items.${index}.label`] ? 'border-rose-400 focus:border-rose-500' : 'border-slate-300 focus:border-indigo-500'}`} />
                                                        <InputError message={formErrors[`stimulus_pie_items.${index}.label`]} className="mt-1 text-xs" />
                                                    </div>
                                                    <div className="min-w-0 self-start">
                                                        <input type="number" min="0" step="any" value={item.value} disabled={item.auto_calculate} aria-invalid={Boolean(formErrors[`stimulus_pie_items.${index}.value`])} aria-label={`Nilai diagram lingkaran ${index + 1}`} onChange={(event) => {
                                                            const items = [...data.stimulus_pie_items];
                                                            items[index] = { ...items[index], value: event.target.value };
                                                            updatePieItems(items);
                                                        }} className={`h-10 w-full min-w-0 rounded-lg bg-white py-1 text-sm focus:ring-indigo-500 disabled:bg-indigo-50 disabled:text-indigo-900 ${formErrors[`stimulus_pie_items.${index}.value`] ? 'border-rose-400 focus:border-rose-500' : 'border-slate-300 focus:border-indigo-500'}`} />
                                                        <InputError message={formErrors[`stimulus_pie_items.${index}.value`]} className="mt-1 text-xs" />
                                                    </div>
                                                    <label className="flex h-10 items-center justify-center"><input type="checkbox" aria-label={`Hitung otomatis kategori ${index + 1}`} checked={item.auto_calculate} onChange={(event) => {
                                                        if (!event.target.checked) return;
                                                        updatePieItems(data.stimulus_pie_items.map((current, itemIndex) => ({ ...current, auto_calculate: itemIndex === index })));
                                                    }} className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" /></label>
                                                    <label className="flex h-10 items-center justify-center"><input type="checkbox" aria-label={`Tanyakan kategori ${index + 1}`} checked={item.asked} onChange={(event) => updatePieItems(data.stimulus_pie_items.map((current, itemIndex) => itemIndex === index ? { ...current, asked: event.target.checked } : current))} className="rounded border-slate-300 text-violet-600 focus:ring-violet-500" /></label>
                                                    <button type="button" aria-label={`Hapus data diagram lingkaran ${index + 1}`} disabled={item.auto_calculate || data.stimulus_pie_items.length <= 2} onClick={() => updatePieItems(data.stimulus_pie_items.filter((_, itemIndex) => itemIndex !== index))} className="flex h-10 items-center justify-center text-rose-600 disabled:opacity-30">×</button>
                                                </div>
                                            ))}
                                        </div>
                                        <div className="mt-2 flex flex-wrap items-center justify-between gap-2"><button type="button" disabled={data.stimulus_pie_items.length >= 12} onClick={() => updatePieItems([...data.stimulus_pie_items, { label: '', value: '0', asked: false, auto_calculate: false }])} className="text-sm font-semibold text-indigo-700 disabled:opacity-40">+ Tambah data</button><p className="text-[11px] text-slate-500">Ditanya tetap membentuk irisan, tetapi nilainya tampil sebagai ?</p></div>
                                    </div>
                                    <InputError message={errors.stimulus_pie_items || errors.stimulus_pie_total || errors.stimulus_pie_unit} />
                                </div>
                            )}

                            {data.stimulus_visual_type !== 'none' && (
                                <div className="mt-5 border-t border-indigo-200 pt-4">
                                    <p className="mb-2 text-xs font-bold uppercase tracking-wide text-indigo-700">Preview stimulus</p>
                                    <StimulusVisual visual={stimulusVisualFromForm(data)} />
                                </div>
                            )}
                            </div>}
                        {activeStimulusTab === 'image' && <div role="tabpanel" className="rounded-xl border border-dashed border-slate-300 bg-white p-4">
                            <div className="grid grid-cols-2 rounded-lg bg-slate-100 p-1" role="tablist" aria-label="Sumber gambar stimulus">
                                <button type="button" role="tab" aria-selected={data.stimulus_image_source === 'upload'} onClick={() => setData((current) => ({ ...current, stimulus_image_source: 'upload', remove_stimulus_image: current.stimulus_image_source !== 'upload' && !current.stimulus_image }))} className={`rounded-md px-3 py-2 text-sm font-semibold ${data.stimulus_image_source === 'upload' ? 'bg-white text-emerald-700 shadow-sm' : 'text-slate-600'}`}>Upload gambar</button>
                                <button type="button" role="tab" aria-selected={data.stimulus_image_source === 'template'} onClick={() => setData((current) => ({ ...current, stimulus_image_source: 'template', remove_stimulus_image: false }))} className={`rounded-md px-2 py-2 text-sm font-semibold ${data.stimulus_image_source === 'template' ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-600'}`}>Template gambar</button>
                            </div>
                            {data.stimulus_image_source === 'upload' ? <div className="mt-4">
                                <label className="block text-sm font-medium text-slate-700">
                                    File gambar <span className="font-normal text-slate-500">(opsional)</span>
                                    <input
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        onChange={(event) => {
                                            setData('stimulus_image', event.target.files?.[0] || null);
                                            setData('remove_stimulus_image', false);
                                            setData('stimulus_upload_zoom', 1);
                                            setData('stimulus_upload_offset_x', 0);
                                            setData('stimulus_upload_offset_y', 0);
                                        }}
                                        className="mt-2 block w-full text-sm text-slate-600 file:mr-4 file:rounded-lg file:border-0 file:bg-emerald-100 file:px-4 file:py-2 file:font-semibold file:text-emerald-700 hover:file:bg-emerald-200"
                                    />
                                </label>
                                <p className="mt-2 text-xs text-slate-500">Format JPG, PNG, atau WebP. File awal maksimal 10 MB dan otomatis dikompresi menjadi maksimal 200 KB.</p>
                            </div> : <div className="mt-4 space-y-4">
                                <div className="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                    <p className="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">Pilih gambar</p>
                                    <div className={`grid gap-2 sm:grid-cols-2 ${hasSvgSubfamilies ? 'lg:grid-cols-4' : 'lg:grid-cols-3'}`} aria-label="Katalog template gambar berjenjang">
                                        <label className="text-xs font-semibold text-slate-600">1. Jenis
                                            <select value={selectedSvgCategory} onChange={(event) => selectSvgTemplate(stimulusSvgTemplates.find((template) => template.category === event.target.value))} className="mt-1 block w-full rounded-lg border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                {Array.from(new Map(stimulusSvgTemplates.map((template) => [template.category, template.category_label])).entries()).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                            </select>
                                        </label>
                                        <label className="text-xs font-semibold text-slate-600">2. Kelompok bentuk
                                            <select value={selectedSvgFamily} onChange={(event) => selectSvgTemplate(stimulusSvgTemplates.find((template) => template.category === selectedSvgCategory && template.family === event.target.value))} className="mt-1 block w-full rounded-lg border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                {svgFamilies.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                            </select>
                                        </label>
                                        {hasSvgSubfamilies && <label className="text-xs font-semibold text-slate-600">3. Bangun dasar
                                            <select value={selectedSvgSubfamily} onChange={(event) => selectSvgTemplate(stimulusSvgTemplates.find((template) => template.category === selectedSvgCategory && template.family === selectedSvgFamily && template.subfamily === event.target.value))} className="mt-1 block w-full rounded-lg border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                {svgSubfamilies.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                            </select>
                                        </label>}
                                        <label className="text-xs font-semibold text-slate-600">{hasSvgSubfamilies ? '4. Kombinasi/arsiran' : '3. Variasi'}
                                            <select value={data.stimulus_svg_template} onChange={(event) => selectSvgTemplate(stimulusSvgTemplates.find((template) => template.value === event.target.value))} className="mt-1 block w-full rounded-lg border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                {svgVariations.map((template) => <option key={template.value} value={template.value}>{template.label}</option>)}
                                            </select>
                                        </label>
                                    </div>
                                    <p className="mt-2 truncate text-xs text-indigo-700">{selectedSvgTemplate?.category_label} › {selectedSvgTemplate?.family_label}{selectedSvgTemplate?.subfamily_label && <> › {selectedSvgTemplate.subfamily_label}</>} › <strong>{selectedSvgTemplate?.label}</strong></p>
                                </div>
                                {usesCustomFractionModels && <div className="space-y-3 rounded-xl border border-sky-200 bg-sky-50 p-4">
                                    <label className="block text-sm font-semibold text-slate-700">Jumlah lingkaran
                                        <select value={data.stimulus_fraction_models.length} onChange={(event) => {
                                            const count = Number(event.target.value);
                                            const models = Array.from({ length: count }, (_, index) => data.stimulus_fraction_models[index] || { numerator: String(index + 1), denominator: String((index + 1) * 2), shaded_parts: Array.from({ length: index + 1 }, (_, partIndex) => partIndex) });
                                            updateFractionModels(models);
                                        }} className="mt-1 block w-full rounded-lg border-slate-300 bg-white focus:border-indigo-500 focus:ring-indigo-500">
                                            {[2, 3, 4].map((count) => <option key={count} value={count}>{count} lingkaran</option>)}
                                        </select>
                                    </label>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        {data.stimulus_fraction_models.map((model, index) => <div key={index} className="rounded-lg border border-sky-200 bg-white p-3">
                                            <p className="mb-2 text-xs font-bold uppercase tracking-wide text-sky-700">Lingkaran {index + 1}</p>
                                            <div className="grid grid-cols-2 gap-2">
                                                <label className="text-xs font-medium text-slate-600">Diarsir<input type="number" min="0" max={Number(model.denominator) || 24} step="1" value={model.numerator} onChange={(event) => updateFractionModels(data.stimulus_fraction_models.map((item, itemIndex) => itemIndex === index ? { ...item, numerator: event.target.value, shaded_parts: Array.from({ length: Math.max(0, Math.min(Number(item.denominator) || 24, Number(event.target.value) || 0)) }, (_, partIndex) => partIndex) } : item))} className="mt-1 block w-full rounded-lg border-slate-300 text-sm" /></label>
                                                <label className="text-xs font-medium text-slate-600">Total bagian<input type="number" min="1" max="24" step="1" value={model.denominator} onChange={(event) => updateFractionModels(data.stimulus_fraction_models.map((item, itemIndex) => {
                                                    if (itemIndex !== index) return item;
                                                    const denominator = Math.max(1, Math.min(24, Number(event.target.value) || 1));
                                                    const shadedParts = (item.shaded_parts || []).filter((partIndex) => partIndex < denominator);
                                                    return { ...item, denominator: event.target.value, numerator: String(shadedParts.length), shaded_parts: shadedParts };
                                                }))} className="mt-1 block w-full rounded-lg border-slate-300 text-sm" /></label>
                                            </div>
                                        </div>)}
                                    </div>
                                    <p className="text-xs text-slate-500">Klik sektor pada preview untuk memilih atau menghapus arsiran. Nilai pecahan tidak ditampilkan pada gambar siswa.</p>
                                </div>}
                                <InputError message={errors.stimulus_svg_overlays} />
                                {isProtractorTemplate && <div className="rounded-xl border border-amber-200 bg-amber-50/70 p-4">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div><p className="text-sm font-bold text-amber-950">Sudut pada busur</p><p className="text-xs text-amber-800">Sudut “Ditanya” dihitung otomatis dari total busur dikurangi sudut lainnya, lalu nilainya disembunyikan dari siswa.</p></div>
                                        <span className={`rounded-full px-2.5 py-1 text-xs font-bold ${Math.abs(protractorTotal - protractorSpan) > 0.000001 ? 'bg-rose-100 text-rose-700' : 'bg-white text-amber-800'}`}>Total {protractorTotal}° / {protractorSpan}°</span>
                                    </div>
                                    <div className="mt-3 space-y-2">
                                        {data.stimulus_protractor_angles.map((angle, index) => <div key={angle.id} className="grid grid-cols-[minmax(70px,0.5fr)_minmax(90px,0.7fr)_auto_36px] items-end gap-2 rounded-lg border border-amber-200 bg-white p-2">
                                            <label className="text-[11px] font-semibold text-slate-600">Label<input value={angle.label} maxLength={12} onChange={(event) => updateProtractorAngles(data.stimulus_protractor_angles.map((item) => item.id === angle.id ? { ...item, label: event.target.value } : item))} className="mt-1 block h-9 w-full rounded-lg border-slate-300 px-2 text-sm" placeholder={`x${index + 1}`} /></label>
                                            <label className="text-[11px] font-semibold text-slate-600">{angle.asked ? 'Besar sudut (otomatis)' : 'Besar sudut'}<input type="number" min="0.01" max={protractorSpan} step="any" value={angle.degrees} disabled={angle.asked} onChange={(event) => updateProtractorAngles(data.stimulus_protractor_angles.map((item) => item.id === angle.id ? { ...item, degrees: event.target.value } : item))} className="mt-1 block h-9 w-full rounded-lg border-slate-300 px-2 text-sm disabled:cursor-not-allowed disabled:bg-amber-50 disabled:text-amber-900" /></label>
                                            <label className="flex h-9 items-center gap-1.5 whitespace-nowrap rounded-lg border border-slate-200 px-2 text-xs font-semibold text-slate-700"><input type="checkbox" checked={angle.asked} onChange={(event) => event.target.checked && updateProtractorAngles(data.stimulus_protractor_angles.map((item) => ({ ...item, asked: item.id === angle.id })))} className="rounded border-slate-300 text-violet-600 focus:ring-violet-500" />Ditanya</label>
                                            <button type="button" aria-label={`Hapus sudut ${angle.label}`} disabled={angle.asked || data.stimulus_protractor_angles.length <= 2} onClick={() => updateProtractorAngles(data.stimulus_protractor_angles.filter((item) => item.id !== angle.id))} className="flex h-9 items-center justify-center rounded-lg text-lg text-rose-600 hover:bg-rose-50 disabled:opacity-30">×</button>
                                        </div>)}
                                    </div>
                                    <button type="button" disabled={data.stimulus_protractor_angles.length >= 8} onClick={() => {
                                        const labels = ['x', 'y', 'z', 'a', 'b', 'c', 'd', 'e'];
                                        const usedLabels = new Set(data.stimulus_protractor_angles.map((angle) => angle.label));
                                        const nextLabel = labels.find((label) => !usedLabels.has(label)) || `s${data.stimulus_protractor_angles.length + 1}`;
                                        updateProtractorAngles([...data.stimulus_protractor_angles, { id: crypto.randomUUID(), label: nextLabel, degrees: '', asked: false }]);
                                    }} className="mt-3 rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-bold text-amber-800 disabled:opacity-40">+ Tambah sudut</button>
                                    <InputError message={errors.stimulus_protractor_angles} className="mt-2" />
                                </div>}
                                {!usesCustomFractionModels && !isProtractorTemplate && <div className="grid gap-3 sm:grid-cols-2">
                                    <label className="text-sm font-medium text-slate-700">{selectedSvgTemplate?.dimension_a_label || 'Ukuran'}<input type="number" min={selectedSvgTemplate?.allow_signed_dimensions ? undefined : selectedSvgTemplate?.integer_dimensions ? 1 : 0.01} step={selectedSvgTemplate?.integer_dimensions ? 1 : 'any'} value={data.stimulus_svg_dimension_a} onChange={(event) => setData('stimulus_svg_dimension_a', event.target.value)} placeholder="Contoh: 8" className="mt-1 block w-full rounded-lg border-slate-300 focus:border-indigo-500 focus:ring-indigo-500" /></label>
                                    {selectedSvgTemplate?.dimension_b_label && <label className="text-sm font-medium text-slate-700">{selectedSvgTemplate.dimension_b_label}<input type="number" min={selectedSvgTemplate.allow_signed_dimensions ? undefined : selectedSvgTemplate.integer_dimensions ? 1 : 0.01} step={selectedSvgTemplate.integer_dimensions ? 1 : 'any'} value={data.stimulus_svg_dimension_b} onChange={(event) => setData('stimulus_svg_dimension_b', event.target.value)} placeholder="Contoh: 5" className="mt-1 block w-full rounded-lg border-slate-300 focus:border-indigo-500 focus:ring-indigo-500" /></label>}
                                    {selectedSvgTemplate?.dimension_c_label && <label className="text-sm font-medium text-slate-700">{selectedSvgTemplate.dimension_c_label}<input type="number" min={selectedSvgTemplate.allow_signed_dimensions ? undefined : selectedSvgTemplate.integer_dimensions ? 1 : 0.01} step={selectedSvgTemplate.integer_dimensions ? 1 : 'any'} value={data.stimulus_svg_dimension_c} onChange={(event) => setData('stimulus_svg_dimension_c', event.target.value)} placeholder="Contoh: 4" className="mt-1 block w-full rounded-lg border-slate-300 focus:border-indigo-500 focus:ring-indigo-500" /></label>}
                                    {selectedSvgTemplate?.uses_unit !== false && <label className="text-sm font-medium text-slate-700">Satuan<input value={data.stimulus_svg_unit} onChange={(event) => setData('stimulus_svg_unit', event.target.value)} placeholder="cm" className="mt-1 block w-full rounded-lg border-slate-300 focus:border-indigo-500 focus:ring-indigo-500" /></label>}
                                </div>}
                                <InputError message={errors.stimulus_svg_template || errors.stimulus_svg_dimension_a || errors.stimulus_svg_dimension_b || errors.stimulus_svg_dimension_c} />
                                <GeometryCalculationInfo
                                    template={data.stimulus_svg_template}
                                    dimensionA={data.stimulus_svg_dimension_a}
                                    dimensionB={data.stimulus_svg_dimension_b}
                                    dimensionC={data.stimulus_svg_dimension_c}
                                    unit={data.stimulus_svg_unit}
                                    answerCandidates={data.type === 'short_answer' ? data.accepted_answers : data.options.filter((option) => option.is_correct).map((option) => option.content)}
                                />
                                {renderCanvasEditor()}
                                <p className="mt-2 text-center text-xs text-slate-500">Tarik kanvas untuk menggeser · tarik elemen untuk memindahkan · tombol panah untuk presisi · Ctrl/⌘ + scroll untuk zoom</p>
                            </div>}
                            {editableUploadIllustrationUrl && <div className="mt-4">
                                <figure className="relative rounded-xl border border-emerald-200 bg-white p-3 pb-14">
                                    <PositionedImage
                                        src={editableUploadIllustrationUrl}
                                        alt={data.stimulus_image_alt || 'Preview gambar stimulus'}
                                        width={data.stimulus_image_width}
                                        height={data.stimulus_image_height}
                                        zoom={data.stimulus_upload_zoom}
                                        offsetX={data.stimulus_upload_offset_x}
                                        offsetY={data.stimulus_upload_offset_y}
                                        onPan={(position) => setData((current) => ({ ...current, stimulus_upload_offset_x: position.x, stimulus_upload_offset_y: position.y }))}
                                    />
                                    <div className="absolute bottom-3 left-1/2 z-10 flex -translate-x-1/2 items-center gap-1 rounded-xl border border-slate-200 bg-white/95 p-1.5 shadow-lg backdrop-blur">
                                        <button type="button" aria-label="Perkecil gambar upload" onClick={() => setData('stimulus_upload_zoom', Math.max(0.25, Number((data.stimulus_upload_zoom - 0.1).toFixed(2))))} className="rounded-lg px-3 py-1.5 text-sm font-bold text-slate-700 hover:bg-slate-100">−</button>
                                        <span className="min-w-12 text-center text-xs font-bold text-slate-600">{Math.round(data.stimulus_upload_zoom * 100)}%</span>
                                        <button type="button" aria-label="Perbesar gambar upload" onClick={() => setData('stimulus_upload_zoom', Math.min(3, Number((data.stimulus_upload_zoom + 0.1).toFixed(2))))} className="rounded-lg px-3 py-1.5 text-sm font-bold text-slate-700 hover:bg-slate-100">+</button>
                                        <button type="button" onClick={() => setData((current) => ({ ...current, stimulus_upload_zoom: 1, stimulus_upload_offset_x: 0, stimulus_upload_offset_y: 0 }))} className="border-l border-slate-200 px-2 py-1.5 text-xs font-semibold text-indigo-700">Reset</button>
                                    </div>
                                    <figcaption className="mt-3 flex flex-wrap items-center justify-between gap-2 pr-1 text-sm">
                                        <span className="min-w-0 truncate font-medium text-emerald-700">{data.stimulus_image ? `Dipilih: ${data.stimulus_image.name}` : 'Gambar stimulus saat ini'} · tahan dan tarik untuk menggeser</span>
                                        <span className="flex items-center gap-3">{data.stimulus_image && <span className="text-xs text-slate-500">{(data.stimulus_image.size / 1024).toFixed(1)} KB</span>}{!data.stimulus_image && question?.illustration_url && <button type="button" onClick={() => setData('remove_stimulus_image', true)} className="font-semibold text-rose-700">Hapus gambar</button>}</span>
                                    </figcaption>
                                </figure>
                            </div>}
                            {data.remove_stimulus_image && <button type="button" onClick={() => setData('remove_stimulus_image', false)} className="mt-3 text-sm font-semibold text-indigo-700">Batalkan penghapusan gambar</button>}
                                <InputError message={errors.stimulus_image} className="mt-2" />
                                {(data.stimulus_image_source === 'template' || selectedIllustrationUrl || (question?.illustration_url && !data.remove_stimulus_image)) && (
                                    <label className="mt-4 block text-sm font-medium text-slate-700">
                                        Teks alternatif gambar <span className="font-normal text-slate-500">(opsional, untuk aksesibilitas)</span>
                                        <input value={data.stimulus_image_alt} onChange={(event) => setData('stimulus_image_alt', event.target.value)} placeholder="Contoh: Diagram jumlah buku yang dibaca siswa" className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500" />
                                        <InputError message={errors.stimulus_image_alt} className="mt-1" />
                                    </label>
                                )}
                            </div>}
                        <label className="block text-sm font-medium text-slate-700">
                            Pertanyaan
                            <textarea value={data.prompt} onChange={(event) => setData('prompt', event.target.value)} rows={3} className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500" />
                            <span className="mt-1 block text-xs font-normal text-slate-500">Ketik pecahan seperti 1/4; pada tampilan soal akan otomatis menjadi pecahan bertingkat.</span>
                            <InputError message={errors.prompt} className="mt-1" />
                        </label>
                    </div>
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="font-semibold text-slate-900">Kunci jawaban</h2>
                    {data.type === 'short_answer' ? (
                        <div className="mt-4 space-y-3">
                            {data.accepted_answers.map((answer, index) => (
                                <input
                                    key={index}
                                    value={answer}
                                    onChange={(event) => {
                                        const answers = [...data.accepted_answers];
                                        answers[index] = event.target.value;
                                        setData('accepted_answers', answers);
                                    }}
                                    placeholder={`Jawaban diterima ${index + 1}`}
                                    className="block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                                />
                            ))}
                            <button type="button" onClick={() => setData('accepted_answers', [...data.accepted_answers, ''])} className="text-sm font-semibold text-emerald-700">+ Tambah alternatif jawaban</button>
                            <InputError message={errors.accepted_answers} />
                        </div>
                    ) : data.type === 'matching' ? (
                        <div className="mt-4 space-y-5">
                            <div className="rounded-xl border border-indigo-200 bg-indigo-50 p-4 text-sm leading-6 text-indigo-800">
                                Isi pasangan yang benar pada setiap baris. Murid akan melihat lajur kanan terpisah dan menjodohkannya dengan lajur kiri.
                            </div>
                            <div className="space-y-3">
                                <div className="hidden grid-cols-[minmax(0,1fr)_32px_minmax(0,1fr)_40px] gap-3 px-1 text-xs font-semibold uppercase tracking-wide text-slate-500 sm:grid">
                                    <span>Lajur kiri</span><span /><span>Pasangan benar di lajur kanan</span><span />
                                </div>
                                {data.matching_pairs.map((pair, index) => (
                                    <div key={pair.left_id || index} className="grid gap-3 rounded-xl border border-slate-200 p-3 sm:grid-cols-[minmax(0,1fr)_32px_minmax(0,1fr)_40px] sm:items-center">
                                        <textarea
                                            value={pair.left}
                                            onChange={(event) => {
                                                const pairs = [...data.matching_pairs];
                                                pairs[index] = { ...pairs[index], left: event.target.value };
                                                setData('matching_pairs', pairs);
                                            }}
                                            rows={2}
                                            placeholder={`Pernyataan ${index + 1}`}
                                            className="rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500"
                                        />
                                        <span className="hidden text-center text-slate-400 sm:block">→</span>
                                        <textarea
                                            value={pair.right}
                                            onChange={(event) => {
                                                const pairs = [...data.matching_pairs];
                                                pairs[index] = { ...pairs[index], right: event.target.value };
                                                setData('matching_pairs', pairs);
                                            }}
                                            rows={2}
                                            placeholder={`Jawaban ${index + 1}`}
                                            className="rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500"
                                        />
                                        <button
                                            type="button"
                                            disabled={data.matching_pairs.length <= 2}
                                            onClick={() => setData('matching_pairs', data.matching_pairs.filter((_, pairIndex) => pairIndex !== index))}
                                            className="rounded-lg border border-rose-200 px-2 py-2 text-sm font-semibold text-rose-600 disabled:opacity-30"
                                        >
                                            ×
                                        </button>
                                    </div>
                                ))}
                                <button
                                    type="button"
                                    disabled={data.matching_pairs.length >= 8}
                                    onClick={() => setData('matching_pairs', [...data.matching_pairs, { left: '', right: '' }])}
                                    className="text-sm font-semibold text-emerald-700 disabled:opacity-40"
                                >
                                    + Tambah pasangan
                                </button>
                                <InputError message={errors.matching_pairs} />
                            </div>

                            <div className="border-t border-slate-100 pt-5">
                                <h3 className="text-sm font-semibold text-slate-800">Pilihan kanan tambahan <span className="font-normal text-slate-500">(opsional/distraktor)</span></h3>
                                <div className="mt-3 space-y-3">
                                    {data.matching_distractors.map((distractor, index) => (
                                        <div key={distractor.id || index} className="flex gap-3">
                                            <input
                                                value={distractor.content}
                                                onChange={(event) => {
                                                    const distractors = [...data.matching_distractors];
                                                    distractors[index] = { ...distractors[index], content: event.target.value };
                                                    setData('matching_distractors', distractors);
                                                }}
                                                placeholder={`Distraktor ${index + 1}`}
                                                className="min-w-0 flex-1 rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                                            />
                                            <button type="button" onClick={() => setData('matching_distractors', data.matching_distractors.filter((_, distractorIndex) => distractorIndex !== index))} className="rounded-lg border border-rose-200 px-3 text-sm font-semibold text-rose-600">×</button>
                                        </div>
                                    ))}
                                    <button
                                        type="button"
                                        disabled={data.matching_distractors.length >= 4}
                                        onClick={() => setData('matching_distractors', [...data.matching_distractors, { content: '' }])}
                                        className="text-sm font-semibold text-indigo-700 disabled:opacity-40"
                                    >
                                        + Tambah distraktor
                                    </button>
                                    <InputError message={errors.matching_distractors} />
                                </div>
                            </div>
                        </div>
                    ) : data.type === 'category_matrix' ? (
                        <div className="mt-4 space-y-5">
                            <div className="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm leading-6 text-sky-800">
                                Buat 2–4 kategori sebagai kolom, lalu tentukan satu kategori benar untuk setiap pernyataan.
                            </div>

                            <div>
                                <h3 className="text-sm font-semibold text-slate-800">Kategori jawaban</h3>
                                <div className="mt-3 grid gap-3 sm:grid-cols-2">
                                    {data.matrix_columns.map((column, index) => (
                                        <div key={column.id || index} className="flex gap-2">
                                            <input
                                                value={column.label}
                                                onChange={(event) => {
                                                    const columns = [...data.matrix_columns];
                                                    columns[index] = { ...columns[index], label: event.target.value };
                                                    setData('matrix_columns', columns);
                                                }}
                                                placeholder={`Kategori ${index + 1}`}
                                                className="min-w-0 flex-1 rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                                            />
                                            <button
                                                type="button"
                                                disabled={data.matrix_columns.length <= 2}
                                                onClick={() => setData((current) => ({
                                                    ...current,
                                                    matrix_columns: current.matrix_columns.filter((_, columnIndex) => columnIndex !== index),
                                                    matrix_rows: current.matrix_rows.map((row) => ({
                                                        ...row,
                                                        correct_column_index: row.correct_column_index === index
                                                            ? 0
                                                            : row.correct_column_index > index ? row.correct_column_index - 1 : row.correct_column_index,
                                                    })),
                                                }))}
                                                className="rounded-lg border border-rose-200 px-3 text-sm font-semibold text-rose-600 disabled:opacity-30"
                                            >
                                                ×
                                            </button>
                                        </div>
                                    ))}
                                </div>
                                <button type="button" disabled={data.matrix_columns.length >= 4} onClick={() => setData('matrix_columns', [...data.matrix_columns, { label: '' }])} className="mt-3 text-sm font-semibold text-emerald-700 disabled:opacity-40">+ Tambah kategori</button>
                                <InputError message={errors.matrix_columns} />
                            </div>

                            <div className="border-t border-slate-100 pt-5">
                                <h3 className="text-sm font-semibold text-slate-800">Pernyataan dan kunci</h3>
                                <div className="mt-3 space-y-3">
                                    {data.matrix_rows.map((row, index) => (
                                        <div key={row.id || index} className="grid gap-3 rounded-xl border border-slate-200 p-3 sm:grid-cols-[minmax(0,1fr)_190px_40px] sm:items-center">
                                            <textarea
                                                value={row.statement}
                                                onChange={(event) => {
                                                    const rows = [...data.matrix_rows];
                                                    rows[index] = { ...rows[index], statement: event.target.value };
                                                    setData('matrix_rows', rows);
                                                }}
                                                rows={2}
                                                placeholder={`Pernyataan ${index + 1}`}
                                                className="rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500"
                                            />
                                            <select
                                                value={row.correct_column_index}
                                                onChange={(event) => {
                                                    const rows = [...data.matrix_rows];
                                                    rows[index] = { ...rows[index], correct_column_index: Number(event.target.value) };
                                                    setData('matrix_rows', rows);
                                                }}
                                                className="rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500"
                                            >
                                                {data.matrix_columns.map((column, columnIndex) => <option key={column.id || columnIndex} value={columnIndex}>{column.label || `Kategori ${columnIndex + 1}`}</option>)}
                                            </select>
                                            <button type="button" disabled={data.matrix_rows.length <= 2} onClick={() => setData('matrix_rows', data.matrix_rows.filter((_, rowIndex) => rowIndex !== index))} className="rounded-lg border border-rose-200 px-2 py-2 text-sm font-semibold text-rose-600 disabled:opacity-30">×</button>
                                        </div>
                                    ))}
                                </div>
                                <button type="button" disabled={data.matrix_rows.length >= 10} onClick={() => setData('matrix_rows', [...data.matrix_rows, { statement: '', correct_column_index: 0 }])} className="mt-3 text-sm font-semibold text-emerald-700 disabled:opacity-40">+ Tambah pernyataan</button>
                                <InputError message={errors.matrix_rows} />
                            </div>
                        </div>
                    ) : (
                        <div className="mt-4 space-y-3">
                            {data.options.map((option, index) => (
                                <div key={index} className="flex items-center gap-3">
                                    <input
                                        type={data.type === 'single_choice' ? 'radio' : 'checkbox'}
                                        name="correct-option"
                                        checked={option.is_correct}
                                        onChange={(event) => updateOption(index, 'is_correct', event.target.checked)}
                                        className="border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                    />
                                    {data.type === 'single_choice' && <span className="w-6 text-sm font-semibold text-slate-500">{String.fromCharCode(65 + index)}</span>}
                                    <div className="min-w-0 flex-1">
                                        <input value={option.content} aria-invalid={Boolean(formErrors[`options.${index}.content`])} onChange={(event) => updateOption(index, 'content', event.target.value)} className={`block w-full rounded-lg focus:ring-emerald-500 ${formErrors[`options.${index}.content`] ? 'border-rose-400 focus:border-rose-500' : 'border-slate-300 focus:border-emerald-500'}`} />
                                        <InputError message={formErrors[`options.${index}.content`]} className="mt-1" />
                                    </div>
                                </div>
                            ))}
                            <InputError message={errors.options} />
                        </div>
                    )}
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div>
                        <h2 className="font-semibold text-slate-900">Pembahasan setelah TO <span className="font-normal text-slate-500">(opsional)</span></h2>
                        <p className="mt-1 text-xs leading-5 text-slate-500">Pembahasan tidak tampil saat siswa mengerjakan. Konten ini baru ditampilkan setelah try out selesai dikirim.</p>
                    </div>
                    <label className="mt-4 block text-sm font-medium text-slate-700">
                        Teks pembahasan
                        <textarea value={data.explanation} onChange={(event) => setData('explanation', event.target.value)} rows={5} placeholder="Jelaskan konsep, langkah penyelesaian, dan alasan jawaban yang benar." className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500" />
                        <InputError message={errors.explanation} className="mt-1" />
                    </label>
                    <div className="mt-4 rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4">
                        <label className="block text-sm font-medium text-slate-700">
                            Gambar pembahasan <span className="font-normal text-slate-500">(opsional)</span>
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                onChange={(event) => {
                                    setData('explanation_image', event.target.files?.[0] || null);
                                    setData('remove_explanation_image', false);
                                }}
                                className="mt-2 block w-full text-sm text-slate-600 file:mr-4 file:rounded-lg file:border-0 file:bg-indigo-100 file:px-4 file:py-2 file:font-semibold file:text-indigo-700 hover:file:bg-indigo-200"
                            />
                        </label>
                        <p className="mt-2 text-xs text-slate-500">Format JPG, PNG, atau WebP. File maksimal 10 MB dan otomatis dikompresi menjadi maksimal 200 KB.</p>
                        {data.explanation_image && selectedExplanationImageUrl && (
                            <figure className="mt-3 overflow-hidden rounded-xl border border-indigo-200 bg-white p-3">
                                <img src={selectedExplanationImageUrl} alt={data.explanation_image_alt || `Preview ${data.explanation_image.name}`} className="max-h-72 w-full rounded-lg bg-slate-50 object-contain" />
                                <figcaption className="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm">
                                    <span className="min-w-0 truncate font-medium text-indigo-700">Dipilih: {data.explanation_image.name}</span>
                                    <span className="shrink-0 text-xs text-slate-500">{(data.explanation_image.size / 1024).toFixed(1)} KB</span>
                                </figcaption>
                            </figure>
                        )}
                        {!data.explanation_image && question?.explanation_image_url && !data.remove_explanation_image && (
                            <div className="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center">
                                <img src={question.explanation_image_url} alt={question.metadata?.explanation_illustration?.alt || 'Gambar pembahasan saat ini'} className="h-28 w-48 rounded-lg border border-slate-200 object-cover" />
                                <button type="button" onClick={() => setData('remove_explanation_image', true)} className="text-left text-sm font-semibold text-rose-700">Hapus gambar saat disimpan</button>
                            </div>
                        )}
                        {data.remove_explanation_image && <button type="button" onClick={() => setData('remove_explanation_image', false)} className="mt-3 text-sm font-semibold text-indigo-700">Batalkan penghapusan gambar</button>}
                        <InputError message={errors.explanation_image} className="mt-2" />
                        {(data.explanation_image || (question?.explanation_image_url && !data.remove_explanation_image)) && (
                            <label className="mt-4 block text-sm font-medium text-slate-700">
                                Teks alternatif gambar <span className="font-normal text-slate-500">(opsional, untuk aksesibilitas)</span>
                                <input value={data.explanation_image_alt} onChange={(event) => setData('explanation_image_alt', event.target.value)} placeholder="Contoh: Langkah menghitung rata-rata data" className="mt-1 block w-full rounded-lg border-slate-300 focus:border-indigo-500 focus:ring-indigo-500" />
                                <InputError message={errors.explanation_image_alt} className="mt-1" />
                            </label>
                        )}
                    </div>
                </section>

                {(processing || saveError) && (
                    <div role={saveError ? 'alert' : 'status'} aria-live="polite" className={`flex items-start gap-2 rounded-xl border px-4 py-3 text-sm font-semibold ${saveError ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-indigo-200 bg-indigo-50 text-indigo-700'}`}>
                        <span aria-hidden="true">{saveError ? '!' : '↻'}</span>
                        <span>{saveError || (submitIntent === 'draft' ? 'Sedang menyimpan draft…' : 'Sedang mengajukan soal untuk verifikasi…')}</span>
                    </div>
                )}
                <div className="flex flex-wrap justify-end gap-3">
                    <Link href={returnGeneration ? route(returnGeneration.format === 'direct' ? 'ai-questions.show' : 'story-questions.show', returnGeneration.id) : question ? route('questions.show', question.id) : route('questions.index')} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Batal</Link>
                    <button type="button" onClick={() => setPreviewOpen(true)} className="inline-flex items-center gap-2 rounded-lg border border-indigo-300 bg-white px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50">
                        <span aria-hidden="true">◉</span> Preview siswa
                    </button>
                    <button
                        type="submit"
                        name="intent"
                        value="draft"
                        disabled={processing}
                        className="rounded-lg border border-slate-300 bg-white px-5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                    >
                        {processing && submitIntent === 'draft' ? 'Menyimpan...' : question ? 'Simpan sebagai draft' : 'Simpan draft'}
                    </button>
                    <button
                        type="submit"
                        name="intent"
                        value="review"
                        disabled={processing}
                        className="rounded-lg bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50"
                        title={`Simpan dan ajukan untuk diverifikasi oleh ${requiredVerifications} guru`}
                    >
                        {processing && submitIntent === 'review' ? 'Mengajukan...' : `Ajukan Verifikasi (min. ${requiredVerifications} guru)`}
                    </button>
                </div>
            </form>

            <Modal show={canvasFullscreen} maxWidth="7xl" onClose={() => setCanvasFullscreen(false)}>
                <div className="rounded-xl bg-slate-100">
                    <div className="sr-only">Editor kanvas layar penuh</div>
                    {renderCanvasEditor(true)}
                </div>
            </Modal>

            <Modal show={previewOpen} maxWidth="5xl" onClose={() => setPreviewOpen(false)}>
                <StudentQuestionPreview
                    data={data}
                    existingIllustrationUrl={question?.illustration_url}
                    uploadedIllustrationUrl={selectedIllustrationUrl}
                    onClose={() => setPreviewOpen(false)}
                />
            </Modal>
        </AuthenticatedLayout>
    );
}

function StudentQuestionPreview({ data, existingIllustrationUrl, uploadedIllustrationUrl, onClose }: { data: QuestionForm; existingIllustrationUrl?: string; uploadedIllustrationUrl?: string; onClose: () => void }) {
    const [selectedOptions, setSelectedOptions] = useState<number[]>([]);
    const [shortAnswer, setShortAnswer] = useState('');
    const [matrixAnswers, setMatrixAnswers] = useState<Record<number, number>>({});
    const [selectedLeft, setSelectedLeft] = useState<number>();
    const [matches, setMatches] = useState<Record<number, number>>({});

    useEffect(() => {
        setSelectedOptions([]);
        setShortAnswer('');
        setMatrixAnswers({});
        setSelectedLeft(undefined);
        setMatches({});
    }, [data.type]);

    const usesGeometryTemplate = data.stimulus_image_source === 'template';
    const illustrationUrl = usesGeometryTemplate ? undefined : uploadedIllustrationUrl
        || (!data.remove_stimulus_image ? existingIllustrationUrl : undefined);
    const stimulusVisual = stimulusVisualFromForm(data);
    const hasStimulus = Boolean(data.stimulus.trim() || illustrationUrl || stimulusVisual || usesGeometryTemplate);
    const visibleOptions = data.options.filter((option) => option.content.trim());
    const matchingPairs = data.matching_pairs.filter((pair) => pair.left.trim() || pair.right.trim());
    const matchingRightItems = [
        ...matchingPairs.map((pair, index) => ({ id: index, content: pair.right })),
        ...data.matching_distractors
            .filter((item) => item.content.trim())
            .map((item, index) => ({ id: matchingPairs.length + index, content: item.content })),
    ];

    const chooseOption = (index: number) => {
        setSelectedOptions((current) => data.type === 'single_choice'
            ? [index]
            : current.includes(index) ? current.filter((item) => item !== index) : [...current, index]);
    };

    const chooseMatch = (rightId: number) => {
        if (selectedLeft === undefined) return;

        setMatches((current) => {
            const next = Object.fromEntries(
                Object.entries(current).filter(([, value]) => value !== rightId),
            ) as Record<number, number>;
            next[selectedLeft] = rightId;
            return next;
        });
        setSelectedLeft(undefined);
    };

    return (
        <div className="overflow-hidden bg-slate-100">
            <header className="flex items-center justify-between gap-4 bg-slate-900 px-5 py-4 text-white">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-widest text-emerald-300">Preview siswa</p>
                    <h2 className="mt-1 font-bold">Simulasi Adaptif</h2>
                </div>
                <button type="button" onClick={onClose} aria-label="Tutup preview" className="rounded-lg p-2 text-2xl leading-none text-slate-300 hover:bg-white/10 hover:text-white">×</button>
            </header>

            <div className="max-h-[78vh] overflow-y-auto p-4 sm:p-6">
                <div className="mx-auto mb-3 flex max-w-4xl items-center justify-between rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-800">
                    <span>Ini adalah tampilan latihan. Jawaban yang dipilih tidak disimpan.</span>
                    <span className="ml-4 shrink-0 font-semibold">Soal 1 dari 1</span>
                </div>

                <main className="mx-auto grid max-w-4xl overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm lg:grid-cols-2">
                    {hasStimulus && (
                        <aside className="border-b border-slate-200 bg-slate-50/60 p-5 lg:border-b-0 lg:border-r">
                            <p className="text-xs font-semibold uppercase tracking-wider text-indigo-600">Stimulus</p>
                            {illustrationUrl && <PositionedImage src={illustrationUrl} alt={data.stimulus_image_alt || 'Ilustrasi soal'} width={data.stimulus_image_width} height={data.stimulus_image_height} zoom={data.stimulus_upload_zoom} offsetX={data.stimulus_upload_offset_x} offsetY={data.stimulus_upload_offset_y} className="mt-3" />}
                            {usesGeometryTemplate && <GeometryTemplatePreview template={data.stimulus_svg_template} dimensionA={data.stimulus_svg_dimension_a} dimensionB={data.stimulus_svg_dimension_b} dimensionC={data.stimulus_svg_dimension_c} unit={data.stimulus_svg_unit} fractionModels={data.stimulus_fraction_models} protractorAngles={data.stimulus_protractor_angles} overlays={data.stimulus_svg_overlays} zoom={data.stimulus_svg_zoom} offsetX={data.stimulus_svg_offset_x} offsetY={data.stimulus_svg_offset_y} className="mx-auto mt-3 h-auto w-full rounded-lg border border-slate-200 bg-white" />}
                            {stimulusVisual && <StimulusVisual visual={stimulusVisual} className="mt-3" />}
                            {data.stimulus.trim() && <FormattedText text={data.stimulus} className="mt-3 block whitespace-pre-wrap text-sm leading-7 text-slate-700" />}
                        </aside>
                    )}

                    <section className={`min-w-0 p-5 ${hasStimulus ? '' : 'lg:col-span-2 lg:px-10'}`}>
                        <p className="text-xs font-semibold text-emerald-600">Soal 1</p>
                        <h3 className="mt-2 text-lg font-semibold leading-7 text-slate-900">
                            {data.prompt.trim() ? <FormattedText text={data.prompt} /> : <span className="italic text-slate-400">Pertanyaan belum diisi.</span>}
                        </h3>

                        {data.type === 'category_matrix' ? (
                            <div className="mt-5 space-y-2">
                                <p className="mb-3 text-xs text-slate-600">Pilih satu jawaban untuk setiap pernyataan.</p>
                                {data.matrix_rows.filter((row) => row.statement.trim()).map((row, rowIndex) => (
                                    <div key={row.id || rowIndex} className="grid gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                                        <p className="text-sm leading-6 text-slate-800"><span className="mr-1.5 font-semibold text-slate-500">{rowIndex + 1}.</span>{row.statement}</p>
                                        <div className="flex flex-wrap gap-2 sm:justify-end">
                                            {data.matrix_columns.filter((column) => column.label.trim()).map((column, columnIndex) => {
                                                const selected = matrixAnswers[rowIndex] === columnIndex;
                                                return <button key={column.id || columnIndex} type="button" onClick={() => setMatrixAnswers((current) => ({ ...current, [rowIndex]: columnIndex }))} className={`min-h-10 rounded-lg border px-3 py-2 text-xs font-semibold ${selected ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-300 text-slate-700 hover:bg-blue-50'}`}>{column.label}</button>;
                                            })}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : data.type === 'matching' ? (
                            <div className="mt-5">
                                <p className="rounded-lg border border-indigo-200 bg-indigo-50 p-3 text-sm text-indigo-800">{selectedLeft === undefined ? 'Pilih satu pernyataan, kemudian pilih pasangannya.' : 'Sekarang pilih jawaban yang sesuai.'}</p>
                                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <h4 className="text-xs font-semibold uppercase tracking-wide text-slate-500">Pernyataan</h4>
                                        {matchingPairs.map((pair, index) => <button key={pair.left_id || index} type="button" onClick={() => setSelectedLeft(index)} className={`flex w-full items-start gap-2 rounded-lg border-2 p-3 text-left text-sm ${matches[index] !== undefined ? 'border-emerald-500 bg-emerald-50' : selectedLeft === index ? 'border-indigo-500 bg-indigo-50' : 'border-slate-200'}`}><span className="font-bold text-slate-500">{index + 1}</span><span>{pair.left || 'Pernyataan belum diisi'}</span></button>)}
                                    </div>
                                    <div className="space-y-2">
                                        <h4 className="text-xs font-semibold uppercase tracking-wide text-slate-500">Pilihan pasangan</h4>
                                        {matchingRightItems.map((item) => {
                                            const matchedLeft = Object.entries(matches).find(([, rightId]) => rightId === item.id)?.[0];
                                            return <button key={item.id} type="button" onClick={() => chooseMatch(item.id)} className={`flex w-full items-start gap-2 rounded-lg border-2 p-3 text-left text-sm ${matchedLeft !== undefined ? 'border-emerald-500 bg-emerald-50' : selectedLeft !== undefined ? 'border-slate-200 hover:border-indigo-400' : 'border-slate-200'}`}><span className="font-bold text-slate-500">{matchedLeft !== undefined ? Number(matchedLeft) + 1 : '○'}</span><span>{item.content || 'Jawaban belum diisi'}</span></button>;
                                        })}
                                    </div>
                                </div>
                            </div>
                        ) : data.type === 'short_answer' ? (
                            <textarea value={shortAnswer} onChange={(event) => setShortAnswer(event.target.value)} rows={3} placeholder="Tulis jawabanmu" className="mt-5 block w-full rounded-xl border-slate-300 focus:border-emerald-500 focus:ring-emerald-500" />
                        ) : (
                            <div className="mt-5 space-y-3">
                                {visibleOptions.length > 0 ? visibleOptions.map((option, index) => {
                                    const selected = selectedOptions.includes(index);
                                    return <button key={index} type="button" onClick={() => chooseOption(index)} className={`flex min-h-11 w-full items-center gap-3 rounded-xl border p-3 text-left ${selected ? 'border-emerald-500 bg-emerald-50 ring-1 ring-emerald-500' : 'border-slate-200 hover:border-slate-300'}`}>{data.type === 'multiple_choice' ? <span className={`flex h-6 w-6 shrink-0 items-center justify-center rounded border-2 text-sm font-bold ${selected ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300 text-transparent'}`}>✓</span> : <span className="w-5 font-semibold text-slate-600">{String.fromCharCode(65 + index)}</span>}<FormattedText text={option.content} className="text-sm leading-6 text-slate-800" /></button>;
                                }) : <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">Pilihan jawaban belum diisi.</p>}
                            </div>
                        )}
                    </section>
                </main>

                <div className="mx-auto mt-4 flex max-w-4xl justify-between">
                    <button type="button" disabled className="min-h-11 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 opacity-40">Sebelumnya</button>
                    <button type="button" onClick={onClose} className="min-h-11 rounded-lg bg-emerald-600 px-5 py-2 text-sm font-semibold text-white">Selesai preview</button>
                </div>
            </div>
        </div>
    );
}
