import { cn } from '@/lib/utils';

/**
 * Ilustrasi panel login: alur tanam sawah dilihat dari atas (garis kontur
 * melengkung sejajar) dan satu daun bervena. Murni SVG inline di JSX, jadi
 * tidak butuh aset luar dan lolos CSP tanpa nonce. Dekoratif → aria-hidden.
 */
const ROWS = Array.from({ length: 22 }, (_, i) => i);

// Vena daun [posisi di tulang daun, panjang]: makin pendek ke ujung agar tetap di dalam helai.
const VEINS: [number, number][] = [
    [34, 34],
    [70, 32],
    [106, 26],
    [142, 16],
];

export function AuthFieldArt({ className }: { className?: string }) {
    return (
        <svg
            aria-hidden="true"
            focusable="false"
            className={cn('pointer-events-none', className)}
            viewBox="0 0 600 800"
            preserveAspectRatio="xMidYMid slice"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
        >
            <g stroke="currentColor" strokeLinecap="round">
                {ROWS.map((i) => {
                    const y = 300 + i * 26;
                    // Lengkung makin landai ke bawah, seperti petak sawah berundak.
                    const bend = 90 - i * 3;

                    return (
                        <path
                            key={i}
                            d={`M-40 ${y} C 140 ${y - bend} 320 ${y + bend * 0.9} 640 ${y - bend * 0.6}`}
                            strokeWidth={i % 4 === 0 ? 1.4 : 0.8}
                            strokeOpacity={0.08 + (i / ROWS.length) * 0.14}
                        />
                    );
                })}
            </g>

            <g
                transform="translate(400 120) rotate(32)"
                stroke="currentColor"
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeOpacity={0.32}
            >
                <path
                    d="M0 0 C 70 -40 150 -30 210 0 C 150 34 70 42 0 0 Z"
                    strokeWidth={1.6}
                    fill="currentColor"
                    fillOpacity={0.04}
                />
                <path d="M-36 0 L 196 0" strokeWidth={1.4} />
                {VEINS.map(([x, len]) => (
                    <g key={x} strokeWidth={1}>
                        <path
                            d={`M${x} 0 C ${x + len * 0.4} ${-len * 0.3} ${x + len * 0.75} ${-len * 0.55} ${x + len} ${-len * 0.72}`}
                        />
                        <path
                            d={`M${x} 0 C ${x + len * 0.4} ${len * 0.3} ${x + len * 0.75} ${len * 0.55} ${x + len} ${len * 0.72}`}
                        />
                    </g>
                ))}
            </g>
        </svg>
    );
}
