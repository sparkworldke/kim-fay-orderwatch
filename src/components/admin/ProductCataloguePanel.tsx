import { useState } from "react";
import { Boxes, FileWarning, Loader2, Lock, Search, Unlock, Upload } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  useCatalogueProducts, useCatalogueTaxonomies, useProductImports, useSaveCatalogueProduct,
  useSaveTaxonomy, useUnlockCatalogueProduct, useUploadProducts, type CatalogueProduct, type Taxonomy,
} from "@/hooks/admin/useProductCatalogue";
import { downloadApiFile } from "@/lib/api";

export function ProductCataloguePanel() {
  return (
    <div className="rounded-lg border bg-card p-4 shadow-[var(--shadow-panel)]">
      <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold"><Boxes className="h-4 w-4" />Product Catalogue</h3>
      <Tabs defaultValue="products">
        <TabsList className="mb-3 flex h-auto flex-wrap">
          <TabsTrigger value="products">Products</TabsTrigger>
          <TabsTrigger value="brands">Brands</TabsTrigger>
          <TabsTrigger value="categories">Categories</TabsTrigger>
          <TabsTrigger value="trading">Trading Groups</TabsTrigger>
          <TabsTrigger value="imports">Import History</TabsTrigger>
        </TabsList>
        <TabsContent value="products"><Products /></TabsContent>
        <TabsContent value="brands"><Taxonomies type="brands" /></TabsContent>
        <TabsContent value="categories"><Taxonomies type="categories" /></TabsContent>
        <TabsContent value="trading"><Taxonomies type="trading-groups" /></TabsContent>
        <TabsContent value="imports"><Imports /></TabsContent>
      </Tabs>
    </div>
  );
}

function Products() {
  const [q, setQ] = useState(""); const [page, setPage] = useState(1);
  const products = useCatalogueProducts(q, page); const tax = useCatalogueTaxonomies();
  const save = useSaveCatalogueProduct(); const unlock = useUnlockCatalogueProduct();
  const upload = useUploadProducts();
  const update = (product: CatalogueProduct, changes: Partial<CatalogueProduct>) =>
    save.mutate({ id: product.id, ...changes }, { onSuccess: () => toast.success("Product saved"), onError: (e) => toast.error(e.message) });

  return <div className="space-y-3">
    <div className="flex flex-wrap gap-2">
      <div className="relative min-w-56 flex-1"><Search className="absolute left-2 top-2 h-4 w-4 text-muted-foreground" />
        <Input className="pl-8" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} placeholder="Search Inventory ID or product" /></div>
      <label className="inline-flex cursor-pointer items-center rounded-md border px-3 text-xs">
        {upload.isPending ? <Loader2 className="mr-1 h-4 w-4 animate-spin" /> : <Upload className="mr-1 h-4 w-4" />} Upload CSV/XLSX
        <input className="hidden" type="file" accept=".csv,.txt,.xlsx,.xls" onChange={(e) => {
          const file = e.target.files?.[0]; if (!file) return;
          upload.mutate(file, { onSuccess: () => toast.success("Product import queued"), onError: (x) => toast.error(x.message) });
          e.currentTarget.value = "";
        }} />
      </label>
    </div>
    <div className="overflow-x-auto rounded-md border">
      <table className="w-full min-w-[1150px] text-xs"><thead className="bg-muted/40"><tr>
        {["Inventory ID","Product","Brand","Category","Subcategory","Trading Group","Portfolio Group","Ownership","UOM","Factor","Active","Import"].map(h => <th key={h} className="px-2 py-2 text-left">{h}</th>)}
      </tr></thead><tbody>{products.data?.data.map(p => <tr key={p.id} className="border-t">
        <td className="px-2 py-1 font-mono font-semibold">{p.inventory_id}</td>
        <td className="px-2 py-1"><Input defaultValue={p.name ?? ""} onBlur={(e) => e.target.value !== (p.name ?? "") && update(p,{name:e.target.value})} className="w-52" readOnly={save.isPending} /></td>
        <td className="px-2 py-1"><Picker value={p.brand_id} options={tax.brands.data?.data} onChange={(brand_id)=>update(p,{brand_id})}/></td>
        <td className="px-2 py-1"><Picker value={p.category_id} options={tax.categories.data?.data.filter(c=>!c.parent_id)} onChange={(category_id)=>update(p,{category_id})}/></td>
        <td className="px-2 py-1"><Picker value={p.sub_category_id} options={tax.categories.data?.data.filter(c=>c.parent_id===p.category_id)} onChange={(sub_category_id)=>update(p,{sub_category_id})}/></td>
        <td className="px-2 py-1"><Picker value={p.trading_group_id} options={tax.tradingGroups.data?.data} onChange={(trading_group_id)=>update(p,{trading_group_id})}/></td>
        <td className="px-2 py-1">{p.portfolio_group ?? "—"}</td>
        <td className="px-2 py-1"><Select value={p.ownership ?? "none"} onValueChange={v=>update(p,{ownership:v==="none"?null:v as CatalogueProduct["ownership"]})}><SelectTrigger className="w-36"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="none">—</SelectItem><SelectItem value="manufactured">Manufactured</SelectItem><SelectItem value="partner">Partner / Trading</SelectItem></SelectContent></Select></td>
        <td className="px-2 py-1">{p.uom ?? "—"}</td><td className="px-2 py-1">{p.conversion_factor ?? "—"}</td>
        <td className="px-2 py-1"><input type="checkbox" checked={p.is_active} onChange={e=>update(p,{is_active:e.target.checked})}/></td>
        <td className="px-2 py-1">{p.import_locked ? <Button size="sm" variant="outline" onClick={()=>unlock.mutate(p.id)}><Lock className="mr-1 h-3 w-3"/>Unlock</Button> : <span className="inline-flex items-center text-emerald-700"><Unlock className="mr-1 h-3 w-3"/>Open</span>}</td>
      </tr>)}</tbody></table>
    </div>
    <div className="flex items-center justify-between"><span className="text-muted-foreground">{products.data?.total ?? 0} products</span>
      <div className="flex gap-1"><Button variant="outline" disabled={page<=1} onClick={()=>setPage(p=>p-1)}>Previous</Button><Button variant="outline" disabled={page>=(products.data?.last_page??1)} onClick={()=>setPage(p=>p+1)}>Next</Button></div></div>
  </div>;
}

