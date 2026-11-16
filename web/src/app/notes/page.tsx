import type { Metadata } from "next";
import { NotesGate } from "@/components/notes-gate";

export const metadata: Metadata = { title: "Notes" };

export default function NotesPage() {
  return <NotesGate />;
}
