import { AdminOrders } from "../../components/AdminOrders";
import { requireAdminSession } from "../../lib/admin-session";

export const dynamic = "force-dynamic";

export default async function AdminOrdersPage() {
  await requireAdminSession();
  return <AdminOrders />;
}
