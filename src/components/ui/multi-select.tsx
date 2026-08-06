import { Check, ChevronDown, X } from "lucide-react";
import { useMemo, useState } from "react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { cn } from "@/lib/utils";

/**
 * Shared searchable multi-select for filter bars and forms across the app.
 *
 * @example
 * ```tsx
 * import { MultiSelect } from "@/components/ui/multi-select";
 *
 * <MultiSelect
 *   label="Brand"
 *   options={brands}
 *   selected={selected}
 *   onChange={setSelected}
 *   emptyLabel="All brands"
 *   allLabel="All brands"
 *   compact
 * />
 * ```
 */
export type MultiSelectOption = {
  value: string;
  label: string;
};

export type MultiSelectProps = {
  label: string;
  hint?: string;
  /** Plain string options, or `{ value, label }` when display text differs from the value. */
  options: Array<string | MultiSelectOption>;
  selected: string[];
  onChange: (next: string[]) => void;
  allLabel?: string;
  /** When nothing is selected, show this instead of "None selected" (e.g. "All statuses"). */
  emptyLabel?: string;
  maxChips?: number;
  className?: string;
  /** Compact trigger for dense toolbars (dashboard / list filters). */
  compact?: boolean;
  searchPlaceholder?: string;
  disabled?: boolean;
  /** Hide the field label (use when an outer Label already exists). */
  hideLabel?: boolean;
  /** When value differs from label, show value as a secondary hint (e.g. rep code). Default true. */
  showValueHint?: boolean;
};

function normalizeOptions(options: Array<string | MultiSelectOption>): MultiSelectOption[] {
  return options.map((option) =>
    typeof option === "string" ? { value: option, label: option } : option,
  );
}

