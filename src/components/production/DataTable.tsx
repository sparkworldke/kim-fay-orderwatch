import {
  flexRender,
  getCoreRowModel,
  getSortedRowModel,
  useReactTable,
  type ColumnDef,
  type SortingState,
  type VisibilityState,
} from "@tanstack/react-table";
import { useVirtualizer } from "@tanstack/react-virtual";
import {
  ArrowUpDown,
  Download,
  Search,
  SlidersHorizontal,
} from "lucide-react";
import { useRef, useState, type ReactNode } from "react";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";
import { EmptyState, TableSkeleton } from "./Panel";

export type Density = "compact" | "comfortable";

interface DataTableProps<T> {
  data: T[];
  columns: ColumnDef<T, unknown>[];
  getRowId: (row: T) => string;
  selectedId?: string | null;
  onSelectRow?: (row: T) => void;
  /** Called when a desktop row is hovered — use for preview-on-hover. */
  onHoverRow?: (row: T) => void;
  search: string;
  onSearchChange: (value: string) => void;
  searchPlaceholder?: string;
  isLoading?: boolean;
  emptyTitle?: string;
  emptyDescription?: string;
  renderMobileCard: (row: T) => ReactNode;
  toolbar?: ReactNode;
  initialColumnVisibility?: VisibilityState;
  /** Preview a row's details by hovering it (desktop only). */
  selectOnHover?: boolean;
}

