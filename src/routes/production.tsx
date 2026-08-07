import { createFileRoute, Outlet } from "@tanstack/react-router";
import { createContext, useContext, useState } from "react";
import { DashboardHeader } from "@/components/production/DashboardHeader";
import {
  ProductionStockViewContext,
  type ProductionStockView,
} from "@/features/kimfay-production/stock-view-context";
import { MsiProvider } from "@/hooks/useMsiOverrides";
import "@/productionStyle.css";

// Shared reset token so child pages can react to the header's Reset Filters button.
export const ResetTokenContext = createContext(0);
export const useResetToken = () => useContext(ResetTokenContext);

export const Route = createFileRoute("/production")({
  component: IntelligenceLayout,
});

function IntelligenceLayout() {
  const [resetToken, setResetToken] = useState(0);
  const [stockView, setStockView] = useState<ProductionStockView>("finished-goods");

  return (
    <MsiProvider>
      <ProductionStockViewContext.Provider value={{ value: stockView, setValue: setStockView }}>
        <ResetTokenContext.Provider value={resetToken}>
          <div className="production-dashboard flex min-h-dvh flex-col bg-background xl:h-dvh xl:min-h-0 xl:overflow-hidden">
            <DashboardHeader onReset={() => setResetToken((t) => t + 1)} />
            <main className="mx-auto flex min-h-0 w-full max-w-[1800px] flex-1 flex-col overflow-y-auto px-3 py-2 sm:px-4 xl:overflow-hidden">
              <Outlet />
            </main>
          </div>
        </ResetTokenContext.Provider>
      </ProductionStockViewContext.Provider>
    </MsiProvider>
  );
}
