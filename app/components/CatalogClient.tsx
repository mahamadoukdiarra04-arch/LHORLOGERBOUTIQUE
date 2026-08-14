"use client";

import { useMemo, useState } from "react";
import { Bracelet, Finish, Size, products } from "../lib/catalog";
import { ProductCard } from "./ProductCard";

const bracelets: Bracelet[] = ["Cuir", "Acier", "Maille"];
const finishes: Finish[] = ["Or", "Acier", "Noir"];
const sizes: Size[] = ["38 mm", "40 mm", "42 mm"];

type Sort = "featured" | "low" | "high" | "new";

export function CatalogClient() {
  const [selectedBracelets, setSelectedBracelets] = useState<Bracelet[]>([]);
  const [selectedFinishes, setSelectedFinishes] = useState<Finish[]>([]);
  const [selectedSizes, setSelectedSizes] = useState<Size[]>([]);
  const [sort, setSort] = useState<Sort>("featured");
  const [filtersOpen, setFiltersOpen] = useState(false);

  const displayedProducts = useMemo(() => {
    const filtered = products.filter((product) =>
      (selectedBracelets.length === 0 || selectedBracelets.includes(product.bracelet)) &&
      (selectedFinishes.length === 0 || selectedFinishes.includes(product.finish)) &&
      (selectedSizes.length === 0 || selectedSizes.includes(product.size)),
    );

    return [...filtered].sort((first, second) => {
      if (sort === "low") return first.price - second.price;
      if (sort === "high") return second.price - first.price;
      if (sort === "new") return Number(Boolean(second.badge)) - Number(Boolean(first.badge));
      return products.indexOf(first) - products.indexOf(second);
    });
  }, [selectedBracelets, selectedFinishes, selectedSizes, sort]);

  const activeFilters = selectedBracelets.length + selectedFinishes.length + selectedSizes.length;

  const toggle = <T extends string>(value: T, current: T[], setCurrent: (values: T[]) => void) => {
    setCurrent(current.includes(value) ? current.filter((item) => item !== value) : [...current, value]);
  };

  const reset = () => {
    setSelectedBracelets([]);
    setSelectedFinishes([]);
    setSelectedSizes([]);
  };

  return (
    <div className="catalog-layout">
      <div className="catalog-mobile-tools">
        <button className="filter-toggle" type="button" onClick={() => setFiltersOpen((open) => !open)} aria-expanded={filtersOpen}>
          Filtres {activeFilters ? `(${activeFilters})` : ""}
          <span aria-hidden="true">{filtersOpen ? "−" : "+"}</span>
        </button>
        <SortSelect sort={sort} setSort={setSort} />
      </div>

      <aside className={filtersOpen ? "filter-panel filter-panel--open" : "filter-panel"} aria-label="Filtres catalogue">
        <div className="filter-panel__top">
          <strong>Filtrer</strong>
          {activeFilters > 0 && (
            <button type="button" onClick={reset}>
              Tout effacer
            </button>
          )}
        </div>
        <FilterGroup label="Bracelet" values={bracelets} current={selectedBracelets} onToggle={(value) => toggle(value, selectedBracelets, setSelectedBracelets)} />
        <FilterGroup label="Finition" values={finishes} current={selectedFinishes} onToggle={(value) => toggle(value, selectedFinishes, setSelectedFinishes)} />
        <FilterGroup label="Diamètre" values={sizes} current={selectedSizes} onToggle={(value) => toggle(value, selectedSizes, setSelectedSizes)} />
      </aside>

      <section className="catalog-results" aria-live="polite">
        <div className="catalog-results__head">
          <p>{displayedProducts.length} modèle{displayedProducts.length > 1 ? "s" : ""}</p>
          <div className="catalog-desktop-sort">
            <SortSelect sort={sort} setSort={setSort} />
          </div>
        </div>
        {displayedProducts.length ? (
          <div className="catalog-grid">
            {displayedProducts.map((product) => <ProductCard key={product.id} product={product} />)}
          </div>
        ) : (
          <div className="catalog-empty">
            <h2>Aucun modèle ne correspond.</h2>
            <p>Essayez de retirer un ou plusieurs filtres.</p>
            <button className="quiet-link" type="button" onClick={reset}>Réinitialiser les filtres</button>
          </div>
        )}
      </section>
    </div>
  );
}

function FilterGroup<T extends string>({
  label,
  values,
  current,
  onToggle,
}: {
  label: string;
  values: T[];
  current: T[];
  onToggle: (value: T) => void;
}) {
  return (
    <fieldset className="filter-group">
      <legend>{label}</legend>
      {values.map((value) => (
        <label key={value}>
          <input type="checkbox" checked={current.includes(value)} onChange={() => onToggle(value)} />
          <span>{value}</span>
        </label>
      ))}
    </fieldset>
  );
}

function SortSelect({ sort, setSort }: { sort: Sort; setSort: (value: Sort) => void }) {
  return (
    <label className="sort-select">
      <span>Trier</span>
      <select value={sort} onChange={(event) => setSort(event.target.value as Sort)}>
        <option value="featured">Sélection L’Horloger</option>
        <option value="new">Nouveautés</option>
        <option value="low">Prix croissant</option>
        <option value="high">Prix décroissant</option>
      </select>
    </label>
  );
}
