import { Check, ChevronDown, ChevronRight, X } from "lucide-react";
import { useMemo, useState } from "react";
import { Button } from "@/components/ui/button";
import { Command, CommandEmpty, CommandInput, CommandItem, CommandList } from "@/components/ui/command";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import type { ConsultantGroup } from "@/hooks/useOrders";
import { cn } from "@/lib/utils";

type Props = {
  groups: ConsultantGroup[];
  selected: string[];
  onChange: (repCodes: string[]) => void;
};

export function ConsultantMultiSelect({ groups, selected, onChange }: Props) {
  const [open, setOpen] = useState(false);
  const [expanded, setExpanded] = useState<Set<string>>(() => new Set());
  const members = useMemo(() => groups.flatMap((group) => group.members), [groups]);
  const labels = useMemo(() => new Map(members.map((member) => [member.rep_code, member.name])), [members]);
  const allCodes = useMemo(() => [...new Set(members.map((member) => member.rep_code))], [members]);

  const toggleMember = (repCode: string) => {
    onChange(selected.includes(repCode)
      ? selected.filter((code) => code !== repCode)
      : [...selected, repCode]);
  };

  const toggleGroup = (group: ConsultantGroup) => {
    const codes = [...new Set(group.members.map((member) => member.rep_code))];
    const allSelected = codes.length > 0 && codes.every((code) => selected.includes(code));
    onChange(allSelected
      ? selected.filter((code) => !codes.includes(code))
      : [...new Set([...selected, ...codes])]);
  };

  const toggleExpanded = (id: string) => {
    setExpanded((current) => {
      const next = new Set(current);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  };

  return (
    <div className="grid min-w-52 gap-1.5">
      <span className="text-xs font-semibold tracking-wide text-primary">Consultant</span>
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <button
            type="button"
            aria-label="Consultant"
            aria-expanded={open}
            className="flex h-9 w-full items-center gap-2 rounded-md border border-input bg-background px-3 text-left shadow-sm"
          >
            <span className="min-w-0 flex-1 truncate text-sm">
              {selected.length === 0
                ? "All consultants"
                : selected.length === 1
                  ? (labels.get(selected[0]) ?? selected[0])
                  : `${selected.length} consultants`}
            </span>
            <ChevronDown className="size-4 shrink-0 text-muted-foreground" />
          </button>
        </PopoverTrigger>
        <PopoverContent className="w-[min(24rem,94vw)] p-0" align="start">
          <Command>
            <CommandInput placeholder="Search HOD, consultant or rep code..." />
            <div className="flex items-center justify-between border-b px-2 py-1.5">
              <Button variant="ghost" size="sm" className="h-7 text-xs" onClick={() => onChange(allCodes)}>
                Select all
              </Button>
              <Button variant="ghost" size="sm" className="h-7 text-xs" onClick={() => onChange([])}>
                Clear
              </Button>
            </div>
            <CommandList className="max-h-80">
              <CommandEmpty>No consultants found.</CommandEmpty>
              {groups.map((group) => {
                const codes = [...new Set(group.members.map((member) => member.rep_code))];
                const allSelected = codes.length > 0 && codes.every((code) => selected.includes(code));
                const someSelected = !allSelected && codes.some((code) => selected.includes(code));
                const isExpanded = expanded.has(group.id);

                return (
                  <div key={group.id} className="border-b last:border-b-0">
                    <CommandItem
                      value={`${group.name} ${group.members.map((member) => `${member.name} ${member.rep_code}`).join(" ")}`}
                      onSelect={() => toggleGroup(group)}
                      className="font-semibold"
                    >
                      <button
                        type="button"
                        className="mr-1 rounded p-0.5 hover:bg-muted"
                        aria-label={`${isExpanded ? "Collapse" : "Expand"} ${group.name}`}
                        onClick={(event) => {
                          event.preventDefault();
                          event.stopPropagation();
                          toggleExpanded(group.id);
                        }}
                      >
                        {isExpanded ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                      </button>
                      <span className={cn(
                        "mr-2 flex size-4 items-center justify-center rounded border",
                        (allSelected || someSelected) && "border-primary bg-primary text-primary-foreground",
                      )}>
                        {allSelected ? <Check className="size-3" /> : someSelected ? <span className="h-0.5 w-2 bg-current" /> : null}
                      </span>
                      <span className="min-w-0 flex-1 truncate">{group.name}</span>
                      <span className="text-[10px] font-normal text-muted-foreground">{group.members.length}</span>
                    </CommandItem>
                    {isExpanded ? group.members.map((member) => {
                      const active = selected.includes(member.rep_code);
                      return (
                        <CommandItem
                          key={`${group.id}-${member.id}`}
                          value={`${member.name} ${member.rep_code}`}
                          onSelect={() => toggleMember(member.rep_code)}
                          className="pl-10"
                        >
                          <span className={cn(
                            "mr-2 flex size-4 items-center justify-center rounded border",
                            active && "border-primary bg-primary text-primary-foreground",
                          )}>
                            {active ? <Check className="size-3" /> : null}
                          </span>
                          <span className="min-w-0 flex-1 truncate">{member.name}</span>
                          <span className="text-[10px] text-muted-foreground">{member.rep_code}</span>
                        </CommandItem>
                      );
                    }) : null}
                  </div>
                );
              })}
            </CommandList>
          </Command>
          {selected.length > 0 ? (
            <div className="flex flex-wrap gap-1 border-t p-2">
              {selected.slice(0, 4).map((code) => (
                <button
                  key={code}
                  type="button"
                  onClick={() => toggleMember(code)}
                  className="inline-flex max-w-36 items-center gap-1 rounded bg-secondary px-1.5 py-0.5 text-xs"
                >
                  <span className="truncate">{labels.get(code) ?? code}</span>
                  <X className="size-3" />
                </button>
              ))}
              {selected.length > 4 ? (
                <span className="px-1 text-xs text-muted-foreground">+{selected.length - 4}</span>
              ) : null}
            </div>
          ) : null}
        </PopoverContent>
      </Popover>
    </div>
  );
}
