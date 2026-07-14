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

export function BrandFilterCascade({ value, onChange, className }: Props) {
  const { data } = useQuery({
    queryKey: ["brand-filter-options"],
    queryFn: () => apiFetch<{ hierarchy: PartnerGroup[] }>("/api/operations/brand-filter-options"),
  });

  const groups = data?.hierarchy ?? [];
  const activeGroup = groups.find((g) => g.key === value.partner_brand);
  const activeBrand = activeGroup?.brands.find((b) => b.brand === value.brand);

  return (
    <div className={className ?? "grid gap-3 sm:grid-cols-3"}>
      <div className="space-y-1.5">
        <Label className="text-xs text-muted-foreground">Partner Brands</Label>
        <Select
          value={value.partner_brand || "all"}
          onValueChange={(partner) =>
            onChange({ partner_brand: partner === "all" ? "" : partner, brand: "", category: "" })
          }
        >
          <SelectTrigger>
            <SelectValue placeholder="All partner groups" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All partner groups</SelectItem>
            {groups.map((group) => (
              <SelectItem key={group.key} value={group.key}>
                {group.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className="space-y-1.5">
        <Label className="text-xs text-muted-foreground">Brand</Label>
        <Select
          value={value.brand || "all"}
          onValueChange={(brand) => onChange({ ...value, brand: brand === "all" ? "" : brand, category: "" })}
          disabled={!activeGroup}
        >
          <SelectTrigger>
            <SelectValue placeholder="All brands" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All brands</SelectItem>
            {(activeGroup?.brands ?? []).map((node) => (
              <SelectItem key={node.brand} value={node.brand}>
                {node.brand}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className="space-y-1.5">
        <Label className="text-xs text-muted-foreground">Category (optional)</Label>
        <Select
          value={value.category || "all"}
          onValueChange={(category) =>
            onChange({ ...value, category: category === "all" ? "" : category })
          }
          disabled={!activeBrand}
        >
          <SelectTrigger>
            <SelectValue placeholder="All categories" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All categories</SelectItem>
            {(activeBrand?.categories ?? []).map((category) => (
              <SelectItem key={category} value={category}>
                {category}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
    </div>
  );
}