"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { WatchProduct, formatPrice, products } from "../lib/catalog";
import { useCart } from "./CartProvider";
import { ProductCard } from "./ProductCard";

function ProductVariantPicker({
  product,
  selectedVariantId,
  onSelect,
  className = "",
}: {
  product: WatchProduct;
  selectedVariantId?: string;
  onSelect: (variantId: string) => void;
  className?: string;
}) {
  const selectedVariant = product.variants.find((variant) => variant.id === selectedVariantId) ?? product.variants[0];

  if (product.variants.length < 2) return null;

  return (
    <section className={`product-variants ${className}`} aria-label="Choisir un coloris">
      <div className="product-variants__heading"><b>Choisir le coloris</b><span>{selectedVariant?.description}</span></div>
      <div className="product-variants__choices">
        {product.variants.map((variant) => (
          <button
            className={selectedVariant?.id === variant.id ? "product-variant product-variant--active" : "product-variant"}
            type="button"
            key={variant.id}
            aria-pressed={selectedVariant?.id === variant.id}
            onClick={() => onSelect(variant.id)}
          >
            <img src={variant.image.src} alt="" />
            <span className="product-variant__swatch" style={{ background: variant.swatch }} />
            <span>{variant.label}</span>
          </button>
        ))}
      </div>
    </section>
  );
}

