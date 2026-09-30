import { Fragment, ReactNode } from 'react';

type FormattedTextProps = {
    text: string;
    className?: string;
};

const fractionPattern = /(^|[^\p{L}\p{N}_/])(-?\d+(?:[.,]\d+)?)\s*\/\s*(-?\d+(?:[.,]\d+)?)(?![\p{L}\p{N}_/])/gu;

function formattedParts(text: string): ReactNode[] {
    const parts: ReactNode[] = [];
    let cursor = 0;

    for (const match of text.matchAll(fractionPattern)) {
        const index = match.index ?? 0;
        const [source, prefix, numerator, denominator] = match;

        if (index > cursor) {
            parts.push(text.slice(cursor, index));
        }
        if (prefix) {
            parts.push(prefix);
        }

        parts.push(
            <span
                key={`fraction-${index}`}
                aria-label={`${numerator} per ${denominator}`}
                className="mx-0.5 inline-flex min-w-[1.35em] flex-col items-stretch align-middle text-[0.9em] font-medium leading-none"
            >
                <span aria-hidden="true" className="border-b border-current px-0.5 pb-[0.12em] text-center">{numerator}</span>
                <span aria-hidden="true" className="px-0.5 pt-[0.12em] text-center">{denominator}</span>
            </span>,
        );

        cursor = index + source.length;
    }

    if (cursor < text.length) {
        parts.push(text.slice(cursor));
    }

    return parts;
}

export default function FormattedText({ text, className }: FormattedTextProps) {
    return <span className={className}>{formattedParts(text).map((part, index) => <Fragment key={index}>{part}</Fragment>)}</span>;
}
