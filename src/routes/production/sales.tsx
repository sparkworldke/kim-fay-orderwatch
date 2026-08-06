import { createFileRoute } from "@tanstack/react-router";
import { useResetToken } from "@/routes/production";
import { StockSalesIntelligencePage } from "@/pages/StockSalesIntelligence";

export const Route = createFileRoute("/production/sales")({
  component: SalesRoute,
});

function SalesRoute() {
  const resetToken = useResetToken();
  return <StockSalesIntelligencePage resetToken={resetToken} />;
}
