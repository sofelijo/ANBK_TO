import FormattedText from '@/Components/FormattedText';

export type StimulusTextStyle = {
    font_family?: 'sans' | 'serif' | 'mono';
    font_size?: 'sm' | 'base' | 'lg' | 'xl';
    text_align?: 'left' | 'center' | 'right' | 'justify';
    line_spacing?: 'normal' | 'relaxed' | 'loose';
};

const fontClasses: Record<NonNullable<StimulusTextStyle['font_family']>, string> = {
    sans: 'font-sans',
    serif: 'font-serif',
    mono: 'font-mono',
};

const sizeClasses: Record<NonNullable<StimulusTextStyle['font_size']>, string> = {
    sm: 'text-sm',
    base: 'text-base',
    lg: 'text-lg',
    xl: 'text-xl',
};

const alignmentClasses: Record<NonNullable<StimulusTextStyle['text_align']>, string> = {
    left: 'text-left',
    center: 'text-center',
    right: 'text-right',
    justify: 'text-justify',
};

const spacingClasses: Record<NonNullable<StimulusTextStyle['line_spacing']>, string> = {
    normal: 'leading-normal',
    relaxed: 'leading-relaxed',
    loose: 'leading-loose',
};

export const stimulusTextClasses = (style?: StimulusTextStyle): string => [
    fontClasses[style?.font_family || 'sans'],
    sizeClasses[style?.font_size || 'sm'],
    alignmentClasses[style?.text_align || 'left'],
    spacingClasses[style?.line_spacing || 'relaxed'],
].join(' ');

export default function StimulusText({ text, style, className = '' }: { text: string; style?: StimulusTextStyle; className?: string }) {
    return (
        <FormattedText
            text={text}
            className={`${className} ${stimulusTextClasses(style)} whitespace-pre-wrap`}
        />
    );
}
