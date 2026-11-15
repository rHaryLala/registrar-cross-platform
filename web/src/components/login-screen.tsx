"use client";

import { Eye, EyeOff, Loader2 } from "lucide-react";
import { useRouter } from "next/navigation";
import { useEffect, useRef, useState, useSyncExternalStore, type FormEvent } from "react";
import { Wordmark } from "@/components/brand";
import { GoogleSignIn } from "@/components/google-sign-in";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Separator } from "@/components/ui/separator";
import { ApiError } from "@/lib/api";
import { useGoogleLogin, useLogin, useMe } from "@/lib/auth";

/** Message affiché pour une erreur : on s'appuie sur le `detail` de l'API, qui dit quoi faire. */
function messageFor(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.code === "validation_failed") return "Renseignez votre identifiant et votre mot de passe.";
    return error.detail ?? error.title;
  }
  return "Connexion impossible pour le moment. Réessayez dans un instant.";
}

export function LoginScreen() {
  const router = useRouter();
  const me = useMe();
  const login = useLogin();
  const google = useGoogleLogin();
  const [identifier, setIdentifier] = useState("");
  const [password, setPassword] = useState("");
  const [reveal, setReveal] = useState(false);
  const alertRef = useRef<HTMLDivElement>(null);
  // Faux pendant le rendu serveur et l'hydratation, vrai ensuite : avant, le formulaire ne doit rien envoyer.
  const ready = useSyncExternalStore(
    () => () => {},
    () => true,
    () => false,
  );

  const error = login.error ?? google.error;
  const busy = login.isPending || google.isPending;

  useEffect(() => {
    if (me.data) router.replace("/");
  }, [me.data, router]);

  // Une erreur est annoncée et reçoit le focus : la personne au clavier ou au lecteur d'écran la trouve tout de suite.
  useEffect(() => {
    if (error) alertRef.current?.focus();
  }, [error]);

  function submit(e: FormEvent) {
    e.preventDefault();
    google.reset();
    login.mutate({ identifier: identifier.trim(), password }, { onSuccess: () => router.replace("/") });
  }

  return (
    <div className="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center gap-8 px-5 py-10">
      <Wordmark />

      <div>
        <h1 className="text-3xl font-black tracking-tight">Se connecter</h1>
        <p className="mt-2 text-muted-foreground">Étudiants et enseignants : utilisez votre adresse @zurcher.edu.mg.</p>
      </div>

      {error && (
        <div
          ref={alertRef}
          tabIndex={-1}
          role="alert"
          className="rounded-lg border border-destructive p-3 text-sm font-medium text-destructive outline-none"
        >
          {messageFor(error)}
        </div>
      )}

      <GoogleSignIn
        onCredential={(credential) => {
          login.reset();
          google.mutate(credential, { onSuccess: () => router.replace("/") });
        }}
      />

      <div className="flex items-center gap-3 text-sm text-muted-foreground" aria-hidden>
        <Separator className="flex-1" /> ou <Separator className="flex-1" />
      </div>

      {/* method="post" : si le formulaire part avant que React soit prêt (téléphone lent, JavaScript bloqué), jamais d'identifiants
          dans l'adresse (historique, journaux de serveur). Le bouton reste inactif tant que la page n'est pas prête. */}
      <form onSubmit={submit} method="post" className="grid gap-4" noValidate>
        <div className="grid gap-2">
          <Label htmlFor="identifier">Identifiant ou adresse e-mail</Label>
          <Input
            id="identifier"
            name="identifier"
            autoComplete="username"
            autoCapitalize="none"
            spellCheck={false}
            required
            value={identifier}
            onChange={(e) => setIdentifier(e.target.value)}
            aria-invalid={login.error instanceof ApiError && login.error.status === 422 && !identifier.trim() ? true : undefined}
          />
        </div>

        <div className="grid gap-2">
          <Label htmlFor="password">Mot de passe</Label>
          <div className="relative">
            <Input
              id="password"
              name="password"
              type={reveal ? "text" : "password"}
              autoComplete="current-password"
              required
              className="pr-12"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="absolute top-0 right-0"
              aria-label={reveal ? "Masquer le mot de passe" : "Afficher le mot de passe"}
              aria-pressed={reveal}
              onClick={() => setReveal((v) => !v)}
            >
              {reveal ? <EyeOff aria-hidden /> : <Eye aria-hidden />}
            </Button>
          </div>
        </div>

        <Button type="submit" size="lg" disabled={busy || !ready}>
          {busy && <Loader2 className="animate-spin" aria-hidden />}
          {busy ? "Connexion…" : "Se connecter"}
        </Button>
      </form>
    </div>
  );
}
