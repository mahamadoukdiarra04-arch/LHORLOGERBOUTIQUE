import { products } from "./catalog";

export type OrderStatus = "À confirmer" | "Confirmée" | "En livraison" | "Livrée";
export type AcquisitionChannel = "Meta" | "Réachat";

export type AdminOrder = {
  id: string;
  customer: string;
  phone: string;
  district: string;
  productId: string;
  variant: string;
  quantity: number;
  channel?: AcquisitionChannel;
  status: OrderStatus;
  createdAt: string;
};

export type InventoryItem = {
  productId: string;
  quantity: number;
  alertAt: number;
  unitCost: number;
  lastMovement: string;
};

export type InventoryEventType = "Réassort" | "Sortie" | "Publicité";
export type AdPeriod = "7" | "30" | "90";

export type InventoryEvent = {
  id: string;
  productId: string;
  type: InventoryEventType;
  date: string;
  quantity?: number;
  purchasePrice?: number;
  transitPrice?: number;
  totalCost?: number;
  unitCost?: number;
  amount?: number;
  period?: AdPeriod;
};

export const formatAdminPrice = (value: number) =>
  `${Math.round(value).toString().replace(/\B(?=(\d{3})+(?!\d))/g, " ")} FCFA`;

export const productFromId = (id: string) => products.find((product) => product.id === id);

export const orders: AdminOrder[] = [
  { id: "HOR-2608-483921", customer: "Mahamadou Karamoko Diarra", phone: "+223 92 61 84 53", district: "Yirimadio", productId: "tuma-02", variant: "Bleu & or", quantity: 1, status: "À confirmer", createdAt: "Aujourd’hui · 10:42" },
  { id: "HOR-2608-483920", customer: "Aïssata Traoré", phone: "+223 76 25 04 18", district: "Badalabougou", productId: "tuma-03", variant: "Argent & brun", quantity: 1, channel: "Réachat", status: "Confirmée", createdAt: "Aujourd’hui · 09:58" },
  { id: "HOR-2608-483917", customer: "Boubacar Koné", phone: "+223 66 38 72 10", district: "Kalaban Coura", productId: "tuma-01", variant: "Noir & brun", quantity: 2, channel: "Meta", status: "En livraison", createdAt: "Aujourd’hui · 08:17" },
  { id: "HOR-2608-483910", customer: "Fanta Coulibaly", phone: "+223 73 91 15 62", district: "Hamdallaye ACI", productId: "tuma-02", variant: "Noir & or", quantity: 1, channel: "Meta", status: "Livrée", createdAt: "Hier · 17:26" },
  { id: "HOR-2608-483902", customer: "Moussa Sissoko", phone: "+223 90 07 44 82", district: "Sébénikoro", productId: "tuma-03", variant: "Argent & brun", quantity: 1, channel: "Réachat", status: "Livrée", createdAt: "Hier · 15:40" },
  { id: "HOR-2608-483893", customer: "Kadiatou Diallo", phone: "+223 70 02 30 14", district: "Faladié", productId: "tuma-02", variant: "Bleu & or", quantity: 1, channel: "Meta", status: "Confirmée", createdAt: "Hier · 12:03" },
  { id: "HOR-2608-483877", customer: "Oumar Sangaré", phone: "+223 65 18 99 07", district: "Niamakoro", productId: "tuma-01", variant: "Noir intense", quantity: 1, channel: "Meta", status: "Livrée", createdAt: "12 août · 18:11" },
];

export const inventory: InventoryItem[] = [
  { productId: "tuma-01", quantity: 18, alertAt: 7, unitCost: 30_500, lastMovement: "Réassort · 13 août" },
  { productId: "tuma-02", quantity: 8, alertAt: 6, unitCost: 38_600, lastMovement: "Sortie · aujourd’hui" },
  { productId: "tuma-03", quantity: 4, alertAt: 6, unitCost: 36_200, lastMovement: "Sortie · aujourd’hui" },
];

