import type { Metadata } from "next";
import { headers } from "next/headers";
import { CartProvider } from "./components/CartProvider";
import "./globals.css";

export async function generateMetadata(): Promise<Metadata> {
  const requestHeaders = await headers();
  const host = requestHeaders.get("host") ?? "localhost:3001";
  const protocol = host.startsWith("localhost") ? "http" : "https";

  return {
    metadataBase: new URL(`${protocol}://${host}`),
    title: "L’Horloger — Montres à Bamako · Livraison offerte",
    description: "Découvrez la sélection L’Horloger. Commandez votre montre à Bamako, payez à la livraison et profitez de la livraison offerte dans nos zones couvertes.",
    openGraph: {
      title: "L’Horloger — Le temps vous va bien.",
      description: "Montres sélectionnées à Bamako · Livraison offerte · Paiement à la livraison",
      images: [{ url: "/og.png", width: 1728, height: 906, alt: "L’Horloger — Le temps vous va bien" }],
      locale: "fr_ML",
      type: "website",
    },
    twitter: { card: "summary_large_image", images: ["/og.png"] },
  };
}

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <html lang="fr"><body><CartProvider>{children}</CartProvider></body></html>;
}