function Picker({value,options,onChange}:{value:number|null;options?:Taxonomy[];onChange:(id:number|null)=>void}) {
  return <Select value={value ? String(value) : "none"} onValueChange={v=>onChange(v==="none"?null:Number(v))}><SelectTrigger className="w-36"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="none">—</SelectItem>{options?.map(x=><SelectItem key={x.id} value={String(x.id)}>{x.name}</SelectItem>)}</SelectContent></Select>;
}

function Taxonomies({type}:{type:"brands"|"categories"|"trading-groups"}) {
  const data = useCatalogueTaxonomies();
  const query = type === "brands" ? data.brands : type === "categories" ? data.categories : data.tradingGroups;
  const save = useSaveTaxonomy(type);
  const [name, setName] = useState("");
  return (
    <div className="space-y-3">
      <form
        className="flex gap-2"
        onSubmit={(e) => {
          e.preventDefault();
          if (!name.trim()) return;
          save.mutate({ name: name.trim() }, { onSuccess: () => setName("") });
        }}
      >
        <Input value={name} onChange={(e) => setName(e.target.value)} placeholder={`New ${type.replace("-", " ")}`} />
        <Button disabled={save.isPending}>Add</Button>
      </form>
      {type === "brands" ? (
        <p className="text-xs text-muted-foreground">
          Brand ownership drives Production Intel: <strong>Manufactured</strong> vs{" "}
          <strong>Partner / Trading</strong>. Run{" "}
          <code className="rounded bg-muted px-1">php artisan orderwatch:classify-product-ownership</code> after a
          catalogue seeder if ownership is blank.
        </p>
      ) : null}
      <div className="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
        {query.data?.data.map((row) => (
          <div key={row.id} className="flex flex-wrap items-center justify-between gap-2 rounded border p-2">
            <span className="font-medium">{row.name}</span>
            <div className="flex items-center gap-2">
              {type === "brands" ? (
                <Select
                  value={row.ownership ?? "none"}
                  onValueChange={(v) =>
                    save.mutate({
                      id: row.id,
                      name: row.name,
                      ownership: v === "none" ? null : (v as "manufactured" | "partner"),
                    })
                  }
                >
                  <SelectTrigger className="h-8 w-40">
                    <SelectValue placeholder="Ownership" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">—</SelectItem>
                    <SelectItem value="manufactured">Manufactured</SelectItem>
                    <SelectItem value="partner">Partner / Trading</SelectItem>
                  </SelectContent>
                </Select>
              ) : null}
              <label className="flex items-center gap-1 text-muted-foreground">
                <input
                  type="checkbox"
                  checked={row.is_active}
                  onChange={(e) => save.mutate({ id: row.id, name: row.name, is_active: e.target.checked })}
                />
                Active
              </label>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

function Imports() {
  const imports=useProductImports();
  return <div className="space-y-2">{imports.data?.data.map(row=><div key={row.id} className="rounded border p-3"><div className="flex flex-wrap items-center justify-between gap-2"><strong>{row.file_name}</strong><span className="capitalize">{row.status}</span></div>
    <div className="mt-2 h-1.5 overflow-hidden rounded bg-muted"><div className="h-full bg-primary" style={{width:`${row.total_rows?Math.round(row.processed_rows/row.total_rows*100):0}%`}}/></div>
    <p className="mt-2 text-muted-foreground">Processed {row.processed_rows}/{row.total_rows} · Created {row.created_count} · Updated {row.updated_count} · Locked {row.skipped_count} · Unmatched {row.unmatched_count} · Errors {row.error_count}</p>
    {(row.unmatched_count+row.error_count)>0&&<button className="mt-1 inline-flex items-center text-primary hover:underline" onClick={()=>void downloadApiFile(`admin/product-imports/${row.id}/errors`,`product-import-${row.id}-errors.csv`)}><FileWarning className="mr-1 h-3 w-3"/>Download errors</button>}
  </div>)}</div>;
}
