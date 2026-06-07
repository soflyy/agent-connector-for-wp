import { notFound } from "next/navigation";
import { requireAdmin } from "@/lib/admin-auth";
import { getPluginByUrlSlug } from "@/lib/db";
import StatusForm from "../../StatusForm";

export const dynamic = "force-dynamic";

export default async function EditPluginPage({
  params,
}: {
  params: Promise<{ slug: string }>;
}) {
  await requireAdmin();
  const { slug } = await params;
  const plugin = await getPluginByUrlSlug(slug);
  if (!plugin) notFound();

  return <StatusForm initial={plugin} />;
}
