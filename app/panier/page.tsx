import { CartPage } from "../components/CartPage";
import { StoreFooter, StoreHeader } from "../components/StoreChrome";

export default function CartRoute() {
  return <div className="page-shell"><StoreHeader /><CartPage /><StoreFooter /></div>;
}
