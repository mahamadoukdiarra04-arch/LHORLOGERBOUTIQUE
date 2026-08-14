import type { Metadata } from "next";
import { AdminShell } from "../components/AdminShell";

export const metadata: Metadata = {
  title: "L’Horloger — Gestion",
  description: "Espace de pilotage de L’Horloger.",
  robots: { index: false, follow: false },
};

export default function AdminLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <AdminShell>{children}</AdminShell>;
}
