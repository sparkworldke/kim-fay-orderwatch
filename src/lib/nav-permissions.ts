import type { Role } from "@/lib/auth";

const ADMIN_ONLY_URLS = new Set(["/app/administration", "/app/roles", "/app/adoption"]);

export const ALL_AUTHENTICATED_URLS = new Set([
  "/app",
  "/app/executive",
  "/app/orders",
  "/app/products-not-delivered",
  "/app/business-optimization",
  "/app/ai-intelligence",
  "/app/customer-feed",
  "/app/credit-notes-more",
  "/app/inventory",
  "/app/production",
  "/app/production/partners",
  "/app/production/sales",
  "/app/backorders",
  "/app/fill-rate",
  "/app/downloads",
  "/app/zones",
  "/app/customers",
  "/app/so-imports",
  "/app/accounts",
  "/app/kp/contract-cleaners",
  "/app/kp/dormant",
  "/app/kp/items-not-ordered",
  "/app/kp/meetings",
  "/app/kp/calendar",
  "/app/kp/dtc-calltronix/quotes",
  "/app/kp/dtc-calltronix/sales-orders",
  "/app/kp/dtc-calltronix/price-list",
  "/app/kp/dtc-calltronix/customers",
  "/app/kp/fol",
  "/app/kp/fol/calendar",
  "/app/price-change-requests",
  "/app/sales-management",
  "/app/gt/revenue-orders",
  "/app/gt/sfa",
  "/app/kp/commissions",
  "/app/profile",
]);

const ADMIN_ONLY_ADMIN_TABS = new Set([
  "acumatica",
  "ai-keys",
  "data-tools",
  "products",
  "consultant-alerts",
  "roles",
  "permissions",
  "notifications",
  "impersonation",
]);

const ADMIN_MANAGER_ADMIN_TABS = new Set(["team", "consultants"]);

const CS_AND_ADMIN_URLS = new Set(["/app/mailbox", "/app/order-match", "/app/administration"]);

const SALES_CONSULTANT_URLS = new Set(["/app/sales-consultants"]);

// Team page: admin + CS Manager
const ADMIN_MANAGER_URLS = new Set(["/app/team"]);

const PRIVILEGED_ROLES: Role[] = [
  "Administrator",
  "Customer Service Manager",
  "Customer Service Agent",
];
const SALES_CONSULTANT_ROLES: Role[] = [
  "Administrator",
  "Customer Service Manager",
  "Executive",
  "Sales Operations",
  "Sales Consultant",
];

export function isPrivilegedRole(role: Role | undefined): boolean {
  return role != null && PRIVILEGED_ROLES.includes(role);
}

export const MENU_SLUG_BY_URL: Record<string, string> = {
  "/app": "dashboard",
  "/app/executive": "executive-view",
  "/app/orders": "orders",
  "/app/products-not-delivered": "products-not-delivered",
  "/app/business-optimization": "business-optimization",
  "/app/ai-intelligence": "ai-intelligence",
  "/app/customer-feed": "customer-feed",
  "/app/credit-notes-more": "credit-notes",
  "/app/inventory": "inventory",
  "/app/production": "production-stock",
  "/app/production/partners": "production-stock",
  "/app/production/sales": "production-stock",
  "/app/backorders": "backorders",
  "/app/fill-rate": "fill-rate",
  "/app/downloads": "downloads",
  "/app/zones": "zones",
  "/app/customers": "customers",
  "/app/sales-consultants": "sales-consultants",
  "/app/order-match": "order-match",
  "/app/mailbox": "mailbox",
  "/app/administration": "administration",
  "/app/team": "team",
  "/app/roles": "roles",
  "/app/so-imports": "so-imports",
  "/app/accounts": "kp-accounts",
  "/app/kp/contract-cleaners": "kp-contract-cleaners",
  "/app/kp/dormant": "kp-dormant",
  "/app/kp/items-not-ordered": "kp-items-not-ordered",
  "/app/kp/meetings": "kp-meetings",
  "/app/kp/calendar": "kp-calendar",
  "/app/kp/dtc-calltronix/quotes": "kp-dtc-calltronix",
  "/app/kp/dtc-calltronix/sales-orders": "kp-dtc-calltronix",
  "/app/kp/dtc-calltronix/price-list": "kp-dtc-calltronix",
  "/app/kp/dtc-calltronix/customers": "kp-dtc-calltronix",
  "/app/kp/fol": "kp-fol",
  "/app/kp/fol/calendar": "kp-fol",
  "/app/kp/fol/settings": "kp-fol-settings",
  "/app/price-change-requests": "price-change-requests",
  "/app/sales-management": "sales-management",
  "/app/gt/revenue-orders": "gt",
  "/app/gt/sfa": "gt",
  "/app/kp/commissions": "kp-commissions",
  "/app/profile": "profile",
  "/app/adoption": "adoption",
};

