import type { Metadata } from "next";
import { RequireStudent } from "@/components/require-student";
import { RubikGame } from "@/components/rubik-game";

export const metadata: Metadata = { title: "Jeu : Rubik’s Cube" };

export default function GamePage() {
  return (
    <RequireStudent>
      <RubikGame />
    </RequireStudent>
  );
}
