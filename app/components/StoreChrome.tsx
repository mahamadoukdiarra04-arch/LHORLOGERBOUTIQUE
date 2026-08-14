"use client";

import Link from "next/link";
import { useState } from "react";
import { useCart } from "./CartProvider";

export function StoreHeader({ overlay = false }: { overlay?: boolean }) {
  const [menuOpen, setMenuOpen] = useState(false);
  const { itemCount } = useCart();

  const closeMenu = () => setMenuOpen(false);

  return (
    <div className={`site-top ${overlay ? "site-top--overlay" : ""}`}>
      <div className="announcement" role="status">
        Livraison offerte à Bamako <span>·</span> Paiement à la livraison
      </div>
      <header className="store-header">
        <Link className="wordmark" href="/" aria-label="L’Horloger, accueil" onClick={closeMenu}>
          L’Horloger
        </Link>
        <nav className={menuOpen ? "store-nav store-nav--open" : "store-nav"} aria-label="Navigation principale">
          <Link href="/montres" onClick={closeMenu}>
            Montres
          </Link>
          <Link href="/#maison" onClick={closeMenu}>
            La sélection
          </Link>
          <Link href="/#questions" onClick={closeMenu}>
            Questions
          </Link>
        </nav>
        <div className="header-actions">
          <Link className="cart-link" href="/panier" aria-label={`Panier, ${itemCount} article${itemCount > 1 ? "s" : ""}`}>
            Panier <span>{itemCount}</span>
          </Link>
          <button
            className="menu-toggle"
            type="button"
            aria-label={menuOpen ? "Fermer le menu" : "Ouvrir le menu"}
            aria-expanded={menuOpen}
            onClick={() => setMenuOpen((open) => !open)}
          >
            <i />
            <i />
          </button>
        </div>
      </header>
    </div>
  );
}

export function StoreFooter() {
  return (
    <footer className="store-footer">
      <div className="store-footer__top container">
        <div>
          <p className="wordmark">L’Horloger</p>
          <p>Le temps vous va si bien.</p>
        </div>
        <div className="footer-links">
          <Link href="/montres">Montres</Link>
          <Link href="/#questions">Livraison & questions</Link>
          <Link href="/panier">Panier</Link>
        </div>
      </div>
      <div className="store-footer__bottom container">
        <span>Livraison offerte dans nos zones couvertes</span>
        <span>·</span>
        <span>Paiement à la livraison</span>
      </div>
    </footer>
  );
}
