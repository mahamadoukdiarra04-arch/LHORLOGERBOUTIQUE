import { CatalogClient } from "../components/CatalogClient";
import { StoreFooter, StoreHeader } from "../components/StoreChrome";

export default function WatchesPage() {
  return (
    <div className="page-shell">
      <StoreHeader />
      <main className="catalog-page container">
        <div className="page-intro">
          <p className="eyebrow dark">Toutes les montres</p>
          <h1>Choisissez le détail qui vous va.</h1>
          <p>Comparez les modèles selon le bracelet, la finition et le diamètre, puis ouvrez chaque fiche pour voir les détails.</p>
        </div>
        <CatalogClient />
        <p className="catalogue-note">Une sélection courte, pensée pour être vue en détail avant de commander.</p>
      </main>
      <StoreFooter />
    </div>
  );
}
