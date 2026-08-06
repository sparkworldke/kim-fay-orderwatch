import { useEffect, useState } from "react";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { useBrandOptions, useSyncBrandAssignments } from "@/hooks/admin/useAdminSettings";
import type { TeamMember } from "@/types/admin";

export function BrandAssignmentFields({ member }: { member: TeamMember }) {
  const brands = useBrandOptions();
  const sync = useSyncBrandAssignments();
  const [selected, setSelected] = useState<number[]>([]);

  useEffect(() => {
    const names = new Set(member.brand_assignments ?? []);
    setSelected((brands.data?.data ?? []).filter((brand) => names.has(brand.name)).map((brand) => brand.id));
  }, [member.id, member.brand_assignments, brands.data]);

  const showBrands =
    member.org_level === "brandsops" ||
    member.org_level === "hod" && member.department?.slug === "partner_brands";

  if (!showBrands) {
    return null;
  }

  if (brands.isLoading) {
    return <Skeleton className="h-24 w-full md:col-span-2" />;
  }

  const options = (brands.data?.data ?? []).filter((brand) => brand.is_active);
  const groupedOptions = options.reduce((groups, brand) => {
    const group = brand.partner_group?.name ?? "Other Partner Brands";
    groups.set(group, [...(groups.get(group) ?? []), brand]);
    return groups;
  }, new Map<string, typeof options>());

  function toggle(brand: number) {
    setSelected((current) =>
      current.includes(brand) ? current.filter((b) => b !== brand) : [...current, brand],
    );
  }

  function save() {
    sync.mutate({ userId: member.id, brand_ids: selected });
  }

  return (
    <div className="grid gap-2 md:col-span-2">
      <div className="flex items-center justify-between gap-2">
        <Label>Partner brand assignments</Label>
        <button
          type="button"
          className="text-xs text-primary hover:underline disabled:opacity-50"
          disabled={sync.isPending}
          onClick={save}
        >
          {sync.isPending ? "Saving…" : "Save brands"}
        </button>
      </div>
      <p className="text-xs text-muted-foreground">
        Brand Ops users see trading data for assigned partner brands across GT, MT, and KP.
      </p>
      <div className="max-h-40 overflow-y-auto rounded-md border p-3">
        {options.length === 0 ? (
          <p className="text-sm text-muted-foreground">No partner brands in inventory yet.</p>
        ) : (
          <div className="grid gap-3">
            {[...groupedOptions.entries()].map(([group, groupBrands]) => (
              <fieldset key={group} className="rounded border p-2">
                <legend className="px-1 text-xs font-medium text-muted-foreground">{group}</legend>
                <div className="flex flex-wrap gap-3">
                  {groupBrands.map((brand) => (
                    <label key={brand.id} className="flex items-center gap-2 text-sm">
                      <input
                        type="checkbox"
                        className="h-4 w-4 rounded border"
                        checked={selected.includes(brand.id)}
                        onChange={() => toggle(brand.id)}
                      />
                      {brand.name}
                    </label>
                  ))}
                </div>
              </fieldset>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