export const inventoryEvents: InventoryEvent[] = [
  { id: "stock-01", productId: "tuma-01", type: "Réassort", date: "13 août 2026 · 10:20", quantity: 20, purchasePrice: 540_000, transitPrice: 70_000, totalCost: 610_000, unitCost: 30_500 },
  { id: "stock-02", productId: "tuma-02", type: "Réassort", date: "12 août 2026 · 14:35", quantity: 10, purchasePrice: 340_000, transitPrice: 46_000, totalCost: 386_000, unitCost: 38_600 },
  { id: "stock-03", productId: "tuma-03", type: "Réassort", date: "12 août 2026 · 09:15", quantity: 8, purchasePrice: 250_000, transitPrice: 39_600, totalCost: 289_600, unitCost: 36_200 },
  { id: "stock-04", productId: "tuma-03", type: "Sortie", date: "Aujourd’hui · 09:58", quantity: 1 },
  { id: "stock-05", productId: "tuma-02", type: "Sortie", date: "Aujourd’hui · 08:40", quantity: 1 },
  { id: "stock-06", productId: "tuma-01", type: "Sortie", date: "Hier · 12:03", quantity: 1 },
];

export const productPerformance = [
  { productId: "tuma-01", units: 13, revenue: 676_000, grossMargin: 279_500 },
  { productId: "tuma-02", units: 10, revenue: 620_000, grossMargin: 234_000 },
  { productId: "tuma-03", units: 9, revenue: 531_000, grossMargin: 205_200 },
];

export const dailyRevenue = [
  { label: "Lun", revenue: 168_000 },
  { label: "Mar", revenue: 236_000 },
  { label: "Mer", revenue: 194_000 },
  { label: "Jeu", revenue: 328_000 },
  { label: "Ven", revenue: 272_000 },
  { label: "Sam", revenue: 418_000 },
  { label: "Dim", revenue: 316_000 },
];

export const intradayRevenue = [
  { label: "09 h", revenue: 42_000 },
  { label: "11 h", revenue: 58_000 },
  { label: "13 h", revenue: 36_000 },
  { label: "15 h", revenue: 71_000 },
  { label: "17 h", revenue: 94_000 },
  { label: "19 h", revenue: 63_000 },
];

export const periodSummary = {
  today: { label: "Aujourd’hui", revenue: 328_000, margin: 128_400, orders: 8, averageBasket: 41_000, delta: 10.2 },
  yesterday: { label: "Hier", revenue: 276_000, margin: 107_700, orders: 7, averageBasket: 39_429, delta: 6.4 },
  "7": { label: "7 derniers jours", revenue: 1_932_000, margin: 762_100, orders: 47, averageBasket: 41_106, delta: 18.4 },
  "14": { label: "14 derniers jours", revenue: 3_548_000, margin: 1_391_300, orders: 86, averageBasket: 41_256, delta: 15.1 },
  "30": { label: "30 derniers jours", revenue: 7_486_000, margin: 2_936_400, orders: 183, averageBasket: 40_907, delta: 12.8 },
  quarter: { label: "Ce trimestre", revenue: 18_226_000, margin: 7_126_200, orders: 442, averageBasket: 41_235, delta: 11.2 },
  "90": { label: "90 derniers jours", revenue: 20_948_000, margin: 8_189_000, orders: 507, averageBasket: 41_318, delta: 9.6 },
  year: { label: "Depuis le 1er janvier", revenue: 76_328_000, margin: 29_886_100, orders: 1_844, averageBasket: 41_392, delta: 13.7 },
  custom: { label: "Période personnalisée", revenue: 4_078_000, margin: 1_598_500, orders: 99, averageBasket: 41_192, delta: 11.9 },
} as const;

export const acquisition = [
  { channel: "Meta" as const, orders: 29, spend: 374_000, revenue: 1_194_000, share: 68 },
  { channel: "Réachat" as const, orders: 18, spend: 0, revenue: 738_000, share: 32 },
];

export const statusClass = (status: OrderStatus) =>
  ({ "À confirmer": "pending", "Confirmée": "confirmed", "En livraison": "delivery", "Livrée": "delivered" })[status];
