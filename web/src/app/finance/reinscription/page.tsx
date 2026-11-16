import type { Metadata } from "next";
import { ReenrolmentList } from "@/components/reenrolment-list";
import { RequireFinance } from "@/components/require-role";

export const metadata: Metadata = { title: "Autorisation de réinscription" };

export default function ReenrolmentPage() {
  return (
    <RequireFinance>
      <ReenrolmentList />
    </RequireFinance>
  );
}
