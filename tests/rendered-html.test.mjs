import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const publicRoutes = ["/", "/montres", "/montres/nocturne-chrono", "/panier", "/commande", "/admin/connexion"];

async function render(path) {
  const workerUrl = new URL("../dist/server/index.js", import.meta.url);
  workerUrl.searchParams.set("test", `${process.pid}-${Date.now()}-${path}`);
  const { default: worker } = await import(workerUrl.href);

  return worker.fetch(
    new Request(`http://localhost${path}`, { headers: { accept: "text/html" } }),
    { ASSETS: { fetch: async () => new Response("Not found", { status: 404 }) } },
    { waitUntil() {}, passThroughOnException() {} },
  );
}

test("server-renders the L’Horloger public routes", async () => {
  for (const path of publicRoutes) {
    const response = await render(path);
    assert.equal(response.status, 200, `Expected ${path} to respond with 200`);
    assert.match(response.headers.get("content-type") ?? "", /^text\/html\b/i);

    const html = await response.text();
    assert.match(html, /L’Horloger/);
    assert.doesNotMatch(html, /Your site is taking shape|sites-skeleton|codex-preview/i);
  }
});

test("keeps the order flow concise and the catalogue connected", async () => {
  const [home, catalogue, product, cart, checkoutSource, productSource] = await Promise.all([
    render("/").then((response) => response.text()),
    render("/montres").then((response) => response.text()),
    render("/montres/nocturne-chrono").then((response) => response.text()),
    render("/panier").then((response) => response.text()),
    readFile(new URL("../app/components/CheckoutPage.tsx", import.meta.url), "utf8"),
    readFile(new URL("../app/components/ProductDetails.tsx", import.meta.url), "utf8"),
  ]);

  assert.doesNotMatch(home, /Repère pour la livraison/i);
  assert.match(checkoutSource, /Quartier/);
  assert.doesNotMatch(checkoutSource, /Repère pour la livraison/i);
  assert.doesNotMatch(checkoutSource, /name="commune"|type="checkbox"/i);
  assert.doesNotMatch(checkoutSource, /Aucun paiement n’est demandé maintenant|Vous ne saisirez aucune information bancaire/i);
  assert.doesNotMatch(checkoutSource, /Votre demande est prête à être confirmée/i);
  assert.match(catalogue, /Filtres/);
  assert.match(product, /Commander maintenant/);
  assert.match(productSource, /router\.push\("\/commande"\)/);
  assert.match(cart, /Votre panier/);
});

test("protects the administration with a server-side session", async () => {
  const [protectedResponse, login, authSource, sessionSource, shellSource, dashboardSource, ordersSource, stockSource, analysisSource] = await Promise.all([
    render("/admin"),
    render("/admin/connexion").then((response) => response.text()),
    readFile(new URL("../app/lib/admin-auth.ts", import.meta.url), "utf8"),
    readFile(new URL("../app/api/admin/session/route.ts", import.meta.url), "utf8"),
    readFile(new URL("../app/components/AdminShell.tsx", import.meta.url), "utf8"),
    readFile(new URL("../app/components/AdminDashboard.tsx", import.meta.url), "utf8"),
    readFile(new URL("../app/components/AdminOrders.tsx", import.meta.url), "utf8"),
    readFile(new URL("../app/components/AdminStock.tsx", import.meta.url), "utf8"),
    readFile(new URL("../app/components/AdminAnalysis.tsx", import.meta.url), "utf8"),
  ]);

  assert.ok([302, 303, 307, 308].includes(protectedResponse.status));
  assert.match(protectedResponse.headers.get("location") ?? "", /\/admin\/connexion/);
  assert.match(login, /Connexion sécurisée/);
  assert.match(authSource, /constantTimeEquals/);
  assert.match(authSource, /ADMIN_SESSION_SECRET/);
  assert.match(sessionSource, /HttpOnly/);
  assert.match(sessionSource, /SameSite=Lax/);
  assert.match(shellSource, /Accès sécurisé/);
  assert.match(dashboardSource, /CAC Meta/);
  assert.match(ordersSource, /Canal d’acquisition/);
  assert.match(stockSource, /Un réassort ou une sortie/);
  assert.match(analysisSource, /Voir ce qui est vraiment rentable/);
});

test("keeps the full product configuration visible in each order detail", async () => {
  const [ordersSource, ordersData] = await Promise.all([
    readFile(new URL("../app/components/AdminOrders.tsx", import.meta.url), "utf8"),
    readFile(new URL("../app/lib/admin-data.ts", import.meta.url), "utf8"),
  ]);

  assert.match(ordersSource, /admin-order-product-detail/);
  assert.match(ordersSource, /order\.variant/);
  assert.match(ordersSource, /Coloris/);
  assert.match(ordersSource, /Prix unitaire/);
  assert.match(ordersSource, /Sous-total/);
  assert.match(ordersData, /variant: "Bleu & or"/);
  assert.match(ordersData, /variant: "Noir intense"/);
});

test("derives product costs from restocks and keeps CAC scoped to each product", async () => {
  const [stockSource, stockData] = await Promise.all([
    readFile(new URL("../app/components/AdminStock.tsx", import.meta.url), "utf8"),
    readFile(new URL("../app/lib/admin-data.ts", import.meta.url), "utf8"),
  ]);

  assert.match(stockSource, /purchasePrice/);
  assert.match(stockSource, /transitPrice/);
  assert.match(stockSource, /newUnitCost/);
  assert.match(stockSource, /addAdvertisingCost/);
  assert.match(stockSource, /selectedProductEvents/);
  assert.match(stockSource, /eventFilter/);
  assert.match(stockData, /InventoryEvent/);
  assert.match(stockData, /inventoryEvents/);
});

test("filters the product analysis by a selected period", async () => {
  const analysisSource = await readFile(new URL("../app/components/AdminAnalysis.tsx", import.meta.url), "utf8");

  assert.match(analysisSource, /AnalysisPeriod/);
  assert.match(analysisSource, /periodMultipliers/);
  assert.match(analysisSource, /customStart/);
  assert.match(analysisSource, /Période d’analyse produit/);
  assert.match(analysisSource, /Période personnalisée/);
});

test("keeps colour choice cards contained on narrow mobile screens", async () => {
  const [styles, reviewSource] = await Promise.all([
    readFile(new URL("../app/globals.css", import.meta.url), "utf8"),
    readFile(new URL("../scripts/export-local-review.mjs", import.meta.url), "utf8"),
  ]);

  assert.match(styles, /repeat\(auto-fit, minmax\(min\(132px, 100%\), 1fr\)\)/);
  assert.match(styles, /\.product-variant img \{ width: 38px; height: 40px; \}/);
  assert.match(reviewSource, /repeat\(auto-fit,minmax\(min\(132px,100%\),1fr\)\)/);
});

test("uses the supplied local watch photos for the three-product collection", async () => {
  const [catalogSource, visualSource] = await Promise.all([
    readFile(new URL("../app/lib/catalog.ts", import.meta.url), "utf8"),
    readFile(new URL("../app/components/WatchVisual.tsx", import.meta.url), "utf8"),
  ]);

  assert.match(catalogSource, /Nocturne Chrono/);
  assert.match(catalogSource, /Azur Squelette/);
  assert.match(catalogSource, /Éclipse Lunaire/);
  assert.match(catalogSource, /\/products\/nocturne-chrono\.png/);
  assert.match(catalogSource, /\/products\/azur-squelette\.png/);
  assert.match(catalogSource, /\/products\/eclipse-lunaire\.png/);
  assert.match(visualSource, /watch-art__image/);
});
