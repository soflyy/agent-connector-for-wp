import { redirect } from "next/navigation";

// The directory now lives at the site root; keep this path working for old links.
export default function PluginsPage() {
  redirect("/");
}
