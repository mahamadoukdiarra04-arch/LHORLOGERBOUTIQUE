"use client";

import Link from "next/link";
import { FormEvent, useMemo, useState } from "react";
import { formatPrice, products } from "../lib/catalog";
import { useCart } from "./CartProvider";

export function CheckoutPage() {
  const { lines, clearCart } = useCart();
  const [submitted, setSubmitted] = useState<string | null>(null);
  const [firstName, setFirstName] = useState("");

  const items = useMemo(
    () => lines.flatMap((line) => {
      const product = products.find((item) => item.id === line.productId);
      const variant = product?.variants.find((item) => item.label === (line.variant ?? line.bracelet));
      return product ? [{ line, product, variant }] : [];
    }),
    [lines],
  );
  const total = items.reduce((sum, { line, product }) => sum + line.quantity * product.price, 0);

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setFirstName(String(form.get("firstName") || ""));
    setSubmitted(`HOR-2608-${Math.floor(100000 + Math.random() * 900000)}`);
    clearCart();
  };

  if (submitted) {
    return (
      <main className="checkout-page container">
        <section className="checkout-success">
          <p className="eyebrow dark">Commande enregistrée</p>
          <h1>Merci, {firstName}.</h1>
          <strong>Référence {submitted}</strong>
          <p>L’équipe L’Horloger vous contactera pour valider le quartier et le créneau de livraison.</p>
          <ol>
            <li><b>01</b><span>Confirmation de vos informations</span></li>
            <li><b>02</b><span>Préparation de votre montre</span></li>
            <li><b>03</b><span>Livraison et paiement à la réception</span></li>
          </ol>
          <small>Préversion locale : cette demande n’est pas encore transmise à une équipe.</small>
          <Link className="button" href="/montres">Retour aux montres</Link>
        </section>
      </main>
    );
  }

  if (!items.length) {
    return (
      <main className="checkout-page container">
        <section className="empty-state">
          <p className="eyebrow dark">Aucun article à confirmer</p>
          <h1>Votre panier est vide.</h1>
          <Link className="button" href="/montres">Voir les montres</Link>
        </section>
      </main>
    );
  }

  return (
    <main className="checkout-page container">
      <div className="checkout-progress" aria-label="Étapes de commande">
        <span className="checkout-progress__active">01 Panier</span><span>02 Informations</span><span>03 Confirmation</span>
      </div>
      <div className="checkout-layout">
        <section>
          <div className="page-intro page-intro--checkout">
            <p className="eyebrow dark">Finaliser votre commande</p>
            <h1>Vos informations de livraison</h1>
            <p>Nous vous contacterons avant toute livraison.</p>
          </div>
          <form className="checkout-form" onSubmit={submit}>
            <div className="form-row">
              <label>Prénom<input name="firstName" autoComplete="given-name" required /></label>
              <label>Nom<input name="lastName" autoComplete="family-name" required /></label>
            </div>
            <div className="form-row">
              <label>Téléphone<input name="phone" inputMode="tel" autoComplete="tel" placeholder="+223 XX XX XX XX" required /></label>
              <label>Quartier<input name="district" autoComplete="address-level3" required /></label>
            </div>
            <button className="button" type="submit">Confirmer ma commande</button>
          </form>
        </section>
        <aside className="checkout-summary">
          <h2>Votre commande</h2>
          {items.map(({ line, product }) => <div className="checkout-summary__line" key={line.key}><span>{product.name} · {line.variant ?? line.bracelet ?? product.bracelet} × {line.quantity}</span><strong>{formatPrice(product.price * line.quantity)}</strong></div>)}
          <div className="checkout-summary__line"><span>Livraison</span><strong>Offerte</strong></div>
          <div className="checkout-summary__total"><span>Total à la réception</span><strong>{formatPrice(total)}</strong></div>
          <Link className="quiet-link" href="/panier">Modifier mon panier</Link>
        </aside>
      </div>
    </main>
  );
}