export function DataTable<T>({
  data,
  columns,
  getRowId,
  selectedId,
  onSelectRow,
  onHoverRow,
  search,
  onSearchChange,
  searchPlaceholder = "Search products...",
  isLoading,
  emptyTitle = "No results",
  emptyDescription = "Adjust your filters or search to see products here.",
  renderMobileCard,
  toolbar,
  initialColumnVisibility = {},
  selectOnHover = true,
}: DataTableProps<T>) {
  const [sorting, setSorting] = useState<SortingState>([
    { id: "qtyOnHand", desc: true },
  ]);
  const [visibility, setVisibility] = useState<VisibilityState>(initialColumnVisibility);
  const [density, setDensity] = useState<Density>("compact");

  const table = useReactTable({
    data,
    columns,
    state: { sorting, columnVisibility: visibility },
    onSortingChange: setSorting,
    onColumnVisibilityChange: setVisibility,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
  });

  const rows = table.getRowModel().rows;
  const totalRows = data.length;
  const cellPad = density === "compact" ? "px-1.5 py-0.5" : "px-2 py-1.5";
  const desktopScrollRef = useRef<HTMLDivElement>(null);
  const rowVirtualizer = useVirtualizer({
    count: rows.length,
    getScrollElement: () => desktopScrollRef.current,
    estimateSize: () => density === "compact" ? 24 : 36,
    overscan: 5,
  });
  const virtualRows = rowVirtualizer.getVirtualItems();
  const paddingTop = virtualRows.length ? virtualRows[0].start : 0;
  const paddingBottom = virtualRows.length
    ? rowVirtualizer.getTotalSize() - virtualRows[virtualRows.length - 1].end
    : 0;

  return (
    <div className="flex min-w-0 flex-col">
      <div className="grid gap-2 border-b border-border p-2 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-3">
        <div className="relative min-w-0 sm:max-w-xs">
          <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={search}
            onChange={(e) => onSearchChange(e.target.value)}
            placeholder={searchPlaceholder}
            aria-label="Search table"
            className="h-9 pl-8"
          />
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {toolbar}
          <div className="hidden items-center rounded-lg border border-border p-0.5 lg:flex">
            {(["comfortable", "compact"] as Density[]).map((d) => (
              <button
                key={d}
                type="button"
                onClick={() => setDensity(d)}
                className={cn(
                  "rounded-md px-2 py-1 text-xs font-semibold capitalize transition-colors",
                  density === d ? "bg-primary text-primary-foreground" : "text-muted-foreground",
                )}
              >
                {d}
              </button>
            ))}
          </div>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline" size="sm" className="h-9 gap-1.5">
                <SlidersHorizontal className="size-4" />
                <span className="hidden sm:inline">Columns</span>
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="max-h-80 overflow-y-auto">
              <DropdownMenuLabel>Visible columns</DropdownMenuLabel>
              {table
                .getAllLeafColumns()
                .filter((c) => c.getCanHide())
                .map((column) => (
                  <DropdownMenuCheckboxItem
                    key={column.id}
                    checked={column.getIsVisible()}
                    onCheckedChange={(v) => column.toggleVisibility(!!v)}
                    onSelect={(e) => e.preventDefault()}
                  >
                    {typeof column.columnDef.header === "string" ? column.columnDef.header : column.id}
                  </DropdownMenuCheckboxItem>
                ))}
            </DropdownMenuContent>
          </DropdownMenu>
          <Button variant="outline" size="sm" className="h-9 gap-1.5" title="Export (coming soon)">
            <Download className="size-4" />
            <span className="hidden sm:inline">Export</span>
          </Button>
        </div>
      </div>

      {isLoading ? (
        <TableSkeleton />
      ) : totalRows === 0 ? (
        <EmptyState title={emptyTitle} description={emptyDescription} />
      ) : (
        <>
          {/* Mobile: app-like cards */}
          <ul className="max-h-[420px] divide-y divide-border overflow-y-auto overscroll-contain lg:hidden">
            {rows.map((row) => {
              const id = getRowId(row.original);
              return (
                <li key={id}>
                  <button
                    type="button"
                    onClick={() => onSelectRow?.(row.original)}
                    className={cn(
                      "w-full px-3 py-2 text-left transition-colors active:bg-[#073b7a] active:text-white",
                      selectedId === id &&
                        "bg-[#073b7a] text-white [&_*:not(.status-badge)]:!text-white",
                    )}
                  >
                    {renderMobileCard(row.original)}
                  </button>
                </li>
              );
            })}
          </ul>

          <div
            ref={desktopScrollRef}
            className={cn(
              "hidden overflow-auto overscroll-contain lg:block",
              density === "compact" ? "h-[152px]" : "h-[218px]",
            )}
          >
            <table className="ui-dense w-full table-fixed">
              <thead className="sticky top-0 z-10 bg-card">
                {table.getHeaderGroups().map((group) => (
                  <tr key={group.id} className="border-b border-border">
                    {group.headers.map((header) => {
                      const canSort = header.column.getCanSort();
                      return (
                        <th
                          key={header.id}
                          style={{
                            width: (header.column.columnDef.meta as { width?: string } | undefined)?.width,
                          }}
                          className={cn(
                            "bg-card text-left text-[11px] leading-tight font-semibold text-primary",
                            cellPad,
                            header.column.columnDef.meta &&
                              (header.column.columnDef.meta as { align?: string }).align === "right" &&
                              "text-right",
                          )}
                        >
                          {canSort ? (
                            <button
                              type="button"
                              onClick={header.column.getToggleSortingHandler()}
                              className="inline-flex max-w-full items-center gap-1 text-left hover:text-navy"
                            >
                              {flexRender(header.column.columnDef.header, header.getContext())}
                              <ArrowUpDown className="size-3 shrink-0 opacity-50" />
                            </button>
                          ) : (
                            flexRender(header.column.columnDef.header, header.getContext())
                          )}
                        </th>
                      );
                    })}
                  </tr>
                ))}
              </thead>
              <tbody>
                {paddingTop > 0 ? (
                  <tr aria-hidden="true"><td style={{ height: paddingTop }} colSpan={table.getVisibleLeafColumns().length} /></tr>
                ) : null}
                {virtualRows.map((virtualRow) => {
                  const row = rows[virtualRow.index];
                  const id = getRowId(row.original);
                  return (
                    <tr
                      key={row.id}
                      onClick={() => onSelectRow?.(row.original)}
                      onMouseEnter={() => {
                        if (onHoverRow) onHoverRow(row.original);
                        else if (selectOnHover) onSelectRow?.(row.original);
                      }}
                      onFocus={() => {
                        if (onHoverRow) onHoverRow(row.original);
                        else if (selectOnHover) onSelectRow?.(row.original);
                      }}
                      tabIndex={onSelectRow ? 0 : undefined}
                      className={cn(
                        "border-b border-border/60 transition-colors last:border-0",
                        onSelectRow &&
                          "cursor-pointer hover:bg-[#073b7a] hover:text-white hover:[&_td_*:not(.status-badge)]:!text-white",
                        selectedId === id &&
                          "bg-[#073b7a] text-white hover:bg-[#073b7a] [&_td_*:not(.status-badge)]:!text-white",
                      )}
                    >
                      {row.getVisibleCells().map((cell) => (
                        <td
                          key={cell.id}
                          className={cn(
                            "truncate align-middle",
                            cellPad,
                            (cell.column.columnDef.meta as { align?: string })?.align === "right" &&
                              "text-right tabular-nums",
                          )}
                        >
                          {flexRender(cell.column.columnDef.cell, cell.getContext())}
                        </td>
                      ))}
                    </tr>
                  );
                })}
                {paddingBottom > 0 ? (
                  <tr aria-hidden="true"><td style={{ height: paddingBottom }} colSpan={table.getVisibleLeafColumns().length} /></tr>
                ) : null}
              </tbody>
            </table>
          </div>

          <div className="border-t border-border p-3 sm:px-4">
            <p className="truncate text-xs text-muted-foreground">
              {totalRows} products · Scroll to view all
            </p>
          </div>
        </>
      )}
    </div>
  );
}
