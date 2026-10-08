import StimulusText, { StimulusTextStyle } from '@/Components/StimulusText';
import { ReactNode } from 'react';

export const stimulusParagraphs = (text: string): string[] => text.split('\n');

export default function StimulusDocument({
    text,
    style,
    image,
    imagePosition = 0,
    className = '',
}: {
    text?: string;
    style?: StimulusTextStyle;
    image?: ReactNode;
    imagePosition?: number;
    className?: string;
}) {
    const paragraphs = text ? stimulusParagraphs(text) : [];
    const position = Math.max(0, Math.min(imagePosition, paragraphs.length));

    if (paragraphs.length === 0) {
        return image ? <div className={className}>{image}</div> : null;
    }

    return (
        <div className={className}>
            {position === 0 && image}
            {paragraphs.map((paragraph, index) => (
                <div key={index}>
                    <StimulusText text={paragraph || '\u00a0'} style={style} className="block text-slate-700" />
                    {position === index + 1 && image}
                </div>
            ))}
        </div>
    );
}
