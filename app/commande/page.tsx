import { CheckoutPage } from "../components/CheckoutPage";
import { StoreFooter, StoreHeader } from "../components/StoreChrome";

export default function CheckoutRoute() {
  return <div className="page-shell"><StoreHeader /><CheckoutPage /><StoreFooter /></div>;
}
