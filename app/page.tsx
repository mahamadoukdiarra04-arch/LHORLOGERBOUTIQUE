"use client";

import { FormEvent, useState } from "react";

const products = [
  {
    id: "tuma-01",
    name: "TUMA 01",
    detail: "Cadran sombre · bracelet cuir",
    tone: "noir",
  },
  {
    id: "tuma-02",
    name: "TUMA 02",
    detail: "Cadran clair · bracelet acier",
    tone: "ivoire",
  },
  {
    id: "tuma-03",
    name: "TUMA 03",
    detail: "Cadran vert profond · maille acier",
    tone: "vert",
  },
];

const faqs = [
  [
    "Comment se passe le paiement ?",
    "Vous réglez uniquement à la réception. Aucune information bancaire n’est demandée sur le site.",
  ],
  [
    "La livraison est-elle vraiment gratuite ?",
    "Oui, dans les zones couvertes. Le quartier et le créneau sont confirmés avec vous avant tout déplacement.",
  ],
  [
    "Quand vais-je recevoir ma commande ?",
    "Le créneau de livraison vous est communiqué pendant notre confirmation par appel ou message.",
  ],
  [
    "Puis-je modifier ou annuler ?",
    "Oui, avant le départ en livraison. Contactez TUMA avec votre référence de commande.",
  ],
];

