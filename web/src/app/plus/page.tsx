import type { Metadata } from "next";
import { MoreMenu } from "@/components/more-menu";
import { RequireStudent } from "@/components/require-student";

export const metadata: Metadata = { title: "Plus" };

export default function MorePage() {
  return (
    <RequireStudent>
      <MoreMenu />
    </RequireStudent>
  );
}
