import StimulusText, { StimulusTextStyle } from '@/Components/StimulusText';

export type StimulusImageLayout = {
    x?: number;
    y?: number;
    width?: number;
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
        <div className={`relative min-h-[32rem] overflow-hidden rounded-lg bg-white ${className}`}>
            {text && <StimulusText text={text} style={style} className="relative z-0 block whitespace-pre-wrap p-6 text-slate-700" />}
            {imageUrl && <img
                src={imageUrl}
                alt={imageAlt}
                style={{ left: `${imageLayout?.x ?? 8}%`, top: `${imageLayout?.y ?? 18}%`, width: `${imageLayout?.width ?? 320}px` }}
                className="absolute z-10 h-auto max-w-[90%] rounded-md object-contain shadow-sm"
            />}
            {secondaryImageUrl && <img
                src={secondaryImageUrl}
                alt={secondaryImageAlt}
                style={{ left: `${secondaryImageLayout?.x ?? 48}%`, top: `${secondaryImageLayout?.y ?? 48}%`, width: `${secondaryImageLayout?.width ?? 320}px` }}
                className="absolute z-20 h-auto max-w-[90%] rounded-md object-contain shadow-sm"
            />}
            {additionalImages.map((image, index) => <img
                key={`${image.url}-${index}`}
                src={image.url}
                alt={image.alt || `Gambar stimulus ${index + 3}`}
                style={{ left: `${image.layout?.x ?? 20}%`, top: `${image.layout?.y ?? 20}%`, width: `${image.layout?.width ?? 320}px`, zIndex: 30 + index }}
                className="absolute h-auto max-w-[90%] rounded-md object-contain shadow-sm"
            />)}
        </div>
    );
}
