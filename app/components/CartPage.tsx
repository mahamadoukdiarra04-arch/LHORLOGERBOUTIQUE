"use client";

import Link from "next/link";
import { formatPrice, products } from "../lib/catalog";
import { useCart } from "./CartProvider";
import { WatchVisual } from "./WatchVisual";

export function CartPage() {
  const { lines, updateQuantity, removeFromCart } = useCart();
  const items = lines.flatMap((line) => {
    const product = products.find((item) => item.id === line.productId);
    const variant = product?.variants.find((item) => item.label === (line.variant ?? line.bracelet));
    return product ? [{ line, product, variant }] : [];
  });
  const subtotal = items.reduce((total, { line, product }) => total + line.quantity * product.price, 0);

  return (
    <main className="cart-page container">
      <div className="page-intro">
        <p className="eyebrow dark">Votre sélection</p>
        <h1>Panier</h1>
      </div>
      {!items.length ? (
        <section className="empty-state">
          <p className="eyebrow dark">Votre panier est vide</p>
          <h2>La sélection L’Horloger vous attend.</h2>
          <Link className="button" href="/montres">Voir les montres</Link>
        </section>
      ) : (
        <div className="cart-layout">
          <section className="cart-lines" aria-label="Articles du panier">
            {items.map(({ line, product, variant }) => (
              <article className="cart-line" key={line.key}>
                <Link className="cart-line__visual" href={`/montres/${product.slug}`}>
                  <WatchVisual product={product} compact image={variant?.image.src} />
                </Link>
                <div className="cart-line__copy">
                  <p>Sélection L’Horloger · {product.reference}</p>
                  <h2><Link href={`/montres/${product.slug}`}>{product.name}</Link></h2>
                  <span>{line.variant ?? line.bracelet ?? product.bracelet} · {product.size}</span>
                  <button className="remove-link" type="button" onClick={() => removeFromCart(line.key)}>Retirer</button>
                </div>
                <div className="cart-line__price">
                  <strong>{formatPrice(product.price * line.quantity)}</strong>
                  <div className="quantity-control quantity-control--small">
                    <button type="button" aria-label={`Diminuer ${product.name}`} onClick={() => updateQuantity(line.key, line.quantity - 1)}>−</button>
                    <span>{line.quantity}</span>
                    <button type="button" aria-label={`Augmenter ${product.name}`} onClick={() => updateQuantity(line.key, line.quantity + 1)}>+</button>
                  </div>
                </div>
              </article>
            ))}
          </section>
          <aside className="cart-summary">
            <h2>Récapitulatif</h2>
            <div><span>Sous-total</span><strong>{formatPrice(subtotal)}</strong></div>
            <div><span>Livraison</span><strong>Offerte</strong></div>
            <div className="cart-summary__total"><span>Total</span><strong>{formatPrice(subtotal)}</strong></div>
            <Link className="button" href="/commande">Passer à la commande</Link>
            <p>Vous paierez uniquement à la réception, après confirmation de votre commande.</p>
          </aside>
        </div>
      )}
    </main>
  );
}
