import type { Metadata } from "next";
import { headers } from "next/headers";
import "./globals.css";

export async function generateMetadata(): Promise<Metadata> {
  const requestHeaders = await headers();
  const host = requestHeaders.get("host") ?? "localhost:3000";
  const protocol = host.startsWith("localhost") ? "http" : "https";
  const metadataBase = new URL(`${protocol}://${host}`);

  return {
    metadataBase,
    title: "TUMA — Montres à Bamako · Livraison offerte",
    description:
      "Découvrez la sélection TUMA. Commandez votre montre en ligne à Bamako, payez à la livraison et profitez de la livraison offerte dans nos zones couvertes.",
    openGraph: {
      title: "TUMA — Le temps vous va bien.",
      description: "Montres sélectionnées à Bamako · Livraison offerte · Paiement à la livraison",
      images: [{ url: "/og.png", width: 1728, height: 906, alt: "TUMA — Le temps vous va bien" }],
      locale: "fr_ML",
      type: "website",
    },
    twitter: {
      card: "summary_large_image",
      title: "TUMA — Le temps vous va bien.",
      description: "Montres sélectionnées à Bamako · Paiement à la livraison",
      images: ["/og.png"],
    },
  };
}

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="fr">
      <body>{children}</body>
    </html>
  );
}
