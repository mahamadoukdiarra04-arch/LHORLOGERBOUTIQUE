"use client";

import { FormEvent, useState } from "react";
import { useRouter } from "next/navigation";

export function AdminLogin() {
  const router = useRouter();
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [message, setMessage] = useState("");
  const [submitting, setSubmitting] = useState(false);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (submitting) return;

    setSubmitting(true);
    setMessage("");
    try {
      const response = await fetch("/api/admin/session", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify({ username, password }),
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok) {
        setMessage(result.error ?? "La connexion n’a pas pu être établie.");
        return;
      }
      router.replace("/admin");
      router.refresh();
    } catch {
      setMessage("La connexion n’a pas pu être établie. Vérifiez votre accès internet.");
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <main className="admin-login-page">
      <section className="admin-login" aria-labelledby="admin-login-title">
        <a className="admin-login__brand" href="/">L’Horloger</a>
        <p className="admin-kicker">Accès équipe</p>
        <h1 id="admin-login-title">Connexion sécurisée</h1>
        <p>Connectez-vous pour accéder au pilotage des ventes, commandes et stocks.</p>
        <form onSubmit={submit}>
          <label><span>Identifiant</span><input value={username} onChange={(event) => setUsername(event.target.value)} autoComplete="username" autoCapitalize="characters" required /></label>
          <label><span>Mot de passe</span><input value={password} onChange={(event) => setPassword(event.target.value)} type="password" autoComplete="current-password" required /></label>
          {message && <p className="admin-login__message" role="alert">{message}</p>}
          <button type="submit" disabled={submitting}>{submitting ? "Connexion…" : "Accéder à l’administration"}</button>
        </form>
        <a className="admin-login__back" href="/">← Retour à la boutique</a>
      </section>
    </main>
  );
}
