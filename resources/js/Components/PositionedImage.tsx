import { PointerEvent as ReactPointerEvent, useRef, useState } from 'react';

type Position = { x: number; y: number };

export default function PositionedImage({ src, alt, width = 800, height = 450, zoom = 1, offsetX = 0, offsetY = 0, onPan, className = '' }: { src: string; alt: string; width?: number; height?: number; zoom?: number; offsetX?: number; offsetY?: number; onPan?: (position: Position) => void; className?: string }) {
    const [dragging, setDragging] = useState(false);
    const dragStart = useRef({ clientX: 0, clientY: 0, offsetX: 0, offsetY: 0 });

    const startDrag = (event: ReactPointerEvent<HTMLDivElement>) => {
        if (!onPan || event.button !== 0) return;
        event.currentTarget.setPointerCapture(event.pointerId);
        dragStart.current = { clientX: event.clientX, clientY: event.clientY, offsetX, offsetY };
        setDragging(true);
    };
    const moveDrag = (event: ReactPointerEvent<HTMLDivElement>) => {
        if (!dragging || !onPan) return;
        const bounds = event.currentTarget.getBoundingClientRect();
        onPan({
            x: Math.max(-100, Math.min(100, Number((dragStart.current.offsetX + (((event.clientX - dragStart.current.clientX) / bounds.width) * 100)).toFixed(2)))),
            y: Math.max(-100, Math.min(100, Number((dragStart.current.offsetY + (((event.clientY - dragStart.current.clientY) / bounds.height) * 100)).toFixed(2)))),
        });
    };
    const stopDrag = (event: ReactPointerEvent<HTMLDivElement>) => {
        if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId);
        setDragging(false);
    };

    return (
        <div
            role={onPan ? 'application' : undefined}
            aria-label={onPan ? 'Gambar yang dapat digeser' : undefined}
            onPointerDown={startDrag}
            onPointerMove={moveDrag}
            onPointerUp={stopDrag}
            onPointerCancel={stopDrag}
            style={{ maxWidth: `${width}px`, aspectRatio: `${width} / ${height}` }}
            className={`relative mx-auto w-full overflow-hidden rounded-lg border border-slate-200 bg-white select-none ${onPan ? dragging ? 'touch-none cursor-grabbing' : 'touch-none cursor-grab' : ''} ${className}`}
        >
            <img
                src={src}
                alt={alt}
                draggable={false}
                style={{ transform: `translate(${offsetX}%, ${offsetY}%) scale(${zoom})` }}
                className="pointer-events-none absolute inset-0 h-full w-full object-contain"
            />
        </div>
    );
}
