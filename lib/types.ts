export type AIStatusLevel = "official" | "unofficial" | "coming_soon" | "none";

export type PluginCategory =
  | "ecommerce"
  | "seo"
  | "security"
  | "forms"
  | "page-builder"
  | "performance"
  | "marketing"
  | "analytics"
  | "backup"
  | "membership"
  | "content"
  | "social"
  | "media"
  | "other";

export const CATEGORY_LABELS: Record<PluginCategory, string> = {
  ecommerce: "E-Commerce",
  seo: "SEO",
  security: "Security",
  forms: "Forms",
  "page-builder": "Page Builder",
  performance: "Performance",
  marketing: "Marketing",
  analytics: "Analytics",
  backup: "Backup",
  membership: "Membership",
  content: "Content",
  social: "Social",
  media: "Media",
  other: "Other",
};

export interface UnofficialPlugin {
  name: string;
  pluginUrl: string;
  description: string;
  author: string;
  authorUrl?: string;
}

export interface AIStatus {
  level: AIStatusLevel;
  /** Plugin version that added official AI abilities */
  officialSince?: string;
  /** Link to official docs, changelog, or announcement */
  officialDocsUrl?: string;
  /** Number of abilities registered */
  abilitiesCount?: number;
  /** Abilities this plugin exposes (e.g. "search_products", "create_order") */
  abilities?: string[];
  unofficialPlugins: UnofficialPlugin[];
  notes?: string;
  /** ISO 8601 date this status was last verified */
  lastVerified: string;
}

export interface Plugin {
  slug: string;
  name: string;
  tagline: string;
  wpOrgUrl?: string;
  repoUrl?: string;
  isPremium: boolean;
  categories: PluginCategory[];
  /** e.g. "5+ million" */
  activeInstalls: string;
  author: string;
  authorUrl?: string;
}

export interface PluginWithStatus extends Plugin {
  aiStatus: AIStatus;
}

export interface Submission {
  id: string;
  pluginSlug: string;
  type: "new_unofficial" | "status_correction" | "new_official";
  submittedAt: string;
  submitterEmail?: string;
  data: Partial<AIStatus> & { unofficialPlugin?: UnofficialPlugin };
  notes?: string;
  reviewed: boolean;
}
