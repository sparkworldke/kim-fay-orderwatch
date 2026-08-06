import { createContext, useContext } from "react";

export const ProductionResetContext = createContext(0);

export function useProductionResetToken() {
  return useContext(ProductionResetContext);
}
