import { PointerEvent, useRef } from 'react';

export function SignaturePad({ disabled, onChange }: { disabled: boolean; onChange: (file: File | null) => void }) {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const drawing = useRef(false);

    function point(event: PointerEvent<HTMLCanvasElement>) {
        const canvas = canvasRef.current;
        if (!canvas) {
            return { x: 0, y: 0 };
        }
        const rect = canvas.getBoundingClientRect();

        return {
            x: (event.clientX - rect.left) * (canvas.width / rect.width),
            y: (event.clientY - rect.top) * (canvas.height / rect.height),
        };
    }

    function start(event: PointerEvent<HTMLCanvasElement>) {
        if (disabled) {
            return;
        }
        const canvas = canvasRef.current;
        const context = canvas?.getContext('2d');
        if (!canvas || !context) {
            return;
        }
        drawing.current = true;
        canvas.setPointerCapture(event.pointerId);
        const cursor = point(event);
        context.strokeStyle = '#111827';
        context.lineWidth = 2.5;
        context.lineCap = 'round';
        context.lineJoin = 'round';
        context.beginPath();
        context.moveTo(cursor.x, cursor.y);
    }

    function move(event: PointerEvent<HTMLCanvasElement>) {
        if (!drawing.current) {
            return;
        }
        const context = canvasRef.current?.getContext('2d');
        if (!context) {
            return;
        }
        const cursor = point(event);
        context.lineTo(cursor.x, cursor.y);
        context.stroke();
    }

    function finish() {
        if (!drawing.current) {
            return;
        }
        drawing.current = false;
        const canvas = canvasRef.current;
        if (!canvas) {
            return;
        }
        canvas.toBlob((blob) => {
            onChange(blob ? new File([blob], 'student-signature.png', { type: 'image/png' }) : null);
        }, 'image/png');
    }

    function clear() {
        const canvas = canvasRef.current;
        const context = canvas?.getContext('2d');
        if (!canvas || !context) {
            return;
        }
        context.clearRect(0, 0, canvas.width, canvas.height);
        onChange(null);
    }

    return (
        <div>
            <canvas
                ref={canvasRef}
                width={680}
                height={48}
                aria-label="Student signature"
                className="h-16 w-full touch-none border-b border-neutral-800 bg-white sm:h-12"
                onPointerDown={start}
                onPointerMove={move}
                onPointerUp={finish}
                onPointerCancel={finish}
            />
            <button
                className="mt-2 min-h-11 text-sm text-neutral-700 underline-offset-2 hover:underline sm:min-h-0"
                type="button"
                onClick={clear}
                disabled={disabled}
            >
                Clear signature
            </button>
        </div>
    );
}
