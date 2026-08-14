"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { ReactNode, useState } from "react";

const navigation = [
  { href: "/admin", label: "Vue d’ensemble", mark: "01" },
  { href: "/admin/commandes", label: "Commandes", mark: "02" },
  { href: "/admin/stock", label: "Stock & coûts", mark: "03" },
  { href: "/admin/analyse", label: "Analyse produits", mark: "04" },
];

export function AdminShell({ children }: { children: ReactNode }) {
  const pathname = usePathname();
  const [menuOpen, setMenuOpen] = useState(false);
  const closeMenu = () => setMenuOpen(false);
  const signOut = async () => {
    await fetch("/api/admin/session", { method: "DELETE", credentials: "same-origin" });
    window.location.assign("/admin/connexion");
  };

  if (pathname === "/admin/connexion") return <>{children}</>;

  return (
    <div className="admin-shell">
      <aside className={menuOpen ? "admin-sidebar admin-sidebar--open" : "admin-sidebar"}>
        <div className="admin-brand-row">
          <Link href="/admin" className="admin-wordmark" onClick={closeMenu}>L’Horloger</Link>
          <span>Gestion</span>
        </div>
        <nav className="admin-navigation" aria-label="Navigation administration">
          {navigation.map((item) => {
            const active = item.href === "/admin" ? pathname === item.href : pathname.startsWith(item.href);
            return (
              <Link key={item.href} href={item.href} className={active ? "admin-nav-link admin-nav-link--active" : "admin-nav-link"} onClick={closeMenu}>
                <span>{item.mark}</span>{item.label}
              </Link>
            );
          })}
        </nav>
        <div className="admin-sidebar__footer">
          <span className="admin-local-indicator">Accès sécurisé</span>
          <Link href="/" onClick={closeMenu}>Voir la boutique →</Link>
        </div>
      </aside>

      <div className="admin-main">
        <header className="admin-header">
          <button type="button" className="admin-menu-toggle" aria-label={menuOpen ? "Fermer la navigation" : "Ouvrir la navigation"} aria-expanded={menuOpen} onClick={() => setMenuOpen((open) => !open)}>
            <i /><i />
          </button>
          <div>
            <p>Centre de pilotage</p>
            <strong>Bonjour, équipe L’Horloger</strong>
          </div>
          <div className="admin-header__actions">
            <span className="admin-date">Mardi 19 août</span>
            <span className="admin-alert-count" aria-label="3 alertes nécessitent votre attention">3 alertes</span>
            <button type="button" className="admin-signout" onClick={signOut}>Se déconnecter</button>
          </div>
        </header>
        <div className="admin-content">{children}</div>
      </div>
    </div>
  );
}
