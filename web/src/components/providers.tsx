"use client";

import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { ThemeProvider } from "next-themes";
import { useEffect, useState, type ReactNode } from "react";
import { Toaster } from "@/components/ui/sonner";
import { installChunkRecovery } from "@/lib/chunk-recovery";
import { registerServiceWorker } from "@/lib/offline-shell";

export function Providers({ children }: { children: ReactNode }) {
  const [client] = useState(
    () =>
      new QueryClient({
        defaultOptions: { queries: { refetchOnWindowFocus: false, retry: 1 } },
      }),
  );

  // Un fichier de l'application qui ne se charge pas (réseau coupé, nouvelle version en ligne) : une seule reprise automatique.
  useEffect(() => installChunkRecovery(), []);
  // Coque de l'application gardée pour un usage sans réseau (production seulement).
  useEffect(() => registerServiceWorker(), []);

  return (
    // Le thème suit le système par défaut (téléphone en plein soleil : clair ; poste de bureau : sombre au choix).
    <ThemeProvider attribute="class" defaultTheme="system" enableSystem disableTransitionOnChange>
      <QueryClientProvider client={client}>
        {children}
        <Toaster position="top-center" />
      </QueryClientProvider>
    </ThemeProvider>
  );
}
