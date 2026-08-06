export const formatNumber = (value: number, digits = 0) =>
  Number.isFinite(value)
    ? value.toLocaleString("en-US", { minimumFractionDigits: digits, maximumFractionDigits: digits })
    : "—";

export const formatPercent = (value: number, digits = 1) =>
  Number.isFinite(value) ? `${value > 0 ? "+" : ""}${value.toFixed(digits)}%` : "—";

export const formatShare = (value: number, digits = 1) =>
  Number.isFinite(value) ? `${value.toFixed(digits)}%` : "—";

export const formatCover = (value: number) =>
  !Number.isFinite(value) ? "∞" : `${value.toFixed(1)}`;

export const compactNumber = (value: number) =>
  Math.abs(value) >= 1_000_000
    ? `${(value / 1_000_000).toFixed(1)}M`
    : Math.abs(value) >= 1000
      ? `${Math.round(value / 1000)}K`
      : `${value}`;