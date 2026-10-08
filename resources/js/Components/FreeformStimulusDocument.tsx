import StimulusText, { StimulusTextStyle } from '@/Components/StimulusText';

export type StimulusImageLayout = {
    x?: number;
    y?: number;
    width?: number;
};

const EDITOR_CANVAS_WIDTH = 768;

const responsiveImageStyle = (layout: StimulusImageLayout | undefined, defaultX: number, defaultY: number) => {
    const width = Math.min(90, Math.max(13, ((layout?.width ?? 320) / EDITOR_CANVAS_WIDTH) * 100));
    const left = Math.min(layout?.x ?? defaultX, 100 - width);

    return { left: `${Math.max(0, left)}%`, top: `${layout?.y ?? defaultY}%`, width: `${width}%` };
};

export default function FreeformStimulusDocument({
    text,
    style,
    imageUrl,
    imageAlt = 'Gambar stimulus',
    imageLayout,
    secondaryImageUrl,
    secondaryImageAlt = 'Gambar stimulus kedua',
    secondaryImageLayout,
    additionalImages = [],
    className = '',
}: {
    text?: string;
    style?: StimulusTextStyle;
    imageUrl?: string;
    imageAlt?: string;
    imageLayout?: StimulusImageLayout;
    secondaryImageUrl?: string;
    secondaryImageAlt?: string;
    secondaryImageLayout?: StimulusImageLayout;
    additionalImages?: { url: string; alt?: string; layout?: StimulusImageLayout }[];
    className?: string;
}) {
    if (!imageUrl && !secondaryImageUrl && additionalImages.length === 0) {
        return text ? <StimulusText text={text} style={style} className={className} /> : null;
    }

    return (
        <div className={`relative aspect-[768/620] w-full min-w-0 max-w-full overflow-hidden rounded-lg bg-white ${className}`}>
            {text && <StimulusText text={text} style={style} className="relative z-0 block whitespace-pre-wrap p-6 text-slate-700" />}
            {imageUrl && <img
                src={imageUrl}
                alt={imageAlt}
                style={responsiveImageStyle(imageLayout, 8, 18)}
                className="absolute z-10 h-auto max-w-[90%] rounded-md object-contain shadow-sm"
            />}
            {secondaryImageUrl && <img
                src={secondaryImageUrl}
                alt={secondaryImageAlt}
                style={responsiveImageStyle(secondaryImageLayout, 48, 48)}
                className="absolute z-20 h-auto max-w-[90%] rounded-md object-contain shadow-sm"
            />}
            {additionalImages.map((image, index) => <img
                key={`${image.url}-${index}`}
                src={image.url}
                alt={image.alt || `Gambar stimulus ${index + 3}`}
                style={{ ...responsiveImageStyle(image.layout, 20, 20), zIndex: 30 + index }}
                className="absolute h-auto max-w-[90%] rounded-md object-contain shadow-sm"
            />)}
        </div>
    );
}
