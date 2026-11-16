import type { Metadata } from "next";
import { DeadlinesEditor } from "@/components/deadlines-editor";
import { RequireDeadlines } from "@/components/require-role";

export const metadata: Metadata = { title: "Échéances de paiement" };

export default function DeadlinesPage() {
  return (
    <RequireDeadlines>
      <DeadlinesEditor />
    </RequireDeadlines>
  );
}
