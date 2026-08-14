import { AdminDashboard } from "../components/AdminDashboard";
import { requireAdminSession } from "../lib/admin-session";

export const dynamic = "force-dynamic";

export default async function AdminHomePage() {
  await requireAdminSession();
  return <AdminDashboard />;
}