export function ProductDetails({ product }: { product: WatchProduct }) {
  const { addToCart } = useCart();
  const router = useRouter();
  const [quantity, setQuantity] = useState(1);
  const [activeImage, setActiveImage] = useState(0);
  const [selectedVariantId, setSelectedVariantId] = useState(product.variants[0]?.id);
  const selectedVariant = product.variants.find((variant) => variant.id === selectedVariantId) ?? product.variants[0];
  const gallery = selectedVariant
    ? [selectedVariant.image, ...product.gallery.filter((image) => image.src !== selectedVariant.image.src)]
    : product.gallery;

  const orderNow = () => {
    addToCart(product.id, quantity, selectedVariant?.label ?? product.bracelet);
    router.push("/commande");
  };

  const related = products.filter((item) => item.id !== product.id).slice(0, 3);

  return (
    <>
      <div className="breadcrumbs container">
        <Link href="/">Accueil</Link><span>/</span><Link href="/montres">Montres</Link><span>/</span><span>{product.name}</span>
      </div>
      <section className="product-page container">
        <div className="product-gallery">
          <figure className="product-gallery__main">
            <img src={gallery[activeImage].src} alt={gallery[activeImage].alt} />
            <span className="product-gallery__reference">{product.reference}</span>
            <span className="product-gallery__counter">{activeImage + 1} / {gallery.length}</span>
            <figcaption>{gallery[activeImage].caption}</figcaption>
          </figure>
          <ProductVariantPicker
            product={product}
            selectedVariantId={selectedVariantId}
            className="product-variants--quick"
            onSelect={(variantId) => { setSelectedVariantId(variantId); setActiveImage(0); }}
          />
          <div className="product-gallery__thumbs" aria-label={`Galerie de ${product.name}`}>
            {gallery.map((image, index) => (
              <button
                className={`gallery-thumb ${activeImage === index ? "gallery-thumb--active" : ""}`}
                type="button"
                key={image.src}
                aria-label={`Afficher ${image.label}`}
                aria-pressed={activeImage === index}
                onClick={() => setActiveImage(index)}
              >
                <img src={image.src} alt="" />
                <span>{image.label}</span>
              </button>
            ))}
          </div>
        </div>

        <div className="product-summary">
          <p className="eyebrow dark">Sélection L’Horloger · {product.reference}</p>
          {product.badge && <span className="product-status">{product.badge}</span>}
          <h1>{product.name}</h1>
          <p className="product-subtitle">{product.shortDescription}</p>
          <p className="product-price">{formatPrice(product.price)}</p>
          <p className="product-copy">{product.description}</p>
          <div className="product-story">
            <p className="eyebrow dark">L’esprit du modèle</p>
            <h2>{product.storyTitle}</h2>
            <p>{product.styleNote}</p>
          </div>
          <div className="product-choice" aria-label="Caractéristiques choisies">
            <span><b>Coloris</b>{selectedVariant?.label ?? product.dial}</span>
            <span><b>Finition</b>{product.finish}</span>
            <span><b>Diamètre</b>{product.size}</span>
          </div>
          <ProductVariantPicker
            product={product}
            selectedVariantId={selectedVariantId}
            className="product-variants--summary"
            onSelect={(variantId) => { setSelectedVariantId(variantId); setActiveImage(0); }}
          />
          <div className="product-highlights" aria-label="Points forts du modèle">
            {product.highlights.map(([label, value]) => <div key={label}><b>{label}</b><span>{value}</span></div>)}
          </div>
          <div className="purchase-row">
            <div className="quantity-control" aria-label="Quantité">
              <button type="button" aria-label="Diminuer la quantité" onClick={() => setQuantity((value) => Math.max(1, value - 1))}>−</button>
              <span>{quantity}</span>
              <button type="button" aria-label="Augmenter la quantité" onClick={() => setQuantity((value) => value + 1)}>+</button>
            </div>
            <button className="button purchase-button" type="button" onClick={orderNow}>Commander maintenant</button>
          </div>
          <div className="purchase-reassurance"><span>Livraison offerte à Bamako</span><span>Paiement à la réception</span></div>
          <div className="product-accordions">
            <details open><summary>Fiche technique</summary><p className="product-specification-note">Les dimensions et fonctions sont renseignées selon la fiche du modèle ; les finitions complètent sa description visuelle.</p><dl>{product.specifications.map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl></details>
            <details><summary>Livraison à Bamako</summary><p>Renseignez simplement votre quartier. L’équipe L’Horloger vous contacte ensuite pour confirmer votre commande et convenir du créneau de livraison.</p></details>
            <details><summary>Paiement à la réception</summary><p>Le règlement se fait au moment de la réception de votre commande. Aucune information bancaire n’est demandée sur ce site.</p></details>
          </div>
        </div>
      </section>
      <section className="product-editorial container">
        <div className="product-editorial__image"><img src={product.editorial.image} alt={product.editorial.alt} /></div>
        <div className="product-editorial__copy">
          <p className="eyebrow dark">{product.editorial.eyebrow}</p>
          <h2>{product.editorial.title}</h2>
          <p>{product.editorial.copy}</p>
          <span>Portée en situation réelle</span>
        </div>
      </section>
      <section className="product-benefits container" aria-label={`Atouts de ${product.name}`}>
        <div className="product-benefits__intro"><p className="eyebrow dark">Repères essentiels</p><h2>Les fonctions en un regard.</h2></div>
        <div className="product-benefits__grid">
          {product.benefits.map((benefit) => (
            <article key={benefit.label}>
              <strong>{benefit.mark}</strong>
              <div><span>{benefit.label}</span><h3>{benefit.value}</h3><p>{benefit.copy}</p></div>
            </article>
          ))}
        </div>
      </section>
      <section className="product-features container">
        <div className="product-features__head">
          <p className="eyebrow dark">Les détails qui comptent</p>
          <h2>Une montre pensée pour être portée.</h2>
        </div>
        <div className="product-features__grid">
          {product.features.map((feature, index) => (
            <article key={feature.title}>
              <span>0{index + 1}</span>
              <h3>{feature.title}</h3>
              <p>{feature.copy}</p>
            </article>
          ))}
        </div>
      </section>
      <section className="related-section container">
        <div className="section-title-row"><div><p className="eyebrow dark">Continuer la sélection</p><h2>Autres modèles</h2></div><Link className="quiet-link" href="/montres">Voir toutes les montres</Link></div>
        <div className="catalog-grid catalog-grid--three">{related.map((item) => <ProductCard product={item} key={item.id} />)}</div>
      </section>
      <div className="mobile-buy-bar"><span>{formatPrice(product.price)}</span><button type="button" onClick={orderNow}>Commander</button></div>
    </>
  );
}
