"use client";

import { useMemo, useState } from "react";
import { AcquisitionChannel, AdminOrder, formatAdminPrice, OrderStatus, orders, productFromId, statusClass } from "../lib/admin-data";

const statuses: Array<OrderStatus | "Toutes"> = ["Toutes", "À confirmer", "Confirmée", "En livraison", "Livrée"];
const statusOptions: OrderStatus[] = ["À confirmer", "Confirmée", "En livraison", "Livrée"];
const channels: AcquisitionChannel[] = ["Meta", "Réachat"];

type OrderDraft = { status: OrderStatus; channel: AcquisitionChannel | "" };

export function AdminOrders() {
  const [managedOrders, setManagedOrders] = useState<AdminOrder[]>(orders);
  const [query, setQuery] = useState("");
  const [status, setStatus] = useState<(typeof statuses)[number]>("Toutes");
  const [expanded, setExpanded] = useState<string | null>(orders[0].id);
  const [drafts, setDrafts] = useState<Record<string, OrderDraft>>({});
  const [feedback, setFeedback] = useState<Record<string, string>>({});

  const filteredOrders = useMemo(() => managedOrders.filter((order) => {
    const product = productFromId(order.productId);
    const haystack = `${order.id} ${order.customer} ${order.phone} ${order.district} ${order.variant} ${product?.name ?? ""}`.toLowerCase();
    return haystack.includes(query.toLowerCase()) && (status === "Toutes" || order.status === status);
  }), [managedOrders, query, status]);

  const getDraft = (order: AdminOrder): OrderDraft => drafts[order.id] ?? { status: order.status, channel: order.channel ?? "" };

  const updateDraft = (order: AdminOrder, patch: Partial<OrderDraft>) => {
    setDrafts((current) => ({ ...current, [order.id]: { ...(current[order.id] ?? { status: order.status, channel: order.channel ?? "" }), ...patch } }));
    setFeedback((current) => ({ ...current, [order.id]: "" }));
  };

  const saveOrder = (order: AdminOrder) => {
    const draft = getDraft(order);
    if (draft.status !== "À confirmer" && !draft.channel) {
      setFeedback((current) => ({ ...current, [order.id]: "Choisissez Meta ou Réachat avant de confirmer la commande." }));
      return;
    }
    setManagedOrders((current) => current.map((item) => item.id === order.id ? { ...item, status: draft.status, channel: draft.channel || undefined } : item));
    setDrafts((current) => {
      const next = { ...current };
      delete next[order.id];
      return next;
    });
    setFeedback((current) => ({ ...current, [order.id]: "Commande mise à jour." }));
  };

  return (
    <>
      <section className="admin-page-head admin-page-head--compact">
        <div><p className="admin-kicker">Commandes</p><h1>Chaque détail à portée de main.</h1><p>Retrouvez le client, sa commande, son canal et l’étape de livraison.</p></div>
        <div className="admin-order-total"><span>À confirmer</span><strong>{managedOrders.filter((order) => order.status === "À confirmer").length}</strong></div>
      </section>

      <section className="admin-filter-bar" aria-label="Filtres de commandes">
        <label className="admin-search"><span>Rechercher</span><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Client, référence ou quartier" /></label>
        <div className="admin-status-filters" aria-label="État de commande">{statuses.map((item) => <button key={item} type="button" className={status === item ? "admin-filter-pill admin-filter-pill--active" : "admin-filter-pill"} onClick={() => setStatus(item)}>{item}</button>)}</div>
      </section>

      <section className="admin-orders-list" aria-live="polite">
        <div className="admin-orders-list__head"><span>Commande</span><span>Client</span><span>Produits</span><span>Canal</span><span>État</span><span>Montant</span></div>
        {filteredOrders.length ? filteredOrders.map((order) => {
          const product = productFromId(order.productId);
          const amount = (product?.price ?? 0) * order.quantity;
          const unitPrice = product?.price ?? 0;
          const selectedVariant = product?.variants.find((variant) => variant.label === order.variant);
          const isOpen = expanded === order.id;
          const draft = getDraft(order);
          return <article className={isOpen ? "admin-order-card admin-order-card--open" : "admin-order-card"} key={order.id}>
            <div className="admin-order-card__row">
              <div><span className="admin-order-ref">{order.id}</span><small>{order.createdAt}</small></div>
              <div><strong>{order.customer}</strong><small>{order.phone}</small></div>
              <div><strong>{product?.name}</strong><small>Qté {order.quantity}</small></div>
              <span className={order.channel ? `admin-channel-tag admin-channel-tag--${order.channel === "Meta" ? "meta" : "repeat"}` : "admin-channel-tag admin-channel-tag--pending"}>{order.channel ?? "À renseigner"}</span>
              <span className={`admin-status admin-status--${statusClass(order.status)}`}>{order.status}</span>
              <div className="admin-order-amount"><strong>{formatAdminPrice(amount)}</strong><button type="button" onClick={() => setExpanded(isOpen ? null : order.id)} aria-expanded={isOpen}>{isOpen ? "Fermer" : "Détails"}</button></div>
            </div>
            {isOpen && <div className="admin-order-card__details">
              <div className="admin-order-detail-grid">
                <section className="admin-order-product-detail" aria-label="Article commandé">
                  {product && <img src={selectedVariant?.image.src ?? product.image} alt="" />}
                  <div><span>Article commandé</span><strong>{product?.name ?? "Produit indisponible"}</strong><small>{product?.reference ?? "—"} · {order.variant}</small>{selectedVariant?.description && <em>{selectedVariant.description}</em>}</div>
                </section>
                <dl className="admin-order-facts">
                  <div><dt>Coloris</dt><dd>{order.variant}</dd></div>
                  <div><dt>Finition</dt><dd>{product?.finish ?? "—"}</dd></div>
                  <div><dt>Diamètre</dt><dd>{product?.size ?? "—"}</dd></div>
                  <div><dt>Quantité</dt><dd>{order.quantity}</dd></div>
                  <div><dt>Prix unitaire</dt><dd>{formatAdminPrice(unitPrice)}</dd></div>
                  <div><dt>Sous-total</dt><dd>{formatAdminPrice(amount)}</dd></div>
                  <div><dt>Canal</dt><dd>{order.channel ?? "À renseigner"}</dd></div>
                  <div><dt>Statut</dt><dd>{order.status}</dd></div>
                </dl>
              </div>
              <div className="admin-order-card__details-top"><div><span>Quartier</span><strong>{order.district}</strong></div><div><span>Paiement</span><strong>À la réception</strong></div><div><span>Livraison</span><strong>Offerte</strong></div><div><span>Coût produit</span><strong>{formatAdminPrice((product?.price ?? 0) * 0.6)}</strong></div></div>
              <div className="admin-order-editor"><label><span>Statut</span><select value={draft.status} onChange={(event) => updateDraft(order, { status: event.target.value as OrderStatus })}>{statusOptions.map((item) => <option key={item}>{item}</option>)}</select></label><label><span>Canal d’acquisition</span><select value={draft.channel} onChange={(event) => updateDraft(order, { channel: event.target.value as AcquisitionChannel | "" })}><option value="">À renseigner</option>{channels.map((item) => <option key={item}>{item}</option>)}</select></label><p>Le canal est requis dès que la commande quitte l’état « À confirmer ».</p><button type="button" className="admin-detail-action" onClick={() => saveOrder(order)} disabled={draft.status !== "À confirmer" && !draft.channel}>Enregistrer</button></div>
              {feedback[order.id] && <p className="admin-order-editor__feedback" role="status">{feedback[order.id]}</p>}
            </div>}
          </article>;
        }) : <div className="admin-empty"><strong>Aucune commande ne correspond.</strong><span>Essayez un autre nom, quartier ou statut.</span></div>}
      </section>
    </>
  );
}