export default function Home() {
  const [selectedProduct, setSelectedProduct] = useState(products[0].id);
  const [orderReference, setOrderReference] = useState<string | null>(null);
  const [firstName, setFirstName] = useState("");

  const chooseProduct = (productId: string) => {
    setSelectedProduct(productId);
    document.getElementById("commande")?.scrollIntoView({ behavior: "smooth" });
  };

  const submitOrder = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setFirstName(String(form.get("firstName") || ""));
    setOrderReference(`TUMA-2608-${Math.floor(100000 + Math.random() * 900000)}`);
  };

  return (
    <main>
      <div className="announcement" role="status">
        Livraison offerte à Bamako <span>·</span> Paiement à la livraison
      </div>

      <header className="site-header">
        <a className="wordmark" href="#top" aria-label="TUMA, accueil">
          TUMA
        </a>
        <nav aria-label="Navigation principale">
          <a href="#montres">Montres</a>
          <a href="#maison">La sélection</a>
          <a href="#questions">Questions</a>
        </nav>
        <a className="header-button" href="#commande">
          Commander
        </a>
      </header>

      <section className="hero" id="top">
        <div className="hero-copy">
          <p className="eyebrow">Sélection de montres · Bamako</p>
          <h1>Une montre qui finit la tenue.</h1>
          <p className="hero-intro">
            Des modèles choisis pour être portés souvent. Vous commandez en quelques minutes,
            nous livrons gratuitement et vous payez à la réception.
          </p>
          <div className="hero-actions">
            <a className="button button-light" href="#montres">
              Voir la sélection
            </a>
            <a className="text-link" href="#fonctionnement">
              Comment ça marche <span aria-hidden="true">↓</span>
            </a>
          </div>
        </div>
        <img
          className="hero-image"
          src="/og.png"
          alt="Montre classique à boîtier doré et bracelet noir, visuel d’ambiance TUMA"
        />
      </section>

      <section className="proofs" aria-label="Les engagements TUMA">
        <div>
          <strong>0 FCFA</strong>
          <span>de frais de livraison</span>
        </div>
        <div>
          <strong>À la réception</strong>
          <span>vous réglez votre commande</span>
        </div>
        <div>
          <strong>À Bamako</strong>
          <span>livraison sur rendez-vous</span>
        </div>
      </section>

      <section className="section collection" id="montres">
        <div className="section-heading">
          <div>
            <p className="eyebrow dark">La sélection TUMA</p>
            <h2>Les modèles du moment</h2>
          </div>
          <p>
            Une sélection courte, avec les dimensions et finitions utiles pour choisir sans
            hésiter.
          </p>
        </div>

        <div className="product-grid">
          {products.map((product, index) => (
            <article className="product-card" key={product.id}>
              <div className={`watch-stage ${product.tone}`} aria-hidden="true">
                <span className="watch-strap top" />
                <span className="watch-case">
                  <span className="watch-face">
                    <i />
                    <b />
                  </span>
                </span>
                <span className="watch-strap bottom" />
                <span className="product-number">0{index + 1}</span>
              </div>
              <div className="product-copy">
                <p className="product-label">Modèle de démonstration</p>
                <h3>{product.name}</h3>
                <p>{product.detail}</p>
                <div className="product-bottom">
                  <span className="price-placeholder">Prix à confirmer</span>
                  <button className="button-link" onClick={() => chooseProduct(product.id)}>
                    Choisir <span aria-hidden="true">↗</span>
                  </button>
                </div>
              </div>
            </article>
          ))}
        </div>
        <p className="catalogue-note">
          Les modèles, leurs photos et leurs prix sont à remplacer par le catalogue réellement
          disponible avant ouverture.
        </p>
      </section>

      <section className="brand-section" id="maison">
        <div className="brand-rule" />
        <p className="eyebrow dark">TUMA · Le temps vous va bien</p>
        <h2>Pas besoin d’en faire trop.</h2>
        <p>
          Une ligne nette, un cadran lisible, un bracelet qui tombe bien. TUMA réunit des
          montres faciles à porter au travail, en sortie ou quand vous voulez simplement soigner
          le détail.
        </p>
        <a className="button" href="#montres">
          Choisir ma montre
        </a>
      </section>

      <section className="section steps" id="fonctionnement">
        <div className="section-heading compact">
          <div>
            <p className="eyebrow dark">Simple et direct</p>
            <h2>Choisissez. Nous confirmons. Vous recevez.</h2>
          </div>
        </div>
        <ol>
          <li>
            <span>01</span>
            <div>
              <h3>Choisissez</h3>
              <p>Sélectionnez le modèle et laissez vos coordonnées.</p>
            </div>
          </li>
          <li>
            <span>02</span>
            <div>
              <h3>Nous confirmons</h3>
              <p>Nous vous contactons pour valider le quartier et le créneau.</p>
            </div>
          </li>
          <li>
            <span>03</span>
            <div>
              <h3>Vous recevez</h3>
              <p>La livraison est offerte. Le paiement se fait à la réception.</p>
            </div>
          </li>
        </ol>
      </section>

      <section className="order-section" id="commande">
        <div className="order-intro">
          <p className="eyebrow">Votre sélection</p>
          <h2>Préparer ma commande</h2>
          <p>
            Laissez juste ce qu’il faut pour que nous puissions vous rappeler et organiser la
            livraison. Vous ne saisirez aucune information bancaire ici.
          </p>
          <p className="contact-note">
            Une question avant de choisir ? <a href="#questions">Consultez nos réponses</a>.
          </p>
        </div>

        <form className="order-form" onSubmit={submitOrder}>
          <label>
            Modèle choisi
            <select
              name="product"
              value={selectedProduct}
              onChange={(event) => setSelectedProduct(event.target.value)}
              required
            >
              {products.map((product) => (
                <option value={product.id} key={product.id}>
                  {product.name} — catalogue à compléter
                </option>
              ))}
            </select>
          </label>
          <div className="form-row">
            <label>
              Prénom
              <input name="firstName" autoComplete="given-name" required />
            </label>
            <label>
              Nom
              <input name="lastName" autoComplete="family-name" required />
            </label>
          </div>
          <div className="form-row">
            <label>
              Téléphone
              <input
                name="phone"
                inputMode="tel"
                autoComplete="tel"
                placeholder="+223 XX XX XX XX"
                required
              />
            </label>
            <label>
              Commune
              <select name="commune" required defaultValue="">
                <option value="" disabled>
                  Choisir
                </option>
                <option>Commune I</option>
                <option>Commune II</option>
                <option>Commune III</option>
                <option>Commune IV</option>
                <option>Commune V</option>
                <option>Commune VI</option>
                <option>Autre zone</option>
              </select>
            </label>
          </div>
          <label>
            Quartier
            <input name="district" required />
          </label>
          <label>
            Repère pour la livraison
            <textarea name="landmark" rows={3} required />
          </label>
          <label className="checkbox-label">
            <input type="checkbox" name="consent" required />
            <span>J’accepte d’être contacté(e) pour confirmer cette commande.</span>
          </label>
          <button className="button button-light full" type="submit">
            Confirmer ma commande
          </button>
          <small>Vous ne saisirez aucune information bancaire sur ce site.</small>

          {orderReference && (
            <div className="order-preview" role="status">
              <p>Préversion du parcours</p>
              <h3>Merci, {firstName || ""}.</h3>
              <strong>Référence {orderReference}</strong>
              <span>
                Votre demande serait ensuite confirmée par l’équipe TUMA. Aucun envoi n’est fait
                depuis cette préversion.
              </span>
            </div>
          )}
        </form>
      </section>

      <section className="section faq" id="questions">
        <div className="section-heading compact">
          <div>
            <p className="eyebrow dark">Questions fréquentes</p>
            <h2>Tout est clair avant de commander.</h2>
          </div>
          <p>Les conditions exactes de livraison, d’échange et de garantie seront publiées ici.</p>
        </div>
        <div className="faq-list">
          {faqs.map(([question, answer]) => (
            <details key={question}>
              <summary>{question}</summary>
              <p>{answer}</p>
            </details>
          ))}
        </div>
      </section>

      <footer>
        <div className="footer-top">
          <div>
            <p className="wordmark">TUMA</p>
            <p>Montres sélectionnées à Bamako.</p>
          </div>
          <div className="footer-links">
            <a href="#montres">Montres</a>
            <a href="#questions">Livraison & questions</a>
            <a href="#commande">Commander</a>
          </div>
        </div>
        <div className="footer-bottom">
          <span>Livraison offerte dans nos zones couvertes</span>
          <span>·</span>
          <span>Paiement à la livraison</span>
        </div>
      </footer>
    </main>
  );
}
