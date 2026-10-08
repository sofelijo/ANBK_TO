import FormattedText from '@/Components/FormattedText';
import DOMPurify from 'dompurify';

export const RICH_STIMULUS_PREFIX = '<!--toa-rich-->';

export const isRichStimulusText = (text: string): boolean => text.startsWith(RICH_STIMULUS_PREFIX);

export const stimulusEditorHtml = (text: string): string => {
    if (isRichStimulusText(text)) return text.slice(RICH_STIMULUS_PREFIX.length);

    const escaped = text
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    return escaped.replaceAll('\n', '<br>');
};

const sanitizedRichText = (text: string): string => {
    const sanitized = DOMPurify.sanitize(text.slice(RICH_STIMULUS_PREFIX.length), {
        ALLOWED_TAGS: ['b', 'strong', 'i', 'em', 'u', 's', 'span', 'div', 'p', 'br', 'ul', 'ol', 'li'],
        ALLOWED_ATTR: ['style', 'align'],
    });

    if (typeof document === 'undefined') return DOMPurify.sanitize(sanitized, { ALLOWED_TAGS: ['b', 'strong', 'i', 'em', 'u', 's', 'span', 'div', 'p', 'br', 'ul', 'ol', 'li'] });

    const template = document.createElement('template');
    template.innerHTML = sanitized;
    template.content.querySelectorAll<HTMLElement>('[style]').forEach((element) => {
        const allowedStyles: [string, string][] = [];
        const fontFamily = element.style.getPropertyValue('font-family');
        const fontSize = element.style.getPropertyValue('font-size');
        const lineHeight = element.style.getPropertyValue('line-height');
        const textAlign = element.style.getPropertyValue('text-align');

        if (/^(ui-(sans-serif|serif|monospace)|system-ui|sans-serif|serif|monospace|Georgia)(,\s*(ui-(sans-serif|serif|monospace)|system-ui|sans-serif|serif|monospace|Georgia))*$/i.test(fontFamily)) allowedStyles.push(['font-family', fontFamily]);
        if (/^(14|16|18|20)px$/.test(fontSize)) allowedStyles.push(['font-size', fontSize]);
        if (/^(1|1\.5|2)$/.test(lineHeight)) allowedStyles.push(['line-height', lineHeight]);
        if (/^(left|center|right|justify)$/.test(textAlign)) allowedStyles.push(['text-align', textAlign]);

        element.removeAttribute('style');
        allowedStyles.forEach(([property, value]) => element.style.setProperty(property, value));
        if (!element.getAttribute('style')) element.removeAttribute('style');
    });
    template.content.querySelectorAll<HTMLElement>('[align]').forEach((element) => {
        if (!/^(left|center|right|justify)$/.test(element.getAttribute('align') || '')) element.removeAttribute('align');
    });

    return template.innerHTML;
};

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
    if (isRichStimulusText(text)) {
        return (
            <div
                className={`${className} ${stimulusTextClasses(style)} whitespace-pre-wrap`}
                dangerouslySetInnerHTML={{ __html: sanitizedRichText(text) }}
            />
        );
    }

    return (
        <FormattedText
            text={text}
            className={`${className} ${stimulusTextClasses(style)} whitespace-pre-wrap`}
        />
    );
}
