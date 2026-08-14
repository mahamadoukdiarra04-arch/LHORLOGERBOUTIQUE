"use client";

import { useMemo, useState } from "react";
import { acquisition, formatAdminPrice, inventory, periodSummary, productFromId, productPerformance } from "../lib/admin-data";

type SortMetric = "revenue" | "grossMargin" | "units";
type AnalysisPeriod = keyof typeof periodSummary;

const periodMultipliers: Record<Exclude<AnalysisPeriod, "custom">, number> = {
  today: 0.18,
  yesterday: 0.16,
  "7": 1,
  "14": 1.85,
  "30": 3.8,
  quarter: 9.2,
  "90": 10.6,
  year: 38.6,
};

const formatPeriodDates = (start: string, end: string) => {
  const formatter = new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "short", year: "numeric" });
  return `Du ${formatter.format(new Date(`${start}T12:00:00`))} au ${formatter.format(new Date(`${end}T12:00:00`))}`;
};

export function AdminAnalysis() {
  const [sortBy, setSortBy] = useState<SortMetric>("revenue");
  const [period, setPeriod] = useState<AnalysisPeriod>("7");
  const [customStart, setCustomStart] = useState("2026-08-01");
  const [customEnd, setCustomEnd] = useState("2026-08-19");

  const customDays = Math.max(1, Math.round((new Date(`${customEnd}T12:00:00`).getTime() - new Date(`${customStart}T12:00:00`).getTime()) / 86_400_000) + 1);
  const multiplier = period === "custom" ? customDays / 7 : periodMultipliers[period];
  const periodLabel = period === "custom" ? formatPeriodDates(customStart, customEnd) : periodSummary[period].label;
  const performance = useMemo(() => productPerformance.map((row) => ({
    ...row,
    units: Math.max(1, Math.round(row.units * multiplier)),
    revenue: Math.round(row.revenue * multiplier),
    grossMargin: Math.round(row.grossMargin * multiplier),
  })), [multiplier]);
  const sortedProducts = useMemo(() => [...performance].sort((first, second) => second[sortBy] - first[sortBy]), [performance, sortBy]);
  const bestRevenue = useMemo(() => [...performance].sort((first, second) => second.revenue - first.revenue)[0], [performance]);
  const bestMargin = useMemo(() => [...performance].sort((first, second) => second.grossMargin - first.grossMargin)[0], [performance]);
  const lowStock = inventory.filter((item) => item.quantity <= item.alertAt);
  const bestRevenueProduct = bestRevenue ? productFromId(bestRevenue.productId) : undefined;
  const bestMarginProduct = bestMargin ? productFromId(bestMargin.productId) : undefined;
  const channelMultiplier = Math.max(0.15, multiplier);

  return (
    <>
      <section className="admin-page-head admin-page-head--compact">
        <div><p className="admin-kicker">Analyse produits</p><h1>Voir ce qui est vraiment rentable.</h1><p>Comparez ventes, marge et stock disponible pour orienter les prochains réassorts.</p></div>
        <div className="admin-head-actions">
          <label className="admin-period-select"><span>Période analysée</span><select aria-label="Période d’analyse produit" value={period} onChange={(event) => setPeriod(event.target.value as AnalysisPeriod)} onInput={(event) => setPeriod(event.currentTarget.value as AnalysisPeriod)}><option value="today">Aujourd’hui</option><option value="yesterday">Hier</option><option value="7">7 derniers jours</option><option value="14">14 derniers jours</option><option value="30">30 derniers jours</option><option value="quarter">Ce trimestre</option><option value="90">90 derniers jours</option><option value="year">Depuis le 1er janvier</option><option value="custom">Période personnalisée</option></select></label>
          {period === "custom" && <div className="admin-custom-range"><label><span>Du</span><input aria-label="Début de la période d’analyse" type="date" value={customStart} max={customEnd} onInput={(event) => setCustomStart(event.currentTarget.value)} onChange={(event) => setCustomStart(event.target.value)} /></label><label><span>Au</span><input aria-label="Fin de la période d’analyse" type="date" value={customEnd} min={customStart} onInput={(event) => setCustomEnd(event.currentTarget.value)} onChange={(event) => setCustomEnd(event.target.value)} /></label></div>}
        </div>
      </section>

      <p className="admin-analysis-caption" aria-live="polite">Analyse basée sur : <b>{periodLabel}</b></p>

      <section className="admin-analysis-highlights">
        <article><p>Meilleur CA</p><strong>{bestRevenueProduct?.name}</strong><span>{formatAdminPrice(bestRevenue?.revenue ?? 0)} · {bestRevenue?.units ?? 0} unités</span></article>
        <article><p>Meilleure marge</p><strong>{bestMarginProduct?.name}</strong><span>{formatAdminPrice(bestMargin?.grossMargin ?? 0)} de marge brute</span></article>
        <article><p>À sécuriser</p><strong>{productFromId(lowStock[0]?.productId ?? "")?.name ?? "Stock sain"}</strong><span>{lowStock.length ? `${lowStock[0].quantity} unités pour un seuil de ${lowStock[0].alertAt}` : "Aucun seuil atteint"}</span></article>
      </section>

      <section className="admin-panel admin-product-table-panel">
        <div className="admin-panel__header"><div><p className="admin-panel__eyebrow">Classement détaillé</p><h2>Produits par rentabilité</h2></div><label className="admin-mini-select"><span>Trier par</span><select aria-label="Trier les produits" value={sortBy} onChange={(event) => setSortBy(event.target.value as SortMetric)} onInput={(event) => setSortBy(event.currentTarget.value as SortMetric)}><option value="revenue">Chiffre d’affaires</option><option value="grossMargin">Marge brute</option><option value="units">Unités vendues</option></select></label></div>
        <div className="admin-product-table" role="table" aria-label="Performance des produits">
          <div className="admin-product-table__head" role="row"><span>Produit</span><span>Ventes</span><span>CA</span><span>Marge</span><span>Stock</span><span>Rentabilité</span></div>
          {sortedProducts.map((row, index) => {
            const product = productFromId(row.productId);
            const stock = inventory.find((item) => item.productId === row.productId);
            const profitability = product ? Math.round((product.price - (stock?.unitCost ?? 0)) / product.price * 100) : 0;
            return <div className="admin-product-table__row" role="row" key={row.productId}><div><b>0{index + 1}</b><span><strong>{product?.name}</strong><small>{product?.reference} · {product?.bracelet}</small></span></div><span>{row.units} unités</span><strong>{formatAdminPrice(row.revenue)}</strong><strong>{formatAdminPrice(row.grossMargin)}</strong><span className={stock && stock.quantity <= stock.alertAt ? "admin-inline-stock admin-inline-stock--low" : "admin-inline-stock"}>{stock?.quantity ?? 0} unités</span><strong>{profitability} %</strong></div>;
          })}
        </div>
      </section>

      <section className="admin-two-columns">
        <article className="admin-panel admin-cac-panel"><div className="admin-panel__header"><div><p className="admin-panel__eyebrow">Coût d’acquisition</p><h2>Lecture simple du CAC</h2></div></div><div className="admin-cac-list">{acquisition.map((channel) => { const orders = Math.max(1, Math.round(channel.orders * channelMultiplier)); const spend = Math.round(channel.spend * channelMultiplier); return <div key={channel.channel}><span className={channel.channel === "Meta" ? "admin-channel-dot admin-channel-dot--meta" : "admin-channel-dot"} /><strong>{channel.channel}</strong><small>{orders} commandes</small><b>{spend ? formatAdminPrice(spend / orders) : "0 FCFA"}</b></div>; })}</div><p className="admin-panel__note">CAC = dépenses du canal ÷ commandes attribuées, sur la période sélectionnée. Le réachat est suivi sans dépense attribuée.</p></article>
        <article className="admin-forecast-card"><p className="admin-panel__eyebrow">Prévision</p><h2>La prévision avancée arrive ici.</h2><p>Elle s’appuiera sur les ventes, la saisonnalité, le stock et les coûts réellement renseignés dans cet espace.</p><div><span>Prérequis actif</span><b>Ventes & stock suivis</b></div></article>
      </section>
    </>
  );
}
