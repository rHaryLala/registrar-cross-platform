import type { Metadata } from "next";
import { MoreMenu } from "@/components/more-menu";
import { RequirePlus } from "@/components/require-role";

export const metadata: Metadata = { title: "Plus" };

export default function MorePage() {
  return (
    <RequirePlus>
      <MoreMenu />
    </RequirePlus>
  );
}
