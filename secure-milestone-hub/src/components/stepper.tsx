import { motion } from "motion/react";
import { Check } from "lucide-react";
import { cn } from "@/lib/utils";

export interface StepDef {
  id: string;
  label: string;
  hint?: string;
}

export function Stepper({
  steps,
  current,
  className,
}: {
  steps: StepDef[];
  current: number;
  className?: string | undefined;
}) {
  return (
    <ol className={cn("flex w-full items-start gap-2 sm:gap-3", className)}>
      {steps.map((step, i) => {
        const done = i < current;
        const active = i === current;
        return (
          <li key={step.id} className="flex min-w-0 flex-1 flex-col gap-2">
            <div className="flex items-center gap-2">
              <span
                className={cn(
                  "grid h-7 w-7 shrink-0 place-items-center rounded-full border text-xs font-semibold transition-colors",
                  done && "border-success bg-success text-success-foreground",
                  active && "border-primary bg-primary text-primary-foreground",
                  !done && !active && "border-border bg-card text-muted-foreground",
                )}
              >
                {done ? <Check className="h-3.5 w-3.5" aria-hidden /> : i + 1}
              </span>
              <div className="relative h-px flex-1 overflow-hidden bg-border">
                <motion.div
                  className="absolute inset-0 origin-left bg-primary"
                  initial={false}
                  animate={{ scaleX: done ? 1 : 0 }}
                  transition={{ duration: 0.35, ease: "easeOut" }}
                />
              </div>
            </div>
            <div className="min-w-0">
              <p
                className={cn(
                  "truncate text-xs font-medium sm:text-sm",
                  active ? "text-foreground" : "text-muted-foreground",
                )}
              >
                {step.label}
              </p>
              {step.hint && (
                <p className="hidden truncate text-xs text-muted-foreground sm:block">{step.hint}</p>
              )}
            </div>
          </li>
        );
      })}
    </ol>
  );
}
