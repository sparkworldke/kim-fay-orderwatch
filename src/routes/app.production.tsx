import { createFileRoute, Outlet } from "@tanstack/react-router";
import { useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import { DashboardHeader } from "@/components/production/DashboardHeader";
import { ProductionResetContext } from "@/features/kimfay-production/reset-context";
import {
  ProductionStockViewContext,
  type ProductionStockView,
} from "@/features/kimfay-production/stock-view-context";
import { MsiProvider } from "@/hooks/useMsiOverrides";
import { apiFetch } from "@/lib/api";
import "@/productionStyle.css";

export const Route = createFileRoute("/app/production")({
  component: ProductionAndStockLayout,
});

function ProductionAndStockLayout() {
  const [resetToken, setResetToken] = useState(0);
  const [stockView, setStockView] = useState<ProductionStockView>("finished-goods");
  const queryClient = useQueryClient();

  // Only warm tiny shared metadata here. Heavy stock chunks and trends load
  // progressively inside their own widgets after the first paint.
  useEffect(() => {
    void queryClient.prefetchQuery({
      queryKey: ["production-version"],
      queryFn: () => apiFetch("operations/production/version"),
      staleTime: 60_000,
    });
    void queryClient.prefetchQuery({
      queryKey: ["production-reference"],
      queryFn: () => apiFetch("operations/production/reference"),
      staleTime: 60 * 60 * 1000,
      gcTime: 24 * 60 * 60 * 1000,
    });
  }, [queryClient]);

  return (
    <MsiProvider>
      <ProductionStockViewContext.Provider value={{ value: stockView, setValue: setStockView }}>
        <ProductionResetContext.Provider value={resetToken}>
          <section className="production-dashboard -m-4 flex min-h-[calc(100dvh-3.5rem)] flex-col bg-background md:-m-6">
            <DashboardHeader onReset={() => setResetToken((token) => token + 1)} />
            <div className="mx-auto flex w-full max-w-[1800px] flex-1 flex-col px-3 py-2 sm:px-4">
              <Outlet />
            </div>
          </section>
        </ProductionResetContext.Provider>
      </ProductionStockViewContext.Provider>
    </MsiProvider>
  );
}
