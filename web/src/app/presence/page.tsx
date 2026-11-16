import type { Metadata } from "next";
import { PresenceGate } from "@/components/presence-gate";

export const metadata: Metadata = { title: "Présence" };

export default function PresencePage() {
  return <PresenceGate />;
}
