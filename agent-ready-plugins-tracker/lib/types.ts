/** A third-party plugin that provides AI abilities for another plugin. */
export interface ThirdPartyAbility {
  pluginName: string;
  /** wordpress.org slug or plugin folder of the third-party plugin. */
  pluginSlug: string;
  /** URL to that plugin (.org page or commercial page). */
  pluginLink: string;
}

export interface Plugin {
  /** WP plugin file: "woocommerce/woocommerce.php". Primary identifier. */
  slug: string;
  /** URL-safe slug derived from `slug` ("woocommerce"). Present on reads. */
  urlSlug?: string;
  name: string;
  /** .org page or commercial plugin page. */
  link?: string;
  /** Whether the plugin ships its own (official) AI abilities. */
  includesAbilities: boolean;
  /** Third-party plugins that provide abilities for this plugin (incl. our own packs). */
  thirdPartyAbilities: ThirdPartyAbility[];
}

/** What the AI research job determines for a plugin. */
export interface AIResearchResult {
  /** Whether the plugin now ships its own official AI abilities. */
  pluginIncludesOfficialAbilities: boolean;
  /** Third-party plugins providing abilities for it. */
  thirdPartyAbilitiesProvidedBy: ThirdPartyAbility[];
}
