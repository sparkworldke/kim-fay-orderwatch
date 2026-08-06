import { Link, useRouterState } from "@tanstack/react-router";
import { Boxes, Factory, Handshake, Maximize2, PackageCheck, RotateCcw, TrendingUp } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useProductionStockView } from "@/features/kimfay-production/stock-view-context";

const MODULE_DEFS = [
  { path: "", label: "Manufactured Intel", short: "Manufactured", icon: Factory },
  { path: "/partners", label: "Partner / Trading Intel", short: "Partner", icon: Handshake },
  { path: "/sales", label: "Sales Volume Intel", short: "Sales", icon: TrendingUp },
] as const;

export function DashboardHeader({ onReset }: { onReset: () => void }) {
  const pathname = useRouterState({ select: (r) => r.location.pathname });
  const { value: stockView, setValue: setStockView } = useProductionStockView();
  // Standalone /production* routes share this header; app shell uses /app/production*.
  const base = pathname.startsWith("/app/production") ? "/app/production" : "/production";
  const modules = MODULE_DEFS.map((m) => ({ ...m, to: `${base}${m.path}` || base }));

  const goFullscreen = () => {
    if (typeof document === "undefined") return;
    if (document.fullscreenElement) void document.exitFullscreen();
    else void document.documentElement.requestFullscreen?.();
  };

  return (
    <header className="production-module-header">
      <div className="production-module-header__inner">
        <nav className="production-module-nav" aria-label="Production and stock sections">
          {modules.map((m) => (
            <Link
              key={m.to}
              to={m.to}
              className="production-module-nav__link"
              activeOptions={{ exact: true }}
              activeProps={{ className: "production-module-nav__link--active" }}
            >
              <m.icon className="size-4" />
              <span className="sm:hidden">{m.short}</span>
              <span className="hidden sm:inline">{m.label}</span>
            </Link>
          ))}
          <span className="mx-1 h-6 w-px shrink-0 bg-border" aria-hidden="true" />
          <div className="flex shrink-0 items-center gap-1" aria-label="Stock Type">
            <Link
              to={base}
              onClick={() => setStockView("finished-goods")}
              className={`production-module-nav__link ${stockView === "finished-goods" ? "production-module-nav__link--active" : ""}`}
              aria-pressed={stockView === "finished-goods"}
            >
              <PackageCheck className="size-4" />
              <span>Finished Goods</span>
            </Link>
            <Link
              to={base}
              onClick={() => setStockView("raw-materials")}
              className={`production-module-nav__link ${stockView === "raw-materials" ? "production-module-nav__link--active" : ""}`}
              aria-pressed={stockView === "raw-materials"}
            >
              <Boxes className="size-4" />
              <span>Raw Materials</span>
            </Link>
          </div>
          <div className="hidden min-w-0 max-w-[310px] xl:block">
            <p className="truncate text-xs font-bold text-navy">
              {stockView === "finished-goods" ? "Finished Goods" : "Raw Materials"}
            </p>
            <p className="truncate text-[10px] text-muted-foreground">
              {stockView === "finished-goods"
                ? "All finished-goods stores with stock (every non-RMS warehouse)"
                : "Inventory from every synced warehouse containing RMS"}
            </p>
          </div>
        </nav>

        <div className="production-module-actions">
          <Button variant="outline" size="sm" className="h-8 gap-1.5" onClick={onReset}>
            <RotateCcw className="size-4" />
            <span className="hidden sm:inline">Reset filters</span>
          </Button>
          <Button variant="outline" size="sm" className="h-8 gap-1.5" onClick={goFullscreen}>
            <Maximize2 className="size-4" />
            <span className="hidden sm:inline">Full screen</span>
          </Button>
        </div>
      </div>
    </header>
  );
}

export function PageTitle({ title, subtitle }: { title: string; subtitle: string }) {
  return (
    <div className="min-w-0">
      <h1 className="truncate text-base font-black text-navy sm:text-lg">{title}</h1>
      <p className="truncate text-[11px] text-muted-foreground sm:text-xs">{subtitle}</p>
    </div>
  );
}
