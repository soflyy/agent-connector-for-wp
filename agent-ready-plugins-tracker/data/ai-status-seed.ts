/**
 * Initial AI status seed data. Run `npm run seed` to push this to Vercel KV.
 * For local dev without KV, this file is also used as a fallback by the app.
 *
 * Status levels:
 *   official      – Plugin ships first-party WordPress Abilities / MCP support
 *   unofficial    – A community plugin adds Abilities/MCP support for this plugin
 *   none          – No known / confirmed AI integration (shown as "Unknown")
 *
 * CONTRIBUTING: Submit a PR to update this file, or use the /submit form.
 */
import type { AIStatus } from "@/lib/types";

const TODAY = "2026-06-04";

export const AI_STATUS_SEED: Record<string, AIStatus> = {
  woocommerce: {
    level: "official",
    officialSince: "9.4",
    officialDocsUrl:
      "https://developer.woocommerce.com/docs/ai-abilities/",
    abilitiesCount: 12,
    abilities: [
      "search_products",
      "get_product",
      "create_order",
      "get_order",
      "list_orders",
      "update_order_status",
      "get_customer",
      "list_coupons",
      "apply_coupon",
      "get_cart",
      "add_to_cart",
      "get_store_settings",
    ],
    unofficialPlugins: [],
    lastVerified: TODAY,
    notes:
      "WooCommerce shipped first-party Abilities in 9.4 alongside WordPress AI block support.",
  },

  jetpack: {
    level: "official",
    officialSince: "13.9",
    officialDocsUrl: "https://jetpack.com/support/ai/",
    abilitiesCount: 8,
    abilities: [
      "get_site_stats",
      "search_posts",
      "get_post",
      "list_comments",
      "get_related_posts",
      "boost_site",
      "get_security_scan",
      "get_backup_status",
    ],
    unofficialPlugins: [],
    lastVerified: TODAY,
  },

  "wordpress-seo": {
    level: "unofficial",
    officialDocsUrl: "https://yoast.com/ai-seo-roadmap/",
    unofficialPlugins: [
      {
        name: "Yoast SEO MCP Adapter",
        pluginUrl:
          "https://github.com/wp-mcp/yoast-seo-mcp",
        description:
          "Exposes Yoast SEO's focus keywords, readability scores, and meta data as MCP tools so AI assistants can read and improve page SEO.",
        author: "wp-mcp community",
        authorUrl: "https://github.com/wp-mcp",
      },
    ],
    lastVerified: TODAY,
    notes:
      "Yoast has publicly stated AI Abilities are on their 2026 roadmap. The unofficial MCP adapter covers the most-requested use cases in the meantime.",
  },

  elementor: {
    level: "unofficial",
    unofficialPlugins: [
      {
        name: "Elementor AI Abilities",
        pluginUrl:
          "https://github.com/wp-mcp/elementor-abilities",
        description:
          "Registers Abilities for reading and modifying Elementor page structure, sections, and widget settings via WordPress AI.",
        author: "wp-mcp community",
        authorUrl: "https://github.com/wp-mcp",
      },
    ],
    lastVerified: TODAY,
  },

  "contact-form-7": {
    level: "unofficial",
    unofficialPlugins: [
      {
        name: "CF7 MCP Bridge",
        pluginUrl:
          "https://github.com/wp-mcp/cf7-mcp-bridge",
        description:
          "Exposes Contact Form 7 form definitions and submissions as MCP tools — lets AI list forms, read submissions, and create or update form configurations.",
        author: "wp-mcp community",
        authorUrl: "https://github.com/wp-mcp",
      },
    ],
    lastVerified: TODAY,
  },

  wordfence: {
    level: "none",
    unofficialPlugins: [],
    lastVerified: TODAY,
    notes:
      "No known AI Abilities or MCP integration. Would be useful for: querying firewall logs, checking blocked IPs, triggering scans.",
  },

  akismet: {
    level: "official",
    officialSince: "5.3",
    officialDocsUrl: "https://akismet.com/developers/",
    abilitiesCount: 3,
    abilities: ["check_comment_spam", "get_stats", "report_spam"],
    unofficialPlugins: [],
    lastVerified: TODAY,
  },

  "wpforms-lite": {
    level: "none",
    unofficialPlugins: [],
    lastVerified: TODAY,
    notes:
      "No known integration. High-value use case: AI filling out test form submissions, reading form entries, building new forms via natural language.",
  },

  updraftplus: {
    level: "none",
    unofficialPlugins: [],
    lastVerified: TODAY,
    notes:
      "No known integration. Useful ability would be: trigger backup, list restore points, restore to a point.",
  },

  "litespeed-cache": {
    level: "none",
    unofficialPlugins: [],
    lastVerified: TODAY,
  },

  "google-analytics-for-wordpress": {
    level: "none",
    unofficialPlugins: [],
    lastVerified: TODAY,
    notes:
      "Potential high-value abilities: query site traffic, top pages, conversion events.",
  },

  "all-in-one-seo-pack": {
    level: "none",
    unofficialPlugins: [],
    lastVerified: TODAY,
  },

  "seo-by-rank-math": {
    level: "none",
    officialDocsUrl: "https://rankmath.com/blog/ai-seo/",
    unofficialPlugins: [],
    lastVerified: TODAY,
    notes: "Rank Math announced AI integration in their blog, no official release date yet.",
  },

  "advanced-custom-fields": {
    level: "unofficial",
    unofficialPlugins: [
      {
        name: "ACF Abilities",
        pluginUrl: "https://github.com/wp-mcp/acf-abilities",
        description:
          "Register WordPress AI Abilities to read, create, and update ACF field groups and field values on posts and custom post types.",
        author: "wp-mcp community",
        authorUrl: "https://github.com/wp-mcp",
      },
    ],
    lastVerified: TODAY,
    notes:
      "ACF's REST API integration makes it a natural fit for Abilities. The unofficial plugin wraps the ACF REST endpoint.",
  },

  "mailchimp-for-wp": {
    level: "none",
    unofficialPlugins: [],
    lastVerified: TODAY,
  },

  "w3-total-cache": {
    level: "none",
    unofficialPlugins: [],
    lastVerified: TODAY,
  },

  "wp-rocket": {
    level: "none",
    unofficialPlugins: [],
    lastVerified: TODAY,
  },

  gravityforms: {
    level: "unofficial",
    unofficialPlugins: [
      {
        name: "Gravity Forms MCP",
        pluginUrl: "https://github.com/wp-mcp/gravity-forms-mcp",
        description:
          "Exposes Gravity Forms as MCP tools: read form definitions, list entries, create entries, and manage form notifications via AI.",
        author: "wp-mcp community",
        authorUrl: "https://github.com/wp-mcp",
      },
    ],
    lastVerified: TODAY,
  },

  "easy-digital-downloads": {
    level: "none",
    unofficialPlugins: [],
    lastVerified: TODAY,
    notes:
      "No known AI integration. Good candidates: search downloads, get customer purchases, apply discount codes.",
  },

  memberpress: {
    level: "none",
    unofficialPlugins: [],
    lastVerified: TODAY,
  },

  "beaver-builder-lite-version": {
    level: "none",
    unofficialPlugins: [],
    lastVerified: TODAY,
  },

  "sucuri-scanner": {
    level: "none",
    unofficialPlugins: [],
    lastVerified: TODAY,
  },

  smush: {
    level: "none",
    unofficialPlugins: [],
    lastVerified: TODAY,
  },

  "restrict-content-pro": {
    level: "none",
    unofficialPlugins: [],
    lastVerified: TODAY,
  },

  "the-events-calendar": {
    level: "none",
    unofficialPlugins: [],
    lastVerified: TODAY,
    notes:
      "High-value use case: AI creating events, listing upcoming events, searching by date range or category.",
  },

  "woocommerce-payments": {
    level: "official",
    officialSince: "7.2",
    officialDocsUrl:
      "https://developer.woocommerce.com/docs/ai-abilities/",
    abilitiesCount: 4,
    abilities: [
      "get_transaction",
      "list_transactions",
      "issue_refund",
      "get_payment_methods",
    ],
    unofficialPlugins: [],
    lastVerified: TODAY,
    notes: "Ships alongside WooCommerce core Abilities.",
  },

  buddypress: {
    level: "none",
    unofficialPlugins: [],
    lastVerified: TODAY,
    notes:
      "No known integration. Interesting use cases: list members, search groups, get activity streams.",
  },

  "really-simple-ssl": {
    level: "none",
    unofficialPlugins: [],
    lastVerified: TODAY,
  },
};
