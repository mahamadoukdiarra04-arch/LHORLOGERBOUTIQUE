import { AdminAnalysis } from "../../components/AdminAnalysis";
import { requireAdminSession } from "../../lib/admin-session";

export const dynamic = "force-dynamic";

export default async function AdminAnalysisPage() {
  await requireAdminSession();
  return <AdminAnalysis />;
}
