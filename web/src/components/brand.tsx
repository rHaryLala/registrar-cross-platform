import Image from "next/image";
import { cn } from "@/lib/utils";

const BASE = process.env.NEXT_PUBLIC_BASE_PATH ?? "";

export function Seal({ size = 36, className }: { size?: number; className?: string }) {
  return (
    <Image
      src={`${BASE}/sceau-uaz.png`}
      alt="Sceau de l’Université Adventiste Zurcher"
      width={size}
      height={size}
      className={cn("shrink-0 rounded-full bg-white", className)}
      priority
    />
  );
}

export function Wordmark({ className }: { className?: string }) {
  return (
    <span className={cn("flex items-center gap-3", className)}>
      <Seal />
      <span className="leading-tight">
        <span className="block text-[11px] font-semibold tracking-[0.14em] uppercase text-muted-foreground">Université Adventiste</span>
        <span className="block text-base font-extrabold tracking-tight text-accent-text">Zurcher</span>
      </span>
    </span>
  );
}