export function MultiSelect({
  label,
  hint,
  options,
  selected,
  onChange,
  allLabel,
  emptyLabel,
  maxChips = 2,
  className,
  compact = false,
  searchPlaceholder,
  disabled = false,
  hideLabel = false,
  showValueHint = true,
}: MultiSelectProps) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const normalized = useMemo(() => normalizeOptions(options), [options]);
  const labelByValue = useMemo(
    () => new Map(normalized.map((option) => [option.value, option.label])),
    [normalized],
  );
  const allSelected = normalized.length > 0 && selected.length === normalized.length;
  const visible = useMemo(() => selected.slice(0, maxChips), [selected, maxChips]);
  const overflow = selected.length - visible.length;
  const filteredOptions = useMemo(() => {
    const needle = query.trim().toLocaleLowerCase();
    if (!needle) return normalized;
    return normalized.filter(
      (option) =>
        option.label.toLocaleLowerCase().includes(needle)
        || option.value.toLocaleLowerCase().includes(needle),
    );
  }, [normalized, query]);

  const toggle = (value: string) =>
    onChange(selected.includes(value) ? selected.filter((v) => v !== value) : [...selected, value]);

  const selectVisible = () => {
    onChange(Array.from(new Set([...selected, ...filteredOptions.map((option) => option.value)])));
  };

  const displayChip = (value: string) => labelByValue.get(value) ?? value;

  return (
    <div className={cn("min-w-0", compact ? "space-y-0.5" : "space-y-1.5", className)}>
      {!hideLabel ? (
        <div className="flex items-baseline gap-1.5">
          <span
            className={cn(
              "font-semibold tracking-wide text-primary",
              compact ? "text-[8px]" : "text-xs",
            )}
          >
            {label}
          </span>
          {hint ? (
            <span className={cn("text-muted-foreground", compact ? "text-[8px]" : "text-[11px]")}>
              {hint}
            </span>
          ) : null}
        </div>
      ) : null}
      <Popover
        open={open}
        onOpenChange={(nextOpen) => {
          if (disabled) return;
          setOpen(nextOpen);
          if (!nextOpen) setQuery("");
        }}
      >
        <PopoverTrigger asChild>
          <button
            type="button"
            aria-label={label}
            aria-expanded={open}
            disabled={disabled}
            className={cn(
              "flex w-full items-center gap-2 border text-left transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-60",
              compact
                ? "h-7 rounded-md border-input bg-background px-2 shadow-sm"
                : "h-11 rounded-lg border-border bg-card px-2.5 hover:border-primary/40",
            )}
          >
            <div className="flex min-w-0 flex-1 flex-wrap items-center gap-1 overflow-hidden">
              {selected.length === 0 ? (
                <span
                  className={cn(
                    "truncate text-muted-foreground",
                    compact ? "text-[9px]" : "text-sm",
                  )}
                >
                  {emptyLabel ?? "None selected"}
                </span>
              ) : allSelected && allLabel ? (
                <Badge variant="secondary" className={cn("font-medium", compact && "h-5 px-1.5 text-[9px]")}>
                  {allLabel} ({normalized.length})
                </Badge>
              ) : (
                <>
                  {visible.map((value) => (
                    <span
                      key={value}
                      className={cn(
                        "inline-flex max-w-[9.5rem] items-center gap-1 rounded-md bg-secondary font-medium text-secondary-foreground",
                        compact ? "px-1 py-0 text-[9px]" : "px-1.5 py-0.5 text-xs",
                      )}
                    >
                      <span className="truncate">{displayChip(value)}</span>
                      {!disabled ? (
                        <X
                          role="button"
                          aria-label={`Remove ${displayChip(value)}`}
                          className="size-3 shrink-0 opacity-60 hover:opacity-100"
                          onClick={(e) => {
                            e.stopPropagation();
                            onChange(selected.filter((v) => v !== value));
                          }}
                        />
                      ) : null}
                    </span>
                  ))}
                  {overflow > 0 ? (
                    <span
                      className={cn(
                        "rounded-md bg-secondary font-medium text-secondary-foreground",
                        compact ? "px-1 py-0 text-[9px]" : "px-1.5 py-0.5 text-xs",
                      )}
                    >
                      +{overflow}
                    </span>
                  ) : null}
                </>
              )}
            </div>
            <ChevronDown
              className={cn(
                "shrink-0 text-muted-foreground",
                compact ? "size-3.5" : "size-4",
              )}
            />
          </button>
        </PopoverTrigger>
        <PopoverContent className="w-[min(22rem,94vw)] p-0" align="start">
          <Command shouldFilter={false}>
            <CommandInput
              placeholder={searchPlaceholder ?? `Search ${label.toLowerCase()}...`}
              value={query}
              onValueChange={setQuery}
            />
            <div className="flex items-center justify-between border-b border-border px-2 py-1.5">
              <Button
                variant="ghost"
                size="sm"
                className="h-7 text-xs"
                disabled={filteredOptions.length === 0}
                onClick={selectVisible}
              >
                {query.trim() ? `Select results (${filteredOptions.length})` : "Select all"}
              </Button>
              <Button variant="ghost" size="sm" className="h-7 text-xs" onClick={() => onChange([])}>
                Clear all
              </Button>
            </div>
            <CommandList className="max-h-64">
              <CommandEmpty>No matches found.</CommandEmpty>
              <CommandGroup>
                {filteredOptions.map((option) => {
                  const active = selected.includes(option.value);
                  return (
                    <CommandItem
                      key={option.value}
                      value={`${option.label} ${option.value}`}
                      onSelect={() => toggle(option.value)}
                    >
                      <span
                        className={cn(
                          "mr-2 flex size-4 items-center justify-center rounded border border-border",
                          active && "border-primary bg-primary text-primary-foreground",
                        )}
                      >
                        {active ? <Check className="size-3" /> : null}
                      </span>
                      <span className="min-w-0 flex-1 truncate">{option.label}</span>
                      {showValueHint && option.label !== option.value ? (
                        <span className="ml-2 shrink-0 text-[10px] text-muted-foreground">
                          {option.value}
                        </span>
                      ) : null}
                    </CommandItem>
                  );
                })}
              </CommandGroup>
            </CommandList>
          </Command>
        </PopoverContent>
      </Popover>
    </div>
  );
}
