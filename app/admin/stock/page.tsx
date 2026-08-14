import { AdminStock } from "../../components/AdminStock";
import { requireAdminSession } from "../../lib/admin-session";

export const dynamic = "force-dynamic";

export default async function AdminStockPage() {
  await requireAdminSession();
  return <AdminStock />;
}
