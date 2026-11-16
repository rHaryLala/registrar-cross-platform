import type { Metadata } from "next";
import { RequireTariff } from "@/components/require-role";
import { TariffEditor } from "@/components/tariff-editor";

export const metadata: Metadata = { title: "Barème financier" };

export default function TariffPage() {
  return (
    <RequireTariff>
      <TariffEditor />
    </RequireTariff>
  );
}
