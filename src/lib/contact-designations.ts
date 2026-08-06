export const CONTACT_DESIGNATIONS = [
  { key: "ceo_md", label: "CEO/MD" },
  { key: "cfo_finance", label: "CFO/Head of Finance" },
  { key: "cco_coo", label: "CCO/COO" },
  { key: "head_procurement", label: "Head of Procurement" },
  { key: "custom", label: "Custom" },
] as const;

export type ContactDesignationKey = (typeof CONTACT_DESIGNATIONS)[number]["key"];

export function designationLabel(key: string, customLabel?: string | null): string {
  if (key === "custom") return customLabel?.trim() || "Custom";
  return CONTACT_DESIGNATIONS.find((d) => d.key === key)?.label ?? key;
}
