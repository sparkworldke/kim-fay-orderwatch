import { SlidersHorizontal } from "lucide-react";
import type { ReactNode } from "react";
import { Button } from "@/components/ui/button";
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@/components/ui/sheet";

export function FilterTrigger({ onClick, count }: { onClick: () => void; count?: number }) {
  return (
    <Button size="sm" className="shrink-0 gap-1.5 bg-blue-700 hover:bg-blue-800" onClick={onClick}>
      <SlidersHorizontal className="size-3.5" />
      Apply Filters
      {count ? (
        <span className="grid size-4 place-items-center rounded-full bg-primary text-[10px] font-bold text-primary-foreground">
          {count}
        </span>
      ) : null}
    </Button>
  );
}

export function FilterDrawer({
  open,
  onOpenChange,
  subtitle,
  children,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  subtitle?: string;
  children: ReactNode;
}) {
  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="right" className="flex w-full flex-col gap-0 overflow-y-auto sm:max-w-sm">
        <SheetHeader>
          <SheetTitle className="flex items-center gap-2">
            <SlidersHorizontal className="size-4 text-primary" />
            Filters
          </SheetTitle>
          {subtitle ? <SheetDescription>{subtitle}</SheetDescription> : null}
        </SheetHeader>
        <div className="mt-3 flex flex-col gap-3">{children}</div>
        <div className="sticky bottom-0 mt-auto bg-background pt-4">
          <Button className="w-full bg-blue-700 hover:bg-blue-800" onClick={() => onOpenChange(false)}>
            Apply Filters
          </Button>
        </div>
      </SheetContent>
    </Sheet>
  );
}
