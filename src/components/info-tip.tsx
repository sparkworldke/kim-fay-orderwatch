import { Info } from "lucide-react";
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/components/ui/tooltip";

/**
 * Small info icon with an explanatory tooltip, safe to place inside
 * clickable cards (clicks on the icon do not bubble to the parent).
 */
export function InfoTip({ text, className }: { text: string; className?: string }) {
  return (
    <TooltipProvider delayDuration={150}>
      <Tooltip>
        <TooltipTrigger asChild>
          <span
            tabIndex={0}
            aria-label={text}
            className={`inline-flex shrink-0 cursor-help text-muted-foreground/70 hover:text-muted-foreground ${className ?? ""}`}
            onClick={(event) => event.stopPropagation()}
          >
            <Info className="h-3.5 w-3.5" />
          </span>
        </TooltipTrigger>
        <TooltipContent className="max-w-72 text-left leading-snug">{text}</TooltipContent>
      </Tooltip>
    </TooltipProvider>
  );
}
