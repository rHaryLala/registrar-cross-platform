import type { Metadata } from "next";
import { BalancesList } from "@/components/balances-list";
import { RequireBalances } from "@/components/require-role";

export const metadata: Metadata = { title: "Solde de compte" };

export default function BalancesPage() {
  return (
    <RequireBalances>
      <BalancesList />
    </RequireBalances>
  );
}
