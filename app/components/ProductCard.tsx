"use client";

import Link from "next/link";
import { useState } from "react";
import { WatchProduct, formatPrice } from "../lib/catalog";
import { useCart } from "./CartProvider";
import { WatchVisual } from "./WatchVisual";

export function ProductCard({ product }: { product: WatchProduct }) {
  const { addToCart } = useCart();
  const [added, setAdded] = useState(false);

  const add = () => {
    addToCart(product.id, 1, product.variants[0]?.label ?? product.bracelet);
    setAdded(true);
    window.setTimeout(() => setAdded(false), 1800);
  };

  return (
    <article className="catalog-card">
      <Link className="catalog-card__visual" href={`/montres/${product.slug}`} aria-label={`Voir ${product.name}`}>
        <WatchVisual product={product} compact showReference />
        {product.badge && <span className="catalog-card__badge">{product.badge}</span>}
      </Link>
      <div className="catalog-card__body">
        <p className="catalog-card__eyebrow">Sélection L’Horloger</p>
        <div className="catalog-card__title-row">
          <div>
            <h3>{product.name}</h3>
            <p>{product.shortDescription}</p>
          </div>
          <strong>{formatPrice(product.price)}</strong>
        </div>
        <div className="catalog-card__actions">
          <Link className="quiet-link" href={`/montres/${product.slug}`}>
            Voir le modèle
          </Link>
          <button className="add-button" type="button" onClick={add}>
            {added ? "Ajouté" : "Ajouter"}
          </button>
        </div>
      </div>
    </article>
  );
}
