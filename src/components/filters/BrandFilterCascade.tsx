import { useMemo } from "react";
import { useQuery } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";

export type BrandFilterValue = {
  partner_brand: string;
  brand: string;
  category: string;
};

type BrandNode = {
  brand: string;
  categories: string[];
};

type PartnerGroup = {
  key: string;
  label: string;
  brands: BrandNode[];
};

type Props = {
  value: BrandFilterValue;
  onChange: (next: BrandFilterValue) => void;
  className?: string;
};

type CascadeProps = Props & {
  /** Hide category third level (dashboard brands tab). */
  hideCategory?: boolean;
  /** Limit which partner groups appear (e.g. only trading for Partner Brand teams). */
  onlyKeys?: string[];
};

/**
 * Cascading Brand group → Brand → Category filters.
 *
 * Options are loaded from `operations/brand-filter-options` and are dynamic:
 * selecting "Kimfay Brands" lists Fay, Sifa, Cosy, …; selecting a brand lists
 * that brand's categories (Toilet Paper, Wipes, …). Categories for the whole
 * brand group are also available before a single brand is chosen.
 */
export function BrandFilterCascade({
  value,
  onChange,
  className,
  hideCategory = false,
  onlyKeys,
}: CascadeProps) {
  const { data, isLoading } = useQuery({
    queryKey: ["brand-filter-options"],
    queryFn: () => apiFetch<{ hierarchy: PartnerGroup[] }>("operations/brand-filter-options"),
    staleTime: 5 * 60_000,
    refetchOnWindowFocus: false,
  });

  const groups = useMemo(
    () => (data?.hierarchy ?? []).filter((group) => !onlyKeys || onlyKeys.includes(group.key)),
    [data?.hierarchy, onlyKeys],
  );

  const activeGroup = groups.find((g) => g.key === value.partner_brand);
  const activeBrand = activeGroup?.brands.find((b) => b.brand === value.brand);

  // Brands under the selected group; if only one group is shown, use that list.
  const brandOptions =
    activeGroup?.brands
    ?? (groups.length === 1 ? groups[0].brands : []);

  // Categories: prefer the selected brand; otherwise union of all brands in the group
  // so users can filter "Kimfay Brands + Toilet Paper" without picking Fay first.
  const categoryOptions = useMemo(() => {
    if (activeBrand) {
      return activeBrand.categories;
    }
    if (!activeGroup && groups.length !== 1) {
      return [] as string[];
    }
    const source = activeGroup ?? groups[0];
    if (!source) return [] as string[];
    const set = new Set<string>();
    for (const node of source.brands) {
      for (const cat of node.categories) {
        if (cat?.trim()) set.add(cat.trim());
      }
    }
    return Array.from(set).sort((a, b) => a.localeCompare(b, undefined, { sensitivity: "base" }));
  }, [activeBrand, activeGroup, groups]);

  const categoriesEnabled = categoryOptions.length > 0 && (!!activeGroup || groups.length === 1 || !!value.brand);

  return (
    <div className={className ?? `grid gap-3 ${hideCategory ? "sm:grid-cols-2" : "sm:grid-cols-3"}`}>
      <div className="space-y-1.5">
        <Label className="text-xs text-muted-foreground">Brand group</Label>
        <Select
          value={value.partner_brand || "all"}
          onValueChange={(partner) =>
            onChange({ partner_brand: partner === "all" ? "" : partner, brand: "", category: "" })
          }
        >
          <SelectTrigger>
            <SelectValue placeholder={isLoading ? "Loading…" : "All brand groups"} />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All brand groups</SelectItem>
            {groups.map((group) => (
              <SelectItem key={group.key} value={group.key}>
                {group.label}
                {group.brands.length > 0 ? ` (${group.brands.length})` : ""}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className="space-y-1.5">
        <Label className="text-xs text-muted-foreground">Brand</Label>
        <Select
          value={value.brand || "all"}
          onValueChange={(brand) => {
            const groupKey =
              value.partner_brand
              || groups.find((g) => g.brands.some((b) => b.brand === brand))?.key
              || "";
            onChange({
              partner_brand: brand === "all" ? value.partner_brand : groupKey || value.partner_brand,
              brand: brand === "all" ? "" : brand,
              category: "",
            });
          }}
          disabled={brandOptions.length === 0 && !value.partner_brand}
        >
          <SelectTrigger>
            <SelectValue
              placeholder={
                !value.partner_brand && groups.length !== 1
                  ? "Select brand group first"
                  : brandOptions.length === 0
                    ? "No brands in group"
                    : "All brands"
              }
            />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All brands</SelectItem>
            {brandOptions.map((node) => (
              <SelectItem key={node.brand} value={node.brand}>
                {node.brand}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      {!hideCategory && (
        <div className="space-y-1.5">
          <Label className="text-xs text-muted-foreground">Category</Label>
          <Select
            value={value.category || "all"}
            onValueChange={(category) =>
              onChange({ ...value, category: category === "all" ? "" : category })
            }
            disabled={!categoriesEnabled}
          >
            <SelectTrigger>
              <SelectValue
                placeholder={
                  !value.partner_brand && !value.brand && groups.length !== 1
                    ? "Select brand group first"
                    : categoryOptions.length === 0
                      ? "No categories"
                      : "All categories"
                }
              />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All categories</SelectItem>
              {categoryOptions.map((category) => (
                <SelectItem key={category} value={category}>
                  {category}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )}
    </div>
  );
}
