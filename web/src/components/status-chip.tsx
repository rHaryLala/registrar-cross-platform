import { cn } from "@/lib/utils";

const tones = {
  ok: "text-success",
  warn: "text-warning",
  bad: "text-destructive",
  info: "text-accent-text",
} as const;

/**
 * Pastille d'état : un point ET un mot. La couleur n'est jamais seule à porter le sens
 * (accessibilité : « Validé », « En attente », jamais un simple point vert).
 */
export function StatusChip({ tone, children, className }: { tone: keyof typeof tones; children: React.ReactNode; className?: string }) {
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border border-current px-2.5 py-0.5 text-xs font-bold",
        tones[tone],
        className,
      )}
    >
      <span aria-hidden className="size-1.5 rounded-full bg-current" />
      {children}
    </span>
  );
}
