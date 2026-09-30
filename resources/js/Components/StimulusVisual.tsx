export type StimulusTableVisual = {
    type: 'table';
    title?: string;
    headers: string[];
    rows: string[][];
};

export type StimulusBarChartVisual = {
    type: 'bar_chart';
    title?: string;
    x_axis_label?: string;
    y_axis_label?: string;
    maximum?: number;
    items?: { label: string; value: number }[];
    categories?: string[];
    series?: { label: string; values: number[] }[];
};

export type StimulusPictogramVisual = {
    type: 'pictogram';
    title?: string;
    symbol: string;
    legend_value: number;
    unit?: string;
    items: { label: string; value: number }[];
};

export type StimulusPieChartVisual = {
    type: 'pie_chart';
    title?: string;
    unit?: string;
    total_mode?: 'percentage' | 'degrees' | 'custom';
    total?: number;
    total_asked?: boolean;
    items: { label: string; value: number; asked?: boolean; auto_calculate?: boolean }[];
};

export type StimulusVisualData = StimulusTableVisual | StimulusBarChartVisual | StimulusPictogramVisual | StimulusPieChartVisual;

const chartColors = ['#38bdf8', '#f59e0b', '#34d399', '#a78bfa', '#fb7185', '#2dd4bf', '#818cf8', '#f97316', '#84cc16', '#e879f9', '#22d3ee', '#facc15'];

const formatNumber = (value: number) => new Intl.NumberFormat('id-ID', {
    maximumFractionDigits: 2,
}).format(value);

const niceScale = (maximum: number) => {
    const safeMaximum = Math.max(1, maximum);
    const rawStep = safeMaximum / 6;
    const magnitude = 10 ** Math.floor(Math.log10(rawStep));
    const normalized = rawStep / magnitude;
    const multiplier = normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 5 ? 5 : 10;
    const step = multiplier * magnitude;

    return { maximum: Math.ceil(safeMaximum / step) * step, step };
};

