export default function Heading({
    title,
    description,
    variant = 'default',
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
}) {
    return (
        <header
            className={variant === 'small' ? 'space-y-1' : 'mb-8 space-y-1.5'}
        >
            <h2
                className={
                    variant === 'small'
                        ? 'text-base font-semibold tracking-tight'
                        : 'text-2xl font-semibold tracking-tight'
                }
            >
                {title}
            </h2>
            {description && (
                <p
                    className={
                        variant === 'small'
                            ? 'text-sm text-muted-foreground'
                            : 'text-[15px] text-muted-foreground'
                    }
                >
                    {description}
                </p>
            )}
        </header>
    );
}
