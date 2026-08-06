import { cn } from "@/lib/utils";
import type { LucideIcon } from "lucide-react";

interface Option<T extends string> {
  value: T;
  label: string;
  icon?: LucideIcon;
}

export function SegmentedControl<T extends string>({
  label,
  options,
  value,
  onChange,
  className,
}: {
  label: string;
  options: Option<T>[];
  value: T;
  onChange: (value: T) => void;
  className?: string;
}) {
  return (
    <div className={cn("min-w-0 space-y-1.5", className)}>
      <span className="text-xs font-semibold tracking-wide text-primary">{label}</span>
      <div
        role="tablist"
        aria-label={label}
        className="flex h-11 items-center gap-1 rounded-lg border border-border bg-card p-1"
      >
        {options.map((option) => {
          const Icon = option.icon;
          const active = option.value === value;
          return (
            <button
              key={option.value}
              role="tab"
              aria-selected={active}
              type="button"
              onClick={() => onChange(option.value)}
              className={cn(
                "flex h-full min-w-0 flex-1 items-center justify-center gap-1.5 rounded-md px-2 text-sm font-semibold transition-colors",
                active
                  ? "bg-primary text-primary-foreground shadow-card"
                  : "text-muted-foreground hover:bg-secondary",
              )}
            >
              {Icon ? <Icon className="size-4 shrink-0" /> : null}
              <span className="truncate">{option.label}</span>
            </button>
          );
        })}
      </div>
    </div>
  );
}