export default function StimulusVisual({ visual, className = '' }: { visual?: StimulusVisualData | null; className?: string }) {
    if (!visual) return null;

    if (visual.type === 'table') {
        return (
            <figure className={`overflow-hidden rounded-xl border border-slate-300 bg-white ${className}`}>
                {visual.title && <figcaption className="border-b border-slate-200 bg-slate-50 px-4 py-3 text-center text-sm font-bold text-slate-900">{visual.title}</figcaption>}
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[420px] border-collapse text-sm">
                        <thead className="bg-indigo-50 text-slate-800">
                            <tr>{visual.headers.map((header, index) => <th key={index} scope="col" className="border-b border-r border-slate-300 px-4 py-3 text-left font-bold last:border-r-0">{header}</th>)}</tr>
                        </thead>
                        <tbody>
                            {visual.rows.map((row, rowIndex) => (
                                <tr key={rowIndex} className={rowIndex % 2 ? 'bg-slate-50/70' : 'bg-white'}>
                                    {visual.headers.map((_, columnIndex) => <td key={columnIndex} className="border-b border-r border-slate-200 px-4 py-3 text-slate-700 last:border-r-0">{row[columnIndex] || ''}</td>)}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </figure>
        );
    }

    if (visual.type === 'pictogram') {
        const legendValue = Math.max(Number(visual.legend_value) || 1, Number.EPSILON);

        return (
            <figure className={`overflow-hidden rounded-xl border border-slate-300 bg-white ${className}`}>
                {visual.title && <figcaption className="border-b border-slate-200 bg-slate-50 px-4 py-3 text-center text-sm font-bold text-slate-900">{visual.title}</figcaption>}
                <div className="space-y-3 p-4">
                    {visual.items.map((item, rowIndex) => {
                        const symbolCount = Math.max(0, Number(item.value)) / legendValue;
                        const fullSymbols = Math.min(40, Math.floor(symbolCount));
                        const fraction = symbolCount - fullSymbols;

                        return (
                            <div key={rowIndex} className="grid grid-cols-[minmax(90px,0.35fr)_minmax(0,1fr)] items-center gap-3" aria-label={`${item.label}: ${formatNumber(Number(item.value))} ${visual.unit || ''}`}>
                                <span className="text-sm font-semibold text-slate-700">{item.label}</span>
                                <span className="flex min-h-8 flex-wrap items-center gap-1 text-2xl" aria-hidden="true">
                                    {Array.from({ length: fullSymbols }).map((_, index) => <span key={index}>{visual.symbol}</span>)}
                                    {symbolCount <= 40 && fraction > 0.001 && (
                                        <span className="relative inline-block h-8 w-8 overflow-hidden align-middle">
                                            <span className="absolute left-0 top-0 block h-8 overflow-hidden whitespace-nowrap" style={{ width: `${fraction * 100}%` }}>
                                                <span className="inline-block w-8">{visual.symbol}</span>
                                            </span>
                                            <span className="absolute inset-0 rounded border border-dashed border-slate-300" />
                                        </span>
                                    )}
                                    {symbolCount > 40 && <span className="text-xs font-semibold text-rose-700">Maksimal 40 simbol</span>}
                                </span>
                            </div>
                        );
                    })}
                </div>
                <div className="flex items-center justify-center gap-2 border-t border-slate-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-slate-800">
                    <span className="text-xl" aria-hidden="true">{visual.symbol}</span>
                    <span>= {formatNumber(legendValue)} {visual.unit || 'objek'}</span>
                </div>
            </figure>
        );
    }

    if (visual.type === 'pie_chart') {
        const items = visual.items.filter((item) => Number(item.value) >= 0);
        const itemTotal = items.reduce((sum, item) => sum + Number(item.value), 0);
        const total = Number(visual.total) > 0 ? Number(visual.total) : itemTotal;
        if (total <= 0) return null;
        const valueSuffix = visual.total_mode === 'percentage' ? '%' : visual.total_mode === 'degrees' ? '°' : visual.unit ? ` ${visual.unit}` : '';

        const centerX = 245;
        const centerY = 190;
        const radius = 140;
        let currentAngle = -90;
        const polarPoint = (angle: number, distance: number) => {
            const radians = angle * Math.PI / 180;
            return { x: centerX + (distance * Math.cos(radians)), y: centerY + (distance * Math.sin(radians)) };
        };

        return (
            <figure className={`toa-chart min-w-0 overflow-hidden rounded-xl border border-slate-300 bg-white p-3 ${className}`}>
                {visual.title && <figcaption className="mb-1 text-center text-sm font-bold text-slate-900">{visual.title}</figcaption>}
                <div className="overflow-x-auto" tabIndex={0} role="region" aria-label="Diagram lingkaran, geser untuk melihat seluruh diagram">
                <svg viewBox={`0 0 800 ${Math.max(400, 110 + items.length * 29)}`} role="img" aria-label={visual.title || 'Diagram lingkaran stimulus'} className="h-auto w-full min-w-[640px]">
                    {items.map((item, index) => {
                        const percentage = Number(item.value) / total;
                        const startAngle = currentAngle;
                        const endAngle = currentAngle + (percentage * 360);
                        currentAngle = endAngle;
                        const start = polarPoint(startAngle, radius);
                        const end = polarPoint(endAngle, radius);
                        const largeArc = percentage > 0.5 ? 1 : 0;
                        const path = percentage >= 0.999999
                            ? undefined
                            : `M ${centerX} ${centerY} L ${start.x} ${start.y} A ${radius} ${radius} 0 ${largeArc} 1 ${end.x} ${end.y} Z`;
                        const labelPoint = polarPoint(startAngle + ((endAngle - startAngle) / 2), radius * 0.62);

                        return (
                            <g key={index}>
                                {path
                                    ? <path d={path} fill={chartColors[index % chartColors.length]} stroke="#fff" strokeWidth="3" />
                                    : <circle cx={centerX} cy={centerY} r={radius} fill={chartColors[index % chartColors.length]} stroke="#fff" strokeWidth="3" />}
                                {percentage >= 0.04 && (
                                    <text x={labelPoint.x} y={labelPoint.y + 5} textAnchor="middle" fontSize="16" fontWeight="800" fill="#0f172a" stroke="#fff" strokeWidth="4" paintOrder="stroke">
                                        {item.asked ? '?' : `${formatNumber(Number(item.value))}${valueSuffix}`}
                                    </text>
                                )}
                            </g>
                        );
                    })}
                    {items.map((item, index) => (
                        <g key={`legend-${index}`} transform={`translate(455 ${60 + (index * 29)})`}>
                            <rect width="18" height="18" rx="4" fill={chartColors[index % chartColors.length]} />
                            <text x="28" y="15" fontSize="15" fontWeight="600" fill="var(--toa-chart-text)">
                                {item.label}{item.asked
                                    ? ' · ?'
                                    : visual.total_mode
                                        ? ` · ${formatNumber(Number(item.value))}${valueSuffix}`
                                        : ` · ${formatNumber((Number(item.value) / total) * 100)}%`}
                            </text>
                        </g>
                    ))}
                    <text x="600" y={Math.max(370, 80 + items.length * 29)} textAnchor="middle" fontSize="14" fontWeight="600" fill="var(--toa-chart-text)">
                        Total: {visual.total_mode === 'custom' && visual.total_asked ? '?' : `${formatNumber(total)}${valueSuffix}`}
                    </text>
                </svg>
                </div>
            </figure>
        );
    }

    const groupedSeries = visual.series?.filter((series) => series.values.some((value) => Number.isFinite(Number(value)))) || [];
    const singleItems = visual.items?.filter((item) => Number.isFinite(Number(item.value))) || [];
    const isGrouped = groupedSeries.length >= 2 && (visual.categories?.length || 0) > 0;
    const categories = isGrouped ? visual.categories! : singleItems.map((item) => item.label);
    const series = isGrouped
        ? groupedSeries
        : [{ label: '', values: singleItems.map((item) => Number(item.value)) }];
    if (categories.length === 0) return null;

    const dataMaximum = Math.max(1, ...series.flatMap((item) => item.values.map(Number)));
    const automaticScale = niceScale(dataMaximum);
    const axisMaximum = visual.maximum && visual.maximum >= dataMaximum ? visual.maximum : automaticScale.maximum;
    const tickStep = visual.maximum && visual.maximum >= dataMaximum ? axisMaximum / 5 : automaticScale.step;
    const tickCount = Math.round(axisMaximum / tickStep);
    const plot = { left: 92, right: 760, top: 28, bottom: 350 };
    const plotWidth = plot.right - plot.left;
    const plotHeight = plot.bottom - plot.top;
    const slot = plotWidth / categories.length;

    return (
        <figure className={`toa-chart min-w-0 overflow-hidden rounded-xl border border-slate-300 bg-white p-3 ${className}`}>
            {visual.title && <figcaption className="mb-1 text-center text-sm font-bold text-slate-900">{visual.title}</figcaption>}
            <div className="overflow-x-auto" tabIndex={0} role="region" aria-label="Diagram batang, geser untuk melihat seluruh diagram">
            <svg viewBox="0 0 800 440" role="img" aria-label={visual.title || 'Diagram batang stimulus'} className="h-auto w-full min-w-[640px]">
                {Array.from({ length: tickCount + 1 }).map((_, index) => {
                    const value = index * tickStep;
                    const y = plot.bottom - (plotHeight * value / axisMaximum);
                    return (
                        <g key={index}>
                            <line x1={plot.left} y1={y} x2={plot.right} y2={y} stroke="var(--toa-chart-grid)" strokeWidth="1.5" />
                            <text x={plot.left - 12} y={y + 5} textAnchor="end" fontSize="15" fontWeight="600" fill="var(--toa-chart-text)">{formatNumber(value)}</text>
                        </g>
                    );
                })}
                <line x1={plot.left} y1={plot.top} x2={plot.left} y2={plot.bottom} stroke="var(--toa-chart-axis)" strokeWidth="3" />
                <line x1={plot.left} y1={plot.bottom} x2={plot.right} y2={plot.bottom} stroke="var(--toa-chart-axis)" strokeWidth="3" />
                {categories.map((category, categoryIndex) => {
                    const groupWidth = slot * 0.78;
                    const barGap = isGrouped ? 3 : 0;
                    const width = Math.max(8, (groupWidth - (barGap * (series.length - 1))) / series.length);
                    const groupX = plot.left + (categoryIndex * slot) + ((slot - groupWidth) / 2);
                    const label = category.length > 14 ? `${category.slice(0, 13)}…` : category;
                    return (
                        <g key={categoryIndex}>
                            {series.map((dataSeries, seriesIndex) => {
                                const value = Math.max(0, Number(dataSeries.values[categoryIndex] || 0));
                                const height = plotHeight * value / axisMaximum;
                                const x = groupX + (seriesIndex * (width + barGap));
                                return <rect key={seriesIndex} x={x} y={plot.bottom - height} width={width} height={height} rx="4" fill={chartColors[seriesIndex % chartColors.length]} stroke="var(--toa-chart-axis)" strokeWidth="1.5" />;
                            })}
                            <text x={plot.left + (categoryIndex * slot) + (slot / 2)} y={plot.bottom + 25} textAnchor="middle" fontSize="14" fontWeight="600" fill="var(--toa-chart-text)">
                                <title>{category}</title>{label}
                            </text>
                        </g>
                    );
                })}
                {isGrouped && series.map((dataSeries, index) => (
                    <g key={index} transform={`translate(${270 + (index * 145)} 390)`}>
                        <rect width="18" height="18" rx="3" fill={chartColors[index % chartColors.length]} />
                        <text x="26" y="15" fontSize="14" fontWeight="600" fill="var(--toa-chart-text)">{dataSeries.label}</text>
                    </g>
                ))}
                {visual.x_axis_label && <text x={(plot.left + plot.right) / 2} y={isGrouped ? 435 : 420} textAnchor="middle" fontSize="15" fontWeight="700" fill="var(--toa-chart-text)">{visual.x_axis_label}</text>}
                {visual.y_axis_label && <text x="20" y={(plot.top + plot.bottom) / 2} textAnchor="middle" transform={`rotate(-90 20 ${(plot.top + plot.bottom) / 2})`} fontSize="15" fontWeight="700" fill="var(--toa-chart-text)">{visual.y_axis_label}</text>}
            </svg>
                </div>
        </figure>
    );
}