export function menuSlugForUrl(url: string): string | undefined {
  if (MENU_SLUG_BY_URL[url]) return MENU_SLUG_BY_URL[url];
  if (url.startsWith("/app/sales-consultants/")) return "sales-consultants";
  if (url.startsWith("/app/accounts")) return "kp-accounts";
  if (url.startsWith("/app/kp/contract-cleaners")) return "kp-contract-cleaners";
  if (url.startsWith("/app/kp/dormant")) return "kp-dormant";
  if (url.startsWith("/app/kp/items-not-ordered")) return "kp-items-not-ordered";
  if (url.startsWith("/app/kp/meetings")) return "kp-meetings";
  if (url.startsWith("/app/kp/calendar")) return "kp-calendar";
  if (url.startsWith("/app/kp/dtc-calltronix")) return "kp-dtc-calltronix";
  if (url.startsWith("/app/kp/fol")) return "kp-fol";
  if (url.startsWith("/app/price-change-requests")) return "price-change-requests";
  if (url.startsWith("/app/sales-management")) return "sales-management";
  if (url.startsWith("/app/kp/commissions")) return "kp-commissions";
  return undefined;
}

/** Menus every signed-in user may always open (not department-hidden). */
export const ALWAYS_VISIBLE_MENU_SLUGS = new Set([
  "dashboard",
  "ai-intelligence",
  "profile",
]);

export function canAccessNavItem(
  role: Role | undefined,
  url: string,
  hiddenMenus: string[] = [],
): boolean {
  if (!role) return false;

  const slug = menuSlugForUrl(url);
  // Kimfay Genius / AI Intelligence must remain visible to every role.
  if (slug && ALWAYS_VISIBLE_MENU_SLUGS.has(slug)) {
    return true;
  }
  if (slug && hiddenMenus.includes(slug)) return false;

  if (ALL_AUTHENTICATED_URLS.has(url)) return true;
  if (role === "Administrator") return true;

  const isCs = role === "Customer Service Manager" || role === "Customer Service Agent";
  const isCsManager = role === "Customer Service Manager";

  if (ADMIN_ONLY_URLS.has(url)) return false;
  if (ADMIN_MANAGER_URLS.has(url)) return isCsManager;
  if (CS_AND_ADMIN_URLS.has(url) && !isCs) return false;
  if (SALES_CONSULTANT_URLS.has(url)) return SALES_CONSULTANT_ROLES.includes(role);
  if (url.startsWith("/app/sales-consultants/")) return SALES_CONSULTANT_ROLES.includes(role);

  return true;
}

export function canSeeAdminTab(role: Role | undefined, tab: string): boolean {
  if (!role) return false;
  if (role === "Administrator") return true;
  if (ADMIN_ONLY_ADMIN_TABS.has(tab)) return false;
  if (ADMIN_MANAGER_ADMIN_TABS.has(tab)) return role === "Customer Service Manager";
  return role === "Customer Service Manager" || role === "Customer Service Agent";
}

export function canTriggerSync(role: Role | undefined): boolean {
  return isPrivilegedRole(role);
}

export function canSyncMailboxes(role: Role | undefined): boolean {
  return isPrivilegedRole(role);
}
