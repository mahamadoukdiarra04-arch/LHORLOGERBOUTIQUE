import { notFound } from "next/navigation";
import { ProductDetails } from "../../components/ProductDetails";
import { StoreFooter, StoreHeader } from "../../components/StoreChrome";
import { getProduct, products } from "../../lib/catalog";

export function generateStaticParams() {
  return products.map((product) => ({ slug: product.slug }));
}

export default async function WatchPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const product = getProduct(slug);
  if (!product) notFound();

  return <div className="page-shell"><StoreHeader /><main><ProductDetails product={product} /></main><StoreFooter /></div>;
}
