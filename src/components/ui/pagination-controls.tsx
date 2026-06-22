import { ChevronLeft, ChevronRight } from "lucide-react";
import { Button } from "@/components/ui/button";

interface PaginationControlsProps {
  currentPage: number;
  lastPage: number;
  total: number;
  perPage: number;
  onPageChange: (page: number) => void;
  onPerPageChange: (perPage: number) => void;
  pageSizes?: number[];
  className?: string;
}

const DEFAULT_SIZES = [20, 50, 100];

function getPageNumbers(current: number, last: number): (number | "…")[] {
  if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);
  if (current <= 4)        return [1, 2, 3, 4, 5, "…", last];
  if (current >= last - 3) return [1, "…", last - 4, last - 3, last - 2, last - 1, last];
  return [1, "…", current - 1, current, current + 1, "…", last];
}

export function PaginationControls({
  currentPage,
  lastPage,
  total,
  perPage,
  onPageChange,
  onPerPageChange,
  pageSizes = DEFAULT_SIZES,
  className = "",
}: PaginationControlsProps) {
  const from = total === 0 ? 0 : (currentPage - 1) * perPage + 1;
  const to   = Math.min(currentPage * perPage, total);
  const pages = getPageNumbers(currentPage, lastPage);

  return (
    <div className={`flex flex-wrap items-center justify-between gap-3 text-sm ${className}`}>
      {/* Left — record summary + per-page */}
      <div className="flex items-center gap-3 text-xs text-muted-foreground">
        <span>
          {total === 0
            ? "No records"
            : `${from.toLocaleString()} – ${to.toLocaleString()} of ${total.toLocaleString()}`}
        </span>

        {/* Per-page selector */}
        <div className="flex items-center gap-1">
          <span className="text-muted-foreground/70">Show</span>
          {pageSizes.map((size) => (
            <button
              key={size}
              type="button"
              onClick={() => { onPerPageChange(size); onPageChange(1); }}
              className={`min-w-[32px] rounded border px-1.5 py-0.5 text-xs font-medium transition-colors ${
                perPage === size
                  ? "border-primary bg-primary/10 text-primary"
                  : "border-muted bg-transparent text-muted-foreground hover:border-foreground/30 hover:text-foreground"
              }`}
            >
              {size}
            </button>
          ))}
        </div>
      </div>

      {/* Right — page buttons */}
      {lastPage > 1 && (
        <div className="flex items-center gap-1">
          <Button
            variant="outline"
            size="icon"
            className="h-7 w-7"
            disabled={currentPage <= 1}
            onClick={() => onPageChange(currentPage - 1)}
          >
            <ChevronLeft className="h-3.5 w-3.5" />
          </Button>

          {pages.map((p, i) =>
            p === "…" ? (
              <span key={`ellipsis-${i}`} className="px-1 text-xs text-muted-foreground">…</span>
            ) : (
              <button
                key={p}
                type="button"
                onClick={() => onPageChange(p as number)}
                className={`min-w-[28px] rounded border px-1.5 py-0.5 text-xs font-medium transition-colors ${
                  p === currentPage
                    ? "border-primary bg-primary text-primary-foreground"
                    : "border-muted bg-transparent text-muted-foreground hover:border-foreground/30 hover:text-foreground"
                }`}
              >
                {p}
              </button>
            )
          )}

          <Button
            variant="outline"
            size="icon"
            className="h-7 w-7"
            disabled={currentPage >= lastPage}
            onClick={() => onPageChange(currentPage + 1)}
          >
            <ChevronRight className="h-3.5 w-3.5" />
          </Button>
        </div>
      )}
    </div>
  );
}
