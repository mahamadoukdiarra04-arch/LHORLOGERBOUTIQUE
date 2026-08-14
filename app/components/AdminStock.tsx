"use client";

import { FormEvent, useMemo, useState } from "react";
import {
  AdPeriod,
  formatAdminPrice,
  inventory,
  inventoryEvents,
  InventoryEvent,
  InventoryEventType,
  InventoryItem,
  productFromId,
  productPerformance,
} from "../lib/admin-data";

type Movement = "in" | "out";
type EventFilter = "Tous" | InventoryEventType;

const adPeriodLabels: Record<AdPeriod, string> = {
  "7": "7 derniers jours",
  "30": "30 derniers jours",
  "90": "90 derniers jours",
};

const adPeriodMultipliers: Record<AdPeriod, number> = {
  "7": 1,
  "30": 4,
  "90": 11,
};

const eventLabel = (type: InventoryEventType) => type === "Réassort" ? "Réassort" : type === "Sortie" ? "Sortie de stock" : "Publicité";

export function AdminStock() {
  const [items, setItems] = useState<InventoryItem[]>(inventory);
  const [events, setEvents] = useState<InventoryEvent[]>(inventoryEvents);
  const [productId, setProductId] = useState(inventory[0].productId);
  const [movement, setMovement] = useState<Movement>("in");
  const [quantity, setQuantity] = useState("1");
  const [purchasePrice, setPurchasePrice] = useState("");
  const [transitPrice, setTransitPrice] = useState("");
  const [feedback, setFeedback] = useState("");
  const [selectedProductId, setSelectedProductId] = useState<string | null>(null);
  const [eventFilter, setEventFilter] = useState<EventFilter>("Tous");
  const [adPeriod, setAdPeriod] = useState<AdPeriod>("30");
  const [adSpend, setAdSpend] = useState("");
  const [adFeedback, setAdFeedback] = useState("");

  const selectedProduct = productFromId(productId);
  const selectedItem = items.find((item) => item.productId === selectedProductId);
  const selectedProductDetail = selectedProductId ? productFromId(selectedProductId) : undefined;
  const selectedProductEvents = useMemo(
    () => events.filter((item) => item.productId === selectedProductId),
    [events, selectedProductId],
  );
  const filteredEvents = useMemo(
    () => selectedProductEvents.filter((item) => eventFilter === "Tous" || item.type === eventFilter),
    [eventFilter, selectedProductEvents],
  );

  const totals = useMemo(() => items.reduce((result, item) => {
    const product = productFromId(item.productId);
    result.units += item.quantity;
    result.cost += item.quantity * item.unitCost;
    result.margin += item.quantity * Math.max(0, (product?.price ?? 0) - item.unitCost);
    return result;
  }, { units: 0, cost: 0, margin: 0 }), [items]);

  const restockQuantity = Math.max(1, Number(quantity) || 1);
  const restockPurchasePrice = Math.max(0, Number(purchasePrice) || 0);
  const restockTransitPrice = Math.max(0, Number(transitPrice) || 0);
  const restockTotalCost = restockPurchasePrice + restockTransitPrice;
  const lotUnitCost = restockTotalCost / restockQuantity;
  const projectedUnitCost = selectedProduct && movement === "in"
    ? ((items.find((item) => item.productId === productId)?.quantity ?? 0) * (items.find((item) => item.productId === productId)?.unitCost ?? 0) + restockTotalCost) /
      ((items.find((item) => item.productId === productId)?.quantity ?? 0) + restockQuantity)
    : 0;

  const submitMovement = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const selected = items.find((item) => item.productId === productId);
    if (!selected || !selectedProduct) return;

    const requestedQuantity = Math.max(1, Number(quantity) || 1);
    if (movement === "in" && (!purchasePrice.trim() || !transitPrice.trim() || restockPurchasePrice <= 0)) {
      setFeedback("Renseignez le prix d’achat et le prix de transit du lot pour calculer le coût unitaire.");
      return;
    }

    const actualQuantity = movement === "out" ? Math.min(requestedQuantity, selected.quantity) : requestedQuantity;
    if (!actualQuantity) {
      setFeedback("Cette sortie ne peut pas être enregistrée : le stock est déjà à zéro.");
      return;
    }

    const newUnitCost = movement === "in"
      ? ((selected.quantity * selected.unitCost) + restockTotalCost) / (selected.quantity + actualQuantity)
      : selected.unitCost;
    const date = "À l’instant";
    const type: InventoryEventType = movement === "in" ? "Réassort" : "Sortie";

    setItems((current) => current.map((item) => item.productId !== productId ? item : {
      ...item,
      quantity: movement === "in" ? item.quantity + actualQuantity : item.quantity - actualQuantity,
      unitCost: newUnitCost,
      lastMovement: `${type} · à l’instant`,
    }));
    setEvents((current) => [{
      id: `stock-${Date.now()}`,
      productId,
      type,
      date,
      quantity: actualQuantity,
      ...(movement === "in" ? {
        purchasePrice: restockPurchasePrice,
        transitPrice: restockTransitPrice,
        totalCost: restockTotalCost,
        unitCost: lotUnitCost,
      } : {}),
    }, ...current]);
    setFeedback(`${type} de ${actualQuantity} unité${actualQuantity > 1 ? "s" : ""} enregistré${actualQuantity > 1 ? "es" : "e"} pour ${selectedProduct.name}.`);
    setQuantity("1");
    setPurchasePrice("");
    setTransitPrice("");
  };

  const openProduct = (nextProductId: string) => {
    setSelectedProductId(nextProductId);
    setProductId(nextProductId);
    setEventFilter("Tous");
    setAdFeedback("");
  };

  const addAdvertisingCost = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!selectedProductId || !selectedProductDetail) return;
    const amount = Math.max(0, Number(adSpend) || 0);
    if (!amount) {
      setAdFeedback("Renseignez un montant publicitaire supérieur à zéro pour calculer le CAC.");
      return;
    }
    setEvents((current) => [{
      id: `ad-${Date.now()}`,
      productId: selectedProductId,
      type: "Publicité",
      date: "À l’instant",
      amount,
      period: adPeriod,
    }, ...current]);
    setAdFeedback(`Coût publicitaire enregistré pour ${adPeriodLabels[adPeriod].toLowerCase()}.`);
    setAdSpend("");
    setEventFilter("Tous");
  };

  const latestAdvertisingCost = selectedProductId
    ? events.find((event) => event.productId === selectedProductId && event.type === "Publicité" && event.period === adPeriod)
    : undefined;
  const selectedPerformance = selectedProductId ? productPerformance.find((item) => item.productId === selectedProductId) : undefined;
  const salesForPeriod = selectedPerformance ? Math.max(1, Math.round(selectedPerformance.units * adPeriodMultipliers[adPeriod])) : 0;
  const cac = latestAdvertisingCost?.amount && salesForPeriod ? latestAdvertisingCost.amount / salesForPeriod : undefined;

  return (
    <>
      <section className="admin-page-head admin-page-head--compact">
        <div><p className="admin-kicker">Stock & coûts</p><h1>Garder la bonne mesure.</h1><p>Suivez les quantités, les coûts supportés et la rentabilité sans gérer de réservations ni de bons de réassort.</p></div>
        <div className="admin-stock-value"><span>Valeur au coût</span><strong>{formatAdminPrice(totals.cost)}</strong></div>
      </section>

      <section className="admin-stock-summary">
        <div><span>Unités disponibles</span><strong>{totals.units}</strong></div><div><span>Marge potentielle du stock</span><strong>{formatAdminPrice(totals.margin)}</strong></div><div><span>Seuils d’alerte</span><strong>{items.filter((item) => item.quantity <= item.alertAt).length} à suivre</strong></div>
      </section>

      <section className="admin-movement-panel">
        <div><p className="admin-panel__eyebrow">Mouvement de stock</p><h2>Un réassort ou une sortie, simplement.</h2><p>Au réassort, le coût unitaire est recalculé automatiquement à partir du prix d’achat et du transit du lot.</p></div>
        <form onSubmit={submitMovement} className="admin-movement-form">
          <label><span>Produit</span><select aria-label="Produit du mouvement" value={productId} onChange={(event) => setProductId(event.target.value)} onInput={(event) => setProductId(event.currentTarget.value)}>{items.map((item) => <option value={item.productId} key={item.productId}>{productFromId(item.productId)?.name}</option>)}</select></label>
          <label><span>Type</span><select aria-label="Type de mouvement" value={movement} onChange={(event) => setMovement(event.target.value as Movement)} onInput={(event) => setMovement(event.currentTarget.value as Movement)}><option value="in">Réassort</option><option value="out">Sortie de stock</option></select></label>
          <label><span>Quantité</span><input aria-label="Quantité de mouvement" type="number" min="1" value={quantity} onChange={(event) => setQuantity(event.target.value)} /></label>
          {movement === "in" && <>
            <label><span>Prix d’achat du lot</span><input aria-label="Prix d’achat du lot" type="number" min="0" inputMode="numeric" value={purchasePrice} onChange={(event) => setPurchasePrice(event.target.value)} /></label>
            <label><span>Prix de transit du lot</span><input aria-label="Prix de transit du lot" type="number" min="0" inputMode="numeric" value={transitPrice} onChange={(event) => setTransitPrice(event.target.value)} /></label>
          </>}
          <button className="admin-dark-link" type="submit">Valider le mouvement</button>
        </form>
        {movement === "in" && <p className="admin-cost-preview" aria-live="polite">Lot : <b>{formatAdminPrice(restockTotalCost)}</b> · coût unitaire du lot : <b>{formatAdminPrice(lotUnitCost)}</b>{restockTotalCost > 0 && <> · nouveau coût moyen estimé : <b>{formatAdminPrice(projectedUnitCost)}</b></>}</p>}
        {feedback && <p className="admin-movement-feedback" role="status">{feedback}</p>}
      </section>

      <section className="admin-inventory-grid" aria-label="Inventaire produits">
        {items.map((item) => {
          const product = productFromId(item.productId);
          if (!product) return null;
          const low = item.quantity <= item.alertAt;
          const margin = product.price - item.unitCost;
          const cardAdvertising = events.find((event) => event.productId === item.productId && event.type === "Publicité" && event.period === "30");
          const performance = productPerformance.find((entry) => entry.productId === item.productId);
          const cardCac = cardAdvertising?.amount && performance ? cardAdvertising.amount / Math.max(1, performance.units * adPeriodMultipliers["30"]) : undefined;
          return <button type="button" className={`${low ? "admin-inventory-card admin-inventory-card--low" : "admin-inventory-card"}${selectedProductId === item.productId ? " admin-inventory-card--selected" : ""}`} key={item.productId} onClick={() => openProduct(item.productId)} aria-pressed={selectedProductId === item.productId}>
            <div className="admin-inventory-card__header"><div><p>{product.reference}</p><h2>{product.name}</h2></div><span className={low ? "admin-stock-chip admin-stock-chip--low" : "admin-stock-chip"}>{low ? "À réassortir" : "Disponible"}</span></div>
            <div className="admin-stock-quantity"><strong>{item.quantity}</strong><span>unités en stock<br />Seuil : {item.alertAt}</span></div>
            <div className="admin-inventory-figures"><span>Coût unitaire <b>{formatAdminPrice(item.unitCost)}</b></span><span>Marge / unité <b>{formatAdminPrice(margin)}</b></span><span>Rentabilité <b>{Math.round((margin / product.price) * 100)} %</b></span></div>
            <div className="admin-ad-line"><span>Publicité & CAC</span><b>{cardCac ? `CAC 30 j · ${formatAdminPrice(cardCac)}` : "Renseigner les coûts d’ads"}</b></div>
            <div className="admin-inventory-card__footer"><small>{item.lastMovement}</small><span>Voir la fiche et l’historique →</span></div>
          </button>;
        })}
      </section>

      {selectedItem && selectedProductDetail && <section className="admin-product-detail" aria-label={`Fiche de ${selectedProductDetail.name}`}>
        <div className="admin-product-detail__head"><div><p className="admin-panel__eyebrow">Fiche produit</p><h2>{selectedProductDetail.name}</h2><p>{selectedProductDetail.reference} · Tous les mouvements et coûts du produit, au même endroit.</p></div><button type="button" onClick={() => setSelectedProductId(null)} aria-label="Fermer la fiche produit">Fermer</button></div>

        <div className="admin-product-detail__metrics">
          <span>Stock disponible <b>{selectedItem.quantity} unités</b></span><span>Coût unitaire moyen <b>{formatAdminPrice(selectedItem.unitCost)}</b></span><span>Marge par unité <b>{formatAdminPrice(selectedProductDetail.price - selectedItem.unitCost)}</b></span><span>Valeur en stock <b>{formatAdminPrice(selectedItem.quantity * selectedItem.unitCost)}</b></span>
        </div>

        <div className="admin-product-detail__body">
          <div className="admin-cost-breakdown"><div><p className="admin-panel__eyebrow">Dernier réassort</p><h3>Composition du coût</h3></div>{(() => {
            const lastRestock = selectedProductEvents.find((event) => event.type === "Réassort");
            if (!lastRestock) return <p>Aucun réassort n’est encore renseigné pour ce produit.</p>;
            return <div className="admin-cost-breakdown__figures"><span>Quantité <b>{lastRestock.quantity} unités</b></span><span>Prix d’achat <b>{formatAdminPrice(lastRestock.purchasePrice ?? 0)}</b></span><span>Transit <b>{formatAdminPrice(lastRestock.transitPrice ?? 0)}</b></span><span>Coût du lot <b>{formatAdminPrice(lastRestock.totalCost ?? 0)}</b></span><span>Coût unitaire du lot <b>{formatAdminPrice(lastRestock.unitCost ?? 0)}</b></span></div>;
          })()}</div>

          <form className="admin-ad-form" onSubmit={addAdvertisingCost}>
            <div><p className="admin-panel__eyebrow">Publicité & acquisition</p><h3>Calculer le CAC produit</h3><p>Enregistrez le coût de publicité et sa période ; le CAC est calculé selon les ventes de cette montre.</p></div>
            <label><span>Période publicitaire</span><select aria-label="Période publicitaire" value={adPeriod} onChange={(event) => { setAdPeriod(event.target.value as AdPeriod); setAdFeedback(""); }} onInput={(event) => { setAdPeriod(event.currentTarget.value as AdPeriod); setAdFeedback(""); }}><option value="7">7 derniers jours</option><option value="30">30 derniers jours</option><option value="90">90 derniers jours</option></select></label>
            <label><span>Coût des ads</span><input aria-label="Coût des ads" type="number" min="0" inputMode="numeric" placeholder="Ex. 75 000" value={adSpend} onChange={(event) => setAdSpend(event.target.value)} /></label>
            <button className="admin-dark-link" type="submit">Enregistrer le coût</button>
            {cac ? <p className="admin-cac-result" role="status"><b>CAC {adPeriodLabels[adPeriod]}</b><span>{formatAdminPrice(latestAdvertisingCost?.amount ?? 0)} de publicité / {salesForPeriod} ventes = <strong>{formatAdminPrice(cac)}</strong></span></p> : <p className="admin-cac-result admin-cac-result--empty">Renseignez un coût publicitaire et sélectionnez une période pour calculer le CAC.</p>}
            {adFeedback && <p className="admin-movement-feedback" role="status">{adFeedback}</p>}
          </form>
        </div>

        <div className="admin-event-history"><div className="admin-event-history__head"><div><p className="admin-panel__eyebrow">Historique</p><h3>Les événements du produit</h3></div><div className="admin-event-filter" aria-label="Filtrer l’historique">{(["Tous", "Réassort", "Sortie", "Publicité"] as EventFilter[]).map((filter) => <button key={filter} type="button" className={eventFilter === filter ? "is-active" : ""} onClick={() => setEventFilter(filter)}>{filter}</button>)}</div></div>
          <div className="admin-events">{filteredEvents.length ? filteredEvents.map((item) => <article key={item.id} className="admin-event"><div><span className={`admin-event-type admin-event-type--${item.type.toLowerCase().replace("é", "e")}`}>{eventLabel(item.type)}</span><p>{item.date}</p></div><div>{item.type === "Réassort" && <><strong>+{item.quantity} unités</strong><span>Achat {formatAdminPrice(item.purchasePrice ?? 0)} · transit {formatAdminPrice(item.transitPrice ?? 0)} · coût unitaire {formatAdminPrice(item.unitCost ?? 0)}</span></>}{item.type === "Sortie" && <><strong>−{item.quantity} unités</strong><span>Sortie enregistrée du stock</span></>}{item.type === "Publicité" && <><strong>{formatAdminPrice(item.amount ?? 0)}</strong><span>Publicité · {item.period ? adPeriodLabels[item.period] : "Période renseignée"}</span></>}</div></article>) : <p className="admin-empty-events">Aucun événement ne correspond à ce filtre.</p>}</div>
        </div>
      </section>}
    </>
  );
}
