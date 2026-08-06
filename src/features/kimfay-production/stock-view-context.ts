import { createContext, useContext } from "react";

export type ProductionStockView = "finished-goods" | "raw-materials";

export const ProductionStockViewContext = createContext<{
  value: ProductionStockView;
  setValue: (value: ProductionStockView) => void;
}>({
  value: "finished-goods",
  setValue: () => undefined,
});

export const useProductionStockView = () => useContext(ProductionStockViewContext);
