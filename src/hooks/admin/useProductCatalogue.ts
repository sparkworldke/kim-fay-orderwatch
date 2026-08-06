import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { API_BASE_URL, apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";

export type Taxonomy = {
  id: number;
  name: string;
  is_active: boolean;
  source: string;
  products_count?: number;
  /** Brands only: manufactured | partner (partner = trading brands). */
  ownership?: "manufactured" | "partner" | null;
};
export type Category = Taxonomy & { parent_id: number | null; parent?: { id: number; name: string } | null };
export type CatalogueProduct = {
  id: number; inventory_id: string; name: string | null; ownership: "manufactured" | "partner" | null;
  portfolio_group: string | null; conversion_factor: string | null; uom: string | null;
  profit_margin_target: string | null; supplier: string | null; is_active: boolean; import_locked: boolean;
  brand_id: number | null; category_id: number | null; sub_category_id: number | null; trading_group_id: number | null;
  brand?: Taxonomy | null; category?: Taxonomy | null; sub_category?: Taxonomy | null; trading_group?: Taxonomy | null;
};
export type ProductImport = {
  id: number; status: "queued" | "running" | "completed" | "failed"; file_name: string;
  total_rows: number; processed_rows: number; created_count: number; updated_count: number;
  skipped_count: number; unmatched_count: number; error_count: number; error_message?: string | null;
};
type Page<T> = { data: T[]; current_page: number; last_page: number; total: number };
const key = ["product-catalogue"] as const;

export function useCatalogueTaxonomies() {
  const brands = useQuery({ queryKey: [...key, "brands"], queryFn: () => apiFetch<{ data: Taxonomy[] }>("admin/brands") });
  const categories = useQuery({ queryKey: [...key, "categories"], queryFn: () => apiFetch<{ data: Category[] }>("admin/categories") });
  const tradingGroups = useQuery({ queryKey: [...key, "trading-groups"], queryFn: () => apiFetch<{ data: Taxonomy[] }>("admin/trading-groups") });
  return { brands, categories, tradingGroups };
}

export function useCatalogueProducts(q = "", page = 1) {
  return useQuery({
    queryKey: [...key, "products", q, page],
    queryFn: () => apiFetch<Page<CatalogueProduct>>(`admin/products?q=${encodeURIComponent(q)}&page=${page}&per_page=50`),
  });
}

export function useProductImports() {
  return useQuery({
    queryKey: [...key, "imports"],
    queryFn: () => apiFetch<{ data: ProductImport[] }>("admin/product-imports"),
    refetchInterval: (query) => query.state.data?.data.some((row) => ["queued", "running"].includes(row.status)) ? 3000 : false,
  });
}

export function useSaveCatalogueProduct() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, ...body }: Partial<CatalogueProduct> & { id: number }) =>
      apiFetch<CatalogueProduct>(`admin/products/${id}`, { method: "PUT", body }),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: key }); },
  });
}

export function useUnlockCatalogueProduct() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => apiFetch(`admin/products/${id}/unlock`, { method: "POST" }),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: key }); },
  });
}

export function useSaveTaxonomy(type: "brands" | "categories" | "trading-groups") {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      ...body
    }: {
      id?: number;
      name: string;
      is_active?: boolean;
      parent_id?: number | null;
      ownership?: "manufactured" | "partner" | null;
    }) => apiFetch(`admin/${type}${id ? `/${id}` : ""}`, { method: id ? "PUT" : "POST", body }),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: key }); },
  });
}

export function useUploadProducts() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (file: File) => {
      const form = new FormData(); form.append("file", file);
      const response = await fetch(`${API_BASE_URL}/admin/products/imports`, {
        method: "POST", headers: { Accept: "application/json", Authorization: `Bearer ${getToken()}` }, body: form,
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message ?? "Product import could not be queued.");
      return data;
    },
    onSuccess: () => { void qc.invalidateQueries({ queryKey: [...key, "imports"] }); },
  });
}
