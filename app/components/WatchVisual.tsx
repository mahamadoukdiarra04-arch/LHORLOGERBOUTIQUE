import { WatchProduct } from "../lib/catalog";

type WatchVisualProps = {
  product: WatchProduct;
  compact?: boolean;
  showReference?: boolean;
  image?: string;
};

export function WatchVisual({ product, compact = false, showReference = false, image }: WatchVisualProps) {
  return (
    <div className={`watch-art watch-art--${product.tone} ${compact ? "watch-art--compact" : ""}`}>
      <img className="watch-art__image" src={image ?? product.image} alt={product.name} />
      {showReference && <span className="watch-art__reference">{product.reference}</span>}
    </div>
  );
}
