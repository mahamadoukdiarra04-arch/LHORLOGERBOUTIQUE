import Link from "next/link";
import { ProductCard } from "./components/ProductCard";
import { StoreFooter, StoreHeader } from "./components/StoreChrome";
import { products } from "./lib/catalog";

const faqs = [
  ["Comment se passe le paiement ?", "Vous payez uniquement à la réception. Aucune information bancaire n’est demandée sur le site."],
  ["La livraison est-elle vraiment gratuite ?", "Oui dans les zones couvertes. Le quartier et le créneau sont confirmés avec vous avant tout déplacement."],
  ["Quand vais-je recevoir ma commande ?", "Le créneau vous est communiqué lors de la confirmation par appel ou message."],
  ["Puis-je modifier ou annuler ?", "Oui avant le départ en livraison. Contactez L’Horloger avec votre référence de commande."],
];

export default function Home() {
  return (
    <div className="page-shell home-shell">
      <StoreHeader overlay />
      <main>
        <section className="home-hero">
          <div className="home-hero__copy">
            <p className="eyebrow">Sélection de montres · Bamako</p>
            <h1>Une montre qui finit la tenue.</h1>
            <p>Des modèles choisis pour être portés souvent. Explorez la sélection, ajoutez votre modèle au panier, puis réglez à la réception.</p>
            <div className="hero-actions">
              <Link className="button button-light" href="/montres">Voir la sélection</Link>
              <a className="text-link" href="#fonctionnement">Comment ça marche <span aria-hidden="true">↓</span></a>
            </div>
          </div>
          <img className="home-hero__image" src="/products/nocturne-chrono.png" alt="Nocturne Chrono, montre noire et dorée portée au poignet" />
        </section>

        <section className="proofs" aria-label="Les engagements L’Horloger">
          <div><strong>0 FCFA</strong><span>de frais de livraison</span></div>
          <div><strong>À la réception</strong><span>vous réglez votre commande</span></div>
          <div><strong>À Bamako</strong><span>livraison sur rendez-vous</span></div>
        </section>

        <section className="section container" id="montres">
          <div className="section-title-row">
            <div><p className="eyebrow dark">La sélection L’Horloger</p><h2>Les modèles du moment</h2></div>
            <div><p>Une sélection courte, avec les détails utiles pour choisir sans hésiter.</p><Link className="quiet-link" href="/montres">Voir le catalogue</Link></div>
          </div>
          <div className="catalog-grid catalog-grid--three">{products.slice(0, 3).map((product) => <ProductCard key={product.id} product={product} />)}</div>
          <p className="catalogue-note">Une sélection courte de trois montres, choisies pour leurs lignes affirmées et leurs détails singuliers.</p>
        </section>

        <section className="brand-section" id="maison">
          <div className="brand-rule" />
          <p className="eyebrow dark">L’Horloger · Le temps vous va bien</p>
          <h2>Pas besoin d’en faire trop.</h2>
          <p>Une ligne nette, un cadran lisible, un bracelet qui tombe bien. L’Horloger réunit des montres faciles à porter au travail, en sortie ou quand vous voulez simplement soigner le détail.</p>
          <Link className="button" href="/montres">Choisir ma montre</Link>
        </section>

        <section className="section container steps" id="fonctionnement">
          <div className="section-title-row section-title-row--single"><div><p className="eyebrow dark">Simple et direct</p><h2>Choisissez. Nous confirmons. Vous recevez.</h2></div></div>
          <ol>
            <li><span>01</span><div><h3>Choisissez</h3><p>Ajoutez votre modèle au panier et passez à la commande.</p></div></li>
            <li><span>02</span><div><h3>Nous confirmons</h3><p>Nous vous contactons pour valider le quartier et le créneau.</p></div></li>
            <li><span>03</span><div><h3>Vous recevez</h3><p>La livraison est offerte. Le paiement se fait à la réception.</p></div></li>
          </ol>
        </section>

        <section className="section container faq" id="questions">
          <div className="section-title-row"><div><p className="eyebrow dark">Questions fréquentes</p><h2>Tout est clair avant de commander.</h2></div><p>Les conditions exactes de livraison, d’échange et de garantie seront publiées ici.</p></div>
          <div className="faq-list">{faqs.map(([question, answer]) => <details key={question}><summary>{question}</summary><p>{answer}</p></details>)}</div>
        </section>
      </main>
      <StoreFooter />
    </div>
  );
}
