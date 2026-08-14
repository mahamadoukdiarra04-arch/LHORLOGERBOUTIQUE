"use client";

import Link from "next/link";
import { useMemo, useRef, useState } from "react";
import { acquisition, dailyRevenue, formatAdminPrice, inventory, intradayRevenue, orders, periodSummary, productFromId, productPerformance, statusClass } from "../lib/admin-data";

type RankingMetric = "revenue" | "grossMargin" | "units";

const rankingLabels: Record<RankingMetric, string> = {
  revenue: "Chiffre d’affaires",
  grossMargin: "Marge brute",
  units: "Unités vendues",
};

export function AdminDashboard() {
  const [period, setPeriod] = useState<keyof typeof periodSummary>("7");
  const [customStart, setCustomStart] = useState("2026-08-01");
  const [customEnd, setCustomEnd] = useState("2026-08-19");
  const [rankingMetric, setRankingMetric] = useState<RankingMetric>("revenue");
  const [showNotification, setShowNotification] = useState(false);
  const dismissTimer = useRef<number | null>(null);
  const summary = periodSummary[period];
  const chartData = period === "today" || period === "yesterday" ? intradayRevenue : dailyRevenue;
  const chartMaximum = Math.max(...chartData.map((item) => item.revenue));
  const periodLabel = period === "custom" ? `Du ${new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "short" }).format(new Date(`${customStart}T12:00:00`))} au ${new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "short", year: "numeric" }).format(new Date(`${customEnd}T12:00:00`))}` : summary.label;
  const lowStock = inventory.filter((item) => item.quantity <= item.alertAt);
  const ranking = useMemo(
    () => [...productPerformance].sort((left, right) => right[rankingMetric] - left[rankingMetric]).slice(0, 4),
    [rankingMetric],
  );

  const triggerOrderAlert = () => {
    const AudioContextClass = window.AudioContext;
    if (AudioContextClass) {
      const context = new AudioContextClass();
      [0, 0.08, 0.17].forEach((start, index) => {
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        oscillator.type = index === 1 ? "square" : "sine";
        oscillator.frequency.setValueAtTime([1046, 1318, 1568][index], context.currentTime + start);
        gain.gain.setValueAtTime(0.0001, context.currentTime + start);
        gain.gain.exponentialRampToValueAtTime(0.12, context.currentTime + start + 0.015);
        gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + start + 0.18);
        oscillator.connect(gain).connect(context.destination);
        oscillator.start(context.currentTime + start);
        oscillator.stop(context.currentTime + start + 0.2);
      });
      window.setTimeout(() => context.close(), 500);
    }
    setShowNotification(true);
    if (dismissTimer.current) window.clearTimeout(dismissTimer.current);
    dismissTimer.current = window.setTimeout(() => setShowNotification(false), 6000);
  };

  return (
    <>
      {showNotification && (
        <div className="admin-toast" role="status">
          <span className="admin-toast__spark">✦</span>
          <div><strong>Nouvelle commande reçue</strong><p>HOR-2608-483922 · Azur Squelette · 62 000 FCFA</p></div>
          <button type="button" onClick={() => setShowNotification(false)} aria-label="Fermer la notification">×</button>
        </div>
      )}

      <section className="admin-page-head">
        <div>
          <p className="admin-kicker">Vue d’ensemble</p>
          <h1>Les repères pour décider.</h1>
          <p>Suivez le rythme des ventes, les coûts et les actions à mener sans quitter L’Horloger.</p>
        </div>
        <div className="admin-head-actions">
          <label className="admin-period-select"><span>Période</span><select value={period} onChange={(event) => setPeriod(event.target.value as keyof typeof periodSummary)}><option value="today">Aujourd’hui</option><option value="yesterday">Hier</option><option value="7">7 derniers jours</option><option value="14">14 derniers jours</option><option value="30">30 derniers jours</option><option value="quarter">Ce trimestre</option><option value="90">90 derniers jours</option><option value="year">Depuis le 1er janvier</option><option value="custom">Période personnalisée</option></select></label>
          {period === "custom" && <div className="admin-custom-range"><label><span>Du</span><input type="date" value={customStart} max={customEnd} onInput={(event) => setCustomStart(event.currentTarget.value)} onChange={(event) => setCustomStart(event.target.value)} /></label><label><span>Au</span><input type="date" value={customEnd} min={customStart} onInput={(event) => setCustomEnd(event.currentTarget.value)} onChange={(event) => setCustomEnd(event.target.value)} /></label></div>}
          <button className="admin-sound-button" type="button" onClick={triggerOrderAlert}>Déclencher une alerte</button>
        </div>
      </section>

      <section className="admin-metrics" aria-label="Indicateurs clés">
        <MetricCard label="Chiffre d’affaires" value={formatAdminPrice(summary.revenue)} delta={`+${summary.delta} %`} note={periodLabel} />
        <MetricCard label="Marge brute" value={formatAdminPrice(summary.margin)} delta={`${Math.round((summary.margin / summary.revenue) * 100)} %`} note="Après coût produit" />
        <MetricCard label="CAC Meta" value={formatAdminPrice(acquisition[0].spend / acquisition[0].orders)} delta="29 commandes" note="Dépenses Meta uniquement" />
        <MetricCard label="Panier moyen" value={formatAdminPrice(summary.averageBasket)} delta={`${summary.orders} commandes`} note="Toutes sources confondues" />
      </section>

      <section className="admin-two-columns admin-two-columns--hero">
        <article className="admin-panel admin-revenue-panel">
          <div className="admin-panel__header"><div><p className="admin-panel__eyebrow">Ventes</p><h2>{period === "today" || period === "yesterday" ? "CA au fil de la journée" : "CA au fil de la période"}</h2></div><strong>{formatAdminPrice(summary.revenue)}</strong></div>
          <div className="admin-chart" aria-label="Chiffre d’affaires journalier">
            {chartData.map((item) => <div className="admin-chart__item" key={item.label}><div className="admin-chart__bar"><span style={{ height: `${Math.round((item.revenue / chartMaximum) * 100)}%` }} title={`${item.label} ${formatAdminPrice(item.revenue)}`} /></div><small>{item.label}</small></div>)}
          </div>
          <div className="admin-chart__caption"><span>{periodLabel}</span><span>Objectif <b>{formatAdminPrice(Math.round(summary.revenue * 1.1))}</b></span></div>
        </article>

        <article className="admin-panel admin-acquisition-panel">
          <div className="admin-panel__header"><div><p className="admin-panel__eyebrow">Acquisition</p><h2>Ce qui déclenche les ventes</h2></div><Link href="/admin/analyse" className="admin-text-link">Voir l’analyse</Link></div>
          <div className="admin-channel-list">
            {acquisition.map((item) => <div className="admin-channel" key={item.channel}><div className="admin-channel__line"><strong>{item.channel}</strong><span>{item.share} % des commandes</span></div><div className="admin-channel__rail"><i style={{ width: `${item.share}%` }} /></div><div className="admin-channel__figures"><span>{item.orders} commandes</span><span>{item.spend ? `CAC ${formatAdminPrice(item.spend / item.orders)}` : "CAC 0 FCFA"}</span></div></div>)}
          </div>
          <p className="admin-panel__note">Le réachat est suivi comme canal séparé, sans coût d’acquisition.</p>
        </article>
      </section>

      <section className="admin-two-columns">
        <article className="admin-panel admin-ranking-panel">
          <div className="admin-panel__header"><div><p className="admin-panel__eyebrow">Produits</p><h2>Les modèles qui portent L’Horloger</h2></div><label className="admin-mini-select"><span>Classer par</span><select value={rankingMetric} onChange={(event) => setRankingMetric(event.target.value as RankingMetric)}><option value="revenue">CA</option><option value="grossMargin">Marge</option><option value="units">Unités</option></select></label></div>
          <ol className="admin-ranking">
            {ranking.map((row, index) => {
              const product = productFromId(row.productId);
              if (!product) return null;
              const value = rankingMetric === "units" ? `${row.units} unités` : formatAdminPrice(row[rankingMetric]);
              return <li key={row.productId}><span className="admin-ranking__number">0{index + 1}</span><div><strong>{product.name}</strong><small>{product.reference} · {row.units} vendues</small></div><b>{value}</b></li>;
            })}
          </ol>
          <p className="admin-panel__note">Classement par {rankingLabels[rankingMetric].toLowerCase()} sur la période choisie.</p>
        </article>

        <article className="admin-panel admin-orders-panel">
          <div className="admin-panel__header"><div><p className="admin-panel__eyebrow">À traiter</p><h2>Commandes récentes</h2></div><Link href="/admin/commandes" className="admin-text-link">Tout voir</Link></div>
          <div className="admin-recent-orders">
            {orders.slice(0, 4).map((order) => {
              const product = productFromId(order.productId);
              return <div className="admin-recent-order" key={order.id}><div><strong>{order.customer}</strong><small>{product?.name} · {order.createdAt}</small></div><span className={`admin-status admin-status--${statusClass(order.status)}`}>{order.status}</span></div>;
            })}
          </div>
        </article>
      </section>

      <section className="admin-stock-alert">
        <div><p className="admin-kicker">Stock à surveiller</p><h2>{lowStock.length} modèles atteignent leur seuil d’alerte.</h2><p>Un mouvement simple de réassort ou de sortie suffit pour garder l’inventaire à jour. Aucune réservation ou bon de réassort n’est utilisé.</p></div>
        <div className="admin-stock-alert__models">{lowStock.map((item) => { const product = productFromId(item.productId); return <span key={item.productId}><b>{product?.name}</b>{item.quantity} en stock</span>; })}</div>
        <Link href="/admin/stock" className="admin-dark-link">Gérer le stock</Link>
      </section>
    </>
  );
}

function MetricCard({ label, value, delta, note }: { label: string; value: string; delta: string; note: string }) {
  return <article className="admin-metric"><p>{label}</p><strong>{value}</strong><div><b>{delta}</b><span>{note}</span></div></article>;
}
